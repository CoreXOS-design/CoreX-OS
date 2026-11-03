<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Services\MarketIntelligence\Pockets\PriceMoves;
use Carbon\Carbon;
use Tests\TestCase;

/** AT-519 5.10: a price that flips up and back within 7 days is capture noise; only net cuts count. */
final class PriceMovesTest extends TestCase
{
    private function h(int $old, ?int $new, string $at): object
    {
        return (object) ['old_price' => $old, 'new_price' => $new, 'changed_at' => $at];
    }

    public function test_522_bidstone_road_is_one_real_cut_not_two(): void
    {
        $now = Carbon::parse('2026-10-31');
        $r = PriceMoves::net([
            $this->h(990_000, 1_050_000, '2026-08-06 09:00:00'),
            $this->h(1_050_000, 990_000, '2026-08-06 17:00:00'),
            $this->h(1_050_000, 990_000, '2026-08-10 08:00:00'),
        ], $now);

        $this->assertSame(1, $r['cuts']);
        $this->assertSame(60_000, $r['amount']);
        $this->assertSame(1_050_000, $r['first_price'], 'the price before the one real cut');
        $this->assertCount(1, $r['rows']);
    }

    public function test_a_cut_reversed_within_a_week_is_noise_but_after_ten_days_both_count(): void
    {
        $now = Carbon::parse('2026-10-31');
        $quick = PriceMoves::net([$this->h(1_000_000, 950_000, '2026-10-01'), $this->h(950_000, 1_000_000, '2026-10-05')], $now);
        $this->assertSame(0, $quick['cuts']);
        $this->assertNull($quick['first_price']);
        $this->assertSame([], $quick['rows']);

        $slow = PriceMoves::net([$this->h(1_000_000, 950_000, '2026-10-01'), $this->h(950_000, 1_000_000, '2026-10-11')], $now);
        $this->assertSame(1, $slow['cuts'], 'a rise back after ten days is a real second move');
        $this->assertCount(2, $slow['rows']);
        $this->assertSame(1_000_000, $slow['first_price']);
    }

    public function test_a_partial_rise_is_not_a_reversal_and_glitch_rows_are_ignored(): void
    {
        $now = Carbon::parse('2026-10-31');
        $r = PriceMoves::net([
            $this->h(1_000_000, 900_000, '2026-10-01'),
            $this->h(900_000, 950_000, '2026-10-02'),     // a rise, but not back to 1.0m
            $this->h(1_499_000, null, '2026-10-03'),      // glitch: NULL price
            $this->h(0, 4_500_000, '2026-10-04'),          // glitch: 0 old price
        ], $now);

        $this->assertSame(1, $r['cuts']);
        $this->assertSame(100_000, $r['amount']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(1, $r['cuts_30d']);
    }
}
