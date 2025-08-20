<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Group extends Model
{
    use HasUuids;

    // Table name
    protected $table = 'access_group';

    // Primary key
    protected $primaryKey = 'id';

    // Primary key type
    protected $keyType = 'uuid';

    // Disable auto-incrementing since we use UUID
    public $incrementing = false;

    // Timestamps
    public $timestamps = true;
    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    // Fillable fields
    protected $fillable = [
        'title',
        'description',
        'account_id',
        'created_by_id',
        'rubric_id',
    ];

    // // Relationships
    // public function account()
    // {
    //     return $this->belongsTo(AccessAccount::class, 'account_id');
    // }

    // public function createdBy()
    // {
    //     return $this->belongsTo(UserRoleAccount::class, 'created_by_id');
    // }

    // public function rubric()
    // {
    //     return $this->belongsTo(Rubric::class, 'rubric_id');
    // }
}
