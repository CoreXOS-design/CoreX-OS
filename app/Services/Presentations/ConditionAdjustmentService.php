<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\PropertySettingItem;
use Illuminate\Support\Facades\Log;

/**
 * Build 3 — Centralise the "which condition applies to this version?"
 * decision so the answer is identical for the review screen, the live
 * recalc endpoint, and the PDF compile path.
 *
 * Priority (per Build 3 prompt §B (4)):
 *   1. presentation_versions.condition_level_id   (agent's override on review)
 *   2. properties.condition_level_id              (property's recorded condition)
 *   3. null                                       (no adjustment — baseline only)
 *
 * Snapshot rule:
 *   When a version is published, the agent's choice + the agency's
 *   adjustment_pct at that moment are frozen on the version row
 *   (condition_adjustment_pct + condition_label). Subsequent agency
 *   settings drift never moves the historic PDF — that's the whole
 *   reason we snapshot. Live recompute reads agency settings; published
 *   PDF reads the version snapshot.
 *
 * Adjustment shape (Build 8 — scaled-band):
 *   The condition factor (1 + pct/100) is applied to ALL THREE band
 *   values — lower, middle, upper — via bcmath, so the ordered
 *   invariant lower < middle < upper holds for any factor. Pre-fix
 *   only middle was adjusted; at +20% on the Uvongo PDF this produced
 *   Middle R864k > Upper R747,500 (the bookend percentiles were
 *   un-scaled), which read as a broken recommended band and let two
 *   different "CMA middle" numbers escape into the same report.
 *
 *       lower_adjusted  = round(lower  * (1 + pct / 100))
 *       middle_adjusted = round(middle * (1 + pct / 100))
 *       upper_adjusted  = round(upper  * (1 + pct / 100))
 *
 *   Negative pct shrinks, positive grows. Range gate at the validator
 *   (-100 exclusive < x <= 200). Legacy applyToMiddle() is preserved
 *   for any external callers that still scale a lone middle; the band
 *   API to use going forward is applyToBand().
 */
final class ConditionAdjustmentService
{
    /**
     * Resolve the condition level for live computation (review screen,
     * recalc endpoint). Reads agency settings, NOT the version snapshot —
     * the version snapshot is only the source of truth AFTER publish.
     *
     * @return array{level: ?PropertySettingItem, source: string}
     */
    public function resolveLive(PresentationVersion $version, ?Presentation $presentation = null): array
    {
        $presentation ??= $version->presentation;

        // 1. Version override (agent picked on review).
        // withoutGlobalScopes() bypasses BelongsToAgency (this resolver
        // is the trust boundary — we explicitly check agency_id below)
        // but we still skip soft-deleted rows so an agency-deleted
        // condition gracefully falls through to the next source.
        if ($version->condition_level_id) {
            $level = PropertySettingItem::withoutGlobalScopes()
                ->where('id', $version->condition_level_id)
                ->where('agency_id', $version->agency_id)
                ->whereNull('deleted_at')
                ->first();
            if ($level) return ['level' => $level, 'source' => 'version_override'];
        }

        // 2. Property's recorded condition.
        $propertyConditionId = $presentation?->property?->condition_level_id;
        if ($propertyConditionId) {
            $level = PropertySettingItem::withoutGlobalScopes()
                ->where('id', $propertyConditionId)
                ->where('agency_id', $version->agency_id)
                ->whereNull('deleted_at')
                ->first();
            if ($level) return ['level' => $level, 'source' => 'property_default'];
        }

        // 3. Nothing — baseline only.
        Log::info('[PRES-INFO] no condition resolved — baseline evaluation applied', [
            'presentation_version_id' => $version->id,
            'presentation_id'         => $presentation?->id,
        ]);
        return ['level' => null, 'source' => 'none'];
    }

