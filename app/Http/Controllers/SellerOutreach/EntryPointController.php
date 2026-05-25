<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Services\Prospecting\PitchLockConflictException;
use App\Services\Prospecting\ProspectingClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    public function fromProperty(Request $request, Property $property)
    {
        $agencyId = $this->resolveAgencyId($request);
        if ((int) $property->agency_id !== $agencyId) {
            abort(404);
        }

        $sellers = $this->loadSellerContactsForProperty($agencyId, $property);

        if ($sellers->isEmpty()) {
            return redirect()
                ->route('corex.properties.show', $property)
                ->with('error', 'No seller contact linked to this property. Link a seller contact before composing a pitch.');
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

    public function fromProspecting(Request $request, int $prospectingListingId)
    {
        $agencyId = $this->resolveAgencyId($request);

        $listing = DB::table('prospecting_listings')
            ->where('id', $prospectingListingId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();
        abort_if(!$listing, 404);

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

        return view('seller-outreach.entry.prospecting-create-contact', [
            'listing' => $listing,
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

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:255',
            // A.2.5 — optional SA ID number with format + checksum validation.
            'id_number'  => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
        ]);

        $idNumber = isset($validated['id_number']) ? preg_replace('/\s+/', '', (string) $validated['id_number']) : null;

        if (empty($validated['phone']) && empty($validated['email'])) {
            return back()
                ->withErrors(['contact_required' => 'Provide a phone or email so we can dedupe and reach the seller.'])
                ->withInput();
        }

        $existing = $this->findExistingContact($agencyId, $validated);
        $isNew = $existing === null;

        $result = DB::transaction(function () use ($request, $agencyId, $listing, $validated, $existing, $idNumber) {
            // Branch context is mandatory on Contact rows in CoreX schema.
            $branchId = $request->user()->branch_id;

            $contact = $existing ?: Contact::create(array_filter([
                'agency_id'             => $agencyId,
                'branch_id'             => $branchId,
                'first_name'            => $validated['first_name'],
                // contacts.last_name is NOT NULL in the schema — store an empty
                // string when the agent skipped it. The spec's S3 dedupe only
                // needs first_name + phone/email anyway.
                'last_name'             => $validated['last_name'] ?? '',
                'phone'                 => $validated['phone'] ?? null,
                'email'                 => $validated['email'] ?? null,
                'created_by_user_id'    => $request->user()->id,
                // A.2.5 — POPIA audit. captured_at + source are only set when
                // the agent actually filled in the ID; null otherwise.
                'id_number'             => $idNumber,
                'id_number_captured_at' => $idNumber ? now() : null,
                'id_number_source'      => $idNumber ? 'seller_outreach_entry' : null,
            ], static fn ($v) => $v !== null && $v !== ''));

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

            $property = $this->promoteListingToProperty($agencyId, $listing, $request->user());

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

            return [$contact, $property];
        });

        [$contact, $property] = $result;

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

    /**
     * Dedupe per spec S3:
     *  - exact phone (normalised digits-only) → hard match
     *  - exact email (lowercased, trimmed) → hard match
     *  - soft name matches: surfaced for v2; v1 creates a new contact.
     */
    private function findExistingContact(int $agencyId, array $data): ?Contact
    {
        $normalisedPhone = !empty($data['phone'])
            ? preg_replace('/\D/', '', (string) $data['phone'])
            : null;
        $normalisedEmail = !empty($data['email'])
            ? strtolower(trim((string) $data['email']))
            : null;

        if ($normalisedPhone) {
            $existing = Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereRaw("REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?", [$normalisedPhone])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        if ($normalisedEmail) {
            $existing = Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalisedEmail])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return null;
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
    private function promoteListingToProperty(int $agencyId, $listing, $actor): Property
    {
        $address = trim((string) ($listing->address ?? ''));
        $suburb  = trim((string) ($listing->suburb ?? ''));
        $normalised = trim(strtolower((string) ($listing->normalized_address ?? $address)));

        $existing = Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($address, $normalised, $listing) {
                $q->where('external_id', 'prospecting:' . $listing->id)
                  ->orWhere('address', $address)
                  ->orWhereRaw('LOWER(TRIM(address)) = ?', [$normalised]);
            })
            ->when($suburb !== '', fn ($q) => $q->where(function ($qq) use ($suburb) {
                $qq->where('suburb', $suburb)->orWhereNull('suburb');
            }))
            ->first();
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

        // properties.beds/baths/garages/price/suburb/property_type/status are
        // NOT NULL — fall back to the schema defaults (0 / empty / 'house' /
        // 'draft') when the prospecting row doesn't carry the value.
        return Property::create([
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
        ]);
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

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($id === null, 403, 'No agency context — super_admin without an active agency cannot compose pitches.');
        return (int) $id;
    }
}
