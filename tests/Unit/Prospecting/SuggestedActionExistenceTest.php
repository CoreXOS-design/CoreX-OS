<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Models\ProspectingListing;
use App\Models\SuggestedActionThresholds;
use App\Services\Prospecting\SuggestedActionResolver;
use Tests\TestCase;

/**
 * MIC funnel phase 1 — EXISTENCE CHECK (Johan 2026-08-13).
 *
 * When a work-list listing resolves to a property already on HFC's books
 * (company_stock_property_id set), the SuggestedActionResolver must surface an
 * "ALREADY EXISTS · OPEN PROPERTY" chip (naming who is on it, linking to the
 * property) INSTEAD of any Pitch Now offer — so a second agent opens the
 * existing record rather than working it again.
 *
 * No DB — the resolver is pure; models are instantiated unsaved. TestCase boots
 * the app so route() resolves.
 */
final class SuggestedActionExistenceTest extends TestCase
{
    private function resolver(): SuggestedActionResolver
    {
        return app(SuggestedActionResolver::class);
    }

    private function thresholds(): SuggestedActionThresholds
    {
        // Spec defaults — values are irrelevant to the existence short-circuit
        // (it runs before the threshold-driven pitch rules), but must be present.
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

    public function test_existing_property_surfaces_open_property_not_pitch(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => null, 'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => 987,
                'company_stock_agent_name'  => 'Jane Agent',
            ],
            tiers: ['strong' => 5, 'mid' => 0, 'weak' => 0, 'total' => 5, 'top_score' => 90],
            listing: $this->listing(),
            thresholds: $this->thresholds(),
            viewer: null,
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertSame('EXISTS', $action->rank);
        $this->assertStringContainsStringIgnoringCase('already exists', $action->label);
        $this->assertStringContainsString('/properties/987', $action->href, 'links to the existing property');
        $this->assertStringContainsString('Jane Agent', $action->tooltipHtml, 'names who is already on it');
        // Even with strong buyer signal (which would otherwise fire a PITCH NOW · HIGH chip),
        // the existence short-circuit wins — MIC must not offer existing stock as a fresh pitch.
        $this->assertStringNotContainsStringIgnoringCase('pitch now', $action->label);
    }

    public function test_no_matched_property_still_offers_pitch(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => null, 'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => null,   // NOT an existing property
                'company_stock_agent_name'  => null,
            ],
            tiers: ['strong' => 5, 'mid' => 0, 'weak' => 0, 'total' => 5, 'top_score' => 90],
            listing: $this->listing(),
            thresholds: $this->thresholds(),
            viewer: null,
            isManager: false,
        );

        $this->assertNotNull($action);
        $this->assertNotSame('EXISTS', $action->rank, 'a non-existing listing is still pitchable');
    }

    public function test_existing_property_without_agent_still_shows_open_property(): void
    {
        $action = $this->resolver()->resolve(
            state: [
                'claim' => null, 'pitch' => null, 'contacts' => 0, 'temp_lock' => null,
                'company_stock_property_id' => 555,
                'company_stock_agent_name'  => null,   // no agent assigned
            ],
            tiers: ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null],
            listing: $this->listing(),
            thresholds: $this->thresholds(),
            viewer: null,
            isManager: false,
        );

        $this->assertSame('EXISTS', $action->rank);
        $this->assertStringContainsString('/properties/555', $action->href);
    }
}
