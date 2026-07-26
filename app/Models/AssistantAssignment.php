<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-267 — the Assistant → Assigned Agent relationship.
 *
 * An Assistant is not a role and not a user type: it is THIS row. The assignment
 * carries the status and the permission matrix; the resolver
 * (AssistantPermissionResolver, Prompt C) intersects that matrix against the
 * Assigned Agent's LIVE permissions on every check, so the assistant can never
 * do more than the agent can, and loses a permission the moment the agent does.
 *
 * Terminology: `agent_user_id` is the ASSIGNED AGENT. Do not call it a sponsor —
 * `users.sponsored_by_user_id` is the commission mentor and is unrelated.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §6.3, §6.6
 */
class AssistantAssignment extends Model
{
    use BelongsToAgency, BelongsToBranch, SoftDeletes;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED   = 'revoked';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'assistant_user_id',
        'agent_user_id',
        'status',
        // AT-267 V2 — behaviour settings the agent controls on the assistant control page.
        'can_manage_my_records',
        'show_attribution',
        'notify_on_action',
        'can_download_documents',
        'suspend_reason',
        'snapshot_taken_at',
        'created_by_user_id',
        'revoked_by_user_id',
        'revoked_at',
        'revoke_reason',
    ];

    protected $casts = [
        'snapshot_taken_at'     => 'datetime',
        'revoked_at'            => 'datetime',
        'can_manage_my_records'  => 'boolean',
        'show_attribution'       => 'boolean',
        'notify_on_action'       => 'boolean',
        'can_download_documents' => 'boolean',
    ];

    /**
     * In-memory defaults for the V2 behaviour settings so a freshly-created assignment reads the
     * same values the DB default would give, without a refresh() — the DB columns default the same.
     */
    protected $attributes = [
        'can_manage_my_records'  => true,
        'show_attribution'       => true,
        'notify_on_action'       => false,
        'can_download_documents' => true,
    ];

    /**
     * `active_assistant_user_id` is a STORED generated column that backs the
     * one-active-agent-per-assistant unique key. It is maintained by MySQL —
     * never write to it.
     */
    protected $guarded = ['active_assistant_user_id'];

    protected static function booted(): void
    {
        // No orphans: soft-deleting an assignment soft-deletes its matrix rows, so a
        // restore brings the matrix back exactly as it was. This is what makes the
        // soft-deleted assignment usable AS the reassignment archive — no second table.
        static::deleted(function (self $assignment) {
            if ($assignment->isForceDeleting()) {
                return;
            }
            $assignment->permissions()->delete();
        });

        static::restored(function (self $assignment) {
            $assignment->permissions()->withTrashed()->restore();
        });
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    /** The Assigned Agent — the permission ceiling. */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(AssistantAssignmentPermission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Does the matrix grant this permission key?
     *
     * Fails CLOSED — an absent row is a denial, never a default. A matrix that has
     * not been seeded yet grants nothing. This is only HALF the check: the resolver
     * must still intersect the result against the Assigned Agent's live permissions.
     */
    public function grants(string $permissionKey): bool
    {
        $row = $this->permissions->firstWhere('permission_key', $permissionKey);

        if (!$row || $row->is_locked) {
            return false;
        }

        return (bool) $row->granted;
    }

    /** The matrix scope for a `.view` key, or null when not granted. */
    public function scopeFor(string $permissionKey): ?string
    {
        $row = $this->permissions->firstWhere('permission_key', $permissionKey);

        if (!$row || $row->is_locked || !$row->granted) {
            return null;
        }

        return $row->scope;
    }
}
