<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Contact;
use App\Models\ContactDeadEndFlag;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TvaContactCapture;
use App\Models\Prospecting\TvaContactCaptureItem;
use App\Models\Scopes\ContactScope;
use App\Services\Contacts\ContactIdentifierService;
use App\Services\ContactDuplicateService;
use Illuminate\Support\Facades\DB;

/**
 * MIC compose — multi-seller link (Part A) + TVA number picker (Part B) — Johan 2026-08-14.
 *
 * Property (1) → many seller-links (contact_property role=seller) → many STANDALONE Contacts,
 * each its own canonical record keyed on its own SA ID. Never merged. The TVA picker writes the
 * agent-chosen scraped numbers onto the RESPECTIVE individual Contact.
 *
 * Read/call only across the contact pillar (cc3): resolves/creates Contacts on SA ID via
 * ContactDuplicateService, writes numbers via ContactPhone/ContactEmail + ContactIdentifierService
 * (the exact machinery the deeds-capture TVA ingest uses) — never edits those services.
 */
class ComposeSellerService
{
    public function __construct(
        private readonly ContactDuplicateService $dupes,
        private readonly ContactIdentifierService $identifiers,
    ) {}

    /**
     * The compose screen's live seller + TVA state for a listing: the sellers already linked to the
     * (promoted) property, and the TVA captures matched to them by SA ID with their un-ingested
     * scraped numbers for the agent to pick.
     *
     * @return array{property_id:int|null, sellers:array<int,array<string,mixed>>, tva:array<string,array<string,mixed>>}
     */
    public function payload(int $agencyId, object $listing): array
    {
        $propertyId = ! empty($listing->matched_property_id) ? (int) $listing->matched_property_id : null;
        $sellers = $propertyId !== null ? $this->linkedSellers($agencyId, $propertyId) : [];

        $idNumbers = array_values(array_filter(array_map(fn ($s) => $s['id_number'], $sellers)));

        $linkedDeed = null;
        if (! empty($listing->linked_deed_tracked_property_id)) {
            $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->find((int) $listing->linked_deed_tracked_property_id);
            if ($tp) {
                $linkedDeed = ['tracked_property_id' => (int) $tp->id, 'address' => $this->deedAddressLine($tp)];
            }
        }

        return [
            'property_id' => $propertyId,
            'sellers'     => $sellers,
            'tva'         => $this->tvaForIdNumbers($agencyId, $idNumbers),
            'linked_deed' => $linkedDeed,
            'removed'     => $this->removedIdNumbers((int) $listing->id),
        ];
    }

    /**
     * Contacts linked to the property as sellers, each with its own numbers + dead-end flag.
     *
     * @return array<int,array<string,mixed>>
     */
    public function linkedSellers(int $agencyId, int $propertyId): array
    {
        $links = DB::table('contact_property')
            ->where('property_id', $propertyId)
            ->where('role', 'seller')
            ->get(['contact_id', 'is_primary'])
            ->keyBy('contact_id');

        if ($links->isEmpty()) {
            return [];
        }

        $contacts = Contact::withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agencyId)
            ->whereIn('id', $links->keys()->all())
            ->with(['phones', 'emails', 'deadEndFlag'])
            ->get();

