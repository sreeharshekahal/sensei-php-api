<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserRoleAccount extends Model
{
    use HasUuids;

    // Table name
    protected $table = 'access_userroleaccount';

    // Primary key
    protected $primaryKey = 'id';

    // Primary key is UUID
    public $incrementing = false;
    protected $keyType = 'string';
    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    // Timestamps
    public $timestamps = true; // Use created and updated columns

 

    // Fillable fields
    protected $fillable = [
        'account_id',
        'role_id',
        'user_id',
    ];

    // // Relationships
    // public function account()
    // {
    //     return $this->belongsTo(Account::class, 'account_id');
    // }

    // public function role()
    // {
    //     return $this->belongsTo(Role::class, 'role_id');
    // }

    // public function user()
    // {
    //     return $this->belongsTo(AuthUser::class, 'user_id');
    // }

    // public function groups()
    // {
    //     return $this->belongsToMany(
    //         AccessGroup::class,
    //         'access_group_users',
    //         'userroleaccount_id',
    //         'group_id'
    //     );
    // }
}