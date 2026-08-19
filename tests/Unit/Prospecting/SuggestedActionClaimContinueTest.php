<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Models\ProspectingListing;
use App\Models\SuggestedActionThresholds;
use App\Models\User;
use App\Services\Prospecting\SuggestedActionResolver;
use Tests\TestCase;

/**
 * 2026-08-14 (Johan) — MIC worklist "my claims" dead-end fix.
 *
 * R2 ("claim expiring soon") and R4 ("claim stale, follow up") used to
 * render the warning text ITSELF as the only click target — a $dispatch()
 * nothing on the MIC page ever listened for, so clicking either did
 * nothing. Locks in the fix: both rules now surface a real "Continue" CTA
 * (clickType=anchor, routed through the same seller-outreach entry point
 * PITCH NOW uses — opening it renews the claim as a side effect of
 * EntryPointController::fromProspecting() -> claimOnPitchNow()), while the
 * original warning text survives as a small, separate statusBadge.
 *
 * No DB — the resolver is pure; models are instantiated unsaved, matching
 * the existing pattern in SuggestedActionExistenceTest.
 */
final class SuggestedActionClaimContinueTest extends TestCase
{
    private function resolver(): SuggestedActionResolver
    {
        return app(SuggestedActionResolver::class);
    }

    private function thresholds(): SuggestedActionThresholds
    {
        return new SuggestedActionThresholds([
            'stale_listing_days' => 30, 'expiry_warning_hours' => 24,
            'outcome_overdue_days' => 3, 'outcome_stale_days' => 14,
            'follow_up_days' => 7, 'pitch_recency_days' => 30,
            'high_value_strong_min' => 2, 'stock_repitch_days' => 30,
            'colleague_claim_stale_days' => 14, 'investigate_mid_min' => 1,
            'new_listing_lookback_days' => 1,
        ]);
    }

    private function listing(): ProspectingListing
    {
        $l = new ProspectingListing(['is_active' => true]);
        $l->id = 4242;
        return $l;
    }

    private function viewer(int $id): User
    {
        $u = new User();
        $u->id = $id;
        return $u;
    }

    public function test_r2_claim_expiring_soon_offers_a_real_continue_cta_and_keeps_the_warning_badge(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => [
                    'user_id'        => 501,
                    'is_active'      => true,
                    'feedback_at'    => null,
                    'hours_left'     => 3.5,
                    'status'         => 'claimed',
                    'last_updated_at' => now()->subHours(20)->toDateTimeString(),
                ],
                'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(),
            thresholds: $this->thresholds(),
            viewer: $this->viewer(501),
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertSame('R2', $action->rank);

        // The old bug: the click target WAS the warning, and it did nothing.
        $this->assertSame('Continue', $action->label, 'the clickable chip must be a real CTA, not the warning text');
        $this->assertSame('anchor', $action->clickType, 'must be a real link, not a dead alpine dispatch');
        $this->assertNotNull($action->href);
        $this->assertStringContainsString('/prospecting/4242/outreach/compose', $action->href, 'routes through the same pitch-entry endpoint PITCH NOW uses');

        // The warning must survive — just no longer as the only clickable thing.
        $this->assertSame('CLAIM EXPIRES SOON', $action->statusBadgeLabel);
        $this->assertSame('critical', $action->statusBadgeTier);
    }

    public function test_r4_stale_claim_offers_a_real_continue_cta_and_keeps_the_follow_up_badge(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => [
                    'user_id'         => 501,
                    'status'          => 'contacted',
                    'last_updated_at' => now()->subDays(10)->toDateTimeString(),
                    // R2 (checked first) requires a non-null hours_left to
                    // fire — null here means "not on the 48h expiry track",
                    // matching how ProspectingListingStateEnricher builds a
                    // long-stale claim, so R4 is reached instead.
                    'hours_left'      => null,
                    'feedback_at'     => null,
                    'is_active'       => true,
                ],
                'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(),
            thresholds: $this->thresholds(),
            viewer: $this->viewer(501),
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertSame('R4', $action->rank);
        $this->assertSame('Continue', $action->label);
        $this->assertSame('anchor', $action->clickType);
        $this->assertStringContainsString('/prospecting/4242/outreach/compose', $action->href);

        $this->assertSame('FOLLOW UP CLAIM', $action->statusBadgeLabel);
        $this->assertSame('await', $action->statusBadgeTier);
    }
}
