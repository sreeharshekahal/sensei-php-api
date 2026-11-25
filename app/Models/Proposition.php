<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Proposition extends Model
{
    protected $table = 'rubrics_proposition';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'id',
        'title',
        'description',
        'rubric_id',
    ];

    protected static function booted()
    {
        static::creating(function ($proposition) {
            if (!$proposition->id) {
                $proposition->id = (string) Str::uuid();
            }
        });
    }
}
