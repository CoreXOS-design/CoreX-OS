<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Support\Compliance\FicaQuickState;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Quick Links W1 (.ai/specs/quick-links.md §4.1) — every state the contact's FICA chip can be in. Pure rules, no DB.
 */
final class FicaQuickStateTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-12 10:00:00');
    }

    private function sub(string $status, array $over = []): object
    {
        return (object) array_merge([
            'id' => 7, 'status' => $status,
            'created_at' => $this->now->copy()->subDays(3), 'updated_at' => $this->now->copy()->subDays(3),
            'verified_at' => null, 'token_expires_at' => $this->now->copy()->addDays(11),
        ], $over);
    }

    private function state(array $subs, ?Carbon $legacy = null, int $months = 11): array
    {
        return FicaQuickState::resolve(collect($subs), $legacy, $months, $this->now);
    }

    public function test_nothing_on_file_offers_to_send(): void
    {
        $s = $this->state([]);
        $this->assertSame('none', $s['key']);
        $this->assertSame('danger', $s['tone']);
        $this->assertSame('send', $s['action']);
        $this->assertSame('FICA — not done', $s['label']);
    }

    public function test_a_request_waiting_on_the_client_offers_resend_not_a_second_send(): void
    {
        $s = $this->state([$this->sub('draft')]);
        $this->assertSame('awaiting', $s['key']);
        $this->assertSame('resend', $s['action']);
        $this->assertSame(7, $s['submission_id']);
    }

    public function test_an_expired_link_says_so_and_offers_resend(): void
    {
        $s = $this->state([$this->sub('draft', ['token_expires_at' => $this->now->copy()->subDay()])]);
        $this->assertSame('awaiting_expired', $s['key']);
        $this->assertSame('warning', $s['tone']);
        $this->assertSame('resend', $s['action']);
    }

    public function test_a_submitted_form_is_under_review_with_no_action(): void
    {
        foreach (['submitted', 'under_review', 'agent_approved', 'referred_to_co'] as $status) {
            $s = $this->state([$this->sub($status)]);
            $this->assertSame('in_review', $s['key'], $status);
            $this->assertNull($s['action'], $status);
        }
    }

    public function test_corrections_requested_offers_resend(): void
    {
        $s = $this->state([$this->sub('corrections_requested')]);
        $this->assertSame('corrections', $s['key']);
        $this->assertSame('resend', $s['action']);
    }

    public function test_a_fresh_approval_is_green_with_the_date_and_no_action(): void
    {
        $s = $this->state([$this->sub('approved', ['verified_at' => $this->now->copy()->subMonths(2)])]);
        $this->assertSame('approved', $s['key']);
        $this->assertSame('success', $s['tone']);
        $this->assertNull($s['action']);
        $this->assertStringContainsString($this->now->copy()->subMonths(2)->format('d M Y'), $s['label']);
    }

    public function test_the_expiring_point_is_the_agencys_own_setting(): void
    {
        $approved = $this->sub('approved', ['verified_at' => $this->now->copy()->subMonths(5)]);

        $this->assertSame('approved', $this->state([$approved], null, 11)['key'], 'default 11 months: 5 months old is fine');
        $s = $this->state([$approved], null, 4);
        $this->assertSame('expiring', $s['key'], 'an agency that says 4 months sees it expiring');
        $this->assertSame('renew', $s['action']);
    }

    public function test_a_renewal_in_progress_outranks_the_old_approval_but_an_old_request_never_hides_a_newer_approval(): void
    {
        $oldApproved = $this->sub('approved', ['id' => 1, 'created_at' => $this->now->copy()->subMonths(12), 'verified_at' => $this->now->copy()->subMonths(12)]);
        $renewal     = $this->sub('draft', ['id' => 2, 'created_at' => $this->now->copy()->subDay()]);
        $this->assertSame('awaiting', $this->state([$renewal, $oldApproved])['key']);

        $newApproved = $this->sub('approved', ['id' => 3, 'created_at' => $this->now->copy()->subDay(), 'verified_at' => $this->now->copy()->subDay()]);
        $staleDraft  = $this->sub('draft', ['id' => 2, 'created_at' => $this->now->copy()->subMonths(2)]);
        $this->assertSame('approved', $this->state([$newApproved, $staleDraft])['key']);
    }

    public function test_the_old_esign_fica_still_counts_and_ages_with_the_same_setting(): void
    {
        $this->assertSame('legacy', $this->state([], $this->now->copy()->subMonths(3))['key']);
        $s = $this->state([], $this->now->copy()->subMonths(12));
        $this->assertSame('legacy_expiring', $s['key']);
        $this->assertSame('renew', $s['action']);
    }

    public function test_a_rejected_fica_offers_a_new_form_and_a_cancelled_one_is_ignored(): void
    {
        $this->assertSame('rejected', $this->state([$this->sub('rejected')])['key']);
        $this->assertSame('send', $this->state([$this->sub('rejected')])['action']);
        $this->assertSame('none', $this->state([$this->sub('cancelled')])['key']);
    }
}
