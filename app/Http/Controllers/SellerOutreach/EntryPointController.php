<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Events\Map\MapProspectLaunched;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Property;
use App\Models\PropertyNote;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Map\MapProspectStatusService;
use App\Services\Prospecting\PitchLockConflictException;
use App\Services\Prospecting\ProspectingClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Entry-point controller for the seller-outreach composer.
 *
 * Three entry surfaces:
 *  - Property pillar: pick a seller-role contact linked to the property.
 *  - Prospecting listing: capture/dedupe a contact, promote the listing
 *    to a Property, then redirect to the composer.
 *
 * (Contact pillar entry is a direct link to the composer — no controller
 * action needed.)
 *
 * Spec: .ai/specs/seller-outreach-spec.md S1, S3.
 */
final class EntryPointController extends Controller
{
    /** Pivot roles considered "seller-side" for outreach. */
    private const SELLER_ROLES = ['seller', 'owner', 'landlord', 'lessor'];

    public function __construct(
        private readonly MapProspectStatusService $prospectStatus = new MapProspectStatusService(),
    ) {}

    public function fromProperty(Request $request, Property $property)
    {
        $agencyId = $this->resolveAgencyId($request);
        if ((int) $property->agency_id !== $agencyId) {
            abort(404);
        }

        $sellers = $this->loadSellerContactsForProperty($agencyId, $property);

        // #2 In-stock row with no seller linked: DON'T dead-end on the property
        // page. Route straight into seller capture for this existing property so
        // the agent can pitch in one flow (capture/dedupe seller → link → composer).
        if ($sellers->isEmpty()) {
            return view('seller-outreach.entry.prospecting-create-contact', [
                'property' => $property,
            ]);
        }

        if ($sellers->count() === 1) {
            return redirect()->route('seller-outreach.composer.show', [
                'contact'     => $sellers->first()->id,
                'property_id' => $property->id,
            ]);
        }

        return view('seller-outreach.entry.property-seller-chooser', [
            'property' => $property,
            'sellers'  => $sellers,
        ]);
    }

    /**
     * #2 — Capture (or link) a seller contact for an EXISTING in-stock property
     * that had no seller, then open the composer. Mirrors the contact half of
     * storeFromProspecting (search-or-create + blocking duplicate gate + POPIA
     * ID capture), but the property already exists so there is no promotion,
     * TrackedProperty match, or pitch-claim here (claim is owned elsewhere).
     */
    public function storeFromProperty(Request $request, Property $property)
    {
        $agencyId = $this->resolveAgencyId($request);
        if ((int) $property->agency_id !== $agencyId) {
            abort(404);
        }

        $linked = $this->resolveLinkedExistingContact($request, $agencyId);
        if ($linked !== null) {
            $contact = $linked;
            $isNew   = false;
        } else {
            $validated = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name'  => 'nullable|string|max:100',
                'phone'      => 'nullable|string|max:30',
                'email'      => 'nullable|email|max:255',
                'id_number'  => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
            ]);

            $idNumber = isset($validated['id_number']) ? preg_replace('/\s+/', '', (string) $validated['id_number']) : null;

            if (empty($validated['phone']) && empty($validated['email'])) {
                return back()
                    ->withErrors(['contact_required' => 'Provide a phone or email so we can dedupe and reach the seller.'])
                    ->withInput();
            }

            $gate = $this->duplicateGate(
                $request,
                $agencyId,
                $validated,
                $idNumber,
                route('seller-outreach.entry.from-property', ['property' => $property->id]),
            );
            if ($gate instanceof \Illuminate\Http\RedirectResponse) {
                return $gate;
            }
            $existing = $gate;
            $isNew = $existing === null;

            $branchId = $request->user()->branch_id;
            $contact = $existing ?: Contact::create(array_merge(
                array_filter([
                    'agency_id'             => $agencyId,
                    'branch_id'             => $branchId,
                    'first_name'            => $validated['first_name'],
                    'email'                 => $validated['email'] ?? null,
                    'created_by_user_id'    => $request->user()->id,
                    'id_number'             => $idNumber,
                    'id_number_captured_at' => $idNumber ? now() : null,
                    'id_number_source'      => $idNumber ? 'seller_outreach_entry' : null,
                ], static fn ($v) => $v !== null && $v !== ''),
                [
                    'last_name' => $validated['last_name'] ?? '',
                    'phone'     => $validated['phone'] ?? '',
                ],
            ));

