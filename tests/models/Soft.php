<?php

use Pesa\Mongodb\Eloquent\Model as Eloquent;
use Pesa\Mongodb\Eloquent\SoftDeletes;

class Soft extends Eloquent
{
    use SoftDeletes;

    protected $connection = 'mongodb';
    protected $collection = 'soft';
    protected static $unguarded = true;
    protected $dates = ['deleted_at'];
}
