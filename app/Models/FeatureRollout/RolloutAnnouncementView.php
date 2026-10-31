<?php

namespace App\Models\FeatureRollout;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user has seen the release pop-up for one wave of their agency's gradual rollout (spec §5.4).
 */
class RolloutAnnouncementView extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const KIND_RELEASE = 'release';

    protected $fillable = ['agency_id', 'wave_id', 'user_id', 'kind', 'seen_at'];

    protected $casts = [
        'seen_at' => 'datetime',
    ];
}
