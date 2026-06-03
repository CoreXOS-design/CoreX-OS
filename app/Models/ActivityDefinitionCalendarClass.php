<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 6 (M6.2) — agency-scoped mapping from a calendar event_class
 * slug to an activity_definition. M6.3's ProvisionalPointService reads
 * this table when a calendar event lands to decide what (if anything)
 * to credit, and at what point value, under what anti-gaming guardrails.
 */
final class ActivityDefinitionCalendarClass extends Model
{
    use BelongsToAgency, SoftDeletes;

    /**
     * SPINE-1 trigger kinds. Calendar rows fire from CalendarEventObserver
     * (M6.3) via event_class lookup; instant rows fire from
     * InstantPointService (SPINE-1) via slug lookup. Same table, same
     * model — the trigger_kind column discriminates.
     */
    public const KIND_CALENDAR = 'calendar';
    public const KIND_INSTANT  = 'instant';

    protected $table = 'activity_definition_calendar_classes';

    protected $fillable = [
        'agency_id',
        'event_class',
        'activity_definition_id',
        'trigger_kind',     // SPINE-1
        'slug',             // SPINE-1
        'subject_type',     // SPINE-1
        'value_per_event',
        'requires_feedback',
        'auto_revoke_after_hours',
        'daily_cap',
        'back_date_limit_hours',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value_per_event'         => 'integer',
        'requires_feedback'       => 'boolean',
        'auto_revoke_after_hours' => 'integer',
        'daily_cap'               => 'integer',
        'back_date_limit_hours'   => 'integer',
        'is_active'               => 'boolean',
    ];

    public function activityDefinition(): BelongsTo
    {
        return $this->belongsTo(ActivityDefinition::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForAgency(Builder $q, int $agencyId): Builder
    {
        return $q->where('agency_id', $agencyId);
    }

    public function scopeForEventClass(Builder $q, string $slug): Builder
    {
        return $q->where('event_class', $slug);
    }

    /**
     * SPINE-1 — kind discriminator scopes.
     */
    public function scopeCalendar(Builder $q): Builder
    {
        return $q->where('trigger_kind', self::KIND_CALENDAR);
    }

    public function scopeInstant(Builder $q): Builder
    {
        return $q->where('trigger_kind', self::KIND_INSTANT);
    }

    /**
     * Returns the active mapping for a given calendar event's class +
     * agency, or null. M6.3 calls this once per event. Multiple mappings
     * per (agency, event_class) would each return one row when iterated;
     * the unique constraint allows at most one per (agency, event_class,
     * activity_definition) — and here we resolve to the FIRST active
     * match.
     *
     * SPINE-1 defensive: explicitly gates on trigger_kind='calendar' so
     * that even if a future instant row sneaks in with the same
     * event_class slug, it can never be picked up by the calendar engine.
     */
    public static function resolveForEvent(CalendarEvent $event): ?self
    {
        if (empty($event->agency_id) || empty($event->category)) {
            return null;
        }
        return static::query()
            ->calendar()
            ->forAgency((int) $event->agency_id)
            ->forEventClass((string) $event->category)
            ->active()
            ->first();
    }

    /**
     * SPINE-1 — resolve the active mapping for an instant action slug,
     * coalescing the agency-specific override with the system default
     * (agency_id IS NULL). Same coalesce pattern M6.2's admin picker and
     * CalendarEventFeedbackObserver use: agency override wins, falls
     * back to system default. Returns null when neither exists or both
     * are inactive — the InstantPointService treats null as "skip credit".
     */
    public static function resolveForInstant(int $agencyId, string $slug): ?self
    {
        return static::query()
            ->instant()
            ->where('slug', $slug)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->active()
            ->orderByRaw('agency_id IS NULL') // agency-specific (0) first, system default (1) second
            ->first();
    }
}
