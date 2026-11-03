<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-454 phase 5 - one thing a manager did about an agent's funnel (road-to-sold.md section 9.3, 9.4). APPEND-ONLY: this model refuses to
 * update or delete a row. Visibility follows the agent (own / branch / agency) exactly like the sign-off ledger.
 */
class RtsManagerAction extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent;

    public const TRAINING_ASSIGNED    = 'training_assigned';
    public const COACHING_NOTE        = 'coaching_note';
    public const FLAG_ACKNOWLEDGED    = 'flag_acknowledged';
    public const TARGET_SET           = 'target_set';
    public const TARGET_CLEARED       = 'target_cleared';
    public const SUGGESTION_IGNORED   = 'suggestion_ignored';

    protected $table = 'rts_manager_actions';

    protected $fillable = ['agency_id', 'branch_id', 'track', 'user_id', 'by_user_id', 'kind', 'subject_key', 'note', 'value_from', 'value_to', 'command_task_id', 'acted_at'];

    protected $casts = ['acted_at' => 'datetime', 'value_from' => 'integer', 'value_to' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('The manager action history is append-only - an action is a new row.'));
        static::deleting(fn () => throw new \LogicException('The manager action history is append-only - nothing is deleted.'));
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

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id')->withTrashed();
    }
}
