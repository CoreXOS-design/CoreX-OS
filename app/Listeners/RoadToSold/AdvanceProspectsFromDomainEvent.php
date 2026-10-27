<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Contracts\DomainEvent;
use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsProspect;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\Support\TrackedPropertyResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v3 - the events that move a prospect from stage to stage (road-to-sold.md 22.2 and 22.8).
 *
 * Registered on the DomainEvent interface beside CompleteTasksFromDomainEvent (task credit and stage movement are independent: neither
 * blocks the other). It never throws to the caller: a failure is logged and the user's save stands. Stages only move forward (a lost deal
 * is the one reset); the same event twice moves nothing; an event for a property the agent has no prospect on creates the prospect at
 * the event's stage - they did the work without the list - unless ANOTHER agent owns the property, in which case it is left alone.
 *
 * Nothing here is agency specific. Agencies whose Road to Sold is switched off, inactive agents and assistants are skipped.
 */
class AdvanceProspectsFromDomainEvent
{
    /** Events that can move a prospect. A cheap exit for everything else the system raises. */
    private const HANDLED = [
        \App\Events\Evaluation\EvaluationBooked::class,
        \App\Events\Evaluation\EvaluationCompleted::class,
        \App\Events\Presentation\PresentationPresented::class,
        \App\Events\Presentation\PresentationOutcomeRecorded::class,
        \App\Events\Document\DocumentFiledToProperty::class,
        \App\Events\Mandate\MandateSigned::class,
        \App\Events\Property\PropertyWentLiveOnPortal::class,
        \App\Events\Marketing\MarketingPostPublished::class,
        \App\Events\Marketing\SellerLinkCreated::class,
        \App\Events\Marketing\SellerLinkOpened::class,
        \App\Events\Contact\ContactContactedByAgent::class,
        \App\Events\Contact\ContactMatchCreated::class,
        \App\Events\Viewing\ViewingScheduled::class,
        \App\Events\Marketing\ViewingPackCreated::class,
        \App\Events\Viewing\ViewingHeld::class,
        \App\Events\Docuperfect\OtpSigned::class,
        \App\Events\Deal\DealCreated::class,
        \App\Events\Deal\DealStageAdvanced::class,
        \App\Events\Deal\DealClosed::class,
    ];

    /** presentation outcome => park reason key (a lost presentation is dead stock) */
    private const LOST_REASONS = [
        'lost_to_competitor'    => 'chose_other_agency',
        'lost_to_price_dispute' => 'price_unrealistic',
        'lost_to_no_decision'   => 'not_selling_now',
        'lost_to_no_response'   => 'cannot_be_reached',
    ];

    public function __construct(private readonly ProspectService $prospects)
    {
    }

    public function handle(DomainEvent $event): void
    {
        if (! in_array($event::class, self::HANDLED, true)) {
            return;
        }
        try {
            $this->apply($event);
        } catch (Throwable $e) {
            Log::warning('Road to Sold prospect movement swallowed an exception', ['event' => $event::class, 'message' => $e->getMessage()]);
        }
    }

    /** A portal lead arrived on a property (not a DomainEvent class: registered on its own). */
    public function handleLead(NewPortalLeadReceived $e): void
    {
        try {
            $lead = $e->portalLead;
            $property = $lead->listing_id ? Property::query()->withoutGlobalScopes()->find((int) $lead->listing_id) : null;
            if (! $property || ! $property->agent_id) {
                return;
            }
            $this->move((int) $lead->agency_id, [(int) $property->agent_id], [
                'stage' => S::LEAD, 'property_id' => (int) $property->id, 'event_class' => NewPortalLeadReceived::class,
                'subject' => ['App\\Models\\PortalLead', (int) $lead->id], 'actor' => null,
            ]);
        } catch (Throwable $ex) {
            Log::warning('Road to Sold lead movement swallowed an exception', ['message' => $ex->getMessage()]);
        }
    }

    // ── one event ────────────────────────────────────────────────────────

