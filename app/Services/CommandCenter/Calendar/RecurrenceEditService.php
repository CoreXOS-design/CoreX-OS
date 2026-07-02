<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Edit / delete a recurring series with an explicit scope:
 *   this   — only the clicked occurrence (an EXCEPTION child, series intact)
 *   future — this occurrence and all after (split: truncate parent + new series)
 *   all    — the whole series (update / soft-delete the parent)
 *
 * Exceptions are modelled with parent_event_id + metadata['recurrence_override_date'].
 * Nothing is ever hard-deleted (cancel = a dismissed tombstone child; delete-all =
 * a soft-deleted parent).
 */
class RecurrenceEditService
{
    /**
     * EDIT "this occurrence only" → create/update an exception child for the date.
     * The child renders at its (possibly edited) time; the parent skips that date.
     */
    public function editOccurrence(CalendarEvent $parent, string $occurrenceDate, array $fields, User $user): CalendarEvent
    {
        $date = Carbon::parse($occurrenceDate)->toDateString();

        return DB::transaction(function () use ($parent, $date, $fields, $user) {
            $child = $this->findException($parent, $date);

            $meta = is_array($parent->metadata ?? null) ? $parent->metadata : [];
            $meta['recurrence_override_date'] = $date;
            unset($meta['recurrence_cancelled']); // a prior cancel is now an edit
            if (in_array($fields['event_nature'] ?? null, ['actionable', 'informational'], true)) {
                $meta['event_nature'] = $fields['event_nature'];
            }

            $attrs = [
                'parent_event_id' => $parent->id,
                'is_recurring'    => false,
                'recurrence_rule' => null,
                'event_type'      => $parent->event_type,
                'category'        => $fields['category'] ?? $parent->category,
                'title'           => $fields['title'] ?? $parent->title,
                'description'     => array_key_exists('description', $fields) ? ($fields['description'] ?: null) : $parent->description,
                'event_date'      => $fields['event_date'] ?? $this->occurrenceStart($parent, $date),
                'end_date'        => array_key_exists('end_date', $fields) ? ($fields['end_date'] ?: null) : $this->occurrenceEnd($parent, $date),
                'all_day'         => array_key_exists('all_day', $fields) ? (bool) $fields['all_day'] : (bool) $parent->all_day,
                'priority'        => $fields['priority'] ?? $parent->priority,
                'status'          => 'pending',
                'source_type'     => 'manual',
                'user_id'         => $parent->user_id,
                'created_by_id'   => $parent->created_by_id ?: $user->id,
                'agency_id'       => $parent->agency_id,
                'branch_id'       => $parent->branch_id,
                'property_id'     => array_key_exists('property_id', $fields) ? $fields['property_id'] : $parent->property_id,
                'contact_id'      => $parent->contact_id,
                'metadata'        => $meta,
            ];

            if ($child) {
                $child->update($attrs);
                return $child->fresh();
            }
            return CalendarEvent::create($attrs);
        });
    }

    /**
     * EDIT "this and future": end the parent the day before this occurrence and
     * start a NEW series here with the edited fields, carrying the remaining end.
     */
    public function editFuture(CalendarEvent $parent, string $occurrenceDate, array $fields, User $user): CalendarEvent
    {
        $splitDay = Carbon::parse($occurrenceDate)->startOfDay();

        return DB::transaction(function () use ($parent, $splitDay, $occurrenceDate, $fields, $user) {
            $rule = RecurrenceRule::parse($parent->recurrence_rule);

            // Occurrences strictly before the split stay on the (truncated) parent.
            $before = app(RecurrenceExpander::class)->countOccurrencesBefore($parent, $splitDay);

            if ($before <= 0) {
                // Splitting at the very first occurrence == edit all.
                return $this->editAll($parent, $fields);
            }

            // Truncate the parent to end the day before the split.
            $parent->update([
                'recurrence_rule' => RecurrenceRule::build(
                    $rule->freq, $rule->interval, 'until', $splitDay->copy()->subDay()->toDateString(), null
                ),
            ]);

            // Remaining end for the new series.
            $newEndType = 'never';
            $newUntil = null;
            $newCount = null;
            if ($rule && $rule->count !== null) {
                $remaining = $rule->count - $before;
                if ($remaining <= 0) {
                    // Nothing left — the split was past the series end; just truncate.
                    return $parent->fresh();
                }
                $newEndType = 'count';
                $newCount = $remaining;
            } elseif ($rule && $rule->until !== null) {
                $newEndType = 'until';
                $newUntil = $rule->until->toDateString();
            }

            $start = $fields['event_date'] ?? $this->occurrenceStart($parent, $occurrenceDate);
            $meta = is_array($parent->metadata ?? null) ? $parent->metadata : [];
            unset($meta['recurrence_override_date'], $meta['recurrence_cancelled']);
            if (in_array($fields['event_nature'] ?? null, ['actionable', 'informational'], true)) {
                $meta['event_nature'] = $fields['event_nature'];
            }

            return CalendarEvent::create([
                'parent_event_id' => null,
                'is_recurring'    => true,
                'recurrence_rule' => RecurrenceRule::build($rule->freq, $rule->interval, $newEndType, $newUntil, $newCount),
                'event_type'      => $parent->event_type,
                'category'        => $fields['category'] ?? $parent->category,
                'title'           => $fields['title'] ?? $parent->title,
                'description'     => array_key_exists('description', $fields) ? ($fields['description'] ?: null) : $parent->description,
                'event_date'      => $start,
                'end_date'        => array_key_exists('end_date', $fields) ? ($fields['end_date'] ?: null) : $this->occurrenceEnd($parent, $occurrenceDate),
                'all_day'         => array_key_exists('all_day', $fields) ? (bool) $fields['all_day'] : (bool) $parent->all_day,
                'priority'        => $fields['priority'] ?? $parent->priority,
                'status'          => 'pending',
                'source_type'     => 'manual',
                'user_id'         => $parent->user_id,
                'created_by_id'   => $parent->created_by_id ?: $user->id,
                'agency_id'       => $parent->agency_id,
                'branch_id'       => $parent->branch_id,
                'property_id'     => array_key_exists('property_id', $fields) ? $fields['property_id'] : $parent->property_id,
                'contact_id'      => $parent->contact_id,
                'metadata'        => $meta,
            ]);
        });
    }

