<?php

namespace Jenssegers\Mongodb\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;

class MorphTo extends EloquentMorphTo
{
    use HasOneOrManyTrait;

    /**
     * @inheritdoc
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            // For belongs to relationships, which are essentially the inverse of has one
            // or has many relationships, we need to actually query on the primary key
            // of the related models matching on the foreign key that's on a parent.
            $this->query->where($this->getOwnerKey(), '=', $this->parent->{$this->foreignKey});
        }
    }

    /**
     * Build a dictionary with the models.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    protected function buildDictionary(Collection $models)
    {
        foreach ($models as $model) {
            if ($model->{$this->morphType}) {
                $this->dictionary[$model->{$this->morphType}][(string) $model->{$this->foreignKey}][] = $model;
            }
        }
    }

     /**
     * @inheritdoc
     */
    protected function getResultsByType($type)
    {
        $instance = $this->createModelByType($type);

        $key = $instance->getKeyName();

        $query = $instance->newQuery();

        return $query->whereIn($key, $this->gatherKeysByType($type))->get();
    }

    /**
     * Get the owner key with backwards compatible support.
     *
     * @return string
     */
    public function getOwnerKey()
    {
        return property_exists($this, 'ownerKey') ? $this->ownerKey : $this->otherKey;
    }
}
