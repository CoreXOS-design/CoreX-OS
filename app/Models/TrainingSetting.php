<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** AT-525 (spec 9.24) - one platform-level setting of the training-video system (key -> value). */
class TrainingSetting extends Model
{
    protected $table = 'training_settings';
    protected $guarded = [];

    public static function read(string $key, ?string $default = null): ?string
    {
        if (! Schema::hasTable('training_settings')) {
            return $default;
        }

        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function write(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
