<?php

namespace App\Services;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Detects and handles duplicate contacts on creation.
 *
 * Four modes (configured per agency in agency_contact_settings.duplicate_mode):
 *   - auto_link: silent, rejects creation, returns existing contact
 *   - soft_warn: returns duplicates for user to decide (use existing or create anyway)
 *   - hard_block_override: blocks creation, admin can override with reason
 *   - hard_block_request: blocks creation, agent can request access from owner (M3.5)
 *
 * To bypass duplicate detection (seeders, system imports):
 *   Pass $bypassDuplicateCheck = true to shouldBlock() or check before calling.
 */
class ContactDuplicateService
{
    /**
     * Find existing contacts matching the attempted data.
     * Matches on any field in the agency's configured duplicate_match_fields.
     * Agency-scoped only — never cross-agency.
     *
     * @return Collection<Contact> Up to 5 matching contacts
     */
    public function findDuplicates(array $data, int $agencyId): Collection
    {
        $settings = AgencyContactSettings::forAgency($agencyId);
        $matchFields = $settings->duplicate_match_fields ?? ['phone', 'email', 'id_number', 'entity_reg_no'];

        $query = Contact::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNull('purged_at');

        $query->where(function ($q) use ($data, $matchFields, $agencyId) {
            foreach ($matchFields as $field) {
                $value = $data[$field] ?? null;
                if (empty($value)) {
                    continue;
                }
                $normalized = $this->normalizeValue($field, $value);
                if ($normalized === null) {
                    continue;
                }
                // Mirror column — back-compat for contacts that don't yet carry
                // child identifier rows (created via the still-single-field form
                // /importers until AT-125 step 3). Keeps "more matches, never fewer".
                $q->orWhereRaw(
                    $this->normalizeDbExpression($field) . ' = ?',
                    [$normalized]
                );

                // AT-125 — also match ANY of the contact's identifiers (the child
                // tables), so a contact with several phones/emails is found by any
                // of them, not just the primary mirror. Same normalised keys as
                // step 1; indexed (agency_id, *_normalised) subquery; agency-scoped.
                if ($field === 'phone') {
                    $q->orWhereIn('id', ContactPhone::query()
                        ->withoutGlobalScopes()
                        ->whereNull('deleted_at')
                        ->where('agency_id', $agencyId)
                        ->where('phone_normalised', $normalized)
                        ->select('contact_id'));
                } elseif ($field === 'email') {
                    $q->orWhereIn('id', ContactEmail::query()
                        ->withoutGlobalScopes()
                        ->whereNull('deleted_at')
                        ->where('agency_id', $agencyId)
                        ->where('email_normalised', $normalized)
                        ->select('contact_id'));
                }
            }
        });

        return $query->with('createdBy')->limit(5)->get();
    }

