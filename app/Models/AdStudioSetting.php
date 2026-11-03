<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AT-527 - one Ad Studio brand setting (key -> JSON value). Platform record, CoreX's own marketing: no agency scope. Spec .ai/specs/training-videos.md 9.21.5.
 */
class AdStudioSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }

    /** @return array<string,mixed> every stored setting, decoded */
    public static function allValues(): array
    {
        return static::query()->pluck('value', 'key')->map(fn ($v) => json_decode((string) $v, true))->all();
    }
}
