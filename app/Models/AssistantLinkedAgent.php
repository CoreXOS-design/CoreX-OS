<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-267 multi-agent addendum — a Sub-Agent link on an assistant's assignment.
 *
 * A Sub-Agent is NOT the Main Agent (`AssistantAssignment::agent_user_id`, unchanged, still
 * singular, still the permission ceiling). This row only widens WHOSE RECORDS the assistant may
 * see and edit — via User::dataIdentityIds() and BranchScope — and grants no permission at all.
 * There is deliberately no matrix row for a Sub-Agent.
 *
 * Admin/super_admin only manage this relationship — never the Main Agent, never the Sub-Agent
 * themselves (Johan's ruling, 2026-07-28, M2).
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §2.1-§2.3
 */
class AssistantLinkedAgent extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'assistant_assignment_id',
        'agent_user_id',
        'added_by_user_id',
        'removed_by_user_id',
        'removed_at',
    ];

    protected $casts = [
        'removed_at' => 'datetime',
    ];

    /**
     * `active_agent_user_id` is a STORED generated column that backs the
     * restorable one-active-link-per-agent unique key. MySQL-maintained — never write to it.
     */
    protected $guarded = ['active_agent_user_id'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssistantAssignment::class, 'assistant_assignment_id');
    }

    /** The Sub-Agent. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    /**
     * Candidate Sub-Agents for an assignment — guardrails L1/L3-L6 (spec §5). Deliberately
     * agency-WIDE, not branch-filtered (M5, §4): BranchScope is extended instead of restricting
     * the candidate pool. Shared by the admin controller (validation) and the "add" dropdown.
     *
     * @param int|null $includeAgentId when restoring a previously-removed link, that candidate's
     *                                 own id must not be excluded by the "already linked" check.
     */
    public static function eligibleCandidates(AssistantAssignment $assignment, ?int $includeAgentId = null): Collection
    {
        $ownerRoles = Role::query()->where('is_owner', true)->pluck('name')->all();

        $alreadyLinked = static::where('assistant_assignment_id', $assignment->id)
            ->when($includeAgentId, fn ($q) => $q->where('agent_user_id', '!=', $includeAgentId))
            ->pluck('agent_user_id')
            ->all();

        return User::agencyMembers()
            ->where('is_active', true)
            ->where('is_assistant', false)
            ->whereKeyNot($assignment->agent_user_id)
            ->when($ownerRoles, fn ($q) => $q->whereNotIn('role', $ownerRoles))
            ->when($alreadyLinked, fn ($q) => $q->whereNotIn('id', $alreadyLinked))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'branch_id']);
    }
}
