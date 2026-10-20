<?php

namespace App\Models\FeatureRollout;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person and one feature their agency's gradual rollout switched on: when they first opened it,
 * when they were reminded about its tour, when they asked for it early (spec §12.5, §12.6, §12.8).
 */
class RolloutFeatureUser extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'user_id', 'feature_key', 'first_opened_at', 'tour_nudged_at', 'early_requested_at'];

    protected $casts = [
        'first_opened_at'    => 'datetime',
        'tour_nudged_at'     => 'datetime',
        'early_requested_at' => 'datetime',
    ];
}
