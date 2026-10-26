<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\User;
use App\Services\RoadToSold\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 1 - one occurrence of a task (road-to-sold.md section 5.4). user_id is the LIST OWNER the work counts
 * for; recorded_by_user_id is who actually did it (the same person, or the assistant acting for the agent) and
 * recorded_by_kind is snapshotted so a later role change does not rewrite history. points_awarded is a snapshot.
 * Written only through TaskCompletionService. branch_id is the list owner's branch (the earning agent, not the actor).
 */
class RtsTaskCompletion extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    public const STATE_PROVISIONAL = 'provisional';
    public const STATE_CONFIRMED   = 'confirmed';
    public const STATE_REVOKED     = 'revoked';
    public const STATE_OVERRIDDEN  = 'overridden';
    public const STATE_RETURNED    = 'returned';   // a manager sent it back with a comment; it does not count until approved again

    public const SOURCE_AUTO_EVENT = 'auto_event';
    public const SOURCE_CAPTURED   = 'captured';
    public const SOURCE_MANUAL     = 'manual';
    public const SOURCE_MIGRATED   = 'migrated';

    public const KIND_AGENT     = 'agent';
    public const KIND_ASSISTANT = 'assistant';
    public const KIND_SYSTEM    = 'system';

    protected $table = 'rts_task_completions';

    protected $fillable = [
        'agency_id', 'branch_id', 'user_id', 'recorded_by_user_id', 'recorded_by_kind', 'track', 'task_id', 'step_id', 'level_id',
        'occurred_at', 'period_unit', 'period_start', 'activity_date', 'period', 'legacy_source', 'legacy_entry_id', 'quantity', 'points_awarded', 'points_legacy', 'state', 'source',
        'subject_type', 'subject_id', 'event_id', 'idempotency_key', 'evidence_note', 'evidence',
        'confirmed_by_user_id', 'confirmed_at', 'revoked_at', 'revoke_reason', 'override_audit',
        'review_comment', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected $casts = [
        'occurred_at'    => 'datetime',
        'period_start'   => 'datetime',
        'activity_date'  => 'date',
        'confirmed_at'   => 'datetime',
        'revoked_at'     => 'datetime',
        'reviewed_at'    => 'datetime',
        'quantity'       => 'integer',
        'points_awarded' => 'decimal:2',
        'points_legacy'  => 'decimal:2',
        'evidence'       => 'array',
        'override_audit' => 'array',
    ];

    protected function branchParent(): array
    {
        return [User::class, 'user_id'];
    }

    /** Own / branch / agency breadth in the query (Road to Sold's `road_to_sold.view` scope), never in the view. */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        return Scope::apply($query, $user, $requestedScope);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(RtsTask::class, 'task_id')->withTrashed();
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(RtsStep::class, 'step_id')->withTrashed();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id')->withTrashed();
    }

    public function records(): HasMany
    {
        return $this->hasMany(RtsTaskCompletionRecord::class, 'completion_id');
    }
}
