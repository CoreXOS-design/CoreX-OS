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
            ->when($specialty, fn ($q) => $q->capableOf($specialty)) // AT-364 — attorney pickers surface capability-flagged firms too

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
            // AT-364 — stamp the fixed attorney capability that matches the specialty at create time,
            // so a directory-added transfer/bond attorney is immediately picker-visible.
            'is_transfer_attorney' => $specialty === 'transfer_attorney',
            'is_bond_attorney' => $specialty === 'bond_attorney',
            'company' => $data['company'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
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

    /**
     * Johan, 2026-08-25 — the real duplicate check for adding a supplier's
     * working contact (attorney/conveyancer/executor) from inside the e-sign
     * wizard. Explicitly NOT the existing quick-add-contact precedent found
     * elsewhere in the codebase (an ID-number-only check) — the exact gap
     * flagged as the reason to build this properly: a phone number one or
     * two digits off a real match (a transposed-digit typo, not a genuine
     * different person) is invisible to an exact-only comparison, and a
     * supplier's working contact has no ID number to fall back on at all
     * (confirmed: AgencyServiceProviderContact carries none).
     *
     * Three independent signals, any one of which surfaces a possible match
     * for the agent to confirm — never a silent auto-merge, never a silent
     * miss because only one field happened to be checked:
     *   - email, exact (case/whitespace-insensitive) — the strongest signal
     *     a supplier record actually has.
     *   - phone, exact on the same normalised ZA-mobile form
     *     ContactDuplicateService already uses everywhere else, OR a close
     *     match (edit distance <= 2 on the normalised digits) — this is what
     *     actually catches a transposed-digit typo; an exact-only check
     *     would not.
     *   - name, normalised, fuzzy-matched WITHIN the same firm (or same
     *     firm name if adding under a new firm) — a same-named person at an
     *     unrelated firm is not flagged; two similarly-spelled names at the
     *     SAME firm are.
     *
     * Returns every AgencyServiceProviderContact that matched at least one
     * signal, each tagged with which signal(s) fired, so the caller can show
     * the agent WHY it thinks this might be the same person rather than a
     * bare list.
     *
     * @return \Illuminate\Support\Collection<int, array{contact: \App\Models\DealV2\AgencyServiceProviderContact, reasons: array<int, string>}>
     */
    public function findPossibleDuplicateContacts(
        int $agencyId,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?string $firmName = null,
    ): Collection {
        $normalizedEmail = $email ? strtolower(trim($email)) : null;
        $normalizedPhone = $phone ? app(\App\Services\ContactDuplicateService::class)->normalizePhone($phone) : null;
        $normalizedName  = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $normalizedFirm  = $firmName ? strtolower(trim(preg_replace('/\s+/', ' ', $firmName))) : null;

        $candidates = \App\Models\DealV2\AgencyServiceProviderContact::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->with('firm')
            ->get();

        $matches = collect();
        foreach ($candidates as $candidate) {
            $reasons = [];

            if ($normalizedEmail && $candidate->email && strtolower(trim($candidate->email)) === $normalizedEmail) {
                $reasons[] = 'same email on file';
            }

            if ($normalizedPhone && $candidate->phone) {
                $candidatePhone = app(\App\Services\ContactDuplicateService::class)->normalizePhone($candidate->phone);
                if ($candidatePhone) {
                    if ($candidatePhone === $normalizedPhone) {
                        $reasons[] = 'same phone number';
                    } elseif (strlen($candidatePhone) === strlen($normalizedPhone)
                        && levenshtein($candidatePhone, $normalizedPhone) <= 2) {
                        // The exact case the ID-only precedent would have
                        // missed: a phone number one or two digits off — a
                        // likely transposed-digit typo, not a different
                        // person's genuinely different number.
                        $reasons[] = 'very similar phone number — possible typo';
                    }
                }
            }

            $candidateName = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($candidate->attorney_name ?: $candidate->contact_person ?: ''))));
            if ($candidateName !== '' && $normalizedName !== '') {
                $candidateFirm = $candidate->firm ? strtolower(trim(preg_replace('/\s+/', ' ', $candidate->firm->name))) : null;
                $sameFirm = $normalizedFirm !== null && $candidateFirm === $normalizedFirm;
                if ($sameFirm && (
                    $candidateName === $normalizedName
                    || levenshtein($candidateName, $normalizedName) <= 2
                )) {
                    $reasons[] = 'similar name at the same firm';
                }
            }

            if (! empty($reasons)) {
                $matches->push(['contact' => $candidate, 'reasons' => $reasons]);
            }
        }

        return $matches->values();
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
