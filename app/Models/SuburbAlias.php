<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global reference row (no agency_id): a normalised suburb spelling and the canonical normalised
 * suburb it means — "three hills" -> "leisure bay". See .ai/specs/structured-address-matching.md §3.
 */
class SuburbAlias extends Model
{
    protected $table = 'suburb_aliases';

    protected $fillable = ['alias_normalised', 'canonical_normalised', 'p24_suburb_id', 'note', 'source'];

    /** @var array<string, string>|null alias_normalised => canonical_normalised, built once per process. */
    private static ?array $map = null;

    /** @return array<string, string> */
    public static function lookupMap(): array
    {
        if (self::$map === null) {
            try {
                self::$map = static::query()->pluck('canonical_normalised', 'alias_normalised')->all();
            } catch (\Throwable $e) {
                self::$map = []; // the table is optional to the matcher — the config groups still apply
            }
        }

        return self::$map;
    }

    /** Forget the cached map (after an alias is added, and between tests). */
    public static function flushCache(): void
    {
        self::$map = null;
    }
}
