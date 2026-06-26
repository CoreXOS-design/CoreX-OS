<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Contact;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'user_id', 'created_by_id', 'event_type', 'category', 'title', 'description',
        'event_date', 'end_date', 'all_day', 'priority', 'send_reminder', 'status', 'colour',
        'source_type', 'source_id',
        'property_id', 'contact_id', 'branch_id', 'agency_id',
        'reminder_offsets', 'reminders_sent',
        'is_recurring', 'recurrence_rule', 'parent_event_id',
        'metadata',
        'created_by_ai', 'ai_source', 'ai_transcript',
    ];

    protected $casts = [
        'event_date'       => 'datetime',
        'end_date'         => 'datetime',
        'all_day'          => 'boolean',
        'send_reminder'    => 'boolean',
        'is_recurring'     => 'boolean',
        'reminder_offsets' => 'array',
        'reminders_sent'   => 'array',
        'metadata'         => 'array',
        'created_by_ai'    => 'boolean',
    ];

    // ── Colour map by event type ──
    public const TYPE_COLOURS = [
        'deal'        => '#3b82f6', // blue
        'lease'       => '#10b981', // green
        'compliance'  => '#f59e0b', // amber
        'document'    => '#8b5cf6', // purple
        'prospecting' => '#06b6d4', // cyan
        'portal'      => '#ec4899', // pink
        'property'    => '#f97316', // orange
        'manual'      => '#6b7280', // grey
    ];

    public const PRIORITY_ORDER = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id');
    }

    /**
     * Pillar tag for visual grouping: 'property' | 'contact' | 'deal' | null.
     * Derived from FKs first, then event_type for deal/compliance signal.
     */
    public function pillarTag(): ?string
    {
        if ($this->property_id) return 'property';
        if (in_array($this->event_type, ['deal', 'lease'], true)) return 'deal';
        if ($this->contact_id)  return 'contact';
        return null;
    }

    public function remindersLog(): HasMany
    {
        return $this->hasMany(CalendarReminderLog::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CommandTask::class, 'id', 'calendar_event_id');
    }

    // ── Polymorphic links (M2.2) ──

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'calendar_event_id');
    }

    public function linkedProperties(): MorphToMany
    {
        return $this->morphedByMany(Property::class, 'linkable', 'calendar_event_links', 'calendar_event_id')
            ->wherePivot('role', CalendarEventLink::ROLE_SUBJECT_PROPERTY);
    }

    public function linkedContacts(): MorphToMany
    {
        // CAL-7 Class 3 — surface EVERY contact link, regardless of pivot.role.
        // Previously this whitelisted role IN [attendee, buyer_contact,
        // seller_contact]. On staging's live-copy DB, legacy calendar_event_
        // links rows exist with role=NULL or other historical values, and
        // when CalendarEventClassSetting rows are missing (no seed), newly
        // saved links default to 'attendee' — but the same Class 1 path
        // could land any role here in future. The polymorphic
        // linkable_type=Contact::class predicate is already correct
        // scoping; the role filter was a duplicate of intent that excluded
        // valid contacts.
        return $this->morphedByMany(Contact::class, 'linkable', 'calendar_event_links', 'calendar_event_id');
    }

    public function linkedDeals(): MorphToMany
    {
        return $this->morphedByMany(DealV2::class, 'linkable', 'calendar_event_links', 'calendar_event_id')
            ->wherePivot('role', CalendarEventLink::ROLE_RELATED_DEAL);
    }

    public function getLinkedPropertyAttribute()
    {
        return $this->linkedProperties->first();
    }

    public function getLinkedDealAttribute()
    {
        return $this->linkedDeals->first();
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(CalendarEventAuditEntry::class)->orderBy('performed_at', 'desc');
    }

    // ── Scopes ──

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Role-driven visibility scope for the Calendar / Today page.
     * Honours the per-role Data Scope set in Role Manager
     * (command_center.calendar.view → own | branch | all | none).
     * Agency isolation is already applied by BelongsToAgency, so this
     * only narrows within the current agency.
     *
     *   own    → events owned by this user
     *   branch → events in the user's branch (falls back to own if no branch)
     *   all    → no extra narrowing (whole agency)
     *   none   → nothing (no access)
     */
    public function scopeVisibleTo($query, User $user, ?string $scope)
    {
        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->where('branch_id', $user->effectiveBranchId())
                : $query->where('user_id', $user->id),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->where('user_id', $user->id), // 'own' or null
        };
    }

    public function scopeUpcoming($query)
    {
        return $query->where('event_date', '>=', now())
                     ->where('status', 'pending')
                     ->orderBy('event_date');
    }

    public function scopeOverdue($query)
    {
        return $query->where('event_date', '<', now())
                     ->where('status', 'pending');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('event_date', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('event_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeInDateRange($query, $start, $end)
    {
        // Include events that START in range OR SPAN into range (multi-day)
        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('event_date', [$start, $end])
              ->orWhere(function ($q2) use ($start, $end) {
                  $q2->where('event_date', '<', $start)
                     ->where('end_date', '>=', $start);
              });
        });
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    // ── Helpers ──

    public function getColourAttribute($value): string
    {
        return $value ?? (self::TYPE_COLOURS[$this->event_type] ?? '#6b7280');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->event_date->isPast();
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markDismissed(): void
    {
        $this->update(['status' => 'dismissed']);
    }
}
