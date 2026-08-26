<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Models\ProspectingListing;
use App\Models\SuggestedActionThresholds;
use App\Models\User;
use App\Services\Prospecting\SuggestedActionResolver;
use Tests\TestCase;

/**
 * 2026-08-14 (Johan) — MIC worklist lifecycle: once a listing has been
 * fully worked ("Create & continue" on the compose screen created the
 * property record and linked sellers), the row must show "Pitched" and
 * link straight to the property record — not re-offer "Pitch Now" /
 * "Continue", which would reopen the whole contact/deed-linking compose
 * flow a second time (duplication risk).
 *
 * Contract with cc2 (owns the row-data/service side — confirmed against
 * cc2's commit 0eb23ff2f, MarketIntelligenceController::work()): is_pitched
 * (bool) and property_id (int|null) are dynamic properties attached
 * directly on the SAME ProspectingListing object passed into resolve() as
 * $listing — NOT keys on the $state array. Both optional; this resolver
 * only trusts what the caller hands it — absent/false must fall through to
 * today's behaviour untouched. Every "defensive" test below proves that
 * half of the contract as much as the "happy path" tests prove the display.
 *
 * No DB — the resolver is pure; models are instantiated unsaved, matching
 * the existing pattern in SuggestedActionExistenceTest /
 * SuggestedActionClaimContinueTest.
 */
final class SuggestedActionPitchedTest extends TestCase
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

    private function listing(?bool $isPitched = null, ?int $propertyId = null, bool $withProperties = false): ProspectingListing
    {
        $l = new ProspectingListing(['is_active' => true]);
        $l->id = 1551;
        // Dynamic properties, exactly as MarketIntelligenceController::work()
        // attaches them ($primary->is_pitched = ...; $primary->property_id = ...)
        // — left entirely unset when $withProperties is false, reproducing
        // "cc2's fields not on this object at all" (today's real shape).
        if ($isPitched !== null || $withProperties) {
            $l->is_pitched = $isPitched ?? false;
            $l->property_id = $propertyId;
        }
        return $l;
    }

    private function viewer(int $id): User
    {
        $u = new User();
        $u->id = $id;
        return $u;
    }

    /** The claim shape that, on its own, would normally win as R2 ("Continue") — see SuggestedActionClaimContinueTest. */
    private function activeExpiringClaimState(): array
    {
        return [
            'user_id'         => 501,
            'is_active'       => true,
            'feedback_at'     => null,
            'hours_left'      => 3.5,
            'status'          => 'claimed',
            'last_updated_at' => now()->subHours(20)->toDateTimeString(),
        ];
    }

    public function test_pitched_listing_shows_pitched_status_and_links_to_property(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => null, 'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(isPitched: true, propertyId: 6143),
            thresholds: $this->thresholds(),
            viewer: $this->viewer(501),
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertSame('PITCHED', $action->rank);
        $this->assertSame('Pitched', $action->label);
        $this->assertSame('anchor', $action->clickType);
        $this->assertStringContainsString('/properties/6143', $action->href, 'must link to the property record, not compose');
        $this->assertStringNotContainsString('outreach/compose', $action->href, 'must NOT reopen the compose/contact-linking flow');
    }

    public function test_pitched_overrides_an_active_expiring_claim(): void
    {
        // Same claim shape SuggestedActionClaimContinueTest proves resolves
        // to R2/"Continue" on its own — proving PITCHED takes priority once
        // the listing has actually been worked, so the agent's own
        // claim-expiry reminder no longer sends them back into compose.
        $action = $this->resolver()->resolve(
            state: [
                'claim' => $this->activeExpiringClaimState(),
                'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(isPitched: true, propertyId: 6143),
            thresholds: $this->thresholds(),
            viewer: $this->viewer(501),
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertSame('PITCHED', $action->rank, 'a worked listing must show Pitched even while the underlying claim is still technically expiring');
        $this->assertSame('Pitched', $action->label);
    }

    public function test_is_pitched_absent_falls_through_to_existing_behaviour(): void
    {
        // Defensive contract: cc2's dynamic properties not set on the
        // listing object at all (today's real shape, before their change
        // lands) must behave exactly as it always has — same claim shape
        // resolves to R2.
        $action = $this->resolver()->resolve(
            state: [
                'claim' => $this->activeExpiringClaimState(),
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
        $this->assertSame('Continue', $action->label);
    }

    public function test_is_pitched_false_falls_through_to_existing_behaviour(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => $this->activeExpiringClaimState(),
                'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(isPitched: false, propertyId: null),
            thresholds: $this->thresholds(),
            viewer: $this->viewer(501),
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertSame('R2', $action->rank);
    }

    public function test_is_pitched_true_without_a_property_id_does_not_fire(): void
    {
        // Half-populated data must not produce a chip with a broken/empty
        // property link — falls through to whatever rule would otherwise apply.
        $action = $this->resolver()->resolve(
            state: [
                'claim' => $this->activeExpiringClaimState(),
                'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(isPitched: true, propertyId: null),
            thresholds: $this->thresholds(),
            viewer: $this->viewer(501),
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertNotSame('PITCHED', $action->rank);
        $this->assertSame('R2', $action->rank);
    }
}