    private function apply(DomainEvent $event): void
    {
        $agencyId = (int) ($event->agencyId() ?: 0);
        if ($agencyId <= 0) {
            return;
        }
        $subject = $event->subject();
        $base = ['event_class' => $event::class, 'subject' => $subject, 'actor' => $event->actorUserId()];
        $propertyAgent = fn (?int $pid) => $pid ? (int) (Property::query()->withoutGlobalScopes()->whereKey($pid)->value('agent_id') ?: 0) : 0;

        switch ($event::class) {
            case \App\Events\Evaluation\EvaluationBooked::class:
                $this->move($agencyId, [(int) $event->userId], $base + [
                    'stage' => S::APPOINTMENT_SET, 'property_id' => $event->propertyId,
                    'next_at' => $event->startsAt ? Carbon::parse($event->startsAt)->startOfDay() : null, 'next_label' => 'Do the evaluation',
                ]);
                break;
            case \App\Events\Evaluation\EvaluationCompleted::class:
                $this->move($agencyId, [(int) $event->userId], $base + ['stage' => S::EVALUATION_DONE, 'property_id' => $event->propertyId]);
                break;
            case \App\Events\Presentation\PresentationPresented::class:
                $this->move($agencyId, [(int) $event->userId], $base + [
                    'stage' => S::PRESENTATION_GIVEN, 'property_id' => $event->propertyId, 'tracked_id' => $event->trackedPropertyId,
                ]);
                break;
            case \App\Events\Presentation\PresentationOutcomeRecorded::class:
                $p = DB::table('presentations')->where('id', $event->presentationId)->first(['created_by_user_id', 'property_id', 'tracked_property_id']);
                if (! $p || ! $p->created_by_user_id) {
                    break;
                }
                $outcome = (string) $event->outcome;
                if (in_array($outcome, ['still_pending', 'other'], true)) {
                    break;   // neither active nor dead (22.2)
                }
                $this->move($agencyId, [(int) $p->created_by_user_id], $base + [
                    'stage' => S::PRESENTATION_FEEDBACK, 'property_id' => $p->property_id, 'tracked_id' => $p->tracked_property_id,
                    'next_label' => str_starts_with($outcome, 'lost') ? null : 'Get the mandate signed',
                    'park' => self::LOST_REASONS[$outcome] ?? (str_starts_with($outcome, 'lost') ? 'other' : null),
                ]);
                break;
            case \App\Events\Document\DocumentFiledToProperty::class:
                $stage = ['mandate' => S::MANDATE_SIGNED, 'otp' => S::OFFER][strtolower((string) $event->documentTypeSlug)] ?? null;
                if ($stage) {
                    $agent = $propertyAgent((int) $event->propertyId) ?: (int) $event->actorUserId;
                    $this->move($agencyId, [$agent], $base + ['stage' => $stage, 'property_id' => (int) $event->propertyId]);
                }
                break;
            case \App\Events\Mandate\MandateSigned::class:
                $pid = $event->property?->getKey() ? (int) $event->property->getKey() : null;
                $agent = (int) ($event->userId ?: $propertyAgent($pid));
                $this->move($agencyId, [$agent], $base + ['stage' => S::MANDATE_SIGNED, 'property_id' => $pid]);
                break;
            case \App\Events\Property\PropertyWentLiveOnPortal::class:
                $this->move($agencyId, [(int) ($event->actorUserId ?: $event->property->agent_id)], $base + [
                    'stage' => S::LISTING_LIVE, 'property_id' => (int) $event->property->id,
                ]);
                break;
            case \App\Events\Marketing\MarketingPostPublished::class:
                $this->move($agencyId, [(int) $event->post->user_id], $base + ['stage' => S::ADVERTISING, 'property_id' => (int) $event->post->property_id]);
                break;
            case \App\Events\Marketing\SellerLinkCreated::class:
            case \App\Events\Marketing\SellerLinkOpened::class:
                $this->move($agencyId, [(int) $event->userId], $base + ['stage' => S::ADVERTISING, 'property_id' => (int) $event->propertyId]);
                break;
            case \App\Events\Contact\ContactContactedByAgent::class:
                $this->advanceLeadStage($agencyId, (int) ($event->actorUserId ?: 0), (int) $event->contact->id, S::LEAD, S::LEAD_CONTACTED, $base);
                break;
            case \App\Events\Contact\ContactMatchCreated::class:
                $this->advanceLeadStage($agencyId, (int) $event->userId, (int) $event->contactId, S::LEAD_CONTACTED, S::CORE_MATCH, $base);
                break;
            case \App\Events\Viewing\ViewingScheduled::class:
                $this->move($agencyId, [(int) $event->userId], $base + ['stage' => S::VIEWING_SCHEDULED, 'property_id' => $event->propertyId]);
                break;
            case \App\Events\Marketing\ViewingPackCreated::class:
                $pid = $event->calendarEventId ? DB::table('calendar_events')->where('id', $event->calendarEventId)->value('property_id') : null;
                $this->move($agencyId, [(int) $event->userId], $base + ['stage' => S::VIEWING_PACK, 'property_id' => $pid ? (int) $pid : null]);
                break;
            case \App\Events\Viewing\ViewingHeld::class:
                $this->move($agencyId, [(int) $event->userId], $base + ['stage' => S::VIEWING_FEEDBACK, 'property_id' => $event->propertyId]);
                break;
            case \App\Events\Docuperfect\OtpSigned::class:
                $this->move($agencyId, [(int) ($event->userId ?: $propertyAgent($event->propertyId))], $base + ['stage' => S::OFFER, 'property_id' => $event->propertyId]);
                break;
            case \App\Events\Deal\DealCreated::class:
                $this->move($agencyId, $this->dealAgents($event->deal), $base + ['stage' => S::DEAL, 'property_id' => $event->deal->property_id]);
                break;
            case \App\Events\Deal\DealStageAdvanced::class:
                if (strtoupper(substr((string) $event->toStage, 0, 1)) === 'R') {
                    $this->move($agencyId, $this->dealAgents($event->deal), $base + ['stage' => S::REGISTERED, 'property_id' => $event->deal->property_id]);
                }
                break;
            case \App\Events\Deal\DealClosed::class:
                if ($event->outcome === 'won') {
                    $this->move($agencyId, $this->dealAgents($event->deal), $base + ['stage' => S::REGISTERED, 'property_id' => $event->deal->property_id]);
                } else {
                    // the deal fell through: the property is on the market again (22.2)
                    $this->move($agencyId, $this->dealAgents($event->deal), $base + ['stage' => S::ADVERTISING, 'property_id' => $event->deal->property_id, 'back' => true]);
                }
                break;
        }
    }

