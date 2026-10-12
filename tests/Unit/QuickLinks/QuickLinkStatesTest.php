<?php

declare(strict_types=1);

namespace Tests\Unit\QuickLinks;

use App\Support\QuickLinks\MandateState;
use App\Support\QuickLinks\PreapprovalState;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Quick Links W2 (.ai/specs/quick-links.md §5.1/§5.2) — the pre-approval and mandate chips. Pure rules, no DB.
 */
final class QuickLinkStatesTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-12 10:00:00');
    }

    public function test_a_valid_pre_approval_shows_the_amount_and_is_green(): void
    {
        $s = PreapprovalState::resolve('1200000.00', $this->now->copy()->addDays(20), $this->now);
        $this->assertSame('valid', $s['key']);
        $this->assertSame('success', $s['tone']);
        $this->assertSame('Pre-approved R 1.2m', $s['label']);
        $this->assertStringContainsString($this->now->copy()->addDays(20)->format('d M Y'), $s['detail']);
    }

    public function test_a_pre_approval_that_expires_today_is_still_valid(): void
    {
        $this->assertSame('valid', PreapprovalState::resolve(850000, $this->now->copy()->endOfDay(), $this->now)['key']);
        $this->assertSame('Pre-approved R 850k', PreapprovalState::resolve(850000, $this->now->copy()->endOfDay(), $this->now)['label']);
    }

    public function test_an_expired_or_undated_pre_approval_is_amber_and_says_update_it(): void
    {
        $expired = PreapprovalState::resolve(1000000, $this->now->copy()->subDay(), $this->now);
        $this->assertSame('expired', $expired['key']);
        $this->assertSame('warning', $expired['tone']);

        $undated = PreapprovalState::resolve(1000000, null, $this->now);
        $this->assertSame('expired', $undated['key'], 'no expiry date = cannot be called valid (same rule as Contact::hasValidPreapproval)');
    }

    public function test_no_amount_means_no_pre_approval(): void
    {
        foreach ([null, 0, '0.00'] as $amount) {
            $s = PreapprovalState::resolve($amount, $this->now->copy()->addYear(), $this->now);
            $this->assertSame('none', $s['key']);
            $this->assertSame('default', $s['tone']);
        }
    }

    public function test_a_healthy_mandate_shows_type_and_days_left(): void
    {
        $s = MandateState::resolve('Sole', $this->now->copy()->addDays(40), 7, $this->now);
        $this->assertSame('ok', $s['key']);
        $this->assertSame('Sole · 40 days left', $s['label']);
    }

    public function test_the_warning_window_is_the_agencys_own_number_of_days(): void
    {
        $in10 = $this->now->copy()->addDays(10);
        $this->assertSame('ok', MandateState::resolve('Open', $in10, 7, $this->now)['key']);
        $s = MandateState::resolve('Open', $in10, 14, $this->now);
        $this->assertSame('expiring', $s['key']);
        $this->assertSame('warning', $s['tone']);
    }

    public function test_today_tomorrow_and_expired_read_naturally(): void
    {
        $this->assertSame('Sole · expires today', MandateState::resolve('Sole', $this->now->copy()->endOfDay(), 7, $this->now)['label']);
        $this->assertSame('Sole · 1 day left', MandateState::resolve('Sole', $this->now->copy()->addDay(), 7, $this->now)['label']);
        $s = MandateState::resolve('Sole', $this->now->copy()->subDay(), 7, $this->now);
        $this->assertSame('expired', $s['key']);
        $this->assertSame('danger', $s['tone']);
    }

    public function test_the_agencys_own_mandate_wording_is_not_doubled_and_missing_data_shows_nothing(): void
    {
        $s = MandateState::resolve('Sole Mandate', $this->now->copy()->addDays(3), 7, $this->now);
        $this->assertStringContainsString('Sole Mandate ends', $s['detail']);
        $this->assertStringNotContainsString('mandate mandate', strtolower($s['detail']));

        $this->assertNull(MandateState::resolve(null, null, 7, $this->now));
        $this->assertNull(MandateState::resolve('  ', null, 7, $this->now));
        $this->assertSame('open_ended', MandateState::resolve('Sole', null, 7, $this->now)['key']);
    }
}
