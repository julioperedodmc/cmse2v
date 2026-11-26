<?php

namespace App\Models\Steve;

use Illuminate\Database\Eloquent\Model;

class WebUser extends Model
{
    protected $connection = 'steve';
    protected $table = 'web_user';
    protected $primaryKey = 'web_user_pk';
    public $timestamps = false;

    protected $fillable = [
        'username',
        'password',
        'api_password',
        'enabled',
        'authorities',
    ];

    protected $hidden = [
        'password',
        'api_password',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'authorities' => 'array',
    ];
}
