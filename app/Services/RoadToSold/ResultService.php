<?php

namespace App\Services\RoadToSold;

use App\Events\RoadToSold\ProspectResultRecorded;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Models\Property;
use App\Models\PropertyNote;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Presentations\OutcomeLockedException;
use App\Services\Presentations\PresentationOutcomeService;
use App\Support\Events\SafeRaise;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, station 8 "Result" (.ai/specs/road-to-sold.md 23.9 / 23.10): the ONE writer of a result. The evaluation-feedback form, the presentation
 * outcome panel and the belt card all call record(); it sets the belt record, the claim, the property and the other screens' stores TOGETHER, in one
 * transaction, and is idempotent on (record, result, day).
 *
 *   won          the record goes to Mandate signed ("take the mandate / get it signed"); the property Prospecting -> Draft; the claim reads "listing"
 *   pending      ("undecided" / "not now") the record is set aside at the station it was at with a revisit date; the claim stays open
 *   lost         set aside with the result's reason and revisit; the claim is closed as lost and given back to the pool
 *   not_selling  set aside with its reason; the property Prospecting -> Not selling; the claim is closed as not interested. Un-parking returns the property
 *
 * A property the agency already holds as stock is never released or closed by a result (the existing message). Never throws a raw exception: a refusal
 * comes back as ok=false with a plain sentence.
 */
class ResultService
{
    public function __construct(
        private readonly ProspectService $prospects,
        private readonly ResultOptionService $options,
        private readonly ParkReasonService $reasons,
    ) {
    }

