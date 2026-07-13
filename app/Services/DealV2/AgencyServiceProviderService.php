<?php

namespace App\Services\DealV2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealV2;
use Illuminate\Support\Collection;

/**
 * WS2 (AT-158 / DR2, D2) — the provider-directory brain: pick-or-create-inline,
 * reuse across deals, attach to a deal party row, deactivate (preserving
 * history). Agency-scoped throughout.
 */
class AgencyServiceProviderService
{
    /** Directory search for the picker: active, optional specialty + text, preferred first. */
    public function search(int $agencyId, ?string $specialty = null, ?string $term = null, int $limit = 20): Collection
    {
        return AgencyServiceProvider::query()
            ->withoutGlobalScopes()->where('agency_id', $agencyId)
            ->active()
            ->when($specialty, fn ($q) => $q->forSpecialty($specialty))
            ->when($term, function ($q) use ($term) {
                $t = '%' . trim($term) . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $t)->orWhere('company', 'like', $t)->orWhere('email', 'like', $t));
            })
            ->pickerOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * Pick-or-create-inline: return an existing directory provider that matches
     * (agency + specialty + same email, else same normalised name), or create a
     * new one. Idempotent — "the electrician we always use" is stored once and
     * reused across deals.
     */
    public function findOrCreate(int $agencyId, array $data, ?int $userId = null): AgencyServiceProvider
    {
        // AT-253 (STANDARDS Rule 17) — this CREATES a row stamped with $agencyId, and callers
        // reach it by casting a possibly-null effectiveAgencyId() to int, which turns NULL into
        // 0. Agency 0 has no parent row, so the insert would violate the FK and 500 the page
        // (SupplierDirectoryController:51 was one unguarded route away from exactly that).
        // A write with no tenant to write into is a question, not a fallback: say so.
        if ($agencyId <= 0) {
            throw new \App\Exceptions\MissingAgencyContextException('a service provider');
        }

        $specialty = $data['specialty'] ?? 'other';
        $name = trim((string) ($data['name'] ?? ''));
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;

        $existing = AgencyServiceProvider::query()
            ->withoutGlobalScopes()->where('agency_id', $agencyId)
            ->where('specialty', $specialty)
            ->when($email, fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]),
                   fn ($q) => $q->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)]))
            ->first();

        if ($existing) {
            // Reactivate a previously-deactivated match rather than duplicate.
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }
            return $existing;
        }

        return AgencyServiceProvider::create([
            'agency_id' => $agencyId,
            'contact_id' => $data['contact_id'] ?? null,
            'name' => $name,
            'specialty' => $specialty,
            'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_preferred' => (bool) ($data['is_preferred'] ?? false),
            'is_active' => true,
            'created_by_id' => $userId,
        ]);
    }

    /** Attach a directory provider to a deal under a party role (idempotent per role+provider). */
    public function attachToDeal(DealV2 $deal, AgencyServiceProvider $provider, string $role): void
    {
        $exists = $deal->providerParties()
            ->wherePivot('agency_service_provider_id', $provider->id)
            ->wherePivot('role', $role)
            ->exists();

        if (! $exists) {
            $deal->providerParties()->attach($provider->id, ['role' => $role]);
        }
    }

    /** Mark exactly one preferred provider per specialty within the agency. */
    public function markPreferred(AgencyServiceProvider $provider): void
    {
        AgencyServiceProvider::query()->withoutGlobalScopes()
            ->where('agency_id', $provider->agency_id)
            ->where('specialty', $provider->specialty)
            ->where('id', '!=', $provider->id)
            ->update(['is_preferred' => false]);
        $provider->update(['is_preferred' => true]);
    }

    /** Deactivate (soft) — hides from new pickers; historic deal references keep resolving. */
    public function deactivate(AgencyServiceProvider $provider): void
    {
        $provider->update(['is_active' => false]);
    }
}
