<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientSigninAttempt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'identifier',
        'matched',
        'agency_count',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'matched' => 'boolean',
    ];
}
