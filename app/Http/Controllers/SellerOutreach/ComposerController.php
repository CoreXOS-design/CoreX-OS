<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\Outreach\OutreachQueue;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Services\Map\MapProspectStatusService;
use App\Services\Outreach\OutreachWindowService;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Agent-facing pitch composer.
 *
 * Hard blocks at the controller (never bypassable):
 *  - opt-out (messaging_opt_out_at on contact)
 *  - validation issues (no phone for WhatsApp, no email for Email, no tracking_link in body)
 *
 * Soft signal (agent overrides freely):
 *  - cooldown (recent send to the same contact)
 *
 * Spec: .ai/specs/seller-outreach-spec.md S6, S9, S10, 6.1.
 */
final class ComposerController extends Controller
{
    public function __construct(
        private readonly SellerOutreachComposerService $composer,
        private readonly SellerOutreachSenderService $sender,
        private readonly MapProspectStatusService $prospectStatus = new MapProspectStatusService(),
    ) {}

    /**
     * The owner gate (road-to-sold.md 23.6) for a pitch that is a Road to Sold step; null = may send. It fires ONLY when the pitch was opened from the agent's
     * own Road to Sold record (the popup names it as `rts_prospect`). Opened from the property page, a contact, MIC, a CMA import or by hand, nothing is gated -
     * "Road to Sold can only block Road to Sold processes, not the rest" (Johan, 5 Nov 2026). When it does fire it carries the link to the Find owner spot.
     *
     * @return array{message:string,url:string}|null
     */
    private function ownerGate(Request $request, ?Property $property): ?array
    {
        $user = $request->user();
        $prospect = app(\App\Services\SellerOutreach\PitchBeltResolver::class)->recordOpenedFrom($user, $property, (int) $request->input('rts_prospect') ?: null);
        if (! $prospect) {
            return null;
        }
        $gate = app(\App\Services\RoadToSold\OwnerStationService::class);
        $message = $gate->refusal($user, (int) $prospect->tracked_property_id);
        if ($message === null) {
            return null;
        }
        $missing = $gate->missing((int) $prospect->agency_id, (int) $prospect->tracked_property_id);

        return [
            'message' => $message . ($missing !== [] ? ' Still needed: ' . implode(' and ', $missing) . '.' : ''),
            'url'     => app(\App\Services\RoadToSold\PitchPathService::class)->next($prospect, $user, app(\App\Services\RoadToSold\PitchPathService::class)->carry($request))['url'],
        ];
    }

