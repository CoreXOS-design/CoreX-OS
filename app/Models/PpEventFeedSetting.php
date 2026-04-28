<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpEventFeedSetting extends Model
{
    protected $table = 'pp_event_feed_settings';
    public $timestamps = false;
    protected $fillable = ['key', 'value', 'updated_at'];

    public static function getValue(string $key): ?string
    {
        $row = static::where('key', $key)->first();
        return $row?->value;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
    }
}