        return $contacts->map(function (Contact $c) use ($links) {
            // Contactable = the seller has at least one way to reach them (a ticked TVA/typed number
            // or email). This — not the empty single-form input — is the redesigned "continue" gate.
            $contactable = $c->phones->isNotEmpty() || $c->emails->isNotEmpty()
                || trim((string) $c->phone) !== '' || trim((string) $c->email) !== '';

            return [
                'contact_id'  => (int) $c->id,
                'first_name'  => (string) ($c->first_name ?? ''),
                'last_name'   => (string) ($c->last_name ?? ''),
                'id_number'   => $c->id_number !== null && $c->id_number !== '' ? (string) $c->id_number : null,
                'phones'      => $c->phones->map(fn ($p) => ['value' => $p->phone, 'label' => $p->label, 'is_primary' => (bool) $p->is_primary])->values()->all(),
                'emails'      => $c->emails->map(fn ($e) => ['value' => $e->email, 'label' => $e->label, 'is_primary' => (bool) $e->is_primary])->values()->all(),
                'dead_end'    => $c->deadEndFlag ? ['reason' => $c->deadEndFlag->reason, 'label' => ContactDeadEndFlag::reasonLabel($c->deadEndFlag->reason)] : null,
                'is_primary'  => (bool) ($links[$c->id]->is_primary ?? false),
                'contactable' => $contactable,
            ];
        })->sortByDesc('is_primary')->values()->all();
    }

    /** Make ONE seller the primary for the property (others become secondary). */
    public function markPrimary(int $propertyId, int $contactId): void
    {
        DB::transaction(function () use ($propertyId, $contactId) {
            DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')
                ->update(['is_primary' => false, 'updated_at' => now()]);
            DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')
                ->where('contact_id', $contactId)
                ->update(['is_primary' => true, 'updated_at' => now()]);
        });
    }

    /** Default the primary to the first-linked seller when none is designated yet. */
    public function ensurePrimaryDefault(int $propertyId): void
    {
        $hasPrimary = DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')
            ->where('is_primary', true)->exists();
        if ($hasPrimary) {
            return;
        }
        $firstId = DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')
            ->orderBy('id')->value('contact_id');
        if ($firstId) {
            $this->markPrimary($propertyId, (int) $firstId);
        }
    }

    /** Per-seller "No contact details" dead-end acknowledgement (a seller with nothing to reach). */
    public function markSellerDeadEnd(int $agencyId, int $contactId, ?int $propertyId, string $reason, int $userId): void
    {
        ContactDeadEndFlag::updateOrCreate(
            ['contact_id' => $contactId],
            [
                'agency_id'          => $agencyId,
                'property_id'        => $propertyId,
                'reason'             => ContactDeadEndFlag::normaliseReason($reason),
                'source'             => 'seller_outreach',
                'created_by_user_id' => $userId,
            ],
        );
    }

    /** Clear a dead-end flag (the seller became contactable, e.g. a number was ticked). */
    public function clearSellerDeadEnd(int $agencyId, int $contactId): void
    {
        ContactDeadEndFlag::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('contact_id', $contactId)->delete();
    }

    /**
     * The redesigned continue gate: seller names that are NOT reachable and NOT dead-end-flagged.
     * Empty array → every linked seller is either contactable or acknowledged as a dead end.
     *
     * @return array<int,string>
     */
    public function sellersNeedingContact(int $agencyId, int $propertyId): array
    {
        $names = [];
        foreach ($this->linkedSellers($agencyId, $propertyId) as $s) {
            if (! $s['contactable'] && empty($s['dead_end'])) {
                $names[] = trim($s['first_name'] . ' ' . $s['last_name']) ?: ('Contact #' . $s['contact_id']);
            }
        }

        return $names;
    }

    /**
     * TVA captures matched to the given seller SA IDs, keyed by id_number, with their un-ingested
     * (not opted-out) scraped numbers — the agent picks which land on which seller.
     *
     * @param  array<int,string>  $idNumbers
     * @return array<string,array<string,mixed>>
     */
    public function tvaForIdNumbers(int $agencyId, array $idNumbers): array
    {
        if ($idNumbers === []) {
            return [];
        }

        $captures = TvaContactCapture::query()
            ->where('agency_id', $agencyId)
            ->whereIn('id_number', $idNumbers)
            ->with(['items' => fn ($q) => $q->whereNull('ingested_at')->where('opted_out', false)])
            ->get();

        $out = [];
        foreach ($captures as $capture) {
            $items = $capture->items->map(fn ($i) => [
                'id'        => (int) $i->id,
                'type'      => $i->type,
                'value'     => $i->value,
                'date'      => $i->date ? $i->date->format('Y-m-d') : null,
                'link_date' => $i->link_date ? $i->link_date->format('Y-m-d') : null,
            ])->values()->all();

            if ($items === []) {
                continue;   // nothing left to pick for this person
            }

            // Keyed by id_number so the blade can line each capture up with its seller.
            $out[(string) $capture->id_number] = [
                'capture_id' => (int) $capture->id,
                'name'       => trim(($capture->first_name ?? '') . ' ' . ($capture->surname ?? '')),
                'items'      => $items,
            ];
        }

        return $out;
    }

    /**
     * Resolve-or-create a seller Contact on its SA ID (dedupes onto the deed owner — never a
     * duplicate). Back-fills the id number on an existing contact that lacked one.
     */
    public function resolveOrCreateSellerContact(int $agencyId, ?int $branchId, int $userId, string $firstName, string $lastName, string $idNumber): Contact
    {
        $existing = $this->dupes->findDuplicatesForIdentifiers([], [], $idNumber, $agencyId)->first();
        if ($existing) {
            if (empty($existing->id_number)) {
                $existing->update(['id_number' => $idNumber, 'id_number_captured_at' => now(), 'id_number_source' => 'seller_outreach_entry']);
            }

            return $existing;
        }

        return Contact::create([
            'agency_id'             => $agencyId,
            'branch_id'             => $branchId,
            'first_name'            => $firstName !== '' ? $firstName : 'Seller',
            'last_name'             => $lastName,
            'phone'                 => '',
            'id_number'             => $idNumber,
            'id_number_captured_at' => now(),
            'id_number_source'      => 'seller_outreach_entry',
            'created_by_user_id'    => $userId,
        ]);
    }

    /** Link a contact to the property as a seller (idempotent). `source`: 'deed' | 'manual'. */
    public function linkSellerToProperty(int $contactId, int $propertyId, string $source = 'manual'): void
    {
        DB::table('contact_property')->updateOrInsert(
            ['contact_id' => $contactId, 'property_id' => $propertyId],
            ['role' => 'seller', 'source' => $source, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Remove a seller link (the contact + property both survive — only the link is dropped). */
    public function unlinkSeller(int $contactId, int $propertyId): void
    {
        DB::table('contact_property')
            ->where('contact_id', $contactId)
            ->where('property_id', $propertyId)
            ->where('role', 'seller')
            ->delete();
    }

    /**
     * Write the agent-picked TVA numbers onto ONE specific seller Contact (Part B). Mirrors the
     * deeds-capture TVA ingest: dedupes, marks each item ingested, reconciles identifiers. Only the
     * passed item ids are written, and only onto this contact — never merged across sellers.
     *
     * @param  array<int,int>  $itemIds
     * @return int  number of values actually added
     */
    public function ingestPickedNumbers(int $agencyId, Contact $contact, array $itemIds): int
    {
        $items = TvaContactCaptureItem::query()
            ->whereIn('id', $itemIds)
            ->whereNull('ingested_at')
            ->whereHas('capture', fn ($q) => $q->where('agency_id', $agencyId))
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $added = 0;
        $addedPhones = false;
        $addedEmails = false;

        foreach ($items as $item) {
            if ($item->type === TvaContactCaptureItem::TYPE_EMAIL) {
                $normalised = strtolower(trim((string) $item->value));
                if (! $contact->emails()->whereRaw('LOWER(email) = ?', [$normalised])->exists()) {
                    $contact->emails()->create([
                        'agency_id' => $agencyId,
                        'email'     => $item->value,
                        'label'     => 'TVA capture' . ($item->link_date ? ' — linked ' . $item->link_date->format('Y-m-d') : ''),
                    ]);
                    $addedEmails = true;
                    $added++;
                }
            } else {
                $normalised = $this->dupes->normalizePhone((string) $item->value);
                if ($normalised && ! $contact->phones()->where('phone_normalised', $normalised)->exists()) {
                    $contact->phones()->create([
                        'agency_id' => $agencyId,
                        'phone'     => $item->value,
                        'label'     => 'TVA capture' . ($item->link_date ? ' — linked ' . $item->link_date->format('Y-m-d') : ''),
                    ]);
                    $addedPhones = true;
                    $added++;
                }
            }
            $item->update(['ingested_at' => now(), 'ingested_contact_id' => $contact->id]);
        }

        if ($addedPhones) {
            $this->identifiers->reconcilePhones($contact->id);
        }
        if ($addedEmails) {
            $this->identifiers->reconcileEmails($contact->id);
        }

        // A seller that now has a number is no longer a dead end — clear any acknowledgement.
        if ($added > 0) {
            $this->clearSellerDeadEnd($agencyId, (int) $contact->id);
        }

        return $added;
    }

    // ── R1: deed select / unselect / reselect (deed drives the sellers + address) ──────────────

    /** Deed owners (name + SA-ID) for a tracked property. */
    public function deedOwners(int $deedTpId): array
    {
        return DB::table('tracked_property_owners')->where('tracked_property_id', $deedTpId)
            ->orderByDesc('is_primary')->orderBy('id')
            ->get(['name', 'id_number', 'contact_id', 'is_primary'])
            ->map(fn ($r) => [
                'name'       => (string) $r->name,
                'id_number'  => $r->id_number !== null && $r->id_number !== '' ? (string) $r->id_number : null,
                'contact_id' => $r->contact_id ? (int) $r->contact_id : null,
                'is_primary' => (bool) $r->is_primary,
            ])->all();
    }

    /** A one-line display address for a deeds tracked property (deeds-office authoritative). */
    public function deedAddressLine(TrackedProperty $tp): string
    {
        $street = trim(implode(' ', array_filter([$tp->street_number, $tp->street_name])));
        $scheme = $tp->scheme_name ?: $tp->complex_name;

        return trim(implode(', ', array_filter([$street, $scheme, $tp->suburb]))) ?: (string) ($tp->town ?? '');
    }

    /**
     * Select a deed (R1): replace the listing's linked deed, sync the deed's owners as sellers
     * (skipping ones the agent explicitly removed), and populate the property address from the
     * deeds-office record. Deed-sourced sellers of the PREVIOUS deed that aren't in the new deed are
     * dropped; manual sellers are always kept.
     */
    public function selectDeed(int $agencyId, object $listing, int $propertyId, int $deedTpId, ?int $branchId, int $userId): void
    {
        $deedTp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->find($deedTpId);
        if (! $deedTp) {
            return;
        }

        DB::transaction(function () use ($agencyId, $listing, $propertyId, $deedTp, $deedTpId, $branchId, $userId) {
            DB::table('prospecting_listings')->where('id', $listing->id)->update([
                'linked_deed_tracked_property_id' => $deedTpId,
                'linked_deed_by_user_id'          => $userId,
                'linked_deed_at'                  => now(),
                'updated_at'                      => now(),
            ]);

            $owners = $this->deedOwners($deedTpId);
            $newIds = array_values(array_filter(array_map(fn ($o) => $o['id_number'], $owners)));

            // Drop prior deed-sourced sellers not in the new deed (keep manual sellers).
            $priorDeedContactIds = DB::table('contact_property')
                ->where('property_id', $propertyId)->where('role', 'seller')->where('source', 'deed')->pluck('contact_id');
            foreach ($priorDeedContactIds as $cid) {
                $idn = Contact::withoutGlobalScopes()->where('id', $cid)->value('id_number');
                if (! $idn || ! in_array((string) $idn, $newIds, true)) {
                    DB::table('contact_property')->where('property_id', $propertyId)->where('contact_id', $cid)->where('role', 'seller')->delete();
                }
            }

            // Auto-link the new deed's owners as sellers — skipping explicit removals (R2).
            $removed = $this->removedIdNumbers((int) $listing->id);
            foreach ($owners as $o) {
                if (! $o['id_number'] || in_array($o['id_number'], $removed, true)) {
                    continue;
                }
                [$first, $last] = $this->splitName($o['name']);
                $contact = $this->resolveOrCreateSellerContact($agencyId, $branchId, $userId, $first, $last, $o['id_number']);
                $this->linkSellerToProperty((int) $contact->id, $propertyId, 'deed');
            }

            $this->applyDeedAddress($propertyId, $deedTp);
            $this->ensurePrimaryDefault($propertyId);
        });
    }

    /** Unlink the deed (R1 revert): drop deed-sourced sellers (keep manual), clear the link, and
     *  revert the property address to the listing's portal address. */
    public function unlinkDeed(int $agencyId, object $listing, int $propertyId): void
    {
        DB::transaction(function () use ($listing, $propertyId) {
            DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')->where('source', 'deed')->delete();
            DB::table('prospecting_listings')->where('id', $listing->id)->update([
                'linked_deed_tracked_property_id' => null,
                'linked_deed_by_user_id'          => null,
                'linked_deed_at'                  => null,
                'updated_at'                      => now(),
            ]);
            $this->applyListingAddress($propertyId, $listing);
            $this->ensurePrimaryDefault($propertyId);
        });
    }

    private function applyDeedAddress(int $propertyId, TrackedProperty $tp): void
    {
        $prop = Property::withoutGlobalScopes()->find($propertyId);
        if (! $prop) {
            return;
        }
        $prop->update(array_filter([
            'address'       => $this->deedAddressLine($tp),
            'street_number' => $tp->street_number,
            'street_name'   => $tp->street_name,
            'suburb'        => $tp->suburb,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    private function applyListingAddress(int $propertyId, object $listing): void
    {
        $prop = Property::withoutGlobalScopes()->find($propertyId);
        if (! $prop) {
            return;
        }
        $prop->update(array_filter([
            'address' => $listing->address ?? null,
            'suburb'  => $listing->suburb ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    // ── R2: sticky removals ────────────────────────────────────────────────────────────────────

    /** @return array<int,string> id_numbers the agent explicitly removed from this listing. */
    public function removedIdNumbers(int $listingId): array
    {
        return DB::table('prospecting_seller_removals')->where('prospecting_listing_id', $listingId)
            ->pluck('id_number')->map(fn ($v) => (string) $v)->all();
    }

    public function recordRemoval(int $agencyId, int $listingId, ?string $idNumber, ?int $userId): void
    {
        if (empty($idNumber)) {
            return;
        }
        DB::table('prospecting_seller_removals')->updateOrInsert(
            ['prospecting_listing_id' => $listingId, 'id_number' => $idNumber],
            ['agency_id' => $agencyId, 'removed_by_user_id' => $userId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function clearRemoval(int $listingId, ?string $idNumber): void
    {
        if (empty($idNumber)) {
            return;
        }
        DB::table('prospecting_seller_removals')->where('prospecting_listing_id', $listingId)->where('id_number', $idNumber)->delete();
    }

    // ── R3: per-number remove + primary ────────────────────────────────────────────────────────

    public function removeNumber(Contact $contact, string $type, string $value): void
    {
        if ($type === 'email') {
            $contact->emails()->where('email', $value)->delete();
        } else {
            $contact->phones()->where('phone', $value)->delete();
        }
    }

    public function setPrimaryNumber(Contact $contact, string $type, string $value): void
    {
        if ($type === 'email') {
            $contact->emails()->update(['is_primary' => false]);
            $contact->emails()->where('email', $value)->update(['is_primary' => true]);
        } else {
            $contact->phones()->update(['is_primary' => false]);
            $contact->phones()->where('phone', $value)->update(['is_primary' => true]);
        }
    }

    /** @return array{0:string,1:string} split a full name into [first, last]. */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name);
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}