    /** @return int[] every agent on the deal (listing and selling side) */
    private function dealAgents($deal): array
    {
        return array_values(array_unique(array_map(
            fn ($p) => (int) $p['user_id'],
            app(\App\Services\Activity\ParticipantResolver::class)->resolveForDealCommissionFinalised($deal)
        )));
    }

    /**
     * Prospects of this agent at $from whose property has a portal lead from this contact move on to $to. The lead's contact is the only
     * link between a buyer-side event and a property, so a contact with no lead on a prospect moves nothing.
     */
    private function advanceLeadStage(int $agencyId, int $agentId, int $contactId, string $from, string $to, array $base): void
    {
        if ($agencyId <= 0 || $agentId <= 0 || $contactId <= 0) {
            return;
        }
        $propertyIds = PortalLead::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('contact_id', $contactId)
            ->whereNotNull('listing_id')->pluck('listing_id')->map(fn ($v) => (int) $v)->all();
        if ($propertyIds === []) {
            return;
        }
        $agent = $this->validAgent($agentId, $agencyId);
        if (! $agent) {
            return;
        }
        $rows = RtsProspect::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', 'sales')
            ->owned()->where('user_id', (int) $agent->ownershipUserId())->where('stage', $from)->whereIn('property_id', $propertyIds)->get();
        foreach ($rows as $row) {
            $this->prospects->advance($row, $to, $agent, [
                'event_class' => $base['event_class'], 'subject_type' => $base['subject'][0] ?? null, 'subject_id' => isset($base['subject'][1]) ? (int) $base['subject'][1] : null,
            ]);
        }
    }

    // ── moving the prospect ──────────────────────────────────────────────

    /**
     * @param int[] $agentIds
     * @param array{stage:string,property_id?:?int,tracked_id?:?int,event_class?:string,subject?:?array,actor?:?int,next_at?:?Carbon,next_label?:?string,park?:?string,back?:bool} $d
     */
    private function move(int $agencyId, array $agentIds, array $d): void
    {
        foreach (array_values(array_unique(array_filter($agentIds))) as $agentId) {
            $agent = $this->validAgent((int) $agentId, $agencyId);
            if (! $agent) {
                continue;
            }
            $prospect = $this->prospectFor($agent, $agencyId, $d);
            if (! $prospect) {
                continue;
            }
            $actor = ! empty($d['actor']) ? User::query()->withoutGlobalScopes()->find((int) $d['actor']) : null;
            $source = [
                'event_class' => $d['event_class'] ?? null,
                'subject_type' => $d['subject'][0] ?? null,
                'subject_id' => isset($d['subject'][1]) ? (int) $d['subject'][1] : null,
                'next_at' => $d['next_at'] ?? null,
            ];
            if (array_key_exists('next_label', $d)) {
                $source['next_label'] = $d['next_label'];
            }
            $moved = $this->prospects->advance($prospect, $d['stage'], $actor, $source, (bool) ($d['back'] ?? false));
            if ($moved && ! empty($d['park'])) {
                $this->prospects->park($prospect->refresh(), (string) $d['park'], null, null, $actor);
            }
        }
    }

    /** The agent's prospect for the event's property: theirs if it exists, a new one if the property is free, null if another agent owns it. */
    private function prospectFor(User $agent, int $agencyId, array $d): ?RtsProspect
    {
        $propertyId = ! empty($d['property_id']) ? (int) $d['property_id'] : null;
        $trackedId = ! empty($d['tracked_id']) ? (int) $d['tracked_id'] : null;

        if (! $trackedId && $propertyId) {
            $trackedId = RtsProspect::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', 'sales')->owned()
                ->where('property_id', $propertyId)->value('tracked_property_id')
                ?: TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('promoted_to_property_id', $propertyId)->value('id');
            $trackedId = $trackedId ? (int) $trackedId : null;
        }
        if (! $trackedId && $propertyId) {
            $trackedId = app(TrackedPropertyResolver::class)->forProperty($propertyId, $agencyId, (int) $agent->id);
        }
        if (! $trackedId) {
            return null;
        }

        return $this->prospects->place($agent, $trackedId, 'sales', [
            'property_id' => $propertyId, 'selection' => 'event', 'stage' => S::FOUND,
        ]);
    }

    private function validAgent(int $id, int $agencyId): ?User
    {
        $agent = User::query()->withoutGlobalScopes()->find($id);
        if (! $agent || $agent->trashed() || (int) $agent->agency_id !== $agencyId || ! $agent->is_active || $agent->is_assistant) {
            return null;
        }
        $agency = Agency::query()->withoutGlobalScopes()->find($agencyId);

        return $agency && app(AgencyFeatureService::class)->enabled('road-to-sold', $agency) ? $agent : null;
    }
}
