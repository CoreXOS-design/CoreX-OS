<?php

namespace App\Models\CommandCenter;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'created_by_id', 'event_type', 'category', 'title', 'description',
        'event_date', 'end_date', 'all_day', 'priority', 'send_reminder', 'status', 'colour',
        'source_type', 'source_id',
        'property_id', 'contact_id', 'branch_id', 'agency_id',
        'reminder_offsets', 'reminders_sent',
        'is_recurring', 'recurrence_rule', 'parent_event_id',
        'metadata',
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

    // ── Scopes ──

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
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
        return $query->whereBetween('event_date', [$start, $end]);
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
