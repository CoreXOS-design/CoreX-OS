<?php

namespace App\Services\Communications;

use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\BranchScope;
use App\Services\ContactDuplicateService;

/**
 * The shared known-contact gate (AT-32, spec §7.5). Resolves an email address
 * or phone/WhatsApp number to a Contact within an agency. Both capture adapters
 * call this before writing to the permanent archive.
 *
 * Reuses ContactDuplicateService::normalizePhone() (last-9-digits) for numbers
 * and strtolower(trim()) for email. Queries bypass the agency/branch global
 * scopes and filter by explicit agency_id, so it is correct from console/jobs
 * (no auth context) as well as web requests.
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
        return Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(BranchScope::class)
            ->where('agency_id', $agencyId);
    }
}
