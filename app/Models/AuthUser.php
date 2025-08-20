<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AuthUser extends Authenticatable
{
    use Notifiable;

    // Specify custom table name
    protected $table = 'auth_user';

    // Primary key column
    protected $primaryKey = 'id';

    // Disable Laravel's timestamps (created_at, updated_at) since table uses date_joined instead
    public $timestamps = false;

    // Columns that are mass assignable
    protected $fillable = [
        'username',
        'password',
        'first_name',
        'last_name',
        'email',
        'is_superuser',
        'is_staff',
        'is_active',
        'last_login',
        'date_joined',
    ];

    // Columns hidden from JSON responses
    protected $hidden = [
        'password',
    ];

    // Casts for specific columns
    protected $casts = [
        'is_superuser' => 'boolean',
        'is_staff' => 'boolean',
        'is_active' => 'boolean',
        'last_login' => 'datetime',
        'date_joined' => 'datetime',
    ];

    /**
     * Set the user's password (hashed automatically)
     */
    // public function setPasswordAttribute($value)
    // {
    //     $this->attributes['password'] = bcrypt($value);
    // }
}