    /** EDIT "all": update the parent in place (the whole series follows). */
    public function editAll(CalendarEvent $parent, array $fields): CalendarEvent
    {
        $update = collect($fields)->only(['title', 'category', 'event_date', 'end_date', 'description', 'all_day', 'priority', 'property_id'])
            ->filter(fn ($v, $k) => $v !== null || in_array($k, ['end_date', 'description', 'property_id']))->all();
        if (in_array($fields['event_nature'] ?? null, ['actionable', 'informational'], true)) {
            $meta = is_array($parent->metadata ?? null) ? $parent->metadata : [];
            $meta['event_nature'] = $fields['event_nature'];
            $update['metadata'] = $meta;
        }
        $parent->update($update);
        return $parent->fresh();
    }

    /** DELETE "this occurrence": a dismissed tombstone child skips the date (no hard delete). */
    public function deleteOccurrence(CalendarEvent $parent, string $occurrenceDate, User $user): void
    {
        $date = Carbon::parse($occurrenceDate)->toDateString();
        DB::transaction(function () use ($parent, $date, $user) {
            $child = $this->findException($parent, $date);
            $meta = $child && is_array($child->metadata) ? $child->metadata : (is_array($parent->metadata ?? null) ? $parent->metadata : []);
            $meta['recurrence_override_date'] = $date;
            $meta['recurrence_cancelled'] = true;
            if ($child) {
                $child->update(['status' => 'dismissed', 'metadata' => $meta]);
            } else {
                CalendarEvent::create([
                    'parent_event_id' => $parent->id, 'is_recurring' => false, 'recurrence_rule' => null,
                    'event_type' => $parent->event_type, 'category' => $parent->category,
                    'title' => $parent->title, 'event_date' => $this->occurrenceStart($parent, $date),
                    'end_date' => $this->occurrenceEnd($parent, $date), 'all_day' => (bool) $parent->all_day,
                    'priority' => $parent->priority, 'status' => 'dismissed', 'source_type' => 'manual',
                    'user_id' => $parent->user_id, 'created_by_id' => $parent->created_by_id ?: $user->id,
                    'agency_id' => $parent->agency_id, 'branch_id' => $parent->branch_id, 'metadata' => $meta,
                ]);
            }
        });
    }

    /** DELETE "this and future": truncate the parent to end the day before this occurrence. */
    public function deleteFuture(CalendarEvent $parent, string $occurrenceDate): void
    {
        $splitDay = Carbon::parse($occurrenceDate)->startOfDay();
        $rule = RecurrenceRule::parse($parent->recurrence_rule);
        $before = app(RecurrenceExpander::class)->countOccurrencesBefore($parent, $splitDay);
        if ($before <= 0) {
            $this->deleteAll($parent);
            return;
        }
        $parent->update([
            'recurrence_rule' => RecurrenceRule::build(
                $rule->freq, $rule->interval, 'until', $splitDay->copy()->subDay()->toDateString(), null
            ),
        ]);
    }

    /** DELETE "all": soft-delete the parent (+ its exception children). Series stops expanding. */
    public function deleteAll(CalendarEvent $parent): void
    {
        DB::transaction(function () use ($parent) {
            CalendarEvent::withoutGlobalScopes()->where('parent_event_id', $parent->id)->get()
                ->each(fn ($c) => $c->delete());
            $parent->delete();
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function findException(CalendarEvent $parent, string $date): ?CalendarEvent
    {
        return CalendarEvent::withoutGlobalScopes()
            ->where('parent_event_id', $parent->id)
            ->where('metadata->recurrence_override_date', $date)
            ->first();
    }

    /** The default start datetime for an occurrence on $date (parent's time-of-day). */
    private function occurrenceStart(CalendarEvent $parent, string $date): Carbon
    {
        $t = $parent->event_date;
        return Carbon::parse($date)->setTime($t->hour, $t->minute, $t->second);
    }

    private function occurrenceEnd(CalendarEvent $parent, string $date): ?Carbon
    {
        if (!$parent->end_date) {
            return null;
        }
        $dur = $parent->event_date->diffInSeconds($parent->end_date);
        return $this->occurrenceStart($parent, $date)->addSeconds($dur);
    }
}
