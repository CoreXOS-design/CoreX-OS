<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use App\Models\SuggestedActionThresholds;
use App\Models\User;

/**
 * Pure function over already-loaded per-listing state. Returns the single
 * highest-priority SuggestedAction for the row, or null when no rule applies.
 *
 * Spec: .ai/specs/build-e-suggested-action-chips-spec.md §7 + §8.3.
 *
 * ZERO DB queries inside resolve(). The caller is responsible for assembling
 * the $state slice from ProspectingListingStateEnricher output and the
 * $tiers slice from BuyerMatchTierService output before invoking.
 *
 * Rules R1 → R9 are evaluated top-down; the first match wins and returns
 * immediately. Manager-only rules (R1, R8) are skipped when !$isManager.
 * Owner-only rules (R2, R3, R4) require the viewer to match the claim
 * owner / pitch sender. Tooltips are server-rendered with real values.
 */
final class SuggestedActionResolver
{
    public function resolve(
        array $state,
        array $tiers,
        ProspectingListing $listing,
        SuggestedActionThresholds $thresholds,
        ?User $viewer,
        bool $isManager,
    ): ?SuggestedAction {
        $claim = $state['claim'] ?? null;
        $pitch = $state['pitch'] ?? null;
        $viewerId = $viewer?->id !== null ? (int) $viewer->id : null;

        // R1 — manager-only, stale listing-status claim
        if ($isManager && $claim
            && ($claim['status'] ?? null) === 'listing'
            && ($claim['flagged_at'] ?? null) === null
            && $this->daysSince($claim['last_updated_at'] ?? null) >= $thresholds->stale_listing_days
        ) {
            return $this->buildR1($claim, $thresholds);
        }

        // R2 — claim owner, claim expiring within N hours, no feedback yet
        if ($claim && $viewerId !== null
            && (int) ($claim['user_id'] ?? 0) === $viewerId
            && (bool) ($claim['is_active'] ?? true) === true
            && ($claim['feedback_at'] ?? null) === null
            && $claim['hours_left'] !== null
            && $claim['hours_left'] < $thresholds->expiry_warning_hours
        ) {
            return $this->buildR2($claim, $listing);
        }

        // R3 — pitch sender, pitch is in the "outcome-owed" window
        if ($pitch && $viewerId !== null
            && (int) ($pitch['agent_user_id'] ?? 0) === $viewerId
            && in_array($pitch['outcome'] ?? null, [null, 'sent'], true)
        ) {
            $daysSincePitch = $this->daysSince($pitch['sent_at'] ?? null);
            if ($daysSincePitch >= $thresholds->outcome_overdue_days
                && $daysSincePitch <= $thresholds->outcome_stale_days
            ) {
                return $this->buildR3($pitch, $daysSincePitch);
            }
        }

        // R4 — claim owner, claim in contacted/meeting_set stale beyond N days
        if ($claim && $viewerId !== null
            && (int) ($claim['user_id'] ?? 0) === $viewerId
            && in_array($claim['status'] ?? null, ['contacted', 'meeting_set'], true)
            && $this->daysSince($claim['last_updated_at'] ?? null) >= $thresholds->follow_up_days
        ) {
            return $this->buildR4($claim, $listing, $thresholds);
        }

        // R5 — high-value pitch opportunity (not yet in stock)
        $hasRecentPitch = $pitch
            && $this->daysSince($pitch['sent_at'] ?? null) < $thresholds->pitch_recency_days;
        $noActiveClaim = $claim === null;
        $listingActive = (bool) $listing->is_active;
        $notInStock    = $listing->matched_property_id === null;
        $strongCount   = (int) ($tiers['strong'] ?? 0);
        $topScore      = $tiers['top_score'] ?? null;

        if ($listingActive && $notInStock && $noActiveClaim && !$hasRecentPitch
            && $strongCount >= $thresholds->high_value_strong_min
        ) {
            return $this->buildR5($listing, $strongCount, $topScore, $thresholds);
        }

        // R6 — pitch opportunity (any strong matches, not yet high-value)
        if ($listingActive && $notInStock && $noActiveClaim && !$hasRecentPitch
            && $strongCount >= 1
        ) {
            return $this->buildR6($listing, $strongCount);
        }

        // R7 — re-pitch a stock listing where new strong matches arrived
        if ($listing->matched_property_id !== null
            && (! $pitch || $this->daysSince($pitch['sent_at'] ?? null) >= $thresholds->stock_repitch_days)
            && $strongCount >= 1
            && ($claim === null || ($viewerId !== null && (int) ($claim['user_id'] ?? 0) === $viewerId))
        ) {
            return $this->buildR7($listing, $strongCount);
        }

        // R8 — manager-only, colleague's claim stale
        if ($isManager && $claim
            && $viewerId !== null
            && (int) ($claim['user_id'] ?? 0) !== $viewerId
            && (bool) ($claim['is_active'] ?? true) === true
            && $this->daysSince($claim['last_updated_at'] ?? null) >= $thresholds->colleague_claim_stale_days
        ) {
            return $this->buildR8($claim, $thresholds);
        }

        // R9 — investigate: mid-tier buyers but no strong matches
        $midCount = (int) ($tiers['mid'] ?? 0);
        if ($listingActive && $noActiveClaim && ! $hasRecentPitch
            && $strongCount === 0
            && $midCount >= $thresholds->investigate_mid_min
        ) {
            return $this->buildR9($listing, $midCount);
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rule builders. Each composes a tooltip from real values and returns a
    // ready-to-render SuggestedAction. No DB queries.
    // ──────────────────────────────────────────────────────────────────────

    private function buildR1(array $claim, SuggestedActionThresholds $thresholds): SuggestedAction
    {
        $days = $this->daysSince($claim['last_updated_at'] ?? null);
        $tooltip = $this->tooltip(
            'Claim in <em>listing</em> status for '
            . e((string) $days) . ' days with no movement. Flag branch manager.'
        );

        return new SuggestedAction(
            rank:        'R1',
            label:       'FLAG TO BM',
            tier:        'critical',
            icon:        'alarm-clock',
            tooltipHtml: $tooltip,
            clickType:   'modal',
            modalKey:    'flag-bm:' . (int) ($claim['claim_id'] ?? 0),
        );
    }

    private function buildR2(array $claim, ProspectingListing $listing): SuggestedAction
    {
        $hours = (float) ($claim['hours_left'] ?? 0);
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);
        $human = ($h > 0 ? $h . 'h ' : '') . $m . 'min';

        $tooltip = $this->tooltip(
            'Your claim auto-releases in ' . e($human) . ' without feedback.'
        );

        return new SuggestedAction(
            rank:        'R2',
            label:       'CLAIM EXPIRES SOON',
            tier:        'critical',
            icon:        'alarm-clock',
            tooltipHtml: $tooltip,
            clickType:   'alpine',
            alpineCall:  "\$dispatch('open-feedback', { id: {$listing->id}, status: '" . e((string) ($claim['status'] ?? '')) . "' })",
        );
    }

    private function buildR3(array $pitch, int $daysSincePitch): SuggestedAction
    {
        $tooltip = $this->tooltip(
            'You pitched ' . e((string) $daysSincePitch) . ' days ago — log the response.'
        );

        $href = route('seller-outreach.composer.timeline', [
            'contact' => (int) ($pitch['contact_id'] ?? 0),
        ]) . '?send_id=' . (int) ($pitch['send_id'] ?? 0) . '&focus=outcome';

        return new SuggestedAction(
            rank:        'R3',
            label:       'LOG OUTCOME',
            tier:        'await',
            icon:        'clock',
            tooltipHtml: $tooltip,
            clickType:   'anchor',
            href:        $href,
        );
    }

    private function buildR4(array $claim, ProspectingListing $listing, SuggestedActionThresholds $thresholds): SuggestedAction
    {
        $days = $this->daysSince($claim['last_updated_at'] ?? null);
        $statusLabel = str_replace('_', ' ', (string) ($claim['status'] ?? 'contacted'));

        $tooltip = $this->tooltip(
            'Your claim in <em>' . e($statusLabel) . '</em> for '
            . e((string) $days) . ' days. Time to follow up.'
        );

        return new SuggestedAction(
            rank:        'R4',
            label:       'FOLLOW UP CLAIM',
            tier:        'action',
            icon:        'target',
            tooltipHtml: $tooltip,
            clickType:   'alpine',
            alpineCall:  "\$dispatch('open-feedback', { id: {$listing->id}, status: '" . e((string) ($claim['status'] ?? '')) . "' })",
        );
    }

    private function buildR5(ProspectingListing $listing, int $strongCount, ?int $topScore, SuggestedActionThresholds $thresholds): SuggestedAction
    {
        $topPart = $topScore !== null ? ' (top ' . e((string) $topScore) . '%)' : '';
        $tooltip = $this->tooltip(
            e((string) $strongCount) . ' strong-tier buyers'
            . $topPart . '. High-conversion opportunity.'
        );

        return new SuggestedAction(
            rank:        'R5',
            label:       'PITCH NOW · HIGH',
            tier:        'action',
            icon:        'target',
            tooltipHtml: $tooltip,
            clickType:   'anchor',
            href:        route('seller-outreach.entry.from-prospecting', [
                'prospectingListingId' => $listing->id,
            ]),
        );
    }

    private function buildR6(ProspectingListing $listing, int $strongCount): SuggestedAction
    {
        $tooltip = $this->tooltip(
            e((string) $strongCount) . ' strong-tier buyer'
            . ($strongCount === 1 ? '' : 's') . '. Worth a pitch.'
        );

        return new SuggestedAction(
            rank:        'R6',
            label:       'PITCH NOW',
            tier:        'action',
            icon:        'target',
            tooltipHtml: $tooltip,
            clickType:   'anchor',
            href:        route('seller-outreach.entry.from-prospecting', [
                'prospectingListingId' => $listing->id,
            ]),
        );
    }

    private function buildR7(ProspectingListing $listing, int $strongCount): SuggestedAction
    {
        $tooltip = $this->tooltip(
            'Already in agency stock. '
            . e((string) $strongCount) . ' new strong-tier buyer'
            . ($strongCount === 1 ? '' : 's') . ' since last outreach.'
        );

        return new SuggestedAction(
            rank:        'R7',
            label:       'RE-PITCH STOCK',
            tier:        'action',
            icon:        'target',
            tooltipHtml: $tooltip,
            clickType:   'anchor',
            href:        route('seller-outreach.entry.from-property', [
                'property' => (int) $listing->matched_property_id,
            ]),
        );
    }

    private function buildR8(array $claim, SuggestedActionThresholds $thresholds): SuggestedAction
    {
        $days = $this->daysSince($claim['last_updated_at'] ?? null);
        $name = (string) ($claim['claimer_name'] ?? 'a colleague');

        $tooltip = $this->tooltip(
            e($name) . ' held this claim ' . e((string) $days)
            . ' days without update. Consider releasing.'
        );

        return new SuggestedAction(
            rank:        'R8',
            label:       'RESOLVE COLLEAGUE CLAIM',
            tier:        'info',
            icon:        'info',
            tooltipHtml: $tooltip,
            clickType:   'modal',
            modalKey:    'release-as-manager:' . (int) ($claim['claim_id'] ?? 0),
        );
    }

    private function buildR9(ProspectingListing $listing, int $midCount): SuggestedAction
    {
        $tooltip = $this->tooltip(
            'No strong matches but ' . e((string) $midCount) . ' mid-tier buyers. Worth a look.'
        );

        return new SuggestedAction(
            rank:        'R9',
            label:       'INVESTIGATE',
            tier:        'info',
            icon:        'info',
            tooltipHtml: $tooltip,
            clickType:   'alpine',
            alpineCall:  "openBuyerPanel({$listing->id})",
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function tooltip(string $sentenceHtml): string
    {
        return '<strong>Why this action?</strong><br>' . $sentenceHtml;
    }

    /**
     * Days (integer, floor) between $when and now. Returns PHP_INT_MAX when
     * $when is null so a missing timestamp never accidentally satisfies a
     * "≥ N days" condition.
     */
    private function daysSince(?string $when): int
    {
        if ($when === null || $when === '') {
            return PHP_INT_MAX;
        }
        $ts = strtotime($when);
        if ($ts === false) {
            return PHP_INT_MAX;
        }
        $secs = time() - $ts;
        return $secs > 0 ? (int) floor($secs / 86400) : 0;
    }
}
