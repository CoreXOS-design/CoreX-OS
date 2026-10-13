<?php

namespace App\Services\RoadToSold;

use App\Models\Contact;
use App\Models\ContactConsentRecord;
use App\Models\ContactNote;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AT-454 — captures a "Contacted client" outcome and everything that follows from it.
 * Spec: .ai/specs/road-to-sold.md §10. KEY = tracked_properties.id.
 *
 * record() is ONE transaction: call-log row, hold, no-answer streak / drop-off, owner-contact note,
 * last-contacted (conversations only), calendar appointment (valuation booked), and — for a
 * "Do not contact" outcome — a call-channel opt-out consent record + the property's "never
 * suggest" marker. Anything that cannot be accepted is refused with a RoadToSoldException whose
 * message tells the agent what to do.
 */
class OutcomeService
{
    public function __construct(
        private readonly HoldService $holds,
        private readonly OutcomeDefinitionService $definitions,
        private readonly CalendarEventCreator $calendar,
    ) {
    }

    /**
     * @param  array{note?:?string,next_action_at?:?string,valuation_booked?:mixed,appointment_at?:?string,
     *               list_item_id?:?int,property_id?:?int,prospecting_listing_id?:?int,occurred_at?:?string}  $input
     */
    public function record(User $actor, int $trackedPropertyId, int $outcomeDefinitionId, array $input = [], string $track = 'sales'): RtsContactOutcome
    {
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            throw new RoadToSoldException('Switch into an agency before capturing an outcome.');
        }
        $agentId = (int) $actor->ownershipUserId();
        $settings = SettingsService::forAgency($agencyId, $track);