    public function show(Request $request, Contact $contact)
    {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $linkedProperties = $this->loadLinkedProperties($agencyId, $contact);

        // Road to Sold (road-to-sold.md 22.10 step 2): opened from a prospect's row on a contact with no property, the property is made from
        // the MIC address now, so the agent never lands on "No property or address to pitch about". If none can be made, the address capture.
        if ($linkedProperties->isEmpty() && $request->filled('rts_prospect')) {
            $pitchPath = app(\App\Services\RoadToSold\PitchPathService::class);
            $prospect = $pitchPath->prospectFor($request, $request->user());
            if ($prospect && ! $contact->hasStructuredAddress()) {
                if ($pitchPath->ready($prospect, $contact, $request->user())) {
                    $linkedProperties = $this->loadLinkedProperties($agencyId, $contact);
                } else {
                    return redirect()->to($pitchPath->next($prospect, $request->user(), $pitchPath->carry($request))['url'])
                        ->with('error', 'Add the property address first - there is nothing to pitch about yet.');
                }
            }
        }

        // Other Agency Stock can never be pitched (spec other-agency-stock.md §5c): refuse a
        // direct address to it, and keep it out of the picker / the "first linked" default.
        // A contact may stay linked to it — that link is allowed, only the pitch is not.
        $propertyId = $request->query('property_id');
        if ($propertyId) {
            \App\Services\Properties\OtherAgencyStockActionRules::assertAllowed('pitch_seller', $linkedProperties->firstWhere('id', (int) $propertyId));
        }
        $linkedProperties = $linkedProperties
            ->reject(fn (Property $p) => \App\Services\Properties\OtherAgencyStockActionRules::isBlocked('pitch_seller', $p))
            ->values();

        $property = $this->resolvePropertyForContact($propertyId, $linkedProperties);

        $contactRecord = app(\App\Services\SellerOutreach\PitchContactService::class);
        $phoneTab = $contactRecord->phoneTabAvailable($request->user());
        $channel = $request->query('channel', 'whatsapp');
        if (!in_array($channel, ['whatsapp', 'email'], true) && ! ($channel === 'phone' && $phoneTab)) {
            $channel = 'whatsapp';
        }

        // The Phone call tab (road-to-sold.md 23.23): no template, no composed message - the call has already happened, the agent says how it went.
        $phoneCall = $channel === 'phone' ? $contactRecord->phoneForm($request->user(), $contact, $property, app(\App\Services\RoadToSold\PitchPathService::class)->carry($request)) : null;
        // the popup's step 5: known as soon as the composer is open, so its pill works before any call is logged
        $bookUrl = $phoneTab && $property ? app(\App\Services\SellerOutreach\PitchBookService::class)->url($property, $contact, app(\App\Services\RoadToSold\PitchPathService::class)->carry($request)) : null;

        $availableTemplates = SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('channel', $channel === 'phone' ? 'whatsapp' : $channel)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default_for_channel')
            ->orderBy('name')
            ->get();

        // ctype_digit() guards against URL-injection like ?template_id=DROP%20TABLE
        // before any int coercion. composeContext()'s typed `?int` param rejects
        // strings — confirmed in the 2026-05-14 hotfix.
        $rawTemplateId = $request->query('template_id');
        $templateId = (!empty($rawTemplateId) && ctype_digit((string) $rawTemplateId))
            ? (int) $rawTemplateId
            : null;
        if ($templateId && !$availableTemplates->firstWhere('id', $templateId)) {
            $templateId = null;
        }

        $bodyOverride = $request->query('body');
        $subjectOverride = $request->query('subject');

        // AT-61 — address-only mode: when the contact has a captured structured
        // address (AT-60) but NO linked property, compose the pitch off that
        // address instead of dead-ending. Precedence: a linked property always
        // wins (richer source; enables the per-property matching claim). Only
        // fall to address-only when there is genuinely no property to pitch.
        $addressOnly = $property === null
            && $linkedProperties->isEmpty()
            && $contact->hasStructuredAddress();

        // Compose context when a property is selected OR in address-only mode.
        $context = null;
        if (($property || $addressOnly) && $channel !== 'phone') {
            $context = $this->composer->composeContext(
                agencyId:        $agencyId,
                contact:         $contact,
                property:        $property, // null in address-only mode
                channel:         $channel,
                templateId:      $templateId,
                agent:           $request->user(),
                bodyOverride:    $bodyOverride,
                subjectOverride: $subjectOverride,
            );
        }

        // A.3.4 — resolve prospect status for each linked property so the
        // picker can surface collision badges. ≤2 linked properties per
        // contact (per loadLinkedProperties() comment), so calling resolve()
        // per row is cheap — no batching layer needed.
        // (the picker, which shows them, is not in the pitch popup: skip the address-matching lookups there - they cost about a third of a second)
        $propertyStatuses = \App\Support\Embed::on() ? [] : $this->resolveStatusesForPicker(
            $linkedProperties,
            $agencyId,
            (int) $request->user()->id,
        );

        // inside the pitch popup the address is the advert's, on every step (23.30); the property's own (deed) wording shows once, under the popup header
        $advertAddress = null;
        if (\App\Support\Embed::on() && $request->filled('rts_prospect')) {
            $rts = \App\Models\RoadToSold\RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('user_id', (int) $request->user()->ownershipUserId())->find((int) $request->query('rts_prospect'));
            $advertAddress = $rts ? \App\Services\SellerOutreach\AdvertAddress::forProspect($rts) : null;
        }

        return view('seller-outreach.compose', [
            'contact'            => $contact,
            'advertAddress'      => $advertAddress,
            'property'           => $property,
            'linkedProperties'   => $linkedProperties,
            'channel'            => $channel,
            'availableTemplates' => $availableTemplates,
            'context'            => $context,
            'agencyId'           => $agencyId,
            'propertyStatuses'   => $propertyStatuses,
            'addressOnly'        => $addressOnly,
            'phoneTab'           => $phoneTab,
            'phoneCall'          => $phoneCall,
            'bookUrl'            => $bookUrl,
        ]);
    }

