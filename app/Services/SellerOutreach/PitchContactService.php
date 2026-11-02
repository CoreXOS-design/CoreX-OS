<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\OwnerStationService;
use App\Services\RoadToSold\RoadToSoldException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * road-to-sold.md 23.23 drop 3: the Contact step of the pitch popup writes ONE contact record, the same `rts_contact_outcomes` row the belt's
 * "Log contact" writes (23.7a), for all three ways of making contact:
 *
 *   - a WhatsApp or e-mail pitch that really went out -> an ATTEMPT ("No answer" / "No reply", dated by the outcome's own rule) on the channel, so a
 *     sent pitch is never invisible to the belt (23.7.2). WhatsApp is recorded when the agent confirms it went (AT-323), e-mail when the system sent it;
 *   - a phone call -> the outcome the agent picks, through the Road to Sold outcome route (channel `phone`): this class only prepares what the form needs.
 *
 * Nothing here is a second way to record contact: it calls OutcomeService::record(). A send is never undone or blocked because the record could not be
 * written (the agent did send it): the reason comes back as text for the screen.
 */
final class PitchContactService
{
    public function __construct(private readonly OutcomeService $outcomes, private readonly OutcomeDefinitionService $definitions, private readonly OwnerStationService $owner)
    {
    }

    /** The phone tab needs the Road to Sold outcome list: the agency has the feature and the person may use Road to Sold. */
    public function phoneTabAvailable(?User $user): bool
    {
        return $user !== null && $user->hasFeature('road-to-sold') && $user->hasPermission('access_road_to_sold') && RtsContactOutcome::hasChannelColumn();
    }

    /**
     * What the Phone call form shows for this property. `trackedPropertyId` null = nothing on the belt to log against (address-only pitch).
     *
     * @return array{tracked_property_id:?int, refusal:?string, groups:list<array{key:string,title:string,items:list<array<string,mixed>>}>, phone:?string, now:string, book_url:?string}
     */
    public function phoneForm(User $actor, Contact $contact, ?Property $property, array $carry = []): array
    {
        $tp = $property && $property->tracked_property_id ? (int) $property->tracked_property_id : null;
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        $titles = [Def::GROUP_FORWARD => 'Moves it forward', Def::GROUP_AGAIN => 'Try again later', Def::GROUP_OFF_RAMP => 'Off-ramps - why it stops here'];
        $groups = [];
        foreach ($titles as $key => $title) {
            $groups[$key] = ['key' => $key, 'title' => $title, 'items' => []];
        }
        foreach ($this->definitions->list($agencyId, 'sales') as $d) {
            if (in_array($d->key, OutcomeDefinitionService::AFTER_EVALUATION_KEYS, true)) {
                continue;
            }
            $groups[$d->group()]['items'][] = [
                'id' => (int) $d->id, 'label' => $d->labelFor('phone'), 'kind' => $d->kind, 'group' => $d->group(), 'summary' => $d->nextActionSummary(),
                'needs_date' => in_array($d->next_action, [Def::ACTION_PICKED_DATE, Def::ACTION_EXPIRY_ELSE], true),
                'needs_appointment' => $d->next_action === Def::ACTION_APPOINTMENT,
                'offers_valuation' => $d->kind === Def::KIND_CONVERSATION && ! in_array($d->next_action, [Def::ACTION_APPOINTMENT, Def::ACTION_NEVER_AGAIN], true),
                'owner_exempt' => $this->owner->exempt($d),
            ];
        }
        $phone = trim((string) ($contact->phone ?: ($contact->cell_number ?? '')));

        return [
            'tracked_property_id' => $tp,
            'refusal' => $tp ? $this->owner->refusal($actor, $tp) : null,
            'groups' => array_values(array_filter($groups, fn ($g) => $g['items'] !== [])),
            'phone' => $phone !== '' ? $phone : null,
            'now' => now()->format('Y-m-d\TH:i'),
            // the Book evaluation step (23.23 drop 4): the calendar's own form, prefilled with this property and this seller
            'book_url' => $property ? app(PitchBookService::class)->url($property, $contact, $carry) : null,
        ];
    }

    /**
     * Record that a pitch really went out. Returns ['logged' => bool, 'message' => ?string]; never throws, never blocks the send.
     * Idempotent per send (the agent confirming twice, or the page being re-opened, writes once).
     *
     * @return array{logged:bool, message:?string}
     */
    public function recordSend(User $actor, SellerOutreachSend $send, ?Property $property): array
    {
        if (! $this->phoneTabAvailable($actor)) {
            return ['logged' => false, 'message' => null];   // the agency is not on Road to Sold: nothing to record against
        }
        $tp = $property && $property->tracked_property_id ? (int) $property->tracked_property_id : null;
        if (! $tp) {
            return ['logged' => false, 'message' => null];   // an address-only pitch has no property on the belt
        }
        $channel = $send->channel === 'whatsapp' ? 'whatsapp' : 'email';
        $marker = '(pitch send #' . (int) $send->id . ')';

        try {
            if (RtsContactOutcome::query()->where('tracked_property_id', $tp)->where('note', 'like', '%' . $marker . '%')->exists()) {
                return ['logged' => true, 'message' => null];
            }
            $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
            $def = Def::query()->where('agency_id', $agencyId)->where('track', 'sales')->where('is_active', true)->where('key', 'no_answer')->first();
            if (! $def) {
                return ['logged' => false, 'message' => 'The pitch went out, but this agency has no "No answer" outcome to record it against.'];
            }
            $this->outcomes->record($actor, $tp, (int) $def->id, [
                'channel' => $channel,
                'note' => ($channel === 'whatsapp' ? 'WhatsApp pitch sent ' : 'Email pitch sent ') . $marker,
                'prospecting_listing_id' => null,
                'property_id' => $property->id,
            ], 'sales');

            return ['logged' => true, 'message' => null];
        } catch (RoadToSoldException $e) {
            return ['logged' => false, 'message' => 'The pitch went out, but it could not be logged as a contact: ' . $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            Log::warning('Pitch send not logged as a contact', ['send_id' => $send->id, 'error' => $e->getMessage()]);

            return ['logged' => false, 'message' => 'The pitch went out, but it could not be logged as a contact.'];
        }
    }
}
