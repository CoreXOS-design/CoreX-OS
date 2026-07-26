<?php

namespace App\Models\DealV2;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\Concerns\StampsOnBehalfOf;
class DealActivityLog extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, StampsOnBehalfOf;

    /** A child's branch is its parent deal's (spec §7a) — never the acting user's. */
    protected function branchParent(): array
    {
        return [DealV2::class, 'deal_id'];
    }

    public $timestamps = false;

    protected $table = 'deal_activity_log';

    protected $fillable = [
        'agency_id',
        'deal_id',
        'dr1_deal_id', // AT-216: DR1-anchored pipeline audit (coexists with deal_id → deals_v2)
        'deal_step_instance_id',
        'user_id',
        'action',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    /** AT-216: the DR1 deal a DR1-anchored pipeline activity row belongs to. */
    public function dr1Deal(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Deal::class, 'dr1_deal_id');
    }

    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'deal_step_instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
