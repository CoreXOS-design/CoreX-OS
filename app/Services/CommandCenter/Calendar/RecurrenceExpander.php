<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\AgencyContactSettings;
use App\Models\CommandCenter\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Materialise-on-view expansion of a recurring parent event into virtual
 * occurrences within a queried window. Occurrences are NOT persisted — they are
 * in-memory CalendarEvent clones of the parent with the date shifted, so they
 * flow through applyFilters (colour, conflict markers, private redaction,
 * effectiveEventNature) exactly like real events.
 *
 * Exceptions (an edited or cancelled single occurrence) are real child rows with
 * parent_event_id = parent.id and metadata['recurrence_override_date'] set. Their
 * dates are skipped here so the child row (edit) or nothing (cancel = dismissed)
 * takes that slot instead — the series is never broken.
 *
 * Occurrence identity is a synthetic numeric id: parentId * 1e8 + YYYYMMDD. Any
 * id >= 1e8 is an occurrence, which keeps every existing tile @click(intId)
 * unchanged; the client decodes it to (parentId, date).
 */
class RecurrenceExpander
{
    public const OCC_ID_BASE = 100000000; // 1e8 — real event ids stay well below this

    /** Encode a synthetic occurrence id from a parent id + occurrence date. */
    public static function syntheticId(int $parentId, Carbon $date): int
    {
        return $parentId * self::OCC_ID_BASE + ((int) $date->format('Ymd'));
    }

    /** Decode a synthetic occurrence id → ['parent_id'=>int, 'date'=>Carbon] or null. */
    public static function decodeId(int $id): ?array
    {
        if ($id < self::OCC_ID_BASE) {
            return null;
        }
        $parentId = intdiv($id, self::OCC_ID_BASE);
        $ymd = $id % self::OCC_ID_BASE;
        try {
            $date = Carbon::createFromFormat('Ymd', str_pad((string) $ymd, 8, '0', STR_PAD_LEFT))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
        return ['parent_id' => $parentId, 'date' => $date];
    }

    /**
     * Expand a recurring parent into virtual occurrences within [rangeStart, rangeEnd].
     *
     * @return Collection<int,CalendarEvent>
     */
    public function expand(CalendarEvent $parent, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $rule = RecurrenceRule::parse($parent->recurrence_rule);
        if (!$rule || !$parent->event_date) {
            return collect();
        }

        $settings = AgencyContactSettings::forAgency((int) ($parent->agency_id ?: 0));
        $maxOccurrences = $settings->calendarMaxOccurrences();
        $maxDays        = $settings->calendarMaxExpansionDays();

        // Clamp the window: never expand more than maxDays from the range start.
        $windowEnd = $rangeStart->copy()->addDays($maxDays);
        $effectiveEnd = $rangeEnd->lt($windowEnd) ? $rangeEnd->copy() : $windowEnd;
        $rangeStartDay = $rangeStart->copy()->startOfDay();

        $overrideDates = $this->overrideDates($parent);

        $dtstart = $parent->event_date->copy();
        $durationSec = $parent->end_date ? $parent->event_date->diffInSeconds($parent->end_date) : 0;

        $out = collect();
        $cursor = $dtstart->copy();
        $generated = 0;   // counts toward COUNT (RFC semantics: from DTSTART, incl. pre-range)
        $iterations = 0;
        $hardIterationCap = $maxOccurrences + 10000; // runaway guard for far-past series

        while (true) {
            if ($iterations++ > $hardIterationCap) {
                break;
            }
            // End conditions.
            if ($rule->count !== null && $generated >= $rule->count) {
                break;
            }
            if ($rule->until !== null && $cursor->gt($rule->until)) {
                break;
            }
            if ($cursor->copy()->startOfDay()->gt($effectiveEnd)) {
                break;
            }

            $generated++;

            // Emit only occurrences inside the visible window that aren't overridden.
            if ($cursor->gte($rangeStartDay) && !isset($overrideDates[$cursor->toDateString()])) {
                $out->push($this->makeVirtualOccurrence($parent, $cursor->copy(), $durationSec));
                if ($out->count() >= $maxOccurrences) {
                    break;
                }
            }

            $cursor = $rule->advance($cursor);
        }

        return $out;
    }

    /**
     * The occurrence dates (Y-m-d => true) that have an exception child (edit or
     * cancel) and must therefore be skipped during expansion.
     *
     * @return array<string,bool>
     */
    public function overrideDates(CalendarEvent $parent): array
    {
        $dates = [];
        $children = CalendarEvent::withoutGlobalScopes()
            ->where('parent_event_id', $parent->id)
            ->get(['metadata']);
        foreach ($children as $child) {
            $od = is_array($child->metadata ?? null) ? ($child->metadata['recurrence_override_date'] ?? null) : null;
            if ($od) {
                try {
                    $dates[Carbon::parse($od)->toDateString()] = true;
                } catch (\Throwable $e) { /* ignore malformed */ }
            }
        }
        return $dates;
    }

    /**
     * Count occurrences whose start DAY is strictly before $before, bounded by
     * the rule's COUNT/UNTIL. Used by the "this and future" split to decide how
     * many occurrences remain on the truncated parent.
     */
    public function countOccurrencesBefore(CalendarEvent $parent, Carbon $before): int
    {
        $rule = RecurrenceRule::parse($parent->recurrence_rule);
        if (!$rule || !$parent->event_date) {
            return 0;
        }
        $beforeDay = $before->copy()->startOfDay();
        $cursor = $parent->event_date->copy();
        $count = 0;
        $iter = 0;
        while ($iter++ < 100000) {
            if ($cursor->copy()->startOfDay()->gte($beforeDay)) {
                break;
            }
            if ($rule->until !== null && $cursor->gt($rule->until)) {
                break;
            }
            if ($rule->count !== null && $count >= $rule->count) {
                break;
            }
            $count++;
            $cursor = $rule->advance($cursor);
        }
        return $count;
    }

    /** Build one non-persisted virtual occurrence from the parent at $start. */
    private function makeVirtualOccurrence(CalendarEvent $parent, Carbon $start, int $durationSec): CalendarEvent
    {
        /** @var CalendarEvent $occ */
        $occ = $parent->replicate([
            'is_recurring', 'recurrence_rule', 'parent_event_id',
            'reminder_offsets', 'reminders_sent',
        ]);
        $occ->id            = self::syntheticId((int) $parent->id, $start);
        $occ->event_date    = $start->copy();
        $occ->end_date      = $durationSec > 0 ? $start->copy()->addSeconds($durationSec) : null;
        $occ->is_recurring  = false;
        $occ->parent_event_id = $parent->id;
        // Non-'manual' source keeps drag-reschedule off occurrences (edit via panel
        // scope instead) while show()/edit still treat it as editable via the parent.
        $occ->source_type   = 'recurring';
        $occ->exists        = true; // read-only virtual; never saved
        // Dynamic markers used by the controller/JSON layer.
        $occ->is_occurrence   = true;
        $occ->occurrence_date = $start->toDateString();
        $occ->recurrence_parent_id = (int) $parent->id;
        return $occ;
    }
}
