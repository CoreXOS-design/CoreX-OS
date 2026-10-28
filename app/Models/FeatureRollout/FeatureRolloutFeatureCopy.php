<?php

namespace App\Models\FeatureRollout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A dev's edit of the wording the rollout pop-ups show for one feature (Dev Settings → Feature rollout →
 * Feature wording & videos). Platform-wide, owner-only, no agency. No row = the defaults.
 * Spec: agency-feature-rollout.md §19.4.
 */
class FeatureRolloutFeatureCopy extends Model
{
    use SoftDeletes;

    /** At most this many extra links per feature. */
    public const MAX_LINKS = 6;

    protected $table = 'feature_rollout_feature_copy';

    protected $fillable = ['feature_key', 'summary', 'description', 'video_url', 'links', 'updated_by'];

    protected $casts = [
        'links' => 'array',
    ];
}