            if ($existing && $idNumber && empty($existing->id_number)) {
                $existing->update([
                    'id_number'             => $idNumber,
                    'id_number_captured_at' => now(),
                    'id_number_source'      => 'seller_outreach_entry',
                ]);
            }
        }

        // Link contact ↔ existing property via the seller pivot. Idempotent.
        DB::table('contact_property')->updateOrInsert(
            ['contact_id' => $contact->id, 'property_id' => $property->id],
            ['role' => 'seller', 'updated_at' => now(), 'created_at' => now()],
        );

        $name = trim($contact->first_name . ' ' . (string) $contact->last_name);

        return redirect()
            ->route('seller-outreach.composer.show', [
                'contact'     => $contact->id,
                'property_id' => $property->id,
            ])
            ->with('status', $isNew
                ? "Created new contact: {$name}"
                : "Linked to existing contact: {$name}");
    }

    public function fromProspecting(Request $request, int $prospectingListingId)
    {
        $agencyId = $this->resolveAgencyId($request);

        $listing = DB::table('prospecting_listings')
            ->where('id', $prospectingListingId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();
        abort_if(!$listing, 404);

        // A.3.4 — prospect-collision detection. Before the temp-lock fires
        // (which would tie up the listing for the next 30 minutes), check
        // whether HFC already has a relationship to this address. The same
        // service drives the map's Portal Stock Prospect Now flow.
        $collision = $this->resolveCollisionForListing($listing, $agencyId, (int) $request->user()->id);
        if ($collision !== null) {
            return $collision;
        }

        // Temp lock: prevent two agents from pitching the same listing concurrently.
        // The lock auto-expires after the agency-configured window (default 30 min)
        // and is consumed when the pitch submits successfully.
        try {
            app(ProspectingClaimService::class)->createTempLock(
                listingId: $prospectingListingId,
                userId: (int) $request->user()->id,
                agencyId: $agencyId,
            );
        } catch (PitchLockConflictException $e) {
            $blockerName = DB::table('users')->where('id', $e->lockedByUserId)->value('name') ?? 'another agent';
            $expiresIn = $e->expiresAt instanceof \Carbon\Carbon
                ? $e->expiresAt->diffForHumans()
                : \Carbon\Carbon::parse($e->expiresAt)->diffForHumans();
            return redirect()
                ->route('market-intelligence.work')
                ->with('error', "⏳ {$blockerName} is currently pitching this listing. Try again after their lock expires ({$expiresIn}).");
        }

        // MIC ↔ Deeds ↔ Contact loop (Part A) — surface the deed the agent scraped. If a deeds
        // capture exists for this property, its registered owner(s) (name + SA-ID) are already in
        // CoreX as contacts; resolve them so the capture screen can offer "Use from deeds" instead
        // of re-typing. Read-only; failure-isolated (a deed-link hiccup must never break capture).
        $deedLink = $this->safeDeedLink(fn () => app(\App\Services\Prospecting\DeedsCaptureLinkService::class)
            ->ownersForListing($agencyId, $listing));

        return view('seller-outreach.entry.prospecting-create-contact', [
            'listing'      => $listing,
            // #3 Address-first: when the listing carries no street address, the
            // capture form shows a required address field so we land one before
            // creating the Property.
            'needsAddress' => trim((string) ($listing->address ?? '')) === '',
            'deedLink'     => $deedLink,
            // Manual "Link a deed" fallback — the full deeds list + the endpoint that remembers
            // the agent's choice on this listing.
            'deeds'        => $this->safeAvailableDeeds($agencyId),
            'linkDeedUrl'  => route('seller-outreach.entry.link-deed-prospecting', ['prospectingListingId' => $listing->id]),
        ]);
    }

    public function storeFromProspecting(Request $request, int $prospectingListingId)
    {
        $agencyId = $this->resolveAgencyId($request);

        $listing = DB::table('prospecting_listings')
            ->where('id', $prospectingListingId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // #3 Address-first — the address-less (pull-all) listing must land a real address
        // BEFORE we create the Property. The capture reuses the Contact screen's "Property
        // Address" modal, so instead of one blank line we receive the SAME structured
        // fields. Compose a display address and carry the structured parts onto the
        // promoted Property. A listing that already carries an address skips this.
        $captureAddress    = null;
        $structuredAddress = null;
        if (trim((string) ($listing->address ?? '')) === '') {
            $sa = $request->validate([
                'street_number'          => 'nullable|string|max:100',
                'street_name'            => 'nullable|string|max:255',
                'unit_number'            => 'nullable|string|max:100',
                'floor_number'           => 'nullable|string|max:50',
                'unit_section_block'     => 'nullable|string|max:255',
                'complex_name'           => 'nullable|string|max:255',
                'suburb'                 => 'nullable|string|max:255',
                'city'                   => 'nullable|string|max:255',
                'province'               => 'nullable|string|max:255',
                'pitch_addr_province_id' => 'nullable|integer',
                'pitch_addr_city_id'     => 'nullable|integer',
                'pitch_addr_suburb_id'   => 'nullable|integer',
            ]);
            $sa = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $sa);
            // A real address needs at least a street name or a complex/estate name.
            if (($sa['street_name'] ?? '') === '' && ($sa['complex_name'] ?? '') === '') {
                return back()
                    ->withErrors(['street_name' => 'Set a property address (street or complex/estate) before continuing.'])
                    ->withInput();
            }
            $structuredAddress = $sa;
            $captureAddress = $this->composeAddress($sa);
        }

        // The agent may either PICK an existing contact (search) or CAPTURE a new
        // one. A chosen contact_id short-circuits the new-contact validation.
        // Part B — the deliberate "No contact details available" dead-end override (only in the
        // create-new path; a picked existing contact already has a record).
        $isDeadEnd     = false;
        $deadEndReason = null;

        $linked = $this->resolveLinkedExistingContact($request, $agencyId);
        if ($linked !== null) {
            $validated = [];
            $idNumber  = null;
            $existing  = $linked;
            $isNew     = false;
        } else {
            $validated = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name'  => 'nullable|string|max:100',
                'phone'      => 'nullable|string|max:30',
                'email'      => 'nullable|email|max:255',
                // A.2.5 — optional SA ID number with format + checksum validation.
                'id_number'  => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
                // Part B — deliberate dead-end override + its reason (agent picks; default not_in_tva).
                'no_contact_details' => 'nullable|boolean',
                'dead_end_reason'    => 'nullable|string|in:opted_out,not_in_tva,no_record_found',
            ]);

            $idNumber = isset($validated['id_number']) ? preg_replace('/\s+/', '', (string) $validated['id_number']) : null;

            // Part B — the tick only makes sense as a dead-end path: if a phone or email IS
            // entered, real details win and the tick is ignored. When it genuinely applies, the
            // phone/email requirement is lifted, but a name + SA ID (from the deed) are required so
            // the contact is still ID-keyed and dedupes onto the deed owner — never an orphan ghost.
            $isDeadEnd = $request->boolean('no_contact_details')
                && empty($validated['phone']) && empty($validated['email']);

            if (! $isDeadEnd && empty($validated['phone']) && empty($validated['email'])) {
                return back()
                    ->withErrors(['contact_required' => 'Provide a phone or email so we can dedupe and reach the seller.'])
                    ->withInput();
            }
            if ($isDeadEnd && empty($idNumber)) {
                return back()
                    ->withErrors(['contact_required' => 'A dead-end contact needs the owner’s name and SA ID number from the deed.'])
                    ->withInput();
            }

            if ($isDeadEnd) {
                // Resolve-or-create on the SA ID: the deed owner is already an id_number-keyed
                // contact, so this dedupes onto that same record (never a duplicate). No blocking
                // duplicate panel — the ID is the authoritative identity here.
                $deadEndReason = \App\Models\ContactDeadEndFlag::normaliseReason($validated['dead_end_reason'] ?? null);
                $existing = app(\App\Services\ContactDuplicateService::class)
                    ->findDuplicatesForIdentifiers([], [], $idNumber, $agencyId)
                    ->first();
            } else {
                // Blocking duplicate check AT ADD TIME (parity with the Contacts
                // screen and the DR2 party-picker). Replaces the old silent
                // findExistingContact() match-or-create, whose only signal was a
                // "Linked to existing contact" toast one screen later on the
                // composer — the deferral behind the duplicate-contact report.
                $gate = $this->duplicateGate(
                    $request,
                    $agencyId,
                    $validated,
                    $idNumber,
                    route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $prospectingListingId]),
                );
                if ($gate instanceof \Illuminate\Http\RedirectResponse) {
                    return $gate;   // blocking duplicate panel — back to the capture page
                }
                $existing = $gate;  // Contact (auto_link match) or null (create new)
            }
            $isNew = $existing === null;
        }

        // Johan's ruling (2026-08-12, the "14 Chesapeake" incident) — the ONLY
        // gate is right here, the moment an EXISTING contact is being linked to
        // this pitch. Channel-agnostic master gate: Contact::communicationStatus()
        // defaults to COMM_OPTED_IN for a contact with no opt-out history at all
        // (messaging_opt_out_at === null — see the model's own docblock: "not
        // opted out (default; receives all)"), so this can NEVER wrongly withdraw
        // a property for a never-contacted owner with no consent record — it only
        // fires when messaging_opt_out_at is actually set. A brand-new contact
        // ($existing === null) has no history to check.
        // Does NOT block the pitch: the property still gets created and the claim
        // still goes permanent (Johan: "it stays claimed so it removes from mic in
        // any case") — this only marks the outcome (withdrawn + notes) and tells
        // the agent immediately instead of leaving them to discover it later on
        // the composer screen.
        $isOptedOut = $existing !== null && $existing->communicationStatus() !== Contact::COMM_OPTED_IN;

        $result = DB::transaction(function () use ($request, $agencyId, $listing, $validated, $existing, $idNumber, $captureAddress, $structuredAddress, $isOptedOut, $isDeadEnd, $deadEndReason) {
            // Branch context is mandatory on Contact rows in CoreX schema.
            $branchId = $request->user()->branch_id;

            // contacts.last_name + contacts.phone are NOT NULL in the schema —
            // they must reach Contact::create even when empty, so we merge the
            // NOT-NULL columns AFTER the array_filter that strips empty values
            // from the optional ones. Same shape as storeFromTrackedProperty
            // below. The A.2.5 commit (ea2b0295) introduced the array_filter
            // wrapper to keep id_number+audit fields out when empty, and in
            // doing so accidentally stripped last_name='' and phone=null,
            // causing SQL 1364 on every Pitch Now submit where the agent
            // skipped last_name or used email-only contact.
            $contact = $existing ?: Contact::create(array_merge(
                array_filter([
                    'agency_id'             => $agencyId,
                    'branch_id'             => $branchId,
                    'first_name'            => $validated['first_name'],
                    'email'                 => $validated['email'] ?? null,
                    'created_by_user_id'    => $request->user()->id,
                    // A.2.5 — POPIA audit. captured_at + source are only set when
                    // the agent actually filled in the ID; null otherwise.
                    'id_number'             => $idNumber,
                    'id_number_captured_at' => $idNumber ? now() : null,
                    'id_number_source'      => $idNumber ? 'seller_outreach_entry' : null,
                ], static fn ($v) => $v !== null && $v !== ''),
                [
                    'last_name' => $validated['last_name'] ?? '',
                    'phone'     => $validated['phone'] ?? '',
                ],
            ));

            // If the contact ALREADY existed (deduped) but the agent supplied
            // an ID at this entry point, capture-fill it on the existing row
            // when previously absent — never overwrite.
            if ($existing && $idNumber && empty($existing->id_number)) {
                $existing->update([
                    'id_number'             => $idNumber,
                    'id_number_captured_at' => now(),
                    'id_number_source'      => 'seller_outreach_entry',
                ]);
            }

            $property = $this->promoteListingToProperty($agencyId, $listing, $request->user(), $captureAddress, $structuredAddress);

            // Johan's ruling — opted-out maintenance. The property is still
            // created and claimed as normal; this just marks the outcome so
            // nobody pitches this address again without seeing why it's dead.
            if ($isOptedOut) {
                $property->update(['status' => 'withdrawn']);

                $optOutNote = 'Property address cannot be contacted — contact '
                    . trim($existing->first_name . ' ' . (string) $existing->last_name)
                    . ' opted out.';

                PropertyNote::create([
                    'property_id' => $property->id,
                    'user_id'     => $request->user()->id,
                    'content'     => $optOutNote,
                ]);

                ContactNote::create([
                    'contact_id' => $existing->id,
                    'user_id'    => $request->user()->id,
                    'body'       => $optOutNote,
                ]);
            }

            // Universal Match-or-Create: ensure a TrackedProperty exists for this
            // address and link it to both the prospecting listing AND the newly
            // promoted Property. The TP record becomes the long-lived audit trail
            // (source_chain accumulates from every ingestion path that touches this
            // address — P24 capture, PP capture, CMA presentation, manual entry, etc.).
            // Failure-isolated: a TP write hiccup doesn't roll back the contact/property/pivot
            // work, which is the user-visible operation.
            $trackedPropertyId = null;
            try {
                $tp = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)
                    ->matchOrCreate(
                        agencyId: $agencyId,
                        facts: array_filter([
                            'address'                 => $property->address,
                            'street_number'           => $property->street_number,
                            'street_name'             => $property->street_name,
                            'suburb'                  => $property->suburb,
                            'town'                    => $property->town,
                            'province'                => $property->province,
                            'property_type'           => $property->property_type,
                            'bedrooms'                => $property->beds,
                            'bathrooms'               => $property->baths,
                            'garages'                 => $property->garages,
                            'erf_size_m2'             => $property->erf_size_m2,
                            'last_known_asking_price' => $property->price ?: null,
                        ], fn ($v) => $v !== null && $v !== ''),
                        source: [
                            'type' => 'manual_prospect_entry',
                            'ref'  => "property_{$property->id}",
                            'payload' => [
                                'property_id'            => $property->id,
                                'prospecting_listing_id' => $listing->id,
                            ],
                        ],
                        actorUserId: (int) $request->user()->id,
                    );

                // Mark the TP as promoted to this Property and link the listing to the TP.
                // Idempotent — re-promoting the same TP returns the existing linkage.
                if ($tp) {
                    $trackedPropertyId = $tp->id;
                    if (!$tp->isPromoted()) {
                        $tp->update([
                            'promoted_to_property_id' => $property->id,
                            'promoted_at'             => now(),
                            'promoted_by_user_id'     => $request->user()->id,
                            'status'                  => \App\Models\Prospecting\TrackedProperty::STATUS_PROMOTED,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('TrackedProperty link from manual prospect entry failed', [
                    'property_id' => $property->id,
                    'listing_id'  => $listing->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            // Link contact ↔ property via the contact_property pivot with role=seller.
            // Idempotent — re-running the flow for the same pair updates role only.
            DB::table('contact_property')->updateOrInsert(
                [
                    'contact_id'  => $contact->id,
                    'property_id' => $property->id,
                ],
                [
                    'role'       => 'seller',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Close the loop: mark the listing as matched to the promoted Property
            // AND link it to the TrackedProperty (so the listing → TP → property chain is whole).
            DB::table('prospecting_listings')
                ->where('id', $listing->id)
                ->whereNull('matched_property_id')
                ->update(array_filter([
                    'matched_property_id'  => $property->id,
                    'matched_at'           => now(),
                    'tracked_property_id'  => $trackedPropertyId,
                    'updated_at'           => now(),
                ], fn ($v) => $v !== null));

            // Part B — persist the dead-end marker on the ONE canonical contact so any future
            // agent immediately sees it's been chased with nothing contactable. One active flag
            // per contact (updateOrCreate), plus a note for the compounding human history.
            if ($isDeadEnd) {
                \App\Models\ContactDeadEndFlag::updateOrCreate(
                    ['contact_id' => $contact->id],
                    [
                        'agency_id'          => $agencyId,
                        'property_id'        => $property->id,
                        'reason'             => $deadEndReason,
                        'source'             => 'seller_outreach',
                        'created_by_user_id' => $request->user()->id,
                    ],
                );

                ContactNote::create([
                    'contact_id' => $contact->id,
                    'user_id'    => $request->user()->id,
                    'body'       => 'Marked as a dead-end — no contact details available ('
                        . \App\Models\ContactDeadEndFlag::reasonLabel($deadEndReason) . '). Captured from the deed; nothing to reach.',
                ]);
            }

            return [$contact, $property];
        });

        [$contact, $property] = $result;
        $property->refresh(); // pick up the withdrawn-status write made inside the transaction

        $name = trim($contact->first_name . ' ' . (string) $contact->last_name);

        // Pitch lock: capturing + linking a contact via "Pitch now" PERMANENTLY
        // claims this MIC listing to the agent — it drops out of the default
        // canvassing pool (MarketIntelligenceController::work hides active
        // pitched claims) and is exempt from the 48h no-feedback expiry
        // (ProspectingClaim::isExpired). Consuming the temp lock now (rather than
        // only at composer-send) is what makes the lock stick the moment the
        // contact is linked. Failure-isolated: a claim hiccup must never break
        // the contact/property work or the composer redirect. The later
        // composer-send call to the same method is idempotent.
        try {
            app(ProspectingClaimService::class)->consumeLockAsPermanentClaim(
                listingId:    (int) $listing->id,
                userId:       (int) $request->user()->id,
                agencyId:     $agencyId,
                pitchContext: ['recipient_name' => $name],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Pitch-lock claim from prospecting capture failed', [
                'listing_id' => $listing->id,
                'contact_id' => $contact->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Opted-out outcome: show the agent immediately rather than letting them
        // land on a compose screen for someone who was just marked uncontactable.
        // The property/claim/link all still happened exactly as normal above —
        // only the destination and the message differ.
        if ($isOptedOut) {
            return redirect()
                ->route('corex.properties.show', ['property' => $property->id])
                ->with('warning', "Linked to existing contact: {$name} — {$name} has opted out of marketing. "
                    . 'This property has been marked Withdrawn and a note was added to both records.');
        }

        // Part B — dead-end outcome: there is nothing to send (no phone/email), so land on the
        // property with the record + flag saved rather than a composer that can't compose.
        if ($isDeadEnd) {
            $reasonLabel = \App\Models\ContactDeadEndFlag::reasonLabel($deadEndReason);

            return redirect()
                ->route('corex.properties.show', ['property' => $property->id])
                ->with('warning', "Recorded {$name} as a dead-end — no contact details available ({$reasonLabel}). "
                    . 'The property and contact were saved and flagged; there is nothing to send.');
        }

        return redirect()
            ->route('seller-outreach.composer.show', [
                'contact'     => $contact->id,
                'property_id' => $property->id,
            ])
            ->with('status', $isNew
                ? "Created new contact: {$name}"
                : "Linked to existing contact: {$name}");
    }

    /**
     * Map Workspace Phase B (Fix 2+3) — T-pin entry point.
     *
     * Mirrors fromProspecting() but the source-of-truth is a TrackedProperty
     * instead of a portal listing. Locks the TP for 30 min (temp lock keyed
     * to tracked_property_id) and renders the same contact-capture form
     * extended to display TP context. NO collision check — T-pins by
     * definition aren't HFC stock; if the address WAS held/sold the map
     * already routes the user to the H/sold_comp pin instead.
     */
    public function fromTrackedProperty(Request $request, TrackedProperty $trackedProperty)
    {
        $agencyId = $this->resolveAgencyId($request);
        if ((int) $trackedProperty->agency_id !== $agencyId) {
            abort(404);
        }

        try {
            app(ProspectingClaimService::class)->createTempLockForTrackedProperty(
                trackedPropertyId: (int) $trackedProperty->id,
                userId:            (int) $request->user()->id,
                agencyId:          $agencyId,
            );
        } catch (PitchLockConflictException $e) {
            $blockerName = DB::table('users')->where('id', $e->lockedByUserId)->value('name') ?? 'another agent';
            $expiresIn = $e->expiresAt instanceof \Carbon\Carbon
                ? $e->expiresAt->diffForHumans()
                : \Carbon\Carbon::parse($e->expiresAt)->diffForHumans();
            return redirect()
                ->route('corex.map.index')
                ->with('error', "⏳ {$blockerName} is currently pitching this property. Try again after their lock expires ({$expiresIn}).");
        }

        // MIC ↔ Deeds ↔ Contact loop (Part A) — same deed surfacing for the map T-pin pitch,
        // resolved directly off the TrackedProperty in hand.
        $deedLink = $this->safeDeedLink(fn () => app(\App\Services\Prospecting\DeedsCaptureLinkService::class)
            ->ownersForTrackedProperty($agencyId, $trackedProperty));

        return view('seller-outreach.entry.prospecting-create-contact', [
            'listing'         => null,
            'trackedProperty' => $trackedProperty,
            'deedLink'        => $deedLink,
            // Manual "Link a deed" modal is available here too (prefill only — no listing to
            // remember the link on; the T-pin flow persists the owner via storeFromTrackedProperty).
            'deeds'           => $this->safeAvailableDeeds($agencyId),
            'linkDeedUrl'     => null,
        ]);
    }

    /**
     * Map Workspace Phase B (Fix 2+3) — store the captured contact and link
     * it to the Tracked Property as the owner. No Property is created here;
     * TP→Property promotion is reserved for mandate-sign per CLAUDE.md
     * non-negotiable #10. The composer renders with no linked property and
     * the agent can free-form a WhatsApp / email to the captured contact.
     */
    public function storeFromTrackedProperty(Request $request, TrackedProperty $trackedProperty)
    {
        $agencyId = $this->resolveAgencyId($request);
        if ((int) $trackedProperty->agency_id !== $agencyId) {
            abort(404);
        }

        // Pick an existing contact (search) or capture a new one — same rule as
        // storeFromProspecting.
        $linked = $this->resolveLinkedExistingContact($request, $agencyId);
        if ($linked !== null) {
            $validated = [];
            $idNumber  = null;
            $existing  = $linked;
            $isNew     = false;
        } else {
            $validated = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name'  => 'nullable|string|max:100',
                'phone'      => 'nullable|string|max:30',
                'email'      => 'nullable|email|max:255',
                'id_number'  => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
            ]);

            $idNumber = isset($validated['id_number']) ? preg_replace('/\s+/', '', (string) $validated['id_number']) : null;

            if (empty($validated['phone']) && empty($validated['email'])) {
                return back()
                    ->withErrors(['contact_required' => 'Provide a phone or email so we can dedupe and reach the seller.'])
                    ->withInput();
            }

            // Blocking duplicate check AT ADD TIME — same contract as
            // storeFromProspecting (the T-pin pitch shares the capture form,
            // so it must speak the same duplicate-panel language).
            $gate = $this->duplicateGate(
                $request,
                $agencyId,
                $validated,
                $idNumber,
                route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $trackedProperty->id]),
            );
            if ($gate instanceof \Illuminate\Http\RedirectResponse) {
                return $gate;
            }
            $existing = $gate;
            $isNew = $existing === null;
        }

        $contact = DB::transaction(function () use ($request, $agencyId, $trackedProperty, $validated, $existing, $idNumber) {
            $branchId = $request->user()->branch_id;

            // contacts.last_name + contacts.phone are NOT NULL in the schema —
            // they must reach Contact::create even when empty, so we merge the
            // NOT-NULL columns AFTER the array_filter that strips empty values
            // from the optional ones.
            $contact = $existing ?: Contact::create(array_merge(
                array_filter([
                    'agency_id'             => $agencyId,
                    'branch_id'             => $branchId,
                    'first_name'            => $validated['first_name'],
                    'email'                 => $validated['email'] ?? null,
                    'created_by_user_id'    => $request->user()->id,
                    'id_number'             => $idNumber,
                    'id_number_captured_at' => $idNumber ? now() : null,
                    'id_number_source'      => $idNumber ? 'seller_outreach_entry' : null,
                ], static fn ($v) => $v !== null && $v !== ''),
                [
                    'last_name' => $validated['last_name'] ?? '',
                    'phone'     => $validated['phone'] ?? '',
                ],
            ));

            if ($existing && $idNumber && empty($existing->id_number)) {
                $existing->update([
                    'id_number'             => $idNumber,
                    'id_number_captured_at' => now(),
                    'id_number_source'      => 'seller_outreach_entry',
                ]);
            }

            // Link contact ↔ tracked property. Idempotent — re-running the
            // flow for the same TP+contact pair is a no-op. Capture-fill
            // only when owner_contact_id is unset; never silently overwrite
            // a previously-captured owner.
            if ((int) ($trackedProperty->owner_contact_id ?? 0) === 0) {
                $trackedProperty->update(['owner_contact_id' => $contact->id]);
            }

            return $contact;
        });

        $name = trim($contact->first_name . ' ' . (string) $contact->last_name);
        return redirect()
            ->route('seller-outreach.composer.show', [
                'contact' => $contact->id,
                'channel' => 'whatsapp',
            ])
            ->with('status', $isNew
                ? "Created new contact: {$name}"
                : "Linked to existing contact: {$name}");
    }

    /**
     * Resolve an EXISTING contact the agent picked via the "search existing"
     * option. Returns null when no contact_id was posted (the new-contact path).
     * The contact must belong to the acting agency — a stale/forged id 404s
     * rather than silently linking the wrong tenant's contact. Agency-scoped
     * (AgencyScope applies); the ContactScope read filter is intentionally not
     * applied here, mirroring PropertyContactController::searchGlobal so a seller
     * captured by another agent can still be linked (Non-Negotiable #10).
     */
    private function resolveLinkedExistingContact(Request $request, int $agencyId): ?Contact
    {
        if (!$request->filled('contact_id')) {
            return null;
        }

        $contact = Contact::withoutGlobalScope(\App\Models\Scopes\ContactScope::class)
            ->where('agency_id', $agencyId)
            ->whereKey((int) $request->input('contact_id'))
            ->first();

        abort_if($contact === null, 404, 'Selected contact not found in this agency.');

        return $contact;
    }

    /**
     * Blocking duplicate gate for the pitch contact-capture (parity with
     * ContactController@store and Dr2\DealRegisterController@contactInline).
     *
     * Replaces the former silent findExistingContact() match-or-create, which
     * linked to an existing contact without telling the agent until the
     * composer screen ("Linked to existing contact: …"). That deferral is the
     * root cause behind the duplicate-contact report: the dedup ran at add
     * time but its only signal appeared one screen later, so the agent had no
     * chance to decide "use existing" vs "this is a different person".
     *
     * Uses the canonical App\Services\ContactDuplicateService::findDuplicates()
     * (mirror columns + child identifier tables, agency-scoped) — the same
     * matcher the Contacts screen and DR2 use.
     *
     * Returns one of:
     *   - RedirectResponse  back to the capture page carrying a `pitch_duplicate`
     *                       session payload the view renders as a blocking panel
     *                       ("use existing & continue" / "create anyway"), for
     *                       soft_warn and hard_block_* modes.
     *   - Contact           the matched contact when the agency runs auto_link
     *                       mode (silent link is the configured behaviour there).
     *   - null              no duplicate — the caller creates a new contact.
     *
     * Bypassed when the capture form re-submits with bypass_duplicate_check=1
     * (the agent chose "create anyway", subject to mode/can_override).
     */
    private function duplicateGate(
        Request $request,
        int $agencyId,
        array $validated,
        ?string $idNumber,
        string $fallbackUrl,
    ): \Illuminate\Http\RedirectResponse|Contact|null {
        if ($request->boolean('bypass_duplicate_check')) {
            return null;
        }

        $service = app(\App\Services\ContactDuplicateService::class);
        $dupes = $service->findDuplicates([
            'first_name' => $validated['first_name'] ?? '',
            'last_name'  => $validated['last_name'] ?? '',
            'phone'      => $validated['phone'] ?? null,
            'email'      => $validated['email'] ?? null,
            'id_number'  => $idNumber,
        ], $agencyId);

        if ($dupes->isEmpty()) {
            return null;
        }

        $mode = $service->resolveMode($agencyId);

        // auto_link: silently link to the match (the agency configured this).
        if ($mode === 'auto_link') {
            return $dupes->first();
        }

        // soft_warn / hard_block_* → block and surface the matches. can_override
        // uses the SAME formula as the Contacts screen and DR2: only an admin/
        // owner may override a hard_block_override; a soft_warn allows anyone to
        // create anyway (front-end gates the "create anyway" button on mode).
        $canOverride = $mode === 'hard_block_override'
            && in_array($request->user()->effectiveRole(), ['admin', 'super_admin', 'owner'], true);
        $mask = $mode === 'hard_block_request';

        return back(302, [], $fallbackUrl)
            ->withInput()
            ->with('pitch_duplicate', [
                'mode'         => $mode,
                'can_override' => $canOverride,
                'duplicates'   => $dupes->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => $c->full_name,
                    'phone' => $mask ? null : $c->phone,
                    'email' => $mask ? null : $c->email,
                    'owner' => optional($c->createdBy)->name ?? 'Unknown',
                ])->values()->all(),
            ]);
    }

    /**
     * Promote a prospecting_listing to a real Property row.
     *
     * Idempotency: if a Property already exists for this agency with the
     * same normalised address (street_number+street_name OR the free-text
     * `address` column) + suburb, reuse it.
     *
     * Traceability: external_id is set to `prospecting:{listing.id}` so the
     * promoted Property can be traced back to its source listing. The
     * listing's `matched_property_id` is set in the caller's transaction.
     */
    private function promoteListingToProperty(int $agencyId, $listing, $actor, ?string $overrideAddress = null, ?array $structuredAddress = null): Property
    {
        // #3 Address-first: an address-less import (pull-all) reaches here with a
        // blank listing address. The capture step collects one and passes it as
        // $overrideAddress so a real street address always lands on the promoted
        // Property (never the "Prospecting listing N" placeholder). A non-empty
        // listing address always wins — the override only fills the gap.
        $address = trim((string) ($listing->address ?? ''));
        if ($address === '' && $overrideAddress !== null) {
            $address = trim($overrideAddress);
        }
        // The legacy "Address not available" placeholder is NOT an address — fold
        // it to empty so it can never dedupe against, nor be stored on, a property.
        if (strtolower($address) === 'address not available') {
            $address = '';
        }
        $suburb  = trim((string) ($listing->suburb ?? ''));
        $normalised = trim(strtolower((string) ($listing->normalized_address ?? $address)));

        // Ramsgate bug fix — disambiguate by the listing's OWN source-ref FIRST.
        // 'prospecting:{id}' is unique per listing, so re-promoting the same listing
        // reuses ITS property and two DIFFERENT listings never collide.
        $existing = Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('external_id', 'prospecting:' . $listing->id)
            ->first();

        // Only when there is NO source-ref match AND we hold a REAL address may we
        // dedupe by address. An empty address must NEVER match — that empty-address
        // collapse is what landed a no-address Pitch Now on the wrong property.
        // Addressless listings therefore always create their OWN property (keyed
        // by external_id), never collapsing onto an existing addressless one.
        if (!$existing && $address !== '') {
            $existing = Property::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($address, $normalised) {
                    $q->where('address', $address)
                      ->orWhereRaw('LOWER(TRIM(address)) = ?', [$normalised]);
                })
                ->when($suburb !== '', fn ($q) => $q->where(function ($qq) use ($suburb) {
                    $qq->where('suburb', $suburb)->orWhereNull('suburb');
                }))
                ->first();
        }
        if ($existing) {
            // Heal a property promoted before this fix (or any reuse) that
            // has no structured street — only when BOTH are empty, so a
            // manually-edited address is never overwritten.
            if (empty($existing->street_number) && empty($existing->street_name)) {
                [$sNo, $sName] = $this->parseStreet($address, $suburb);
                $patch = [];
                if ($sNo !== null && $sNo !== '') {
                    $patch['street_number'] = $sNo;
                }
                if ($sName !== null && $sName !== '') {
                    $patch['street_name'] = $sName;
                }
                if ($patch !== []) {
                    $existing->forceFill($patch)->save();
                }
            }
            return $existing;
        }

        // CoreX rejects system_owner / super_admin as a Property agent. Fall
        // back to the agency's first admin/branch_manager/agent if the actor
        // is an owner-level account. The promoted property is a temporary
        // record anyway — the assigned agent can reassign later.
        $propertyAgentId = $this->resolvePromotionAgentId($agencyId, $actor);
        $propertyBranchId = $actor->branch_id
            ?? \DB::table('users')->where('id', $propertyAgentId)->value('branch_id');

        // Parse the free-text address into structured fields so the pitched
        // property's Internal Address modal + the outreach address line
        // (built from street_number+street_name+suburb) are populated.
        [$streetNumber, $streetName] = $this->parseStreet($address, $suburb);
        $district = trim((string) ($listing->district ?? ''));

        // When the address came from the shared "Property Address" modal (structured
        // capture on the pitch flow), prefer its EXACT parts over parsing the composed
        // string, and carry the richer columns (complex/unit/city/province + P24 ids)
        // onto the new property so it is pre-filled exactly like a contact-started one.
        $extra = [];
        if (is_array($structuredAddress)) {
            if (($structuredAddress['street_number'] ?? '') !== '') { $streetNumber = $structuredAddress['street_number']; }
            if (($structuredAddress['street_name'] ?? '') !== '')   { $streetName   = $structuredAddress['street_name']; }
            if (($structuredAddress['suburb'] ?? '') !== '')        { $suburb       = $structuredAddress['suburb']; }
            foreach ([
                'complex_name'       => 'complex_name',
                'unit_number'        => 'unit_number',
                'floor_number'       => 'floor_number',
                'unit_section_block' => 'unit_section_block',
                'city'               => 'city',
                'province'           => 'province',
            ] as $src => $col) {
                if (($structuredAddress[$src] ?? '') !== '') { $extra[$col] = $structuredAddress[$src]; }
            }
            foreach ([
                'pitch_addr_province_id' => 'p24_province_id',
                'pitch_addr_city_id'     => 'p24_city_id',
                'pitch_addr_suburb_id'   => 'p24_suburb_id',
            ] as $src => $col) {
                if (!empty($structuredAddress[$src])) { $extra[$col] = (int) $structuredAddress[$src]; }
            }
        }

        // MIC ↔ property-pillar reconciliation (Johan 2026-08-14). The external_id + raw
        // address-string dedup above only catches EXACT string matches. Before minting a NEW
        // property, resolve the FINAL address against the canonical TrackedProperty identity spine
        // (the SAME resolver deeds promote uses: source-ref → GPS ~5m → erf+suburb → normalised
        // structured address → token) so an address that already exists — e.g. a deeds-promoted
        // property under a slightly different string — reconciles to it instead of duplicating.
        // Read-only (findExistingMatch creates nothing); failure-isolated (falls through to create).
        try {
            $reconLat = null;
            $reconLng = null;
            if (!empty($listing->tracked_property_id)) {
                $tpGps = DB::table('tracked_properties')
                    ->where('id', (int) $listing->tracked_property_id)
                    ->where('agency_id', $agencyId)
                    ->first(['latitude', 'longitude']);
                if ($tpGps) {
                    $reconLat = $tpGps->latitude !== null ? (float) $tpGps->latitude : null;
                    $reconLng = $tpGps->longitude !== null ? (float) $tpGps->longitude : null;
                }
            }
            $reconFacts = array_filter([
                'address'       => $address ?: ($listing->address ?? null),
                'street_number' => $streetNumber ?: ProspectingListing::parseStreetNumber($listing->address ?? null),
                'street_name'   => $streetName ?: null,
                'suburb'        => $suburb ?: ($listing->suburb ?? null),
                'latitude'      => $reconLat,
                'longitude'     => $reconLng,
            ], static fn ($v) => $v !== null && $v !== '');

            // Only attempt when we hold a real locating key (a real address or GPS) — never let an
            // empty/placeholder address collapse onto an existing property (the Ramsgate landmine).
            if (($address !== '') || ($reconLat !== null && $reconLng !== null)) {
                $canonical = app(\App\Services\Prospecting\MicPropertyReconciliationService::class)
                    ->resolveExistingProperty($agencyId, $reconFacts);
                if ($canonical) {
                    return $canonical;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MIC property-pillar reconciliation failed (falling through to create)', [
                'listing_id' => $listing->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }

        // properties.beds/baths/garages/price/suburb/property_type/status are
        // NOT NULL — fall back to the schema defaults (0 / empty / 'house' /
        // 'draft') when the prospecting row doesn't carry the value.
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $propertyBranchId,
            'agent_id'      => $propertyAgentId,
            'external_id'   => 'prospecting:' . $listing->id,
            'title'         => $address !== '' ? $address : 'Prospecting listing ' . $listing->id,
            'address'       => $address !== '' ? $address : null,
            'street_number' => $streetNumber,
            'street_name'   => $streetName,
            'suburb'        => $suburb !== '' ? $suburb : '',
            'district'      => $district !== '' ? $district : null,
            'price'         => $listing->price ?? 0,
            'beds'          => $listing->bedrooms ?? 0,
            'baths'         => $listing->bathrooms ?? 0,
            'garages'       => $listing->garages ?? 0,
            'property_type' => $listing->property_type ?? 'house',
            // No listing_type on prospecting_listings — default to 'sale'.
            'listing_type'  => 'sale',
            'status'        => 'draft',
        ], $extra));
    }

    /**
     * Compose a one-line display address from the structured "Property Address" modal
     * fields (unit / section / complex / street / suburb / city / province), mirroring
     * the contact component's summary. Used as the promoted property's address string.
     */
    private function composeAddress(array $a): string
    {
        $parts = [];
        $unit    = trim((string) ($a['unit_number'] ?? ''));
        $section = trim((string) ($a['unit_section_block'] ?? ''));
        $complex = trim((string) ($a['complex_name'] ?? ''));
        $sNo     = trim((string) ($a['street_number'] ?? ''));
        $sName   = trim((string) ($a['street_name'] ?? ''));
        $suburb  = trim((string) ($a['suburb'] ?? ''));
        $city    = trim((string) ($a['city'] ?? ''));
        $prov    = trim((string) ($a['province'] ?? ''));

        if ($unit !== '')    { $parts[] = 'Unit ' . $unit; }
        if ($section !== '') { $parts[] = $section; }
        if ($complex !== '') { $parts[] = $complex; }
        if ($sNo !== '' && $sName !== '') { $parts[] = trim($sNo . ' ' . $sName); }
        elseif ($sName !== '')            { $parts[] = $sName; }
        if ($suburb !== '') { $parts[] = $suburb; }
        if ($city !== '' && strtolower($city) !== strtolower($suburb)) { $parts[] = $city; }
        if ($prov !== '') { $parts[] = $prov; }

        return implode(', ', array_filter($parts));
    }

    /**
     * Prospecting listings carry only a free-text `address` string (plus a
     * separate suburb/district) — NO structured street fields. Parse the
     * street line (the segment before the first comma) into
     * [street_number, street_name] so a pitched Property's Internal Address
     * and the outreach address line are populated instead of "(no address)".
     * Returns [null, null] when there is no real street (e.g. the address is
     * just the suburb).
     */
    private function parseStreet(string $address, string $suburb): array
    {
        $address = trim($address);
        if ($address === '') {
            return [null, null];
        }
        $streetLine = trim((string) (explode(',', $address)[0] ?? ''));
        $suburb = trim($suburb);
        if ($streetLine === ''
            || ($suburb !== '' && mb_strtolower($streetLine) === mb_strtolower($suburb))) {
            return [null, null];
        }
        // Leading street number: "173", "12A", "1/3", "12-14".
        if (preg_match('/^\s*(\d+[A-Za-z]?(?:\s*[\/-]\s*\d+[A-Za-z]?)?)\s+(.+)$/u', $streetLine, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        // No leading number — the whole line is the street name.
        return [null, $streetLine];
    }

    private function resolvePromotionAgentId(int $agencyId, $actor): int
    {
        $role = $actor->role ?? null;
        if ($role && !in_array($role, ['super_admin', 'system'], true)) {
            return (int) $actor->id;
        }
        $fallback = \DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['admin', 'branch_manager', 'agent'])
            ->orderByRaw("FIELD(role, 'admin', 'branch_manager', 'agent')")
            ->orderBy('id')
            ->value('id');
        abort_if(!$fallback, 422, 'No agent available in this agency to assign the promoted property.');
        return (int) $fallback;
    }

    private function loadSellerContactsForProperty(int $agencyId, Property $property)
    {
        return $property->contacts()
            ->withoutGlobalScopes()
            ->where('contacts.agency_id', $agencyId)
            ->whereNull('contacts.deleted_at')
            ->wherePivotIn('role', self::SELLER_ROLES)
            ->orderBy('contacts.first_name')
            ->get();
    }

    /**
     * MIC ↔ Deeds ↔ Contact loop (Part A) — run the deed-owner resolver, never letting a
     * failure break the capture screen. Returns the empty shape the blade expects on any error.
     *
     * @param  callable():array  $resolver
     * @return array{tracked_property_id: int|null, address: string|null, owners: array<int, array<string, mixed>>}
     */
    private function safeDeedLink(callable $resolver): array
    {
        $empty = ['tracked_property_id' => null, 'address' => null, 'owners' => []];
        try {
            return $resolver() ?: $empty;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Deeds-capture link resolution failed (compose screen continues without it)', [
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * The agency's deeds list for the manual "Link a deed" modal, failure-isolated so a hiccup
     * never breaks the compose screen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function safeAvailableDeeds(int $agencyId): array
    {
        try {
            return app(\App\Services\Prospecting\DeedsCaptureLinkService::class)->availableDeeds($agencyId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Deeds list for manual link failed (modal will be empty)', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * MIC ↔ Deeds ↔ Contact loop (Part A, manual link) — the agent picked a deed from the modal.
     * Remember it on this prospecting listing (teaches the matcher so it auto-surfaces next time)
     * and return the deed's owner(s) for the form prefill. Agency-scoped; a stale/forged deed id or
     * an owner-less deed 404/422s rather than linking the wrong tenant's record.
     */
    public function linkDeedToProspecting(Request $request, int $prospectingListingId)
    {
        $agencyId = $this->resolveAgencyId($request);

        $listing = DB::table('prospecting_listings')
            ->where('id', $prospectingListingId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();
        abort_if(! $listing, 404);

        $validated = $request->validate([
            'tracked_property_id' => 'required|integer',
        ]);

        $deedTp = TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereKey((int) $validated['tracked_property_id'])
            ->first();
        abort_if($deedTp === null, 404, 'Deed not found in this agency.');

        $link = app(\App\Services\Prospecting\DeedsCaptureLinkService::class);
        $payload = $link->ownersForTrackedProperty($agencyId, $deedTp);
        if (empty($payload['owners'])) {
            return response()->json(['ok' => false, 'error' => 'That deed has no registered owner to use.'], 422);
        }

        DB::table('prospecting_listings')
            ->where('id', $listing->id)
            ->update([
                'linked_deed_tracked_property_id' => $deedTp->id,
                'linked_deed_by_user_id'          => (int) $request->user()->id,
                'linked_deed_at'                  => now(),
                'updated_at'                      => now(),
            ]);

        return response()->json([
            'ok'                  => true,
            'tracked_property_id' => (int) $deedTp->id,
            'owners'              => $payload['owners'],
        ]);
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($id === null, 403, 'No agency context — super_admin without an active agency cannot compose pitches.');
        return (int) $id;
    }

    /**
     * A.3.4 — apply the map's 6-state prospect-collision rules to a
     * MIC-initiated pitch attempt. Returns a redirect Response when the
     * agent must be diverted (held / own_draft / other_draft); returns
     * null for available, previously_sold, previously_held — those three
     * proceed to the normal contact-capture flow with a warning flash on
     * the "previously_" branches.
     */
    private function resolveCollisionForListing(
        object $listing,
        int $agencyId,
        int $currentUserId,
    ): ?\Symfony\Component\HttpFoundation\Response {
        // prospecting_listings carries address + suburb but no GPS columns.
        // When a TrackedProperty is linked we lift lat/lng from there so the
        // resolver can use GPS-proximity matching (Strategy 2) — otherwise
        // fall back to the normalised-address chain (Strategies 4/5).
        $lat = null;
        $lng = null;
        if (!empty($listing->tracked_property_id)) {
            $tpGps = DB::table('tracked_properties')
                ->where('id', (int) $listing->tracked_property_id)
                ->where('agency_id', $agencyId)
                ->first(['latitude', 'longitude']);
            if ($tpGps) {
                $lat = $tpGps->latitude !== null ? (float) $tpGps->latitude : null;
                $lng = $tpGps->longitude !== null ? (float) $tpGps->longitude : null;
            }
        }

        // Ramsgate bug fix — addressless collision guard. A listing with NO usable
        // locating key (no real street address AND no GPS) cannot be safely matched
        // to an existing property: the map resolver falls back to empty-address /
        // suburb-only matching and collapses DIFFERENT addressless listings onto the
        // same wrong property (Johan's test: a no-address Pitch Now "opened" property
        // 2427 "6 Kerk Street"). With nothing to collide on, skip collision resolution
        // and proceed straight to capture, so THIS listing gets its own correct property.
        $addr = trim((string) ($listing->address ?? ''));
        $hasRealAddress = $addr !== '' && strtolower($addr) !== 'address not available';
        $hasGps = $lat !== null && $lng !== null;
        if (!$hasRealAddress && !$hasGps) {
            $this->fireMicProspectLaunched($listing, $agencyId, $currentUserId);
            return null;
        }

        $facts = [
            'address'       => $listing->address ?? null,
            // AT-URGENT — the real street number, read only from the address's real
            // street segment (see ProspectingListing::parseStreetNumber), so
            // TrackedPropertyMatchOrCreateService's numbersConflict() veto can
            // actually fire and stop "51 Colin Street" colliding with "64 Colin
            // Street" just because they share a suburb + GPS proximity / street name.
            'street_number' => ProspectingListing::parseStreetNumber($listing->address ?? null),
            'latitude'      => $lat,
            'longitude'     => $lng,
            'suburb'        => $listing->suburb ?? null,
        ];

        $status = $this->prospectStatus->resolve($facts, $agencyId, $currentUserId);

        switch ($status['status']) {
            case 'held':
                return redirect()
                    ->route('corex.properties.show', ['property' => $status['property_id']])
                    ->with('warning', "This property is already on HFC's books — opened the existing record instead of starting a new prospect.");

            case 'own_draft':
                $days = (int) ($status['days_in_state'] ?? 0);
                return redirect()
                    ->route('corex.properties.show', ['property' => $status['property_id']])
                    ->with('info', "You already have a draft on this property ({$days} days). Continuing your draft.");

            case 'other_draft':
                $agent = $status['agent_name'] ?? 'another agent';
                $days  = (int) ($status['days_in_state'] ?? 0);
                $state = $status['state_label'] ?? 'draft';
                return redirect()
                    ->route('market-intelligence.work')
                    ->with('warning', "{$agent} has a draft on this property ({$days} days in {$state}). Coordinate with them before prospecting. To override, use the map's override flow.");

            case 'previously_sold':
                $date = $status['sale_date'] ?? null;
                session()->flash('warning', $date
                    ? "Previously sold by HFC on {$date}. Continuing as new prospect."
                    : 'Previously sold by HFC. Continuing as new prospect.');
                $this->fireMicProspectLaunched($listing, $agencyId, $currentUserId);
                return null;

            case 'previously_held':
                $expired = $status['expired_at'] ?? null;
                session()->flash('warning', $expired
                    ? "Previously held by HFC (mandate ended {$expired}). Continuing as new prospect."
                    : 'Previously held by HFC. Continuing as new prospect.');
                $this->fireMicProspectLaunched($listing, $agencyId, $currentUserId);
                return null;

            case 'available':
            default:
                $this->fireMicProspectLaunched($listing, $agencyId, $currentUserId);
                return null;
        }
    }

    /**
     * Fire MapProspectLaunched with source='mic_entry_point' so the audit
     * trail attributes the MIC-initiated launch to the correct surface.
     * Skips silently when no TrackedProperty is linked to the listing —
     * the downstream pitch send will produce its own audit row.
     */
    private function fireMicProspectLaunched(object $listing, int $agencyId, int $currentUserId): void
    {
        $tpId = $listing->tracked_property_id ?? null;
        if (!$tpId) return;
        $tp = TrackedProperty::withoutGlobalScopes()
            ->where('id', (int) $tpId)
            ->where('agency_id', $agencyId)
            ->first();
        if (!$tp) return;
        Event::dispatch(new MapProspectLaunched(
            trackedProperty: $tp,
            agencyId:        $agencyId,
            actingUserId:    $currentUserId,
            locationKey:     'mic_entry:listing_' . (int) $listing->id,
            source:          'mic_entry_point',
        ));
    }
}
