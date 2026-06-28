<?php

namespace App\Services\ViewingPack;

use App\Models\Agency;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use App\Models\ViewingPack;
use App\Services\CommandCenter\CalendarEventService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Viewing Pack ↔ calendar tie-in (AT-107, Step 8, spec §9).
 *
 * Creates OR links a viewing appointment for the pack's tour, REUSING the
 * existing calendar mechanism (CalendarEventService::createManual / ::update) —
 * no parallel event-creation logic, no new event table. The event is a 'viewing'
 * class event carrying the buyer (contact_id), agent (user_id), date/time, and a
 * source link back to the pack (source_type/source_id) so pack ↔ event are
 * mutually discoverable (pack→event via viewing_packs.calendar_event_id;
 * event→pack via the event's existing source() morphTo).
 *
 * The existing CalendarEventFeedback system is untouched — it hangs off the
 * CalendarEvent, so post-viewing feedback is naturally associated with the tour.
 */
class ViewingPackCalendarService
{
    private const DEFAULT_DURATION = 60;

    /** Per-agency default viewing duration in minutes (config rule; NULL → 60). */
    public function durationFor(int $agencyId): int
    {
        $d = (int) (Agency::query()->whereKey($agencyId)->value('viewing_pack_default_duration_minutes') ?? 0);

        return $d > 0 ? $d : self::DEFAULT_DURATION;
    }

    /**
     * Create or link the viewing appointment for a pack at the given tour time.
     * Idempotent: if the pack already links an event, that event is UPDATED in
     * place (no duplicate); otherwise a new one is created via the existing path.
     */
    public function scheduleViewing(ViewingPack $pack, \DateTimeInterface $tourAt, User $actor): CalendarEvent
    {
        $pack->loadMissing(['contact', 'viewingPackProperties.property']);

        $start    = Carbon::instance(Carbon::parse($tourAt));
        $duration = $this->durationFor((int) $pack->agency_id);
        $end      = $start->copy()->addMinutes($duration);

        $buyer     = $pack->contact;
        $count     = $pack->viewingPackProperties->count();
        $firstProp = $pack->viewingPackProperties->first()?->property_id;
        $buyerName = trim((string) ($buyer?->full_name ?? trim(($buyer->first_name ?? '') . ' ' . ($buyer->last_name ?? '')))) ?: 'Buyer';
        $title     = 'Viewing — ' . $buyerName . ($count > 0 ? ' (' . $count . ' ' . Str::plural('property', $count) . ')' : '');

        $data = [
            'category'    => 'viewing',
            'event_type'  => 'manual',
            'title'       => $title,
            'event_date'  => $start,
            'end_date'    => $end,
            'contact_id'  => $buyer?->id,
            'property_id' => $firstProp,
            'agency_id'   => $pack->agency_id,
            'branch_id'   => $actor->branch_id,
            // Reverse link — the event's existing morphTo source() resolves to the pack.
            'source_type' => ViewingPack::class,
            'source_id'   => $pack->id,
        ];

        $service = app(CalendarEventService::class);

        $event = $pack->calendar_event_id
            ? CalendarEvent::find($pack->calendar_event_id)
            : null;

        if ($event) {
            // Link/refresh the existing appointment — never duplicate.
            $event = $service->update($event, $data);
        } else {
            $event = $service->createManual($data, $actor);
        }

        $pack->forceFill([
            'calendar_event_id' => $event->id,
            'tour_at'           => $start,
        ])->save();

        return $event;
    }
}