    /**
     * AT-125 — duplicate detection for a MULTI-identifier create/edit: match any
     * of the incoming phones/emails (or the SA ID number) against ALL of every
     * contact's identifiers (child tables) OR the mirror columns. Used by the
     * contact form and importers so a contact is found by any incoming identifier
     * even if it lives on a secondary row. Agency-scoped; never cross-agency.
     *
     * @param array<int,string> $phones raw incoming phone strings
     * @param array<int,string> $emails raw incoming email strings
     * @return Collection<Contact>
     */
    public function findDuplicatesForIdentifiers(array $phones, array $emails, ?string $idNumber, int $agencyId, ?int $ignoreContactId = null): Collection
    {
        $normPhones = collect($phones)->map(fn ($p) => $this->normalizePhone((string) $p))->filter()->unique()->values();
        $normEmails = collect($emails)->map(fn ($e) => strtolower(trim((string) $e)))->filter(fn ($e) => $e !== '')->unique()->values();
        $normId = $idNumber ? preg_replace('/[\s\-]/', '', $idNumber) : null;

        if ($normPhones->isEmpty() && $normEmails->isEmpty() && empty($normId)) {
            return new Collection();
        }

        $query = Contact::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNull('purged_at');

        if ($ignoreContactId) {
            $query->where('id', '!=', $ignoreContactId);
        }

        $query->where(function ($q) use ($normPhones, $normEmails, $normId, $agencyId) {
            foreach ($normPhones as $norm) {
                $q->orWhereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 9) = ?", [$norm]);
                $q->orWhereIn('id', ContactPhone::query()->withoutGlobalScopes()
                    ->whereNull('deleted_at')->where('agency_id', $agencyId)
                    ->where('phone_normalised', $norm)->select('contact_id'));
            }
            foreach ($normEmails as $norm) {
                $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$norm]);
                $q->orWhereIn('id', ContactEmail::query()->withoutGlobalScopes()
                    ->whereNull('deleted_at')->where('agency_id', $agencyId)
                    ->where('email_normalised', $norm)->select('contact_id'));
            }
            if (! empty($normId)) {
                $q->orWhereRaw("REPLACE(REPLACE(id_number, ' ', ''), '-', '') = ?", [$normId]);
            }
        });

        return $query->with('createdBy')->limit(5)->get();
    }

    /**
     * Get the configured duplicate mode for an agency.
     */
    public function resolveMode(int $agencyId): string
    {
        $settings = AgencyContactSettings::forAgency($agencyId);
        return $settings->duplicate_mode ?? 'soft_warn';
    }

    /**
     * Log a duplicate detection event for audit.
     */
    public function logAttempt(
        int $agencyId,
        int $userId,
        string $mode,
        string $matchField,
        string $matchValue,
        ?int $existingContactId,
        array $attemptedData,
        string $actionTaken,
        ?string $overrideReason = null
    ): void {
        DB::table('contact_duplicate_log')->insert([
            'agency_id' => $agencyId,
            'attempted_by_user_id' => $userId,
            'mode_at_attempt' => $mode,
            'match_field' => $matchField,
            'match_value' => $matchValue,
            'existing_contact_id' => $existingContactId,
            'attempted_data' => json_encode($attemptedData),
            'action_taken' => $actionTaken,
            'override_reason' => $overrideReason,
            'created_at' => now(),
        ]);
    }

    /**
     * Normalize a value for comparison based on field type.
     */
    public function normalizeValue(string $field, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return match ($field) {
            'phone' => $this->normalizePhone($value),
            'email' => strtolower(trim($value)),
            'id_number' => preg_replace('/[\s\-]/', '', $value),
            // Entity foundation (.ai/specs/contact-entity-type.md §6.7) — an
            // entity dedups on its registration number, same normalization as
            // id_number (strip whitespace/hyphens; CIPC's '/' separators are
            // semantically meaningful and kept).
            'entity_reg_no' => preg_replace('/[\s\-]/', '', $value),
            default => $value,
        };
    }

    /**
     * Normalize a phone number to its dedup match key.
     * Handles: +27821234567, 0821234567, 082 123 4567, 27821234567, and any
     * international number (e.g. +1 415 555 2671).
     *
     * ZA forms (+27/27 prefix, leading-0 local, or a bare 9-digit SA mobile
     * core) collapse to the last 9 digits — BYTE-IDENTICAL to the original
     * behaviour, so every existing ZA dedup key is unchanged.
     *
     * Contact-details Phase 1 fix: everything else (any number that isn't in
     * one of those ZA shapes) is no longer collapsed to its last 9 digits.
     * That collapse was destroying identity for international numbers — e.g.
     * a US number "+1 415 555 2671" and an unrelated SA number both reducing
     * to "155552671" would be treated as the SAME contact. Non-ZA numbers now
     * keep their full digit string as the match key: still deterministic and
     * idempotent, but no longer collision-prone across countries.
     */
    public function normalizePhone(string $phone): ?string
    {
        // Strip everything except digits
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) < 9) {
            return null; // Too short to be valid
        }

        // ZA international form: +27/27 prefix -> strip it, then last-9 (unchanged).
        if (str_starts_with($digits, '27') && strlen($digits) >= 11) {
            return substr(substr($digits, 2), -9);
        }
        // ZA local form: leading 0 -> strip it, then last-9 (unchanged).
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return substr(substr($digits, 1), -9);
        }
        // Bare 9-digit SA mobile core, already in its collapsed form (unchanged).
        if (strlen($digits) === 9) {
            return $digits;
        }

        // Not a recognised ZA shape (e.g. an international number with its own
        // country code, or a bare non-ZA local number) — keep the full digits,
        // no destructive last-9 collapse.
        return $digits;
    }

    /**
     * Build a MySQL expression to normalize a DB column value for comparison.
     */
    private function normalizeDbExpression(string $field): string
    {
        return match ($field) {
            'phone' => "RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9)",
            'email' => "LOWER(TRIM(email))",
            'id_number' => "REPLACE(REPLACE(id_number, ' ', ''), '-', '')",
            'entity_reg_no' => "REPLACE(REPLACE(entity_reg_no, ' ', ''), '-', '')",
            default => $field,
        };
    }

    /**
     * Identify the first matching field and value for logging.
     */
    public function identifyMatch(array $data, Contact $existing, int $agencyId): array
    {
        $settings = AgencyContactSettings::forAgency($agencyId);
        $matchFields = $settings->duplicate_match_fields ?? ['phone', 'email', 'id_number', 'entity_reg_no'];

        foreach ($matchFields as $field) {
            $attemptedValue = $data[$field] ?? null;
            $existingValue = $existing->{$field} ?? null;
            if (empty($attemptedValue) || empty($existingValue)) {
                continue;
            }
            $normAttempted = $this->normalizeValue($field, $attemptedValue);
            $normExisting = $this->normalizeValue($field, $existingValue);
            if ($normAttempted && $normExisting && $normAttempted === $normExisting) {
                return ['field' => $field, 'value' => $attemptedValue];
            }
        }

        return ['field' => 'unknown', 'value' => ''];
    }
}
