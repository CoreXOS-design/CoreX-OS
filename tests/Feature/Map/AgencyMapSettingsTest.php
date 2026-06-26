<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\Agency;
use App\Models\AgencyMapSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 4 — multi-tenancy fix: map center/zoom/bounds are per-agency, not the hardcoded
 * HFC KZN South Coast box. A fresh agency falls back to config('map.defaults.*'); an
 * agency that sets its own values gets its own geography.
 */
final class AgencyMapSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_agency_falls_back_to_config_defaults(): void
    {
        $agency = Agency::create(['name' => 'Default Agency', 'slug' => 'default-agency']);

        $cfg = AgencyMapSettings::forAgency((int) $agency->id)->clientConfig();

        $this->assertSame((float) config('map.defaults.center.lat'), $cfg['center']['lat']);
        $this->assertSame((float) config('map.defaults.bounds.north'), $cfg['bounds']['north']);
        $this->assertSame((int) config('map.defaults.zoom'), $cfg['zoom']);
        $this->assertSame(config('map.defaults.sold_window'), $cfg['soldWindow']);
    }

    public function test_a_second_agency_can_set_its_own_geography(): void
    {
        AgencyMapSettings::clearCache();
        $agency = Agency::create(['name' => 'Joburg Agency', 'slug' => 'joburg-agency']);

        // A Gauteng agency — nothing like the KZN South Coast box.
        AgencyMapSettings::forAgency((int) $agency->id)->update([
            'center_lat' => -26.2041, 'center_lng' => 28.0473, 'default_zoom' => 12,
            'bounds_north' => -25.9, 'bounds_south' => -26.5, 'bounds_east' => 28.4, 'bounds_west' => 27.7,
            'default_sold_window' => '12mo',
        ]);
        AgencyMapSettings::clearCache();

        $cfg = AgencyMapSettings::forAgency((int) $agency->id)->clientConfig();

        $this->assertSame(-26.2041, $cfg['center']['lat']);
        $this->assertSame(28.0473, $cfg['center']['lng']);
        $this->assertSame(12, $cfg['zoom']);
        $this->assertSame(-25.9, $cfg['bounds']['north']);
        $this->assertSame('12mo', $cfg['soldWindow']);

        // It must NOT be the HFC box.
        $this->assertNotSame((float) config('map.defaults.bounds.north'), $cfg['bounds']['north']);
    }

    public function test_layer_caps_resolve_from_config_when_no_override(): void
    {
        $agency = Agency::create(['name' => 'Caps Agency', 'slug' => 'caps-agency']);
        $s = AgencyMapSettings::forAgency((int) $agency->id);

        $this->assertSame((int) config('map.defaults.caps.region_cap'), $s->cap('region_cap'));
        $this->assertSame((int) config('map.defaults.caps.max_limit'), $s->cap('max_limit'));
    }
}
