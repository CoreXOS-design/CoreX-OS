<?php

namespace App\Models\CommandCenter;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class CommandTask extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'task_type', 'status', 'priority', 'send_reminder',
        'assigned_to', 'assigned_by',
        'due_date', 'started_at', 'completed_at',
        'property_id', 'contact_id', 'deal_id',
        'source_type', 'source_id', 'calendar_event_id',
        'checklist', 'notes', 'metadata',
        'branch_id', 'agency_id',
    ];

    protected $casts = [
        'due_date'     => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'checklist'      => 'array',
        'metadata'       => 'array',
        'send_reminder'  => 'boolean',
    ];

    public const STATUS_TODO        = 'todo';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING    = 'awaiting';
    public const STATUS_DONE        = 'done';
    public const STATUS_DISMISSED   = 'dismissed';

    public const PRIORITY_ORDER = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    // ── Relationships ──

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CommandTaskNote::class)->orderByDesc('created_at');
    }

    // ── Scopes ──

    public function scopeForUser($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Role-driven visibility scope for the Task board / Today page.
     * Honours the per-role Data Scope set in Role Manager
     * (command_center.tasks.view → own | branch | all | none).
     * Agency isolation is already applied by BelongsToAgency, so this
     * only narrows within the current agency.
     *
     *   own    → tasks assigned to this user
     *   branch → tasks in the user's branch (falls back to own if no branch)
     *   all    → no extra narrowing (whole agency)
     *   none   → nothing (no access)
     */
    public function scopeVisibleTo($query, User $user, ?string $scope)
    {
        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->where('branch_id', $user->effectiveBranchId())
                : $query->where('assigned_to', $user->id),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->where('assigned_to', $user->id), // 'own' or null
        };
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_AWAITING]);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
                     ->where('due_date', '<', now())
                     ->whereNotIn('status', [self::STATUS_DONE, self::STATUS_DISMISSED]);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', today())
                     ->whereNotIn('status', [self::STATUS_DONE, self::STATUS_DISMISSED]);
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('due_date', [now()->startOfWeek(), now()->endOfWeek()])
                     ->whereNotIn('status', [self::STATUS_DONE, self::STATUS_DISMISSED]);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ── Helpers ──

    public function isOverdue(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && !in_array($this->status, [self::STATUS_DONE, self::STATUS_DISMISSED]);
    }

    public function markDone(): void
    {
        $this->update([
            'status'       => self::STATUS_DONE,
            'completed_at' => now(),
        ]);
    }

    public function markInProgress(): void
    {
        $this->update([
            'status'     => self::STATUS_IN_PROGRESS,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    /**
     * Pillar tag for visual grouping on Today / Tasks:
     * 'property' | 'contact' | 'deal' | null
     * Priority: property → deal → contact (property is the physical asset, highest signal).
     */
    public function pillarTag(): ?string
    {
        if ($this->property_id) return 'property';
        if ($this->deal_id)     return 'deal';
        if ($this->contact_id)  return 'contact';
        return null;
    }
}