    /**
     * A.3.4 — batch-resolve prospect status for the picker's properties.
     * Returns [property_id => statusArray] so the view can keyed-lookup.
     */
    private function resolveStatusesForPicker($linkedProperties, int $agencyId, int $userId): array
    {
        $out = [];
        foreach ($linkedProperties as $p) {
            $facts = [
                'address'   => $p->address ?? null,
                'latitude'  => $p->latitude !== null ? (float) $p->latitude : null,
                'longitude' => $p->longitude !== null ? (float) $p->longitude : null,
                'suburb'    => $p->suburb ?? null,
            ];
            $out[(int) $p->id] = $this->prospectStatus->resolve($facts, $agencyId, $userId);
        }
        return $out;
    }

    public function submit(Request $request, Contact $contact, \App\Services\Outreach\OutreachWindowService $window)
    {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $validated = $request->validate([
            // AT-61 — property_id is now optional. Absent ⇒ address-only send
            // (pitch composed off the contact's captured structured address).
            'property_id'  => 'nullable|integer',
            'channel'      => 'required|in:whatsapp,email',
            'template_id'  => 'nullable|integer',
            'subject'      => 'nullable|string|max:255',
            'body'         => 'required|string',
        ]);

        // Property mode when a property_id is supplied (firstOrFail keeps the
        // agency/soft-delete guard). Address-only mode when it is absent — but
        // only if the contact actually has a captured structured address;
        // otherwise there is nothing to pitch and we block (neither case).
        $property = null;
        if (!empty($validated['property_id'])) {
            $property = Property::withoutGlobalScopes()
                ->where('id', $validated['property_id'])
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->firstOrFail();
            \App\Services\Properties\OtherAgencyStockActionRules::assertAllowed('pitch_seller', $property);
        } elseif (!$contact->hasStructuredAddress()) {
            $msg = 'Cannot send: this contact has no linked property and no captured address to pitch.';
            return $request->wantsJson()
                ? response()->json(['message' => $msg], 422)
                : back()->with('error', $msg);
        }

        // v4 gate (road-to-sold.md 23.6): no send for a belt property whose owner is not found yet.
        if ($gate = $this->ownerGate($request, $property)) {
            return $request->wantsJson()
                ? response()->json(['message' => $gate['message'], 'find_owner' => true, 'find_owner_url' => $gate['url']], 422)
                : back()->with('error', $gate['message'])->with('find_owner_url', $gate['url']);
        }

        // Laravel's `nullable|integer` validates but does NOT cast — the value
        // is still a string from the form payload. composeContext() declares
        // `?int $templateId`, so PHP TypeErrors at the call site. Explicit
        // (int) cast at the boundary. Hotfix 2026-05-14.
        $templateId = !empty($validated['template_id']) ? (int) $validated['template_id'] : null;

        $context = $this->composer->composeContext(
            agencyId:        $agencyId,
            contact:         $contact,
            property:        $property,
            channel:         $validated['channel'],
            templateId:      $templateId,
            agent:           $request->user(),
            bodyOverride:    $validated['body'],
            subjectOverride: $validated['subject'] ?? null,
        );

        // Hard block: opt-out
        if ($context->optOutBlocks) {
            $msg = 'Contact has opted out of messaging — cannot send.';
            return $request->wantsJson()
                ? response()->json(['message' => $msg], 422)
                : back()->with('error', $msg);
        }

        // AT-81 — hard block while a consent-request is awaiting a reply. Not an
        // opt-out: explain why and offer the way forward (No Silent Locks).
        if ($context->pendingBlocks) {
            $msg = 'A consent request was already sent to this contact and is awaiting their reply. '
                . 'You can send again once they respond, or after the no-response window lapses them.';
            return $request->wantsJson()
                ? response()->json(['message' => $msg], 422)
                : back()->with('error', $msg);
        }

        // Hard block: validation issues (no phone, no email, missing tracking_link, etc.)
        if (!empty($context->validationIssues)) {
            $msg = 'Cannot send: ' . implode(' ', $context->validationIssues);
            return $request->wantsJson()
                ? response()->json(['message' => $msg], 422)
                : back()->with('error', $msg);
        }

        // AT-117 §4a — send-window lock (server-side enforcement; the UI also gates
        // the button). Refuse the dispatch out-of-window BEFORE any send row is
        // created, so a created send is always in-window — its sent_at is the
        // agency's in-window dispatch evidence.
        $agency = \App\Models\Agency::find($agencyId);
        if ($agency && !$window->isSendAllowed($agency)) {
            $msg = $window->blockedMessage($agency);
            return $request->wantsJson()
                ? response()->json([
                    'message'             => $msg,
                    'send_window_blocked' => true,
                    'next_opens_at'       => optional($window->nextOpensAt($agency))->toIso8601String(),
                ], 422)
                : back()->with('error', $msg);
        }

        $send = $this->sender->send($context);

        // Auto-claim on pitch submit: if the send corresponds to a prospecting
        // listing (via property_id → prospecting_listings.matched_property_id),
        // upgrade the agent's temp lock to a permanent claim with a structured
        // note. Idempotent — existing claim by same agent gets the note appended.
        //
        // AT-61 — skip entirely for address-only sends (no property_id): there
        // is no matched property to map a prospecting listing to, and a NULL
        // lookup would falsely match listings with a NULL matched_property_id.
        $prospectingListing = $send->property_id !== null
            ? \Illuminate\Support\Facades\DB::table('prospecting_listings')
                ->where('matched_property_id', $send->property_id)
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->first(['id'])
            : null;

        if ($prospectingListing) {
            try {
                app(\App\Services\Prospecting\ProspectingClaimService::class)
                    ->consumeLockAsPermanentClaim(
                        listingId: (int) $prospectingListing->id,
                        userId: (int) $request->user()->id,
                        agencyId: $agencyId,
                        pitchContext: [
                            'sent_at'        => $send->sent_at,
                            'channel'        => $send->channel,
                            'recipient_name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
                            'template_name'  => $context->template?->name ?? null,
                        ],
                    );
            } catch (\App\Services\Prospecting\ClaimOwnershipConflictException $e) {
                // Defence in depth — the temp lock should have prevented this.
                // The send is real; just log the anomaly so anyone can audit.
                \Illuminate\Support\Facades\Log::warning('Pitch submitted while listing claimed by another agent', [
                    'send_id'                  => $send->id,
                    'prospecting_listing_id'   => $prospectingListing->id,
                    'submitting_user_id'       => $request->user()->id,
                    'existing_claim_user_id'   => $e->currentOwnerUserId,
                ]);
            }
        }

        // WhatsApp opens the agent's app (wa.me); Email is SENT by the system as a
        // branded HTML email (SellerOutreachSenderService::send), so there is no
        // client URL to open for email — avoids a mailto double-send.
        $clientUrl = $validated['channel'] === 'whatsapp'
            ? $this->sender->whatsappUrl($send)
            : null;

        // The contact record (road-to-sold.md 23.23 / 23.7a): an e-mail is sent by CoreX itself, so it is a contact now; a WhatsApp is recorded when the
        // agent confirms it went (markSent), because CoreX cannot tell.
        $logged = $validated['channel'] === 'email'
            ? app(\App\Services\SellerOutreach\PitchContactService::class)->recordSend($request->user(), $send, $property)
            : ['logged' => false, 'message' => null];

        if ($logged['message']) {
            session()->flash('contact_message', $logged['message']);   // shown on the sent page the agent is sent to next
        }

        if ($request->wantsJson()) {
            return response()->json([
                'send_id'             => $send->id,
                'tracking_short_code' => $send->tracking_short_code,
                'client_url'          => $clientUrl,
                'contact_logged'      => $logged['logged'],
                'contact_message'     => $logged['message'],
                'message'             => $validated['channel'] === 'whatsapp'
                    ? 'Pitch recorded — opening WhatsApp.'
                    : 'Pitch recorded — branded email sent.',
            ]);
        }

        return redirect()
            ->route('seller-outreach.composer.sent', ['contact' => $contact->id, 'send' => $send->id])
            ->with('client_url', $clientUrl);
    }

