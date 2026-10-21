<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\RentalTakeOnImportRow;

/**
 * AT-479 (Johan, 21 Oct) — the people of a company owner / tenant, chosen on the review screen so the link is made at import and
 * not "sorted out later". Stored per party in decisions_json[party]['people'] as entries
 *   { key, contact_id?  (an existing natural-person contact)  |  first_name, last_name, phone, email  (to be created),
 *     capacity (one of ContactRepresentative::CAPACITIES), via? ('matched_person' when it came from the one-click) }.
 * Written to the existing `contact_representatives` relation (Contact::representatives()) at import — no new table.
 */
final class ReosCompanyPeople
{
    /** The role used for a person who was matched to the company by phone / e-mail: CoreX has no "contact person" capacity, "Other" is its free-form one. */
    public const CONTACT_PERSON_CAPACITY = 'Other';

    /**
     * Is this party a company contact right now — or about to be one? (action 'create' with a company name in reOS, or a link to an
     * entity contact). People can only be added to such a party.
     */
    public static function isCompanyParty(RentalTakeOnImportRow $row, string $party): bool
    {
        $m = ($party === 'owner' ? $row->landlord_match_json : $row->tenant_match_json)[1] ?? null;
        $dec = ($row->decisions_json ?? [])[$party] ?? [];
        if (! $m) {
            return false;
        }

        return match ($dec['action'] ?? 'none') {
            'create' => ($m['kind'] ?? 'person') === 'company',
            'link' => (bool) (($c = Contact::withoutGlobalScopes()->whereNull('deleted_at')->find((int) ($dec['contact_id'] ?? 0))) && $c->isEntity()),
            default => false,
        };
    }

    /** The people already linked in CoreX to the company this party will use (empty when the company is new). */
    public static function existingPeople(RentalTakeOnImportRow $row, string $party): array
    {
        $dec = ($row->decisions_json ?? [])[$party] ?? [];
        if (($dec['action'] ?? null) !== 'link' || empty($dec['contact_id'])) {
            return [];
        }
        $company = Contact::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $dec['contact_id']);
        if (! $company || ! $company->isEntity()) {
            return [];
        }

        return $company->representatives()->get()->map(fn (Contact $p) => [
            'id' => (int) $p->id,
            'name' => (string) $p->full_name,
            'capacity' => $p->pivot->capacity ?: null,
        ])->values()->all();
    }

    /** @return string  a stable key for an entry, so the screen can name the one to remove / change */
    public static function keyFor(array $entry): string
    {
        return isset($entry['contact_id'])
            ? 'c' . (int) $entry['contact_id']
            : 'n' . substr(md5(ReosContactNames::key(trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''))) . '|' . mb_strtolower((string) ($entry['email'] ?? '')) . '|' . preg_replace('/\D/', '', (string) ($entry['phone'] ?? ''))), 0, 10);
    }

    public static function capacities(): array
    {
        return ContactRepresentative::CAPACITIES;
    }
}
