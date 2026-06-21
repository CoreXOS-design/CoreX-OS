<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DevSetting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_PREFIX = 'dev_setting:';
    private const CACHE_TTL = 3600;

    public static function get(string $key, $default = null)
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Hidden demo-sidebar nav keys (g:<group> | p:<path>). Always an array.
     * One global list applied to demo-agency members only — see
     * .ai/specs/demo-sidebar-curation.md.
     */
    public static function demoHiddenSidebar(): array
    {
        $decoded = json_decode((string) self::get('demo_hidden_sidebar', '[]'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
