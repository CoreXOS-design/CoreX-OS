<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-216 Pipeline V1.1 — a comment on a pipeline step (agent notes specific to that step).
 * Agency-scoped, soft-deleting (no-hard-delete doctrine).
 */
class DealStepComment extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /** Branch follows the parent step instance (→ its deal); spec §7a. */
    protected function branchParent(): array
    {
        return [DealStepInstance::class, 'deal_step_instance_id'];
    }

    protected $fillable = [
        'agency_id',
        'deal_step_instance_id',
        'user_id',
        'body',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'deal_step_instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
