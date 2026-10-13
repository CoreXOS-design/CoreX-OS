<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-454 — an RTS-level marker on a tracked property ("never suggest"). Spec §9. */
class RtsPropertyFlag extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'rts_property_flags';

    public const NEVER_SUGGEST = 'never_suggest';

    protected $fillable = [
        'agency_id', 'track', 'tracked_property_id', 'flag', 'set_by_user_id', 'outcome_id', 'reason',
    ];
}
