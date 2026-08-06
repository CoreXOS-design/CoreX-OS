<?php

namespace Tests\Unit\Performance;

use App\Services\Performance\PeriodResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * AT-366-A — the period selector maps presets + custom ranges to concrete windows.
 */
class PeriodResolverTest extends TestCase
{
    private PeriodResolver $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new PeriodResolver();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-15 13:20:00');
    }

    public function test_this_month_spans_the_whole_month(): void
    {
        $p = $this->r->resolve('this_month', null, null, $this->now());
        $this->assertSame('2026-08-01 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-08-31 23:59:59', $p->end->toDateTimeString());
    }

    public function test_today(): void
    {
        $p = $this->r->resolve('today', null, null, $this->now());
        $this->assertSame('2026-08-15 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-08-15 23:59:59', $p->end->toDateTimeString());
    }

    public function test_last_7_days_is_a_seven_day_window(): void
    {
        $p = $this->r->resolve('last_7_days', null, null, $this->now());
        $this->assertSame('2026-08-09 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-08-15 23:59:59', $p->end->toDateTimeString());
    }

    public function test_this_year(): void
    {
        $p = $this->r->resolve('this_year', null, null, $this->now());
        $this->assertSame('2026-01-01 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-12-31 23:59:59', $p->end->toDateTimeString());
    }

    public function test_custom_valid_range(): void
    {
        $p = $this->r->resolve('custom', '2026-01-05', '2026-02-10', $this->now());
        $this->assertSame('2026-01-05 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-02-10 23:59:59', $p->end->toDateTimeString());
        $this->assertSame('custom', $p->preset);
    }

    public function test_custom_requires_both_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->r->resolve('custom', '2026-01-05', null, $this->now());
    }

    public function test_custom_end_before_start_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->r->resolve('custom', '2026-02-10', '2026-01-05', $this->now());
    }

    public function test_unknown_preset_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->r->resolve('nonsense', null, null, $this->now());
    }

    public function test_previous_window_ends_just_before_this_one(): void
    {
        $prev = $this->r->resolve('this_month', null, null, $this->now())->previous();
        $this->assertSame('2026-07-31 23:59:59', $prev->end->toDateTimeString());
        $this->assertTrue($prev->start->lessThan($prev->end));
    }
}