        $def = Def::query()->where('agency_id', $agencyId)->where('track', $track)
            ->where('is_active', true)->find($outcomeDefinitionId);
        if (! $def) {
            throw new RoadToSoldException('That outcome is not available any more. Pick another one.');
        }

        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 5000) {
            throw new RoadToSoldException('Keep the note under 5000 characters.');
        }
        $occurredAt = $this->parseDateTime($input['occurred_at'] ?? null, 'when the call happened') ?? now();

        $wantsValuation = ! $def->records_do_not_contact
            && ($def->next_action === Def::ACTION_APPOINTMENT
                || ($def->isConversation() && filter_var($input['valuation_booked'] ?? false, FILTER_VALIDATE_BOOLEAN)));
        $appointmentAt = null;
        if ($wantsValuation) {
            $appointmentAt = $this->parseDateTime($input['appointment_at'] ?? null, 'the valuation');
            if (! $appointmentAt) {
                throw new RoadToSoldException('Pick the date and time of the valuation.');
            }
        }

        $outcome = DB::transaction(function () use ($actor, $agencyId, $agentId, $trackedPropertyId, $def, $input, $track, $note, $occurredAt, $wantsValuation, $appointmentAt, $settings) {
            $tp = TrackedProperty::query()->where('agency_id', $agencyId)->lockForUpdate()->find($trackedPropertyId);
            if (! $tp) {
                throw new RoadToSoldException('That property could not be found.');
            }

            // A hold by someone else stops the capture — except a do-not-contact request, which must
            // always be recordable so the owner is never called again.
            if (! $def->records_do_not_contact) {
                $hold = $this->holds->activeHold($agencyId, $tp->id, $track);
                if ($hold && ! in_array((int) $hold->user_id, array_map('intval', $actor->dataIdentityIds()), true)) {
                    $who = optional($hold->holder)->name ?: 'Another agent';
                    throw new RoadToSoldException("{$who} is working this property until {$hold->expires_at->format('j M Y')}.");
                }
            }

            [$nextAction, $nextAt, $listTag] = $this->resolveNextAction($def, $input, $tp, $wantsValuation);

            // N no-answers in a row (this agent, this property). A conversation, or any outcome that
            // is not a no-answer, resets the run.
            $streak = 0;
            if ($def->counts_as_no_answer) {
                $previous = RtsContactOutcome::query()->where('agency_id', $agencyId)->where('track', $track)
                    ->where('tracked_property_id', $tp->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
                $streak = ($previous && (int) $previous->user_id === $agentId && $previous->no_answer_streak > 0)
                    ? $previous->no_answer_streak + 1 : 1;
            }
            $dropped = $def->counts_as_no_answer && $streak >= $settings->noAnswerDropAfter();
            if ($dropped) {
                $nextAction = 'dropped';
                $nextAt = null;
            }

            $contact = $tp->owner_contact_id
                ? Contact::query()->where('agency_id', $agencyId)->find($tp->owner_contact_id)
                : null;

            $eventId = null;
            if ($wantsValuation) {
                $event = $this->calendar->create([
                    'title'       => 'Valuation: ' . ($tp->displayAddress() ?: 'property'),
                    'category'    => 'property_evaluation',
                    'event_date'  => $appointmentAt->format('Y-m-d H:i:s'),
                    'description' => 'Booked from Road to Sold' . ($note !== '' ? ': ' . $note : ''),
                    'property_id' => $input['property_id'] ?? ($tp->promoted_to_property_id ?: null),
                    'contact_id'  => $contact?->id,
                ], $actor);
                $eventId = $event->id;
            }

            // Newest outcome for the property is the current one.
            RtsContactOutcome::query()->where('agency_id', $agencyId)->where('track', $track)
                ->where('tracked_property_id', $tp->id)->where('is_current', true)
                ->update(['is_current' => false]);

            $outcome = RtsContactOutcome::create([
                'agency_id'              => $agencyId,
                'branch_id'              => $actor->effectiveBranchId(),
                'track'                  => $track,
                'user_id'                => $agentId,
                'tracked_property_id'    => $tp->id,
                'property_id'            => $input['property_id'] ?? ($tp->promoted_to_property_id ?: null),
                'prospecting_listing_id' => $input['prospecting_listing_id'] ?? null,
                'list_item_id'           => $input['list_item_id'] ?? null,
                'contact_id'             => $contact?->id,
                'outcome_definition_id'  => $def->id,
                'outcome_key'            => $def->key,
                'outcome_label'          => $def->label,
                'kind'                   => $def->kind,
                'note'                   => $note !== '' ? $note : null,
                'valuation_booked'       => $wantsValuation,
                'calendar_event_id'      => $eventId,
                'next_action'            => $nextAction,
                'next_action_at'         => $nextAt,
                'list_tag'               => $listTag,
                'no_answer_streak'       => $streak,
                'dropped'                => $dropped,
                'is_current'             => true,
                'occurred_at'            => $occurredAt,
            ]);

            if ($def->records_do_not_contact) {
                $this->holds->markNeverSuggest($agencyId, $tp->id, $agentId, $outcome->id, $def->label, $track);
            } else {
                $this->holds->acquire($agencyId, $actor->effectiveBranchId(), $tp->id, $agentId, $settings->holdDays(), $outcome->id, $track, $occurredAt);
            }

            if ($contact) {
                $this->writeContactSide($contact, $def, $outcome, $note, $agentId, $occurredAt);
            }

            return $outcome;
        });

        $this->markListItemWorked($outcome, $agencyId, $agentId);

        return $outcome;
    }

    /**
     * What happens next, as [next_action, next_action_at, list_tag].
     *
     * A booked valuation settles it: the appointment is the follow-up, so the property leaves the
     * call list whatever outcome it was booked from.
     *
     * @return array{0:string,1:?Carbon,2:?string}
     */
    private function resolveNextAction(Def $def, array $input, TrackedProperty $tp, bool $wantsValuation): array
    {
        if ($wantsValuation) {
            return [Def::ACTION_APPOINTMENT, null, null];
        }

        $today = now()->startOfDay();
        $picked = null;
        $rawPicked = trim((string) ($input['next_action_at'] ?? ''));
        if ($rawPicked !== '') {
            $picked = $this->parseDateTime($rawPicked, 'the follow-up date')?->startOfDay();
            if ($picked && $picked->lt($today)) {
                throw new RoadToSoldException('Pick a follow-up date that is today or later.');
            }
        }

        switch ($def->next_action) {
            case Def::ACTION_AFTER_DAYS:
                return [Def::ACTION_AFTER_DAYS, $today->copy()->addDays(max(1, (int) $def->delay_days)), null];

            case Def::ACTION_PICKED_DATE:
                if (! $picked) {
                    throw new RoadToSoldException('Pick the date this property should come back.');
                }

                return [Def::ACTION_PICKED_DATE, $picked, null];

            case Def::ACTION_EXPIRY_ELSE:
                $expiry = $this->knownExpiryDate($tp);
                if ($expiry && $expiry->gte($today)) {
                    return [Def::ACTION_EXPIRY_ELSE, $expiry->startOfDay(), null];
                }
                if (! $picked) {
                    throw new RoadToSoldException('We do not know when that mandate ends. Pick the date this property should come back.');
                }

                return [Def::ACTION_EXPIRY_ELSE, $picked, null];

            case Def::ACTION_MOVE_TO_LIST:
                return [Def::ACTION_MOVE_TO_LIST, null, $def->list_tag];

            case Def::ACTION_NEVER_AGAIN:
                return [Def::ACTION_NEVER_AGAIN, null, null];

            default: // calendar_appointment without a valuation cannot happen (wantsValuation is true)
                return ['none', null, null];
        }
    }

    /**
     * The end of another agency's mandate when CoreX knows it. No stored expiry exists for other
     * agencies' mandates today (prospecting_listings carries mandate_type only), so this answers
     * null and the agent picks the date. The one place to extend if that data ever arrives.
     */
    protected function knownExpiryDate(TrackedProperty $tp): ?Carbon
    {
        return null;
    }

    /** History on the owner contact (visible in the contact's existing Notes tab) + last contacted. */
    private function writeContactSide(Contact $contact, Def $def, RtsContactOutcome $outcome, string $note, int $agentId, Carbon $at): void
    {
        $body = 'Road to Sold - ' . $def->label . ($note !== '' ? ': ' . $note : '');
        ContactNote::create([
            'contact_id' => $contact->id,
            'user_id'    => $agentId,
            'type'       => $def->isConversation() ? 'Contacted' : null,
            'body'       => $body,
        ]);

        // Conversations move last-contacted (same rule as "contacted and note"); attempts do not.
        if ($def->isConversation()) {
            $contact->markContacted($at);
        }

        // Do not contact: the proper consent record, so recomputeChannelConsent() derives opt_out_call.
        if ($def->records_do_not_contact) {
            $contact->setConsent('channel_call', ContactConsentRecord::DECISION_DECLINED, 'verbal', $agentId, 'agent_web');
        }
    }

    /** The call list row is worked — lane 2 owns rts_list_items; touched only if the table exists. */
    private function markListItemWorked(RtsContactOutcome $outcome, int $agencyId, int $agentId): void
    {
        if (! $outcome->list_item_id) {
            return;
        }
        try {
            if (Schema::hasTable('rts_list_items')) {
                DB::table('rts_list_items')
                    ->where('id', $outcome->list_item_id)->where('agency_id', $agencyId)->where('user_id', $agentId)
                    ->where('status', 'open')
                    ->update(['status' => 'worked', 'updated_at' => now()]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function parseDateTime(mixed $value, string $what): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new RoadToSoldException("We could not read the date for {$what}. Pick it again.");
        }
    }

    // ── Reads (the interface the page, lane 2 and the report use) ───────────────────────────────

    /**
     * Follow-ups due for an agent on $date: the current outcome on each property they worked, whose
     * return date has arrived. Lane 2 puts these first on the list ("origin = follow_up").
     *
     * @return Collection<int, RtsContactOutcome>
     */
    public function followUpsDue(User $user, CarbonInterface|string $date, string $track = 'sales'): Collection
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return collect();
        }

        return RtsContactOutcome::query()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('user_id', $user->dataIdentityIds())
            ->where('is_current', true)->where('dropped', false)
            ->whereNotNull('next_action_at')
            ->whereDate('next_action_at', '<=', Carbon::parse($date)->toDateString())
            ->whereIn('next_action', [Def::ACTION_AFTER_DAYS, Def::ACTION_PICKED_DATE, Def::ACTION_EXPIRY_ELSE])
            ->orderBy('next_action_at')->orderBy('id')
            ->get();
    }

    /**
     * The newest outcome per tracked property.
     *
     * @param  int[]  $trackedPropertyIds
     * @return Collection<int, RtsContactOutcome> keyed by tracked_property_id
     */
    public function currentFor(int $agencyId, array $trackedPropertyIds, string $track = 'sales'): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedPropertyIds))));
        if ($agencyId <= 0 || $ids === []) {
            return collect();
        }

        return RtsContactOutcome::query()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('tracked_property_id', $ids)->where('is_current', true)
            ->get()->keyBy('tracked_property_id');
    }

    /** Full history on one property, newest first (who, when, what was said). */
    public function historyForProperty(int $agencyId, int $trackedPropertyId, string $track = 'sales', int $limit = 50): Collection
    {
        if ($agencyId <= 0) {
            return collect();
        }

        return RtsContactOutcome::query()->with('agent:id,name')
            ->where('agency_id', $agencyId)->where('track', $track)
            ->where('tracked_property_id', $trackedPropertyId)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
    }
}
