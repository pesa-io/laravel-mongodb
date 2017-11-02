<?php
namespace Pesa\Mongodb\Relations;

use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;

class MorphMany extends EloquentMorphMany
{
    use HasOneOrManyTrait;
}
