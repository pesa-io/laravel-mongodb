<?php

namespace Jenssegers\Mongodb\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Jenssegers\Mongodb\Helpers\QueriesRelationships;
use MongoDB\Driver\Cursor;
use MongoDB\Model\BSONDocument;

class Builder extends EloquentBuilder
{
    use QueriesRelationships;

    /**
     * The methods that should be returned from query builder.
     *
     * @var array
     */
    protected $passthru = [
        'toSql',
        'insert',
        'insertGetId',
        'pluck',
        'count',
        'min',
        'max',
        'avg',
        'sum',
        'exists',
        'push',
        'pull',
    ];

    /**
     * @inheritdoc
     */
    public function update(array $values, array $options = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performUpdate($this->model, $values);

            return 1;
        }

        return $this->query->update($this->addUpdatedAtColumn($values), $options);
    }

    /**
     * @inheritdoc
     */
    public function insert(array $values)
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performInsert($this->model, $values);

            return true;
        }

        return parent::insert($values);
    }

    /**
     * @inheritdoc
     */
    public function insertGetId(array $values, $sequence = null)
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performInsert($this->model, $values);

            return $this->model->getKey();
        }

        return parent::insertGetId($values, $sequence);
    }

    /**
     * @inheritdoc
     */
    public function delete()
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performDelete($this->model);

            return $this->model->getKey();
        }

        return parent::delete();
    }

    /**
     * @inheritdoc
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $value = $this->model->{$column};

            // When doing increment and decrements, Eloquent will automatically
            // sync the original attributes. We need to change the attribute
            // temporary in order to trigger an update query.
            $this->model->{$column} = null;

            $this->model->syncOriginalAttribute($column);

            $result = $this->model->update([$column => $value]);

            return $result;
        }

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @inheritdoc
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $value = $this->model->{$column};

            // When doing increment and decrements, Eloquent will automatically
            // sync the original attributes. We need to change the attribute
            // temporary in order to trigger an update query.
            $this->model->{$column} = null;

            $this->model->syncOriginalAttribute($column);

            return $this->model->update([$column => $value]);
        }

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @inheritdoc
     * Add the "has" condition where clause to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $hasQuery
     * @param  \Illuminate\Database\Eloquent\Relations\Relation  $relation
     * @param  string  $operator
     * @param  int  $count
     * @param  string  $boolean
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function addHasWhere(EloquentBuilder $hasQuery, Relation $relation, $operator, $count, $boolean)
    {
        $query = $hasQuery->getQuery();

        // Get the number of related objects for each possible parent.
        $relations = $query->pluck($relation->getHasCompareKey());
        $relations = array_map(function ($id) {
            return (string) $id;
        }, is_array($relations) ? $relations : $relations->toArray());
        $relationCount = array_count_values($relations);

        // Remove unwanted related objects based on the operator and count.
        $relationCount = array_filter($relationCount, function ($counted) use ($count, $operator) {
            // If we are comparing to 0, we always need all results.
            if ($count == 0) {
                return true;
            }

            switch ($operator) {
                case '>=':
                case '<':
                    return $counted >= $count;
                case '>':
                case '<=':
                    return $counted > $count;
                case '=':
                case '!=':
                    return $counted == $count;
            }
        });

        // If the operator is <, <= or !=, we will use whereNotIn.
        $not = in_array($operator, ['<', '<=', '!=']);

        // If we are comparing to 0, we need an additional $not flip.
        if ($count == 0) {
            $not = !$not;
        }

        // All related ids.
        $relatedIds = array_map(function ($id) use ($relation) {
            $relationModel = $relation->getRelated();
            $relationModel->setRelationCast($relation->getHasCompareKey());
            if ($relationModel->useMongoId()
                && $relationModel->hasCast($relation->getHasCompareKey(), null, 'set')) {
                $id = $relationModel->castAttribute($relation->getHasCompareKey(), $id, 'set');
            }
            return $id;
        }, array_keys($relationCount));

        // Add whereIn to the query.
        return $this->whereIn($this->model->getKeyName(), $relatedIds, $boolean, $not);
    }

    /**
     * Create a raw database expression.
     *
     * @param  closure  $expression
     * @return mixed
     */
    public function raw($expression = null)
    {
        // Get raw results from the query builder.
        $results = $this->query->raw($expression);

        // Convert MongoCursor results to a collection of models.
        if ($results instanceof Cursor) {
            $results = iterator_to_array($results, false);

            return $this->model->hydrate($results);
        } // Convert Mongo BSONDocument to a single object.
        elseif ($results instanceof BSONDocument) {
            $results = $results->getArrayCopy();

            return $this->model->newFromBuilder((array) $results);
        } // The result is a single object.
        elseif (is_array($results) && array_key_exists('_id', $results)) {
            return $this->model->newFromBuilder((array) $results);
        }

        return $results;
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return $this->query->getConnection();
    }
}
