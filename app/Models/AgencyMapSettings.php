<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-agency map settings (center / zoom / bounds / default sold window / layer caps).
 *
 * Single source of truth for the map's geographic + cap defaults, resolved per tenant.
 * NULL columns fall back to config('map.defaults.*') — so an agency that has never
 * touched its map config still gets a sensible (HFC) default, but a second agency can
 * set its own box and never inherit the KZN South Coast hardcode. Mirrors
 * AgencyContactSettings (forAgency + null-safe resolvers).
 */
class AgencyMapSettings extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_map_settings';

    protected $fillable = [
        'agency_id',
        'center_lat', 'center_lng', 'default_zoom',
        'bounds_north', 'bounds_south', 'bounds_east', 'bounds_west',
        'default_sold_window', 'layer_caps',
    ];

    protected $casts = [
        'center_lat'   => 'float',
        'center_lng'   => 'float',
        'default_zoom' => 'integer',
        'bounds_north' => 'float',
        'bounds_south' => 'float',
        'bounds_east'  => 'float',
        'bounds_west'  => 'float',
        'layer_caps'   => 'array',
    ];

    /** Per-request cache, keyed by agency id. */
    protected static array $cache = [];

    public static function forAgency(int $agencyId): self
    {
        return self::$cache[$agencyId]
            ??= self::withoutGlobalScopes()->firstOrCreate(['agency_id' => $agencyId]);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /** Resolved center [lat, lng] — column value or config fallback. */
    public function center(): array
    {
        return [
            'lat' => $this->center_lat ?? (float) config('map.defaults.center.lat'),
            'lng' => $this->center_lng ?? (float) config('map.defaults.center.lng'),
        ];
    }

    public function zoom(): int
    {
        return $this->default_zoom ?? (int) config('map.defaults.zoom');
    }

    /** Resolved bounds box ['north','south','east','west']. */
    public function bounds(): array
    {
        return [
            'north' => $this->bounds_north ?? (float) config('map.defaults.bounds.north'),
            'south' => $this->bounds_south ?? (float) config('map.defaults.bounds.south'),
            'east'  => $this->bounds_east  ?? (float) config('map.defaults.bounds.east'),
            'west'  => $this->bounds_west  ?? (float) config('map.defaults.bounds.west'),
        ];
    }

    public function defaultSoldWindow(): string
    {
        $v = $this->default_sold_window ?: (string) config('map.defaults.sold_window', '6mo');
        return in_array($v, ['3mo', '6mo', '12mo', '24mo', 'all'], true) ? $v : '6mo';
    }

    /** Resolved per-layer cap value — agency override or config fallback. */
    public function cap(string $key): int
    {
        $overrides = is_array($this->layer_caps) ? $this->layer_caps : [];
        return (int) ($overrides[$key] ?? config("map.defaults.caps.$key"));
    }

    /** Full resolved config blob for the blade (@json). */
    public function clientConfig(): array
    {
        return [
            'center'      => $this->center(),
            'zoom'        => $this->zoom(),
            'bounds'      => $this->bounds(),
            'soldWindow'  => $this->defaultSoldWindow(),
        ];
    }
}