    /**
     * @param  array{presentation?:Presentation,presentation_data?:array,feedback?:CalendarEventFeedback}  $context
     * @return array{ok:bool,message:string,class:?string,already:bool}
     */
    public function record(RtsProspect $p, string $resultKey, ?CarbonInterface $revisitOn, ?string $note, User $actor, string $source, array $context = []): array
    {
        $agencyId = (int) $p->agency_id;
        $track = (string) ($p->track ?: 'sales');
        $opt = $context['option'] ?? $this->options->byKey($agencyId, $resultKey, $track);
        if (! $opt) {
            return $this->no('That result is not on this agency\'s list any more. Pick another one.');
        }
        if ($p->closed_at !== null || $p->released_at !== null) {
            return $this->no('This property is not on your list any more, so there is no result to record.');
        }
        $class = (string) $opt['class'];
        $claims = $this->claimsOf($p);
        if (in_array($class, ['lost', 'not_selling'], true) && $claims->contains(fn (ProspectingClaim $c) => $c->isPromoted())) {
            return $this->no('This property has already been promoted onto your books - it can no longer be closed out or released.');
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;

        $this->reasons->ensureKey($agencyId, $track, 'not_now', 'Not now', 7);
        $days = (int) ($opt['revisit_days'] ?: 30);
        $revisit = $revisitOn ? Carbon::instance($revisitOn)->startOfDay() : now()->startOfDay()->addDays(max(1, $days));
        if ($revisit->lte(now()->startOfDay())) {
            $revisit = now()->startOfDay()->addDay();
        }

        try {
            DB::transaction(function () use ($p, $opt, $class, $resultKey, $revisit, $note, $actor, $source, $context, $claims) {
                // 1. the idempotency line first: the same result on the same day, from any screen, is one result
                $this->prospects->logResult($p, $resultKey, $actor, $note ?? (string) $opt['label'], ['source' => $source, 'class' => $class]);

                // 2. the other screens' stores: the presentation outcome and the evaluation's feedback carry the same answer
                if (empty($context['skip_stores'])) {
                    $this->writePresentation($p, $opt, $resultKey, $revisit, $note, $actor, $context);
                    $this->writeFeedback($p, $opt, $resultKey, $context);
                }

                // 3. the record, the claim and the property
                $this->applyToRecord($p, $opt, $class, $revisit, $note, $actor, isset($context['step']) ? ['step' => (int) $context['step']] : []);
                $this->applyToClaims($claims, $class);
                $this->applyToProperty($p, $class, $note, $actor);
            });
        } catch (UniqueConstraintViolationException) {
            return ['ok' => true, 'message' => 'That result is already recorded for today.', 'class' => $class, 'already' => true];
        }

        SafeRaise::dispatch('ProspectResultRecorded', fn () => new ProspectResultRecorded(
            prospectId: (int) $p->id, agencyId: $agencyId, userId: (int) $p->user_id, trackedPropertyId: (int) $p->tracked_property_id,
            station: (string) $p->fresh()->stage, resultClass: $class, resultKey: $resultKey, source: $source, actorUserId: (int) $actor->id,
        ));

        return ['ok' => true, 'message' => $this->doneMessage($class, (string) $opt['label'], $revisit), 'class' => $class, 'already' => false];
    }

    /**
     * A dead end at ANY station with the agency's own reason (road-to-sold.md 23.11): the Market Intelligence "lost" feedback goes through here, so there is no
     * reason-less loss. Same effects as a "lost" result: the record is set aside with the reason and its revisit (a "never" reason closes it), the claim is closed as
     * lost and given back, one history line per reason per day, one `ProspectResultRecorded`. It writes no presentation outcome and no evaluation feedback.
     *
     * @return array{ok:bool,message:string,class:?string,already:bool}
     */
    public function offRamp(RtsProspect $p, string $reasonKey, ?string $note, User $actor, string $source, ?int $step = null): array
    {
        $agencyId = (int) $p->agency_id;
        $track = (string) ($p->track ?: 'sales');
        // from the popup the reason comes from the step's own list (road-to-sold.md 23.25); from Market Intelligence's "lost" it is the station's
        $reason = $step !== null
            ? $this->reasons->forStep($agencyId, $track, $step)->firstWhere('key', $reasonKey)
            : $this->reasons->choices($agencyId, $track, ParkReasonService::stationOfStage((string) $p->stage))->firstWhere('key', $reasonKey);
        if (! $reason) {
            return $this->no('Pick one of the reasons shown for why this property stops here.');
        }
        $note = trim((string) $note);
        if (! empty($reason->is_other) && mb_strlen($note) < 3) {
            return $this->no('Say in a few words why it stops here.');
        }
        $days = (int) $reason->default_revisit_days;
        $countsAs = ($reason->counts_as ?? null) === 'not_selling' ? 'not_selling' : 'lost';
        $opt = [
            'key' => 'offramp_' . $reasonKey, 'label' => (string) $reason->label, 'class' => $countsAs, 'park_reason_key' => $reasonKey,
            'needs_date' => false, 'revisit_days' => $days > 0 ? $days : null, 'never' => $days === 0,
        ];

        $result = $this->record($p, 'offramp_' . $reasonKey, null, $note !== '' ? $note : null, $actor, $source, ['option' => $opt, 'skip_stores' => true, 'step' => $step]);
        if ($result['ok'] && empty($result['already'])) {
            $this->markInMic($p, $reasonKey, $countsAs, (string) $reason->label, $note, $actor);
        }

        return $result;
    }

    /**
     * road-to-sold.md 23.25.6 rule 2: every off-ramp leaves the mark the Market Intelligence card reads ("Where it stands: <status> - <reason> (<agent>, <date>)") on the agency's own
     * record of the file - not only a Not selling result, so nobody chases it again. Opted out = do not contact; another agency's mandate / a loss to another agency = mandate
     * elsewhere; everything else = not selling. Never stops the off-ramp if the mark cannot be written.
     */
    private function markInMic(RtsProspect $p, string $reasonKey, string $countsAs, string $label, string $note, User $actor): void
    {
        try {
            $status = match (true) {
                $reasonKey === 'opted_out', $reasonKey === 'do_not_contact' => \App\Models\Prospecting\TrackedPropertyAgencyWork::STATUS_DO_NOT_CONTACT,
                in_array($reasonKey, ['mandate_elsewhere', 'already_listed_open', 'chose_other_agency'], true) => \App\Models\Prospecting\TrackedPropertyAgencyWork::STATUS_MANDATE_ELSEWHERE,
                default => \App\Models\Prospecting\TrackedPropertyAgencyWork::STATUS_NOT_SELLING,
            };
            app(\App\Services\Prospecting\AgencyWorkService::class)->setStatus((int) $p->agency_id, (int) $p->tracked_property_id, $status, $label . ($note !== '' ? ': ' . $note : ''), (int) $actor->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Off-ramp: the Market Intelligence mark could not be written (the off-ramp stands)', ['prospect' => $p->id, 'message' => $e->getMessage()]);
        }
    }

    // ── the pieces ───────────────────────────────────────────────────────

    private function applyToRecord(RtsProspect $p, array $opt, string $class, Carbon $revisit, ?string $note, User $actor, array $meta = []): void
    {
        $p->refresh();
        if ($class === 'won') {
            if ($p->parked_at !== null) {
                $this->prospects->unpark($p, $actor);
                $p->refresh();
            }
            $this->prospects->advance($p, ProspectStages::MANDATE_SIGNED, $actor, [
                'event_class' => 'ResultService', 'subject_type' => 'result', 'subject_id' => (int) $p->id, 'next_label' => 'Take the mandate / get it signed', 'note' => $opt['label'],
            ]);

            return;
        }
        if (! empty($opt['never'])) {   // a reason that is "never" (do not contact) ends the story instead of setting it aside
            $this->prospects->close($p, 'do_not_contact', $actor);

            return;
        }
        $reasonKey = $class === 'pending' ? 'not_now' : ((string) ($opt['park_reason_key'] ?: ($class === 'not_selling' ? 'not_selling_now' : 'other')));
        $this->prospects->park($p, $reasonKey, $revisit, $note ?? (string) $opt['label'], $actor, ['class' => $class, 'result' => $opt['key']] + $meta);
    }

    /** Claims of the record: lost / not selling close them (the property goes back to the pool); the others keep them open. */
    private function applyToClaims($claims, string $class): void
    {
        if (! in_array($class, ['lost', 'not_selling'], true)) {
            return;
        }
        $status = $class === 'lost' ? ProspectingClaim::STATUS_LOST : ProspectingClaim::STATUS_NOT_INTERESTED;
        foreach ($claims as $c) {
            // release_reason "result_*": the claim-release listener leaves the (parked) belt record alone
            $c->update(['status' => $status, 'is_active' => false, 'released_at' => now(), 'release_reason' => 'result_' . $class, 'last_updated_at' => now()]);
        }
    }

    private function applyToProperty(RtsProspect $p, string $class, ?string $note, User $actor): void
    {
        if (! $p->property_id || ! in_array($class, ['won', 'not_selling'], true)) {
            return;
        }
        $property = Property::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($p->property_id);
        if (! $property || ! $property->isProspecting()) {
            return;   // already on its own lifecycle (draft, active ...): not this result's job
        }
        if ($class === 'won') {
            $property->status = 'draft';
            $property->save();

            return;
        }
        // the same as the property page's "Not selling": captured before the save (the observer clears the matches), status written with its reason, open claims closed
        $sync = app(\App\Services\Prospecting\ProspectingStatusSync::class);
        $matched = $sync->matchedListingIds($property);
        $property->status = Property::STATUS_NOT_SELLING;
        \App\Services\Prospecting\ProspectingStatusSync::withReason((string) ($note ?? ''), fn () => $property->save());
        $sync->closeOpenClaims($property, $matched);
        PropertyNote::create([
            'agency_id' => $property->agency_id, 'property_id' => $property->id, 'user_id' => $actor->id,
            'content' => 'Marked Not selling on ' . now()->format('j M Y, H:i') . ' by ' . $actor->name . ' (result of the evaluation). Reason: ' . ($note ?: 'No reason given.'),
        ]);
    }

    /** The presentation's own outcome row, filled through its one service from the shared answer (the fixed map keeps every old reader working). */
    private function writePresentation(RtsProspect $p, array $opt, string $resultKey, Carbon $revisit, ?string $note, User $actor, array $context): void
    {
        $presentation = $context['presentation'] ?? $this->presentationOf($p);
        if (! $presentation) {
            return;
        }
        $enum = ResultOptionService::enumForKey($resultKey);
        $isLostOrOther = in_array($enum, PresentationOutcome::LOST_OUTCOMES, true) || $enum === PresentationOutcome::OUTCOME_OTHER;
        $data = ($context['presentation_data'] ?? []) + ['outcome' => $enum];
        $data['outcome'] = $enum;
        if ($isLostOrOther) {
            $data['cancellation_reason'] = $data['cancellation_reason'] ?? 'other';
            if ($data['cancellation_reason'] === 'other' && trim((string) ($data['notes'] ?? '')) === '') {
                $data['notes'] = $note ?: (string) $opt['label'];
            }
        }
        try {
            $row = app(PresentationOutcomeService::class)->record($presentation, $data, $actor);
            if (Schema::hasColumn('presentation_outcomes', 'result_key')) {
                DB::table('presentation_outcomes')->where('id', $row->id)->update(['result_key' => $resultKey]);
            }
        } catch (OutcomeLockedException) {
            // a locked (90-day) presentation outcome is left as it is; the result still stands on the record
        } catch (\Throwable $e) {
            Log::warning('Belt: the presentation outcome could not follow the result (the result stands)', ['presentation_id' => $presentation->id, 'message' => $e->getMessage()]);
        }
    }

    /** The evaluation's feedback carries the result too, so the calendar form opens pre-filled with it. */
    private function writeFeedback(RtsProspect $p, array $opt, string $resultKey, array $context): void
    {
        $fb = $context['feedback'] ?? $this->feedbackOf($p);
        if (! $fb) {
            return;
        }
        $kd = is_array($fb->kind_specific_data) ? $fb->kind_specific_data : [];
        if (($kd['result_key'] ?? null) === $resultKey && ($kd['outcome'] ?? null) === $opt['label']) {
            return;
        }
        $fb->kind_specific_data = array_merge($kd, ['result_key' => $resultKey, 'outcome' => $opt['label']]);
        $fb->save();
    }

    private function claimsOf(RtsProspect $p)
    {
        return ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $p->agency_id)->where('rts_prospect_id', $p->id)
            ->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')->get();
    }

    private function presentationOf(RtsProspect $p): ?Presentation
    {
        $q = Presentation::query()->withoutGlobalScopes()->where('agency_id', $p->agency_id)->whereNull('deleted_at');
        if (BeltLinks::presentationColumns()) {
            $byProspect = (clone $q)->where('rts_prospect_id', $p->id)->orderByDesc('id')->first();
            if ($byProspect) {
                return $byProspect;
            }
        }

        return $p->property_id ? (clone $q)->where('property_id', $p->property_id)->where('created_by_user_id', $p->user_id)->orderByDesc('id')->first() : null;
    }

    private function feedbackOf(RtsProspect $p): ?CalendarEventFeedback
    {
        if (! BeltLinks::calendarColumns()) {
            return null;
        }
        $eventId = DB::table('calendar_events')->where('rts_prospect_id', $p->id)->where('category', 'property_evaluation')->whereNull('deleted_at')->orderByDesc('event_date')->value('id');

        return $eventId ? CalendarEventFeedback::withoutGlobalScopes()->where('calendar_event_id', $eventId)->whereNotNull('captured_at')->orderByDesc('id')->first() : null;
    }

    // ── words ────────────────────────────────────────────────────────────

    private function doneMessage(string $class, string $label, Carbon $revisit): string
    {
        return match ($class) {
            'won'         => 'Result saved: ' . $label . '. The property is now a Draft - take the mandate and get it signed.',
            'pending'     => 'Result saved: ' . $label . '. It comes back on ' . $revisit->format('j M Y') . '.',
            'lost'        => 'Result saved: ' . $label . '. It is set aside and comes back on ' . $revisit->format('j M Y') . '.',
            default       => 'Result saved: ' . $label . '. The property is marked Not selling and comes back on ' . $revisit->format('j M Y') . '.',
        };
    }

    /** @return array{ok:bool,message:string,class:?string,already:bool} */
    private function no(string $m): array
    {
        return ['ok' => false, 'message' => $m, 'class' => null, 'already' => false];
    }
}
