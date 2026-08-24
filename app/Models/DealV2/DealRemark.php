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
 * AT-158 WS-V6 — a free-form remark on a DR2 deal (the DR1 addRemark analogue).
 * Soft-deletable (no hard deletes); interleaved with the immutable
 * deal_activity_log in the deal view to form one chronological timeline.
 */
class DealRemark extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /** A child's branch is its parent deal's (spec §7a) — never the acting user's. */
    protected function branchParent(): array
    {
        return [DealV2::class, 'deal_id'];
    }

    protected $table = 'deal_v2_remarks';

    protected $fillable = ['agency_id', 'deal_id', 'user_id', 'body'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
