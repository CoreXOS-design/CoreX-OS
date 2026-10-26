<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-454 phase 4 - one line of the sign-off ledger (road-to-sold.md sections 5.4, 8.1). APPEND-ONLY: a request, an approval and a
 * return are each a new row; this model refuses to update or delete one (same rule as rts_settings_audit).
 */
class RtsLevelSignoff extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent;

    public const REQUESTED = 'requested';
    public const APPROVED  = 'approved';
    public const RETURNED  = 'returned';

    protected $table = 'rts_level_signoffs';

    protected $fillable = [
        'agency_id', 'branch_id', 'track', 'user_id', 'from_level_id', 'to_level_id', 'decided_by_user_id', 'decision', 'automatic',
        'comment', 'evidence', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime', 'automatic' => 'boolean', 'evidence' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('The sign-off history is append-only - a decision is a new row.'));
        static::deleting(fn () => throw new \LogicException('The sign-off history is append-only - nothing is deleted.'));
    }

    protected function branchParent(): array
    {
        return [User::class, 'user_id'];
    }

    public function scopeVisibleTo($query, User $viewer, ?string $requestedScope = null)
    {
        return RtsAgentLevel::narrowToViewer($query, $viewer, 'user_id', $requestedScope);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id')->withTrashed();
    }

    public function fromLevel(): BelongsTo
    {
        return $this->belongsTo(RtsLevel::class, 'from_level_id')->withTrashed();
    }

    public function toLevel(): BelongsTo
    {
        return $this->belongsTo(RtsLevel::class, 'to_level_id')->withTrashed();
    }
}
