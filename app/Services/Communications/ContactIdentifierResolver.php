<?php

namespace App\Services\Communications;

use App\Models\Contact;
use App\Services\ContactDuplicateService;

/**
 * The shared known-contact gate (AT-32, spec §7.5). Resolves an email address
 * or phone/WhatsApp number to a Contact within an agency. Both capture adapters
 * call this before writing to the permanent archive.
 *
 * Reuses ContactDuplicateService::normalizePhone() (last-9-digits) for numbers
 * and strtolower(trim()) for email. This is a SYSTEM-level gate: it must see
 * every contact in the agency regardless of who (if anyone) is authenticated,
 * so it drops ALL global scopes (agency, branch, AND the per-user contact
 * visibility scope) and filters by explicit agency_id. Without this, capture
 * run under an agent's session would miss contacts the agent can't personally
 * see and wrongly park their messages in the pending buffer.
 */
class ContactIdentifierResolver
{
    public function __construct(private ContactDuplicateService $duplicates)
    {
    }

    public function resolve(string $identifier, int $agencyId): ?Contact
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return str_contains($identifier, '@')
            ? $this->resolveEmail($identifier, $agencyId)
            : $this->resolvePhone($identifier, $agencyId);
    }

    private function resolveEmail(string $email, int $agencyId): ?Contact
    {
        $normalized = strtolower(trim($email));

        return $this->baseQuery($agencyId)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->first();
    }

    private function resolvePhone(string $phone, int $agencyId): ?Contact
    {
        $normalized = $this->duplicates->normalizePhone($phone);
        if ($normalized === null) {
            return null;
        }

        // Mirrors ContactDuplicateService::normalizeDbExpression('phone').
        return $this->baseQuery($agencyId)
            ->whereNotNull('phone')
            ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?", [$normalized])
            ->first();
    }

    private function baseQuery(int $agencyId)
    {
        // Drop ALL global scopes (agency, branch, per-user contact visibility):
        // a deterministic system gate, not a per-user view. withoutGlobalScopes()
        // also drops SoftDeletes, so re-exclude trashed contacts explicitly.
        // Tenancy is enforced by the explicit agency_id filter.
        return Contact::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId);
    }
}
