<?php

namespace App\Services\Rentals\TakeOnImport;

/**
 * .ai/specs/rental-takeon-import.md §11 (Landing 2) — "map its columns to
 * the template fields on screen (auto-suggest by header name)". A small,
 * bounded heuristic: normalised exact match, normalised containment, and a
 * short synonym list per field for the handful of header words a real CRM
 * export is likely to actually use (Rent, Tenant, Landlord/Owner, etc.) —
 * deliberately not a general fuzzy-matching/NLP library. Never auto-decides
 * silently: this only produces a SUGGESTION the map-columns screen pre-fills
 * and the admin can change or clear before confirming (leases.md §6's own
 * "never auto-link on an unconfident signal" principle, applied here to
 * column identity instead of property/contact identity).
 */
class RentalTakeOnColumnMappingSuggester
{
    /**
     * A few extra normalised words/phrases real CRM exports commonly use,
     * on top of the template's own field label. Keys are RentalTakeOnFieldSchema
     * field keys; values are already-normalised (see normalise()) synonyms.
     */
    private const SYNONYMS = [
        // Deliberately NOT a bare 'street' synonym — that loosely matches
        // "Street NUMBER" too (containment check), stealing the match
        // before street_number's own, more specific label is ever
        // reached. 'address' alone is specific enough on its own.
        'street_name' => ['address'],
        'suburb' => ['area', 'town'],
        'unit_complex_name' => ['complex', 'unit', 'building'],
        'landlord1_name' => ['landlord', 'owner', 'owner name', 'landlordname'],
        'landlord1_id_or_reg' => ['landlordid', 'ownerid', 'idnumber', 'companyregno'],
        'landlord1_email' => ['landlordemail', 'owneremail'],
        'landlord1_phone' => ['landlordphone', 'landlordcell', 'ownerphone'],
        'tenant1_name' => ['tenant', 'tenantname', 'occupant'],
        'tenant1_id_number' => ['tenantid', 'idnumber'],
        'tenant1_email' => ['tenantemail'],
        'tenant1_phone' => ['tenantphone', 'tenantcell'],
        'lease_start_date' => ['startdate', 'leasestart', 'commencementdate', 'movein'],
        'lease_end_date' => ['enddate', 'leaseend', 'expirydate'],
        'monthly_rental_amount' => ['rent', 'rental', 'monthlyrent', 'rentamount'],
        'escalation_percent' => ['escalation', 'annualincrease', 'increase'],
        'next_escalation_date' => ['nextincrease', 'escalationdate'],
        'deposit_held' => ['deposit', 'depositamount'],
        'arrears_opening_balance' => ['arrears', 'openingbalance', 'balance'],
        'agent_email' => ['agent', 'agentname'],
        'notes' => ['note', 'comments', 'remarks'],
    ];

    /**
     * @param array<int, string> $uploadedHeaders  the uploaded file's own header row, in column order
     * @return array<string, int>  field key => uploaded-file column index, for every field a confident guess was made for (never every field — an unmatched field is simply absent, left for the admin to pick by hand)
     */
    public function suggest(array $uploadedHeaders): array
    {
        $normalisedHeaders = [];
        foreach ($uploadedHeaders as $i => $header) {
            $normalisedHeaders[$i] = self::normalise($header);
        }

        $suggestions = [];
        foreach (RentalTakeOnFieldSchema::fields() as $field) {
            $key = $field['key'];
            $candidates = array_merge([self::normalise($field['label'])], self::SYNONYMS[$key] ?? []);

            $bestIndex = null;
            foreach ($normalisedHeaders as $i => $normalisedHeader) {
                if ($normalisedHeader === '') {
                    continue;
                }
                foreach ($candidates as $candidate) {
                    if ($candidate === '') {
                        continue;
                    }
                    if ($normalisedHeader === $candidate
                        || str_contains($normalisedHeader, $candidate)
                        || str_contains($candidate, $normalisedHeader)) {
                        $bestIndex = $i;
                        break 2;
                    }
                }
            }

            if ($bestIndex !== null) {
                $suggestions[$key] = $bestIndex;
            }
        }

        return $suggestions;
    }

    /**
     * Resolves a SAVED mapping (field_key => the header text it was saved
     * against) onto THIS upload's own header row — exact normalised match
     * only, no synonym guessing (we already know precisely which header
     * text we're looking for). A saved field whose header text no longer
     * appears in this file (the CRM renamed or removed that column) is
     * simply omitted — the map-columns screen leaves it for the admin to
     * re-pick by hand rather than silently guessing a replacement.
     *
     * @param array<string, string> $fieldKeyToHeaderText  from RentalTakeOnColumnMapping::mapping_json
     * @param array<int, string> $uploadedHeaders
     * @return array<string, int> field key => column index
     */
    public function resolveSavedMapping(array $fieldKeyToHeaderText, array $uploadedHeaders): array
    {
        $normalisedHeaders = array_map(fn ($h) => self::normalise($h), $uploadedHeaders);

        $resolved = [];
        foreach ($fieldKeyToHeaderText as $key => $headerText) {
            $normalisedTarget = self::normalise($headerText);
            if ($normalisedTarget === '') {
                continue;
            }
            $index = array_search($normalisedTarget, $normalisedHeaders, true);
            if ($index !== false) {
                $resolved[$key] = $index;
            }
        }

        return $resolved;
    }

    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }
}
