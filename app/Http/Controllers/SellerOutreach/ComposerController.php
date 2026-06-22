<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Services\Map\MapProspectStatusService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
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

    public function show(Request $request, Contact $contact)
    {
        $agencyId = $this->ensureAgencyContext($request, $contact);

        $linkedProperties = $this->loadLinkedProperties($agencyId, $contact);

        $propertyId = $request->query('property_id');
        $property = $this->resolvePropertyForContact($propertyId, $linkedProperties);

        $channel = $request->query('channel', 'whatsapp');
        if (!in_array($channel, ['whatsapp', 'email'], true)) {
            $channel = 'whatsapp';
        }

        $availableTemplates = SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('channel', $channel)
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
        if ($property || $addressOnly) {
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
        $propertyStatuses = $this->resolveStatusesForPicker(
            $linkedProperties,
            $agencyId,
            (int) $request->user()->id,
        );

        return view('seller-outreach.compose', [
            'contact'            => $contact,
            'property'           => $property,
            'linkedProperties'   => $linkedProperties,
            'channel'            => $channel,
            'availableTemplates' => $availableTemplates,
            'context'            => $context,
            'agencyId'           => $agencyId,
            'propertyStatuses'   => $propertyStatuses,
            'addressOnly'        => $addressOnly,
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

    public function submit(Request $request, Contact $contact)
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
        } elseif (!$contact->hasStructuredAddress()) {
            $msg = 'Cannot send: this contact has no linked property and no captured address to pitch.';
            return $request->wantsJson()
                ? response()->json(['message' => $msg], 422)
                : back()->with('error', $msg);
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

        if ($request->wantsJson()) {
            return response()->json([
                'send_id'             => $send->id,
                'tracking_short_code' => $send->tracking_short_code,
                'client_url'          => $clientUrl,
                'message'             => $validated['channel'] === 'whatsapp'
                    ? 'Pitch recorded — opening WhatsApp.'
                    : 'Pitch recorded — branded email sent.',
            ]);
        }

        return redirect()
            ->route('seller-outreach.composer.sent', ['contact' => $contact->id, 'send' => $send->id])
            ->with('client_url', $clientUrl);
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

        return view('seller-outreach.sent', [
            'contact'   => $contact,
            'send'      => $sendModel,
            'clientUrl' => $clientUrl,
        ]);
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
