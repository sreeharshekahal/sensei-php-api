<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupUser extends Model
{
    // Table name
    protected $table = 'access_group_users';

    // Primary key
    protected $primaryKey = 'id';

    // Auto-incrementing
    public $incrementing = true;

    // Timestamps (not present in table)
    public $timestamps = false;

    // Fillable fields
    protected $fillable = [
        'group_id',
        'userroleaccount_id',
    ];

    // Relationships
    // public function group()
    // {
    //     return $this->belongsTo(AccessGroup::class, 'group_id');
    // }

    // public function userRoleAccount()
    // {
    //     return $this->belongsTo(UserRoleAccount::class, 'userroleaccount_id');
    // }
}
