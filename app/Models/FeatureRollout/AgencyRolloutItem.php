<?php

namespace App\Models\FeatureRollout;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One feature an agency's rollout plan is holding back, and what has happened to it.
 */
class AgencyRolloutItem extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATE_HELD           = 'held';
    public const STATE_RELEASED       = 'released';
    public const STATE_RELEASED_EARLY = 'released_early';
    public const STATE_AWAITING_ADMIN = 'awaiting_admin';
    public const STATE_DROPPED        = 'dropped';
    /** AT-478 §12.1 — its wave went live but the agency has not set up the basics yet (FeatureReadiness). */
    public const STATE_AWAITING_SETUP = 'awaiting_setup';

    protected $fillable = ['agency_id', 'rollout_id', 'wave_id', 'feature_key', 'state', 'released_at'];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function wave(): BelongsTo
    {
        return $this->belongsTo(AgencyRolloutWave::class, 'wave_id');
    }
}
