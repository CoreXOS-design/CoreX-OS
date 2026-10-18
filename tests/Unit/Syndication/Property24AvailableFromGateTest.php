<?php

declare(strict_types=1);

namespace Tests\Unit\Syndication;

use App\Models\Property;
use App\Services\Syndication\Property24\Property24ListingMapper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (3): one
 * property-level setting (`show_available_from_on_portals`) gates BOTH of
 * this mapper's existing consumers of `lease_start_date` as "available
 * from" — the generic `occupationDate` (any listing type) and
 * `commercialInfo.availabilityDate` (commercial-typed only, §15 GATE 2's
 * own pre-existing line). This is the single decision point both of those
 * lines call (map() lines ~133/~157) — proving it proves the feed output.
 */
final class Property24AvailableFromGateTest extends TestCase
{
    private function shouldSend(Property $property): bool
    {
        $method = new ReflectionMethod(Property24ListingMapper::class, 'shouldSendAvailableFrom');
        $method->setAccessible(true);

        return $method->invoke(new Property24ListingMapper(), $property);
    }

    public function test_sends_when_lease_start_date_set_and_setting_on(): void
    {
        $p = (new Property())->forceFill(['lease_start_date' => '2026-12-01', 'show_available_from_on_portals' => true]);

        self::assertTrue($this->shouldSend($p));
    }

    public function test_does_not_send_when_setting_is_off(): void
    {
        $p = (new Property())->forceFill(['lease_start_date' => '2026-12-01', 'show_available_from_on_portals' => false]);

        self::assertFalse($this->shouldSend($p));
    }

    public function test_does_not_send_when_no_lease_start_date(): void
    {
        $p = (new Property())->forceFill(['lease_start_date' => null, 'show_available_from_on_portals' => true]);

        self::assertFalse($this->shouldSend($p));
    }

    /** Default ON — matches this mapper's own pre-existing, always-on behaviour. */
    public function test_defaults_to_on_when_the_setting_was_never_set(): void
    {
        $p = (new Property())->forceFill(['lease_start_date' => '2026-12-01']);

        self::assertTrue($this->shouldSend($p));
    }
}
