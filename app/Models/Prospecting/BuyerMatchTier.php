<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-agency score thresholds that classify prospecting_buyer_matches.score
 * into a 3-tier badge (strong / mid / weak) on the prospecting tab.
 *
 * Exactly one row per agency (enforced by unique index on agency_id).
 * Falls back to defaultsFor() when no row exists yet for an agency.
 */
final class BuyerMatchTier extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'strong_min_score',
        'mid_min_score',
        'weak_min_score',
        'strong_label',
        'mid_label',
        'weak_label',
        'show_weak_in_badge',
    ];

    protected $casts = [
        'strong_min_score'   => 'integer',
        'mid_min_score'      => 'integer',
        'weak_min_score'     => 'integer',
        'show_weak_in_badge' => 'boolean',
    ];

    /**
     * Defaults returned when no agency row exists yet.
     */
    public static function defaultsFor(int $agencyId): array
    {
        return [
            'agency_id'          => $agencyId,
            'strong_min_score'   => 80,
            'mid_min_score'      => 50,
            'weak_min_score'     => 0,
            'strong_label'       => 'Strong',
            'mid_label'          => 'Mid',
            'weak_label'         => 'Weak',
            'show_weak_in_badge' => true,
        ];
    }
}
