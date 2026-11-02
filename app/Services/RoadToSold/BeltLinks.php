<?php

namespace App\Services\RoadToSold;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Presentation;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsProspect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, stations 5-7 (road-to-sold.md 23.8): the appointment and the presentation carry the property.
 *
 * An evaluation appointment gets `calendar_events.tracked_property_id` and `rts_prospect_id`; a presentation gets `tracked_property_id`, the owner as
 * `seller_contact_id`, `rts_prospect_id` and (when the prospect has an appointment) `calendar_event_id`. A booking that arrives with the keys
 * already set (Road to Sold's own booking) keeps them; one made on the plain calendar is matched to the creating agent's OPEN prospect for the
 * property, and only when exactly one matches - otherwise nothing is filled and the appointment is still booked.
 *
 * Safe before the A4 migration: every write is guarded by hasColumn and never throws into the caller.
 */
class BeltLinks
{
    public static function calendarColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('calendar_events', 'rts_prospect_id') && Schema::hasColumn('calendar_events', 'tracked_property_id');
    }

    public static function presentationColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('presentations', 'rts_prospect_id') && Schema::hasColumn('presentations', 'calendar_event_id');
    }

    /** The tracked property a CoreX property sits on (the property's own link, else the file promoted to it). */
    public function trackedOf(?int $propertyId, int $agencyId): ?int
    {
        if (! $propertyId) {
            return null;
        }
        $id = DB::table('properties')->where('id', $propertyId)->where('agency_id', $agencyId)->value('tracked_property_id');
        if (! $id) {
            $id = TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('promoted_to_property_id', $propertyId)->value('id');
        }

        return $id ? (int) $id : null;
    }

    /** The agent's open (not closed, not released) belt record for a property file, if there is exactly one. */
    public function openProspect(int $agencyId, int $agentId, int $trackedId): ?RtsProspect
    {
        $rows = RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', 'sales')->where('tracked_property_id', $trackedId)
            ->where('user_id', $agentId)->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at')->limit(2)->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /**
     * Fill the keys of an evaluation appointment created on the plain calendar. Returns [trackedId, prospectId] (either may be null). Never throws.
     *
     * @return array{0:?int,1:?int}
     */
    public function linkEvaluation(CalendarEvent $event): array
    {
        try {
            if (! self::calendarColumns() || $event->agency_id === null) {
                return [null, null];
            }
            $tracked = $event->tracked_property_id ? (int) $event->tracked_property_id : $this->trackedOf($event->property_id ? (int) $event->property_id : null, (int) $event->agency_id);
            $prospectId = $event->rts_prospect_id ? (int) $event->rts_prospect_id : null;
            if (! $prospectId && $tracked && $event->user_id) {
                $prospectId = $this->openProspect((int) $event->agency_id, (int) $event->user_id, $tracked)?->id;
            }
            if ($tracked || $prospectId) {
                DB::table('calendar_events')->where('id', $event->id)->update(array_filter([
                    'tracked_property_id' => $event->tracked_property_id ? null : $tracked,
                    'rts_prospect_id'     => $event->rts_prospect_id ? null : $prospectId,
                ], fn ($v) => $v !== null));
                $event->forceFill(['tracked_property_id' => $tracked, 'rts_prospect_id' => $prospectId])->syncOriginal();
            }

            return [$tracked, $prospectId];
        } catch (\Throwable $e) {
            Log::warning('Belt: an evaluation appointment could not be linked to its property (the booking stands)', ['event_id' => $event->id ?? null, 'message' => $e->getMessage()]);

            return [null, null];
        }
    }

    /** The latest evaluation appointment of a belt record that is still on (not cancelled / dismissed), for a presentation to name. */
    public function latestEvaluationEvent(int $prospectId): ?int
    {
        if (! self::calendarColumns()) {
            return null;
        }
        // an appointment on an archived property is invisible to the calendar (its own live-property rule), so it is not one a feedback button can open
        $id = DB::table('calendar_events')->where('rts_prospect_id', $prospectId)->where('category', 'property_evaluation')->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('property_id')->orWhereExists(fn ($e) => $e->selectRaw('1')->from('properties')->whereColumn('properties.id', 'calendar_events.property_id')->whereNull('properties.deleted_at')))
            ->whereNotIn('status', ['cancelled', 'dismissed'])->orderByDesc('event_date')->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * The evaluation did not take place because the seller was not there (feedback status "no show", 23.10): the belt record is set aside with a
     * short revisit (the station-5 off-ramp; the reason list by station arrives with A6, so for now it is "Other" with the words "Evaluation no-show").
     * Returns true when a record was parked. Never throws.
     */
    public function evaluationNoShow(CalendarEvent $event, ?int $actorId): bool
    {
        try {
            $prospectId = ! empty($event->rts_prospect_id) ? (int) $event->rts_prospect_id : null;
            $p = $prospectId ? RtsProspect::withoutGlobalScopes()->find($prospectId) : null;
            if (! $p && $event->agency_id !== null && $event->user_id) {
                $tracked = ! empty($event->tracked_property_id) ? (int) $event->tracked_property_id : $this->trackedOf($event->property_id ? (int) $event->property_id : null, (int) $event->agency_id);
                $p = $tracked ? $this->openProspect((int) $event->agency_id, (int) $event->user_id, $tracked) : null;
            }
            if (! $p || $p->closed_at !== null || $p->released_at !== null || $p->parked_at !== null) {
                return false;
            }
            $actor = $actorId ? \App\Models\User::query()->withoutGlobalScopes()->find($actorId) : null;
            app(ProspectService::class)->park($p, 'other', now()->addDays(14)->startOfDay(), 'Evaluation no-show', $actor);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Belt: a no-show could not be recorded on the belt record (the feedback stands)', ['event_id' => $event->id ?? null, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * A held evaluation's feedback names a result (its own key, or the label picked from the shared list, or the old wording): hand it to the one result service.
     * The record is the appointment's own, else the creating agent's single open record for the property. Never throws.
     */
    public function resultFromFeedback(\App\Models\CommandCenter\CalendarEventFeedback $fb, CalendarEvent $event): void
    {
        try {
            $kd = is_array($fb->kind_specific_data) ? $fb->kind_specific_data : [];
            $agencyId = (int) ($event->agency_id ?? 0);
            if ($agencyId <= 0) {
                return;
            }
            $options = app(ResultOptionService::class);
            $key = ! empty($kd['result_key']) ? (string) $kd['result_key'] : $options->keyForLabel($agencyId, $kd['outcome'] ?? null);
            if (! $key || ! $options->byKey($agencyId, $key)) {
                return;
            }
            $prospectId = ! empty($event->rts_prospect_id) ? (int) $event->rts_prospect_id : null;
            $p = $prospectId ? RtsProspect::withoutGlobalScopes()->find($prospectId) : null;
            if (! $p && $event->user_id) {
                $tracked = ! empty($event->tracked_property_id) ? (int) $event->tracked_property_id : $this->trackedOf($event->property_id ? (int) $event->property_id : ($fb->property_id ? (int) $fb->property_id : null), $agencyId);
                $p = $tracked ? $this->openProspect($agencyId, (int) $event->user_id, $tracked) : null;
            }
            if (! $p || $p->closed_at !== null || $p->released_at !== null) {
                return;
            }
            $actor = \App\Models\User::query()->withoutGlobalScopes()->find($fb->captured_by_user_id ?: $event->user_id);
            if (! $actor) {
                return;
            }
            $revisit = ! empty($kd['revisit_on']) ? \Illuminate\Support\Carbon::parse($kd['revisit_on']) : null;
            app(ResultService::class)->record($p, $key, $revisit, $kd['seller_notes'] ?? null, $actor, 'evaluation_feedback', ['feedback' => $fb]);
        } catch (\Throwable $e) {
            Log::warning('Belt: the evaluation result could not be recorded on the belt record (the feedback stands)', ['feedback_id' => $fb->id ?? null, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Stations 9-11 (road-to-sold.md 23.4): the property's own state moves the agent's belt record. Draft = the property is a Draft (the won result, or the property page's
     * "Moved to Draft"); Active = compliance has passed AND the listing is published (the two events PropertyCompliancePassed and PropertyPublished, in either order, read
     * from the property so the second one to arrive is the one that moves it). Forward only; a record is never CREATED here (a property nobody works on the belt is not a
     * belt record); a closed or released record is left alone. Returns how many moved. Never throws.
     */
    public function syncPropertyStage(Property $property): int
    {
        try {
            $agencyId = (int) $property->agency_id;
            $target = null;
            if ($property->compliance_snapshot_at !== null && $property->published_at !== null) {
                $target = ProspectStages::ACTIVE;
            } elseif ((string) $property->status === 'draft') {
                $target = ProspectStages::DRAFT;
            }
            if (! $target || $agencyId <= 0) {
                return 0;
            }
            $q = RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', 'sales')->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at')
                ->where(function ($w) use ($property) {
                    $w->where('property_id', $property->id);
                    $tracked = $property->tracked_property_id ?? null;
                    if ($tracked && $property->agent_id) {
                        $w->orWhere(fn ($x) => $x->where('tracked_property_id', $tracked)->where('user_id', $property->agent_id));
                    }
                });
            $moved = 0;
            foreach ($q->get() as $p) {
                $agent = \App\Models\User::query()->withoutGlobalScopes()->find((int) $p->user_id);
                if (app(ProspectService::class)->advance($p, $target, $agent, [
                    'event_class' => 'PropertyStage', 'subject_type' => 'property', 'subject_id' => (int) $property->id, 'note' => $target === ProspectStages::ACTIVE ? 'Compliance passed and published' : 'The property is a Draft',
                ])) {
                    $moved++;
                }
            }

            return $moved;
        } catch (\Throwable $e) {
            Log::warning('Belt: the property could not move its belt record (the property stands)', ['property_id' => $property->id ?? null, 'message' => $e->getMessage()]);

            return 0;
        }
    }

    /** A presentation generated for a property: write the tracked property, the owner as seller, the belt record and its appointment. Never throws. */
    public function linkPresentation(Presentation $presentation, ?int $agentId): void
    {
        try {
            $agencyId = (int) $presentation->agency_id;
            $tracked = $this->trackedOf($presentation->property_id ? (int) $presentation->property_id : null, $agencyId);
            if (! $tracked) {
                return;
            }
            $update = [];
            if (! $presentation->tracked_property_id) {
                $update['tracked_property_id'] = $tracked;
            }
            if (! $presentation->seller_contact_id) {
                $owner = DB::table('tracked_properties')->where('id', $tracked)->where('agency_id', $agencyId)->value('owner_contact_id');
                if ($owner) {
                    $update['seller_contact_id'] = (int) $owner;
                }
            }
            if (self::presentationColumns() && $agentId) {
                $prospect = $this->openProspect($agencyId, $agentId, $tracked);
                if ($prospect && ! $presentation->rts_prospect_id) {
                    $update['rts_prospect_id'] = (int) $prospect->id;
                }
                if ($prospect && ! $presentation->calendar_event_id) {
                    $event = $this->latestEvaluationEvent((int) $prospect->id);
                    if ($event) {
                        $update['calendar_event_id'] = $event;
                    }
                }
            }
            if ($update !== []) {
                DB::table('presentations')->where('id', $presentation->id)->update($update + ['updated_at' => now()]);
                foreach ($update as $k => $v) {
                    $presentation->setAttribute($k, $v);
                }
                $presentation->syncOriginal();
            }
        } catch (\Throwable $e) {
            Log::warning('Belt: a presentation could not be linked to its property (the presentation stands)', ['presentation_id' => $presentation->id ?? null, 'message' => $e->getMessage()]);
        }
    }
}
