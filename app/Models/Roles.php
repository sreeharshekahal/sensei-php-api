<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Roles extends Model
{
    // Table name
    protected $table = 'access_role';

    // Primary key is UUID
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    // Map timestamps (since your table uses 'created' and 'updated')
    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    // Mass assignable fields
    protected $fillable = [
        'title',
        'description',
        'rank',
        'account_id'
    ];

    // Automatically generate UUID when creating
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    // // Relationship with Account
    // public function account()
    // {
    //     return $this->belongsTo(AccessAccount::class, 'account_id', 'id');
    // }
}