    /**
     * AT-117 §4 — add the prepared pitch to the deferred outreach queue instead of
     * sending now. Reuses the SAME render the send path uses (composeContext →
     * renderedBody); opt-out/tracking tokens are left literal so they resolve fresh
     * at dispatch (§4b), never frozen at queue time. due_at is constrained to the
     * agency send-window SERVER-SIDE; consent is checked at queue time (canMarketTo)
     * and re-checked at surface by the §5 sweep.
     */
    public function queue(Request $request, Contact $contact, \App\Services\Outreach\OutreachQueueService $queueService)
    {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $validated = $request->validate([
            'property_id' => 'nullable|integer',
            'channel'     => 'required|in:whatsapp,email',
            'template_id' => 'nullable|integer',
            'subject'     => 'nullable|string|max:255',
            'body'        => 'required|string',
        ]);

        // Property resolution — identical guard to submit().
        $property = null;
        if (!empty($validated['property_id'])) {
            $property = Property::withoutGlobalScopes()
                ->where('id', $validated['property_id'])
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->firstOrFail();
            \App\Services\Properties\OtherAgencyStockActionRules::assertAllowed('pitch_seller', $property);
        } elseif (!$contact->hasStructuredAddress()) {
            return $this->queueError($request, 'Cannot queue: this contact has no linked property and no captured address to pitch.');
        }

        if ($gate = $this->ownerGate($request, $property)) {
            return $this->queueError($request, $gate['message'], ['find_owner' => true, 'find_owner_url' => $gate['url']]);
        }

        $templateId = !empty($validated['template_id']) ? (int) $validated['template_id'] : null;

        // SAME render the send path uses — no parallel rendering. renderedBody keeps
        // {opt_out_link}/{tracking_link} literal → resolved fresh at dispatch (§4b).
        $context = $this->composer->composeContext(
            agencyId:        $agencyId,
            contact:         $contact,
            property:        $property,
            channel:         $validated['channel'],
            templateId:      $templateId,
            agent:           $request->user(),
            bodyOverride:    $validated['body'],
            subjectOverride: $validated['subject'] ?? null,
        );

        // Reachability (no phone / no email / missing tracking link) — same hard
        // block as send-now: don't queue something that can never be dispatched.
        if (!empty($context->validationIssues)) {
            return $this->queueError($request, 'Cannot queue: ' . implode(' ', $context->validationIssues));
        }

        // Canonical enqueue (consent + reachability + create READY) — shared with MIC.
        $res = $queueService->enqueue(
            Agency::find($agencyId), $contact, $request->user(), $validated['channel'],
            OutreachQueue::SOURCE_CONTACT, $context->renderedBody, $property
        );
        if (!$res['ok']) {
            return $this->queueError($request, $res['message'], $res['extra'] ?? []);
        }

        return $request->wantsJson()
            ? response()->json(['queued' => true, 'queue_id' => $res['row']->id, 'message' => $res['message']])
            : redirect()->route('seller-outreach.composer.show', ['contact' => $contact->id])->with('success', $res['message']);
    }

