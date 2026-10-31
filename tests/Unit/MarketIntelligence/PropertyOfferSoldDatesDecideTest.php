<?php

declare(strict_types=1);

namespace Tests\Unit\MarketIntelligence;

use App\Services\Properties\PropertyOfferSoldDates as D;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** MIC 5.4 (AT-524): what the two dates become on each status move. */
final class PropertyOfferSoldDatesDecideTest extends TestCase
{
    private Carbon $t0;
    private Carbon $t1;

    protected function setUp(): void
    {
        $this->t0 = Carbon::parse('2026-08-01 10:00:00');
        $this->t1 = Carbon::parse('2026-09-01 11:00:00');
    }

    public function test_an_offer_stamps_under_offer_at_and_clears_a_sale(): void
    {
        $r = D::decide('active', 'under_offer', null, null, $this->t1);
        $this->assertSame($this->t1, $r['under_offer_at']);
        $this->assertNull($r['sold_at']);
        $this->assertNull(D::decide('sold', 'under_offer', null, $this->t0, $this->t1)['sold_at']);
    }

    public function test_a_sale_keeps_the_offer_date_and_stamps_sold_at_once(): void
    {
        $r = D::decide('under_offer', 'sold', $this->t0, null, $this->t1);
        $this->assertSame($this->t0, $r['under_offer_at']);
        $this->assertSame($this->t1, $r['sold_at']);
        $this->assertNull(D::decide('active', 'sold', null, null, $this->t1)['under_offer_at'], 'no offer date is invented');
        $this->assertSame($this->t0, D::decide('sold', 'transferred', null, $this->t0, $this->t1)['sold_at'], 'sold -> transferred keeps the sold date');
    }

    public function test_an_offer_that_falls_through_or_any_other_status_clears_both(): void
    {
        foreach (['active', 'withdrawn', 'expired', 'sold_by_3rd_party', 'let_out', 'rented'] as $to) {
            $r = D::decide('under_offer', $to, $this->t0, null, $this->t1);
            $this->assertNull($r['under_offer_at'], $to);
            $this->assertNull($r['sold_at'], $to);
        }
        $this->assertNull(D::decide('sold', 'active', $this->t0, $this->t1, $this->t1)['sold_at']);
    }

    public function test_status_text_is_compared_by_its_key_not_its_case(): void
    {
        $this->assertSame('under_offer', D::key(' Under Offer '));
        $r = D::decide('Active', 'Under-Offer', null, null, $this->t1);
        $this->assertSame($this->t1, $r['under_offer_at']);
        $r = D::decide('Sold', 'sold', null, $this->t0, $this->t1);
        $this->assertSame($this->t0, $r['sold_at'], 'no change when only the case differs');
    }
}
