<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, $default = null)
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}