    /** Uniform queue-error response (JSON 422 for the Alpine flow, flash for non-JSON). */
    private function queueError(Request $request, string $msg, array $extra = [])
    {
        return $request->wantsJson()
            ? response()->json(array_merge(['queued' => false, 'message' => $msg], $extra), 422)
            : back()->with('error', $msg);
    }

    public function sent(Request $request, Contact $contact, int $send)
    {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $sendModel = SellerOutreachSend::withoutGlobalScopes()
            ->where('id', $send)
            ->where('agency_id', $agencyId)
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Email is system-sent (branded HTML); only WhatsApp has a client URL.
        $clientUrl = $sendModel->channel === 'whatsapp'
            ? $this->sender->whatsappUrl($sendModel)
            : null;

        $sentProperty = $sendModel->property_id
            ? Property::withoutGlobalScopes()->where('agency_id', $agencyId)->find((int) $sendModel->property_id)
            : null;

        return view('seller-outreach.sent', [
            'contact'   => $contact,
            'send'      => $sendModel,
            'clientUrl' => $clientUrl,
            // what the pitch popup says when the agent presses Done ("Pitched · 73 Marine Drive")
            'address'   => $sentProperty ? (trim((string) $sentProperty->composeAddressFromParts()) ?: (string) $sentProperty->address) : '',
        ]);
    }

