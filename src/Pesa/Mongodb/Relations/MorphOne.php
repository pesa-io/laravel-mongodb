<?php
namespace Pesa\Mongodb\Relations;

use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;

class MorphOne extends EloquentMorphOne
{
    use HasOneOrManyTrait;
}
