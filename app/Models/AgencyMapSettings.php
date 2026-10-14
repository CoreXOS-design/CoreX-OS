<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-agency map settings (center / zoom / bounds / default sold window / layer caps).
 *
 * Single source of truth for the map's geographic + cap defaults, resolved per tenant.
 * NULL columns fall back to the area of the agency's OWN properties, else to the
 * neutral country view in config('map.defaults.*') — never another agency's patch. Mirrors
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

    /** Degrees either side of the agency's own stock centre that the default map box covers (about 30 km). */
    private const OWN_AREA_SPAN = 0.30;

    /** Zoom for an area derived from the agency's own stock. */
    private const OWN_AREA_ZOOM = 11;

    /** @var array{lat:float,lng:float}|false|null memo for derivedCentre() (false = looked, found none) */
    private array|false|null $derived = null;

    /**
     * Where this agency's own properties are: the average coordinates of the properties it holds, when it holds any with a position
     * inside South Africa. The "map opens in the wrong country" fix for an agency that has set nothing: its own patch, never another
     * agency's. null = no coordinates yet (the map then opens on the country view).
     *
     * @return array{lat:float,lng:float}|null
     */
    private function derivedCentre(): ?array
    {
        if ($this->derived === null) {
            $row = $this->agency_id
                ? \App\Models\Property::withoutGlobalScopes()
                    ->where('agency_id', $this->agency_id)
                    ->whereBetween('latitude', [-35.0, -22.0])
                    ->whereBetween('longitude', [16.0, 33.0])
                    ->selectRaw('AVG(latitude) AS lat, AVG(longitude) AS lng, COUNT(*) AS n')
                    ->first()
                : null;

            $this->derived = ($row && (int) $row->n > 0 && $row->lat !== null)
                ? ['lat' => round((float) $row->lat, 5), 'lng' => round((float) $row->lng, 5)]
                : false;
        }

        return $this->derived ?: null;
    }

    /** Resolved center [lat, lng] — the agency's setting, else the area of its own stock, else the country. */
    public function center(): array
    {
        $own = $this->derivedCentre();

        return [
            'lat' => $this->center_lat ?? ($own['lat'] ?? (float) config('map.defaults.center.lat')),
            'lng' => $this->center_lng ?? ($own['lng'] ?? (float) config('map.defaults.center.lng')),
        ];
    }

    public function zoom(): int
    {
        return $this->default_zoom ?? ($this->derivedCentre() ? self::OWN_AREA_ZOOM : (int) config('map.defaults.zoom'));
    }

    /** Resolved bounds box ['north','south','east','west']. */
    public function bounds(): array
    {
        $own = $this->derivedCentre();
        $span = self::OWN_AREA_SPAN;

        return [
            'north' => $this->bounds_north ?? ($own ? $own['lat'] + $span : (float) config('map.defaults.bounds.north')),
            'south' => $this->bounds_south ?? ($own ? $own['lat'] - $span : (float) config('map.defaults.bounds.south')),
            'east'  => $this->bounds_east  ?? ($own ? $own['lng'] + $span : (float) config('map.defaults.bounds.east')),
            'west'  => $this->bounds_west  ?? ($own ? $own['lng'] - $span : (float) config('map.defaults.bounds.west')),
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