    /**
     * AT-323 — the agent confirms on the sent page that WhatsApp did NOT actually go
     * out. Records the honest terminal state: this pitch's outcome becomes not_sent,
     * and the SAME truth is mirrored to the linked provisional Communication
     * (send_status -> not_delivered) so the contact comms tiles stop counting it as a
     * reached send. "Yes, I sent it" needs no server call — the row is already sent.
     */
    public function markNotSent(
        Request $request,
        Contact $contact,
        int $send,
        \App\Services\Communications\CommunicationSendStatusService $sendStatus
    ) {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $sendModel = SellerOutreachSend::withoutGlobalScopes()
            ->where('id', $send)
            ->where('agency_id', $agencyId)
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $sendModel->forceFill([
            'outcome'                => SellerOutreachSend::OUTCOME_NOT_SENT,
            'outcome_note'           => 'Agent confirmed on the sent page: WhatsApp did not send.',
            'outcome_set_by_user_id' => (int) $request->user()->id,
            'outcome_set_at'         => now(),
        ])->save();

        // Mirror the SAME truth to the linked comms-archive row via the canonical
        // not-delivered path (fires CommunicationMarkedNotDelivered; excludes it from
        // the tile counts + last-contacted). Idempotent — skip if already flagged.
        if ($sendModel->communication_id) {
            $comm = \App\Models\Communications\Communication::withoutGlobalScopes()
                ->find($sendModel->communication_id);
            if ($comm && $comm->send_status !== \App\Models\Communications\Communication::SEND_STATUS_NOT_DELIVERED) {
                $sendStatus->markNotDelivered(
                    $comm, $contact, (int) $request->user()->id,
                    'Outreach pitch — agent confirmed WhatsApp did not send (AT-323).'
                );
            }
        }

        // AT-323 regression fix — the send() call stamped the AT-81 "pending / awaiting
        // reply" latch (markOutreachPending → outreach_permission_asked_at) at pitch time,
        // which hard-blocks re-sending (isOutreachPending → pendingBlocks → not sendable).
        // A pitch that never actually went out must not freeze the contact: if this
        // contact now has NO genuinely-sent outreach (every send is not_sent), roll the
        // latch back so the agent can re-send immediately. A prior GENUINE send still
        // keeps the contact pending (we only clear when nothing real ever went out).
        $hasGenuineSend = SellerOutreachSend::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->where('outcome', '!=', SellerOutreachSend::OUTCOME_NOT_SENT)
            ->exists();
        if (! $hasGenuineSend) {
            $contact->clearOutreachPending();
        }

        return $request->wantsJson()
            ? response()->json([
                'ok'      => true,
                'outcome' => $sendModel->outcome,
                'message' => 'Recorded as not sent — this pitch is not counted as sent.',
            ])
            : back()->with('success', 'Recorded as not sent — this pitch is not counted as sent.');
    }

    /**
     * AT-323 — "Yes, I sent it" on the pitch sent page. Promotes the mirrored
     * Communication (the "WhatsApp messages sent" counter) to sent — the ONLY path a
     * pitch counts as a sent message. The SellerOutreachSend.outcome (the prospecting /
     * pitched signal read by MIC) is deliberately left untouched here.
     */
    public function markSent(
        Request $request,
        Contact $contact,
        int $send,
        \App\Services\Communications\CommunicationSendStatusService $sendStatus
    ) {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $sendModel = SellerOutreachSend::withoutGlobalScopes()
            ->where('id', $send)
            ->where('agency_id', $agencyId)
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        if ($sendModel->communication_id) {
            $comm = \App\Models\Communications\Communication::withoutGlobalScopes()
                ->find($sendModel->communication_id);
            if ($comm && $comm->send_status !== \App\Models\Communications\Communication::SEND_STATUS_SENT) {
                $sendStatus->markSent($comm, $contact, (int) $request->user()->id);
            }
        }

        // the agent says the WhatsApp went: that is the contact (road-to-sold.md 23.23)
        $property = $sendModel->property_id
            ? Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $sendModel->property_id)
            : null;
        $logged = app(\App\Services\SellerOutreach\PitchContactService::class)->recordSend($request->user(), $sendModel, $property);

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'message' => 'Recorded as sent.', 'contact_logged' => $logged['logged'], 'contact_message' => $logged['message']])
            : back()->with('success', 'Recorded as sent.');
    }

    private function ensureAgencyContext(Request $request, Contact $contact): int
    {
        $user = $request->user();
        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($agencyId === null, 403, 'No agency context — super_admin without an active agency cannot compose pitches.');
        if ((int) $contact->agency_id !== (int) $agencyId) {
            abort(404);
        }
        return (int) $agencyId;
    }

    private function loadLinkedProperties(int $agencyId, Contact $contact)
    {
        // Per pre-flight: contacts ↔ properties via the contact_property pivot
        // (BelongsToMany). HFC has a max of 2 linked properties per contact;
        // a dropdown is fine — no typeahead needed.
        return $contact->properties()
            ->withoutGlobalScopes()
            ->where('properties.agency_id', $agencyId)
            ->whereNull('properties.deleted_at')
            ->orderByDesc('properties.created_at')
            ->get();
    }

    private function resolvePropertyForContact($propertyId, $linkedProperties): ?Property
    {
        if ($propertyId) {
            return $linkedProperties->firstWhere('id', (int) $propertyId);
        }
        return $linkedProperties->first();
    }
}