    /**
     * Legacy — apply a condition pct to a single Middle value. Retained
     * for any external caller still scaling a lone middle. New code
     * should use applyToBand() so lower/middle/upper stay ordered.
     *
     * @param  int|null  $middle      The Middle band value to adjust.
     * @param  float|null $pct        Adjustment % (e.g. 12.0 for +12%, -15.0 for -15%).
     * @return array{
     *   adjusted: ?int,
     *   baseline: ?int,
     *   pct: ?float,
     *   was_applied: bool,
     * }
     */
    public function applyToMiddle(?int $middle, ?float $pct): array
    {
        if ($middle === null) {
            return ['adjusted' => null, 'baseline' => null, 'pct' => $pct, 'was_applied' => false];
        }
        if ($pct === null || abs($pct) < 0.005) {
            return ['adjusted' => $middle, 'baseline' => $middle, 'pct' => $pct ?? 0.0, 'was_applied' => false];
        }
        $adjusted = (int) round($middle * (1 + ($pct / 100)));
        return ['adjusted' => $adjusted, 'baseline' => $middle, 'pct' => $pct, 'was_applied' => true];
    }

    /**
     * Apply a condition pct uniformly across the band — lower, middle,
     * and upper all multiplied by the same (1 + pct/100) factor via
     * bcmath. Preserves the ordered invariant by construction: any
     * positive factor keeps lower < middle < upper if it held on
     * input. Null inputs pass through unchanged.
     *
     * Use this in the render-side compile path. CmaComputeService
     * already condition-adjusts the median inside method_median.raw →
     * method_median.condition_adjusted; this method covers the
     * remaining p25/p75 (lower/upper) tiles that flow out of
     * pool_stats un-scaled.
     *
     * @param  int|null   $lower
     * @param  int|null   $middle
     * @param  int|null   $upper
     * @param  float|null $pct
     * @return array{
     *   lower_adjusted: ?int,
     *   middle_adjusted: ?int,
     *   upper_adjusted: ?int,
     *   pct: ?float,
     *   was_applied: bool,
     * }
     */
    public function applyToBand(?int $lower, ?int $middle, ?int $upper, ?float $pct): array
    {
        $base = [
            'lower_adjusted'  => $lower,
            'middle_adjusted' => $middle,
            'upper_adjusted'  => $upper,
            'pct'             => $pct,
            'was_applied'     => false,
        ];
        if ($pct === null || abs($pct) < 0.005) {
            return $base;
        }
        return [
            'lower_adjusted'  => self::scaleBc($lower,  $pct),
            'middle_adjusted' => self::scaleBc($middle, $pct),
            'upper_adjusted'  => self::scaleBc($upper,  $pct),
            'pct'             => $pct,
            'was_applied'     => $lower !== null || $middle !== null || $upper !== null,
        ];
    }

    /**
     * bcmath scale helper — round(value * (1 + pct/100)). Mirrors
     * CmaComputeService::applyConditionBc so the same factor lands on
     * the median (inside the compute pipeline) and on p25/p75 (inside
     * the render-side compile). Null in → null out.
     */
    private static function scaleBc(?int $value, float $pct): ?int
    {
        if ($value === null) return null;
        $pctStr  = number_format($pct, 6, '.', '');
        $factor  = bcadd('1', bcdiv($pctStr, '100', 8), 8);
        $product = bcmul((string) $value, $factor, 4);
        // Half-up integer round, sign-aware.
        $half    = bccomp($product, '0', 8) >= 0 ? '0.5' : '-0.5';
        $rounded = bcadd(bcadd($product, $half, 8), '0', 0);
        return (int) $rounded;
    }

    /**
     * Snapshot the resolved condition onto the version row. Called at
     * publish time so the version is frozen against future agency
     * settings drift.
     *
     * Idempotent: snapshotting an already-snapshotted version with the
     * same source = no-op.
     */
    public function snapshotOnVersion(PresentationVersion $version, ?PropertySettingItem $level): void
    {
        if ($level === null) {
            $version->forceFill([
                'condition_level_id'        => null,
                'condition_adjustment_pct'  => null,
                'condition_label'           => null,
            ])->save();
            return;
        }

        $version->forceFill([
            'condition_level_id'        => $level->id,
            'condition_adjustment_pct'  => (float) $level->adjustment_pct,
            'condition_label'           => $level->name,
        ])->save();
    }
}
