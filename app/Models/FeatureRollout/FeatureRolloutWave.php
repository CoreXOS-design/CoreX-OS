<?php

namespace App\Models\FeatureRollout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A wave in the PLATFORM default rollout order (Dev Settings → Feature rollout).
 * Owner-only, no agency: an agency that opts in gets its own copy (AgencyRolloutWave),
 * so editing these never moves a running plan. Spec: agency-feature-rollout.md §3.4, §5.1.
 */
class FeatureRolloutWave extends Model
{
    use SoftDeletes;

    public const TRACKS = [
        'general' => 'General',
        'sales'   => 'Sales',
        'rentals' => 'Rentals',
        // "Admin decides" (spec §18): switches on after every other group, for the agency's admins only.
        'admin'   => 'Admin decides (at the end)',
    ];

    protected $fillable = ['plan_id', 'track', 'position', 'title', 'intro_text', 'feature_keys'];

    protected $casts = [
        'feature_keys' => 'array',
        'position'     => 'integer',
    ];

    protected static function booted(): void
    {
        // A group always belongs to a plan; one created without saying which goes in the default plan.
        static::creating(function (self $wave) {
            $wave->plan_id ??= FeatureRolloutPlan::defaultId();
        });
    }

    public function scopeInPlan($query, int $planId)
    {
        return $query->where($query->getModel()->getTable() . '.plan_id', $planId);
    }
}
