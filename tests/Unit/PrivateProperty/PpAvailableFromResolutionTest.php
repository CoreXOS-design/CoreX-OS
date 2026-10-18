<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (3). PP's
 * `AvailableFrom` struct field is REQUIRED (always sent) — before this it
 * was unconditionally `now()`, so a rental re-advertised with a real
 * move-out-based date (§15 GATE 2 row 2) never showed its real
 * availability on PP. Now reads `lease_start_date` when the property's own
 * `show_available_from_on_portals` setting allows it; falls back to
 * `now()` exactly as before otherwise ("send nothing extra" — PP's struct
 * can't omit the field, so the pre-existing default is what "off" means).
 */
class PpAvailableFromResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_uses_lease_start_date_when_set_and_setting_on(): void
    {
        $p = (new Property())->forceFill(['lease_start_date' => '2026-12-01', 'show_available_from_on_portals' => true]);

        self::assertSame('2026-12-01T00:00:00', PrivatePropertyListingMapper::resolveAvailableFrom($p));
    }

    public function test_falls_back_to_now_when_setting_is_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 08:00:00'));
        $p = (new Property())->forceFill(['lease_start_date' => '2026-12-01', 'show_available_from_on_portals' => false]);

        self::assertSame('2026-07-17T08:00:00', PrivatePropertyListingMapper::resolveAvailableFrom($p));
    }

    public function test_falls_back_to_now_when_no_lease_start_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 08:00:00'));
        $p = (new Property())->forceFill(['lease_start_date' => null, 'show_available_from_on_portals' => true]);

        self::assertSame('2026-07-17T08:00:00', PrivatePropertyListingMapper::resolveAvailableFrom($p));
    }

    /** Default ON — matches this field's own pre-existing, always-sent behaviour for a set date. */
    public function test_defaults_to_on_when_the_setting_was_never_set(): void
    {
        $p = (new Property())->forceFill(['lease_start_date' => '2026-12-01']);

        self::assertSame('2026-12-01T00:00:00', PrivatePropertyListingMapper::resolveAvailableFrom($p));
    }
}
