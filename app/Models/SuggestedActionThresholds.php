<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-agency numeric thresholds that drive the Build E suggested-action chip
 * rules engine. Exactly one row per agency (unique on agency_id).
 *
 * Spec: .ai/specs/build-e-suggested-action-chips-spec.md §8.1, §7.2.
 *
 * Resolution path:
 *   ProspectingConfigurationService::getSuggestedActionThresholds()
 *     → cached singleton (per request, per agency)
 *       → SuggestedActionThresholds::getOrCreateForAgency()
 *         → existing row OR fresh row with defaults
 *
 * Defaults below are the source-of-truth — kept in sync with the migration
 * defaults and the SuggestedActionThresholdsSeeder so a brand-new agency
 * gets the same values whether the row is materialised by seed, by an
 * admin saving settings, or by the lazy getOrCreate path.
 */
final class SuggestedActionThresholds extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'suggested_action_thresholds';

    protected $fillable = [
        'agency_id',
        'stale_listing_days',
        'expiry_warning_hours',
        'outcome_overdue_days',
        'outcome_stale_days',
        'follow_up_days',
        'pitch_recency_days',
        'high_value_strong_min',
        'stock_repitch_days',
        'colleague_claim_stale_days',
        'investigate_mid_min',
        'new_listing_lookback_days',
    ];

    protected $casts = [
        'stale_listing_days'         => 'integer',
        'expiry_warning_hours'       => 'integer',
        'outcome_overdue_days'       => 'integer',
        'outcome_stale_days'         => 'integer',
        'follow_up_days'             => 'integer',
        'pitch_recency_days'         => 'integer',
        'high_value_strong_min'      => 'integer',
        'stock_repitch_days'         => 'integer',
        'colleague_claim_stale_days' => 'integer',
        'investigate_mid_min'        => 'integer',
        'new_listing_lookback_days'  => 'integer',
    ];

    /**
     * Spec §7.2 defaults. Single source of truth — getOrCreateForAgency()
     * and the seeder both apply these.
     *
     * new_listing_lookback_days: MIC Work-tab `action_preset=new_today`
     * filter window. 1 day default ≈ "today" — listings first seen in
     * the last 24 hours. Agency can widen via settings.
     */
    public static function defaultsFor(int $agencyId): array
    {
        return [
            'agency_id'                  => $agencyId,
            'stale_listing_days'         => 14,
            'expiry_warning_hours'       => 6,
            'outcome_overdue_days'       => 2,
            'outcome_stale_days'         => 30,
            'follow_up_days'             => 7,
            'pitch_recency_days'         => 7,
            'high_value_strong_min'      => 3,
            'stock_repitch_days'         => 30,
            'colleague_claim_stale_days' => 21,
            'investigate_mid_min'        => 5,
            'new_listing_lookback_days'  => 1,
        ];
    }

    /**
     * Idempotent: returns the existing row for the agency or creates one
     * pre-seeded with defaults. The unique index on agency_id guarantees
     * at most one row per agency even under race conditions.
     *
     * Bypasses the AgencyScope so it works in queued-job / system contexts
     * where Auth::user() may be null.
     */
    public static function getOrCreateForAgency(int $agencyId): self
    {
        /** @var self|null $existing */
        $existing = static::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::query()
            ->withoutGlobalScopes()
            ->create(static::defaultsFor($agencyId));
    }
}
