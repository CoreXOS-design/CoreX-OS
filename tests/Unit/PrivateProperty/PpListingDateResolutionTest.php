<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AT-287 — the PP `ListingDate` must be the syndication GO-LIVE date, never the
 * CoreX capture time (properties.created_at). A property captured, held ~2 weeks
 * for compliance, then syndicated must NOT reach PP already 2 weeks stale.
 */
class PpListingDateResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** First submit: pp_activated_at not yet stamped → go-live is right now, NOT capture. */
    public function test_first_submit_uses_go_live_now_not_capture_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 08:00:00'));

        $p = (new Property())->forceFill([
            'created_at'      => '2026-07-03 09:00:00', // captured 2 weeks earlier
            'pp_activated_at' => null,
            'listed_date'     => null,
        ]);

        $this->assertSame('2026-07-17T08:00:00', PrivatePropertyListingMapper::resolveListingDate($p));
        $this->assertStringNotContainsString('2026-07-03', PrivatePropertyListingMapper::resolveListingDate($p));
    }

    /** Resubmit: pp_activated_at is held stable → the date does not drift on re-send. */
    public function test_resubmit_returns_stable_go_live_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-25 12:00:00')); // a later resubmit

        $p = (new Property())->forceFill([
            'created_at'      => '2026-07-03 09:00:00',
            'pp_activated_at' => '2026-07-10 08:00:00', // original go-live
            'listed_date'     => null,
        ]);

        $this->assertSame('2026-07-10T08:00:00', PrivatePropertyListingMapper::resolveListingDate($p));
    }

    /** Imported stock: no pp_activated_at but a genuine historical listed_date. */
    public function test_imported_stock_falls_back_to_listed_date(): void
    {
        $p = (new Property())->forceFill([
            'created_at'      => '2026-07-03 09:00:00',
            'pp_activated_at' => null,
            'listed_date'     => '2026-06-01',
        ]);

        $this->assertSame('2026-06-01T00:00:00', PrivatePropertyListingMapper::resolveListingDate($p));
    }
}
