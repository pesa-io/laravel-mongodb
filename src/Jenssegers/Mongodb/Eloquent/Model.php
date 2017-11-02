<?php

namespace Jenssegers\Mongodb\Eloquent;

use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Jenssegers\Mongodb\Query\Builder as QueryBuilder;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;

abstract class Model extends BaseModel
{
    use HybridRelations, EmbedsRelations;

    /**
     * The collection associated with the model.
     *
     * @var string
     */
    protected $collection;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = '_id';

    /**
     * The parent relation instance.
     *
     * @var Relation
     */
    protected $parentRelation;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $saveCasts = [];

    /**
     * Custom accessor for the model's id.
     *
     * @param  mixed $value
     * @return mixed
     */
    public function getIdAttribute($value = null)
    {
        // If we don't have a value for 'id', we will use the Mongo '_id' value.
        // This allows us to work with models in a more sql-like way.
        if (!$value and array_key_exists('_id', $this->attributes)) {
            $value = $this->attributes['_id'];
        }

        // Convert ObjectID to string.
        if ($value instanceof ObjectID) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * @inheritdoc
     */
    public function fromDateTime($value)
    {
        // If the value is already a UTCDateTime instance, we don't need to parse it.
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        // Let Eloquent convert the value to a DateTime instance.
        if (!$value instanceof DateTime) {
            $value = parent::asDateTime($value);
        }

        return new UTCDateTime($value->getTimestamp() * 1000);
    }

    /**
     * @inheritdoc
     */
    protected function asDateTime($value)
    {
        // Convert UTCDateTime instances.
        if ($value instanceof UTCDateTime) {
            return Carbon::createFromTimestamp($value->toDateTime()->getTimestamp());
        }

        return parent::asDateTime($value);
    }

    /**
     * @inheritdoc
     */
    protected function getDateFormat()
    {
        return $this->dateFormat ?: 'Y-m-d H:i:s';
    }

    /**
     * @inheritdoc
     */
    public function freshTimestamp()
    {
        return new UTCDateTime(time() * 1000);
    }

    /**
     * @inheritdoc
     */
    public function getTable()
    {
        return $this->collection ?: parent::getTable();
    }

    /**
     * @inheritdoc
     */
    public function getAttribute($key)
    {
        if (!$key) {
            return;
        }

        // Dot notation support.
        if (str_contains($key, '.') and array_has($this->attributes, $key)) {
            return $this->getAttributeValue($key);
        }

        // This checks for embedded relation support.
        if (method_exists($this, $key) and !method_exists(self::class, $key)) {
            return $this->getRelationValue($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * @inheritdoc
     */
    protected function getAttributeFromArray($key)
    {
        // Support keys in dot notation.
        if (str_contains($key, '.')) {
            return array_get($this->attributes, $key);
        }

        return parent::getAttributeFromArray($key);
    }

    /**
     * @inheritdoc
     */
    public function setAttribute($key, $value)
    {
        // Convert _id to ObjectID.
        if ($key == '_id' and is_string($value)) {
            $builder = $this->newBaseQueryBuilder();

            $value = $builder->convertKey($value);
        } // Support keys in dot notation.
        elseif (str_contains($key, '.')) {
            if (in_array($key, $this->getDates()) && $value) {
                $value = $this->fromDateTime($value);
            }

            array_set($this->attributes, $key, $value);

            return;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @inheritdoc
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        // Because the original Eloquent never returns objects, we convert
        // MongoDB related objects to a string representation. This kind
        // of mimics the SQL behaviour so that dates are formatted
        // nicely when your models are converted to JSON.
        foreach ($attributes as $key => &$value) {
            if ($value instanceof ObjectID) {
                $value = (string) $value;
            }
        }

        // Convert dot-notation dates.
        foreach ($this->getDates() as $key) {
            if (str_contains($key, '.') and array_has($attributes, $key)) {
                array_set($attributes, $key, (string) $this->asDateTime(array_get($attributes, $key)));
            }
        }

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function getCasts()
    {
        return $this->casts;
    }

    /**
     * @inheritdoc
     */
    protected function originalIsNumericallyEquivalent($key)
    {
        $current = $this->attributes[$key];
        $original = $this->original[$key];

        // Date comparison.
        if (in_array($key, $this->getDates())) {
            $current = $current instanceof UTCDateTime ? $this->asDateTime($current) : $current;
            $original = $original instanceof UTCDateTime ? $this->asDateTime($original) : $original;

            return $current == $original;
        }

        return parent::originalIsNumericallyEquivalent($key);
    }

    /**
     * Remove one or more fields.
     *
     * @param  mixed $columns
     * @return int
     */
    public function drop($columns)
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        // Unset attributes
        foreach ($columns as $column) {
            $this->__unset($column);
        }

        // Perform unset only on current document
        return $this->newQuery()->where($this->getKeyName(), $this->getKey())->unset($columns);
    }

    /**
     * @inheritdoc
     */
    public function push()
    {
        if ($parameters = func_get_args()) {
            $unique = false;

            if (count($parameters) == 3) {
                list($column, $values, $unique) = $parameters;
            } else {
                list($column, $values) = $parameters;
            }

            // Do batch push by default.
            if (!is_array($values)) {
                $values = [$values];
            }

            $query = $this->setKeysForSaveQuery($this->newQuery());

            $this->pushAttributeValues($column, $values, $unique);

            return $query->push($column, $values, $unique);
        }

        return parent::push();
    }

    /**
     * Remove one or more values from an array.
     *
     * @param  string $column
     * @param  mixed $values
     * @return mixed
     */
    public function pull($column, $values)
    {
        // Do batch pull by default.
        if (!is_array($values)) {
            $values = [$values];
        }

        $query = $this->setKeysForSaveQuery($this->newQuery());

        $this->pullAttributeValues($column, $values);

        return $query->pull($column, $values);
    }

    /**
     * Append one or more values to the underlying attribute value and sync with original.
     *
     * @param  string $column
     * @param  array $values
     * @param  bool $unique
     */
    protected function pushAttributeValues($column, array $values, $unique = false)
    {
        $current = $this->getAttributeFromArray($column) ?: [];

        foreach ($values as $value) {
            // Don't add duplicate values when we only want unique values.
            if ($unique and in_array($value, $current)) {
                continue;
            }

            array_push($current, $value);
        }

        $this->attributes[$column] = $current;

        $this->syncOriginalAttribute($column);
    }

    /**
     * Remove one or more values to the underlying attribute value and sync with original.
     *
     * @param  string $column
     * @param  array $values
     */
    protected function pullAttributeValues($column, array $values)
    {
        $current = $this->getAttributeFromArray($column) ?: [];

        foreach ($values as $value) {
            $keys = array_keys($current, $value);

            foreach ($keys as $key) {
                unset($current[$key]);
            }
        }

        $this->attributes[$column] = array_values($current);

        $this->syncOriginalAttribute($column);
    }

    /**
     * @inheritdoc
     */
    public function getForeignKey()
    {
        return Str::snake(class_basename($this)) . '_' . ltrim($this->primaryKey, '_');
    }

    /**
     * Set the parent relation.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
     */
    public function setParentRelation(Relation $relation)
    {
        $this->parentRelation = $relation;
    }

    /**
     * Get the parent relation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function getParentRelation()
    {
        return $this->parentRelation;
    }

    /**
     * @inheritdoc
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * @inheritdoc
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new QueryBuilder($connection, $connection->getPostProcessor());
    }

    /**
     * @inheritdoc
     */
    protected function removeTableFromKey($key)
    {
        return $key;
    }

    /**
     * @inheritdoc
     */
    public function __call($method, $parameters)
    {
        // Unset method
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * setter for casts
     * @param $cast
     * @param string $castType
     * @return void
     */
    public function setCasts($cast, $castType = 'get')
    {
        if ($castType == 'set') {
            $this->saveCasts = $cast;
            return;
        }
        $this->casts = $cast;
    }

    /**
     * Get the type of save cast for a model attribute.
     *
     * @param  string $key
     * @param string $castType
     * @return string
     */
    protected function getCastType($key, $castType = 'get')
    {
        return trim(strtolower($this->getCasts($castType)[$key]));
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param  string $key
     * @param  array|string|null $types
     * @param string $castType
     * @return bool
     */
    public function hasCast($key, $types = null, $castType = 'get')
    {
        if (array_key_exists($key, $this->getCasts($castType))) {
            return $types ? in_array($this->getCastType($key, $castType), (array) $types, true) : true;
        }

        return false;
    }
    /**
     * check if driver uses mongoId in relations.
     *
     * @return bool
     */
    public function useMongoId()
    {
        return (bool) config('database.connections.mongodb.use_mongo_id', false);
    }

    /**
     * Cast an attribute to a mongo type.
     *
     * @param  string $key
     * @param  mixed $value
     * @param string $castType
     * @return mixed
     */
    public function castAttribute($key, $value, $castType = 'get')
    {
        if (is_null($value)) {
            return;
        }

        if (!$this->hasCast($key, null, $castType)) {
            return $value;
        }

        switch ($this->getCastType($key, $castType)) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'date':
            case 'utcdatetime':
            case 'mongodate':
                return $this->asMongoDate($value);
            case 'mongoid':
            case 'objectid':
                return $this->asMongoID($value);
            case 'timestamp':
                return $this->asTimeStamp($value);
            default:
                return $value;
        }
    }

    /**
     * convert value into ObjectID if its possible
     *
     * @param $value
     * @return UTCDatetime
     */
    protected function asMongoID($value)
    {
        if (is_string($value) and strlen($value) === 24 and ctype_xdigit($value)) {
            return new ObjectID($value);
        }
        return $value;
    }

    /**
     * convert value into UTCDatetime
     * @param $value
     * @return UTCDatetime
     */
    protected function asMongoDate($value)
    {
        if ($value instanceof UTCDatetime) {
            return $value;
        }

        return new UTCDatetime($this->asTimeStamp($value) * 1000);
    }

    /**
     * add relation that ended with _id into objectId
     * if config allow it
     *
     * @param $key
     */
    public function setRelationCast($key)
    {
        if ($key == '_id') {
            $this->saveCasts['_id'] = 'ObjectID';
            return;
        }
        if ($this->useMongoId()) {
            if (ends_with($key, '_id')) {
                $this->saveCasts[$key] = 'ObjectID';
            }
        }
    }
}
