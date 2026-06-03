<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityDefinitionCalendarClass;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\DailyActivityEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Module 6 (M6.3) — sole writer of auto_calendar daily-activity rows.
 *
 * Responsibilities:
 *   - credit(): on observed CalendarEvent::created, resolve the agency
 *     mapping (M6.2), run the anti-gaming gates (back-date, daily cap,
 *     dup-within-week), and insert a provisional row keyed by event id.
 *   - revoke(): on CalendarEvent::updated to cancelled/dismissed or on
 *     soft-delete, flip the row from provisional → revoked. The row is
 *     NEVER hard-deleted; revoke preserves the audit chain.
 *
 * Anti-gaming policy is "fail closed": if any gate decides the row would
 * be a fake credit, we log + return — we do NOT issue partial points.
 *
 * Concurrency: relies on the M6.3 unique constraint
 * (activity_definition_id, user_id, activity_date, calendar_event_id) +
 * the application-level idempotency check below. A second observer firing
 * for the same event would short-circuit on the idempotency check; even
 * if a race squeezed through, the unique constraint refuses the insert.
 *
 * Duplicate-detection caveat: this check runs at credit-time using
 * whatever contact info is on the event NOW. CalendarEventLink rows are
 * usually added in a separate statement AFTER event create, so a freshly-
 * observed event may have an empty linkedContacts() collection. The check
 * is best-effort at this stage; M6.4's feedback-capture path will re-run
 * duplicate detection at confirm-time, when links are guaranteed populated.
 */
final class ProvisionalPointService
{
    /**
     * Credit a provisional point row for the given calendar event, subject
     * to the agency mapping + anti-gaming gates. Idempotent per event.
     */
    public function credit(CalendarEvent $event): void
    {
        $mapping = ActivityDefinitionCalendarClass::resolveForEvent($event);
        if ($mapping === null || ! $mapping->is_active) {
            // resolveForEvent() already applies active(), but we're defensive
            // — if a future caller bypasses the scope, we still fail closed.
            return;
        }

        // Gate 1: back-dated beyond the agency's allowed window.
        // The window is anchored at created_at: an event saved today for
        // last week is back-dated; an event saved last week for last week
        // is fine. fall-back to 0 hours if mapping omits a limit.
        $backLimit = (int) ($mapping->back_date_limit_hours ?? 0);
        if ($event->event_date->lt($event->created_at->copy()->subHours($backLimit))) {
            Log::info('M6.3 credit skipped: back_dated_beyond_limit', [
                'event_id'              => $event->id,
                'event_class'           => $event->category,
                'event_date'            => $event->event_date->toIso8601String(),
                'created_at'            => $event->created_at->toIso8601String(),
                'back_date_limit_hours' => $backLimit,
                'user_id'               => $event->user_id,
            ]);
            return;
        }

        // Gate 2: agency daily cap on this (user, definition).
        if ($mapping->daily_cap !== null) {
            $todayCount = DB::table('daily_activity_entries')
                ->where('user_id', $event->user_id)
                ->where('activity_date', $event->event_date->toDateString())
                ->where('activity_definition_id', $mapping->activity_definition_id)
                ->count();

            if ($todayCount >= (int) $mapping->daily_cap) {
                Log::info('M6.3 credit skipped: daily_cap_reached', [
                    'event_id'               => $event->id,
                    'event_class'            => $event->category,
                    'activity_definition_id' => $mapping->activity_definition_id,
                    'user_id'                => $event->user_id,
                    'activity_date'          => $event->event_date->toDateString(),
                    'daily_cap'              => (int) $mapping->daily_cap,
                    'current_count'          => $todayCount,
                ]);
                return;
            }
        }

        // Gate 3: duplicate (same buyer + property + same ISO week).
        // We require a property_id for context — without one, "viewing
        // the same property twice with the same buyer" is undefined, so
        // we skip the check rather than block a legitimate orphan event.
        if ($event->property_id) {
            $contactIds = collect([$event->contact_id])
                ->filter()
                ->merge($event->linkedContacts()->pluck('contacts.id'))
                ->unique()
                ->values();

            if ($contactIds->isNotEmpty()) {
                $weekStart = $event->event_date->copy()->startOfWeek();
                $weekEnd   = $event->event_date->copy()->endOfWeek();

                $contactIdsArr = $contactIds->all();
                $contactClass  = Contact::class;

                $dupExists = CalendarEvent::query()
                    ->where('id', '!=', $event->id)
                    ->where('agency_id', $event->agency_id)
                    ->where('property_id', $event->property_id)
                    ->whereNotIn('status', ['cancelled', 'dismissed'])
                    ->whereBetween('event_date', [$weekStart, $weekEnd])
                    ->where(function ($q) use ($contactIdsArr, $contactClass) {
                        $q->whereIn('contact_id', $contactIdsArr)
                            ->orWhereExists(function ($sub) use ($contactIdsArr, $contactClass) {
                                $sub->select(DB::raw(1))
                                    ->from('calendar_event_links')
                                    ->whereColumn('calendar_event_links.calendar_event_id', 'calendar_events.id')
                                    ->whereNull('calendar_event_links.deleted_at')
                                    ->where('calendar_event_links.linkable_type', $contactClass)
                                    ->whereIn('calendar_event_links.linkable_id', $contactIdsArr);
                            });
                    })
                    ->exists();

                if ($dupExists) {
                    Log::info('M6.3 credit skipped: duplicate_buyer_property_week', [
                        'event_id'    => $event->id,
                        'event_class' => $event->category,
                        'property_id' => $event->property_id,
                        'contact_ids' => $contactIdsArr,
                        'week_start'  => $weekStart->toDateString(),
                        'week_end'    => $weekEnd->toDateString(),
                        'user_id'     => $event->user_id,
                    ]);
                    return;
                }
            }
        }

        // Idempotency: never double-credit for the same event regardless
        // of point_state. If a row already exists by calendar_event_id,
        // bail out silently — the observer may fire more than once across
        // updates, and re-credit is not the right answer here.
        if (DailyActivityEntry::where('calendar_event_id', $event->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($event, $mapping) {
            DailyActivityEntry::create([
                'activity_date'          => $event->event_date->toDateString(),
                'period'                 => $event->event_date->format('Y-m'),
                'user_id'                => $event->user_id,
                'agency_id'              => $event->agency_id,
                'branch_id'              => $event->branch_id,
                'activity_definition_id' => $mapping->activity_definition_id,
                'value'                  => (int) $mapping->value_per_event,
                'point_state'            => DailyActivityEntry::STATE_PROVISIONAL,
                'source'                 => DailyActivityEntry::SOURCE_AUTO_CALENDAR,
                'calendar_event_id'      => $event->id,
                'created_by'             => $event->created_by_id ?? $event->user_id,
            ]);
        });
    }

    /**
     * Revoke a provisional row for the given event. Soft-delete-safe: the
     * row stays — only point_state + revoke metadata change. If no
     * provisional row exists (already confirmed, already revoked, never
     * credited), this is a silent no-op.
     */
    public function revoke(CalendarEvent $event, string $reason): void
    {
        $entry = DailyActivityEntry::where('calendar_event_id', $event->id)
            ->where('point_state', DailyActivityEntry::STATE_PROVISIONAL)
            ->first();

        if ($entry === null) {
            return;
        }

        DB::transaction(function () use ($entry, $reason) {
            $entry->point_state   = DailyActivityEntry::STATE_REVOKED;
            $entry->revoked_at    = now();
            $entry->revoke_reason = $reason;
            $entry->save();
        });
    }
}
