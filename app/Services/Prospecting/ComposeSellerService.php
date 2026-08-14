<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Contact;
use App\Models\ContactDeadEndFlag;
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

        return [
            'property_id' => $propertyId,
            'sellers'     => $sellers,
            'tva'         => $this->tvaForIdNumbers($agencyId, $idNumbers),
        ];
    }

    /**
     * Contacts linked to the property as sellers, each with its own numbers + dead-end flag.
     *
     * @return array<int,array<string,mixed>>
     */
    public function linkedSellers(int $agencyId, int $propertyId): array
    {
        $contactIds = DB::table('contact_property')
            ->where('property_id', $propertyId)
            ->where('role', 'seller')
            ->pluck('contact_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($contactIds === []) {
            return [];
        }

        $contacts = Contact::withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agencyId)
            ->whereIn('id', $contactIds)
            ->with(['phones', 'emails', 'deadEndFlag'])
            ->get();

        return $contacts->map(fn (Contact $c) => [
            'contact_id' => (int) $c->id,
            'first_name' => (string) ($c->first_name ?? ''),
            'last_name'  => (string) ($c->last_name ?? ''),
            'id_number'  => $c->id_number !== null && $c->id_number !== '' ? (string) $c->id_number : null,
            'phones'     => $c->phones->map(fn ($p) => ['value' => $p->phone, 'label' => $p->label])->values()->all(),
            'emails'     => $c->emails->map(fn ($e) => ['value' => $e->email, 'label' => $e->label])->values()->all(),
            'dead_end'   => $c->deadEndFlag ? ['reason' => $c->deadEndFlag->reason, 'label' => ContactDeadEndFlag::reasonLabel($c->deadEndFlag->reason)] : null,
        ])->values()->all();
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

    /** Link a contact to the property as a seller (idempotent). */
    public function linkSellerToProperty(int $contactId, int $propertyId): void
    {
        DB::table('contact_property')->updateOrInsert(
            ['contact_id' => $contactId, 'property_id' => $propertyId],
            ['role' => 'seller', 'updated_at' => now(), 'created_at' => now()],
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

        return $added;
    }
}
