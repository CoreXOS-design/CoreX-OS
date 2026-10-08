<?php

namespace App\Services\Rentals;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceType;
use App\Models\RentalFaultReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fault flow F3/F5 (Johan, 2026-10-08) - the contractor list offered with a fault: "the suppliers set up in CoreX for
 * that type of work (match on the fault category/trade)". ONE definition used by the owner's portal decision, the
 * agent's record-decision screen and the server-side validation of both, so what is offered, what is accepted and what
 * is stored can never drift.
 *
 * Matching: the fault's catalogue CATEGORY (rental_fault_types.category, e.g. "Plumbing") against the agency's service
 * types (AgencyServiceType code or label); a supplier qualifies when it is active and carries a matching service type
 * (AgencyServiceProviderServiceType). A category and a type match when they are equal once normalised, or one contains
 * the other ("Electrical" ~ "Electrical COC"). A fault with no category, or a category no service type of this agency
 * matches, has an EMPTY list - the screens then say so and offer the other routes; nothing is guessed.
 */
class RentalFaultContractorService
{
    /** @return Collection<int, array{id:int, name:string, phone:?string}> */
    public function optionsFor(RentalFaultReport $fault): Collection
    {
        $category = $fault->faultType?->category;
        if (! $category) {
            return collect();
        }

        return $this->optionsForCategory((int) $fault->agency_id, (string) $category);
    }

    /** @return Collection<int, array{id:int, name:string, phone:?string}> */
    public function optionsForCategory(int $agencyId, string $category): Collection
    {
        $codes = $this->matchingServiceTypeCodes($agencyId, $category);
        if ($codes === []) {
            return collect();
        }

        $providerIds = DB::table('agency_service_provider_service_types')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('service_type', $codes)
            ->pluck('service_provider_id')
            ->unique()
            ->all();
        if ($providerIds === []) {
            return collect();
        }

        return AgencyServiceProvider::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('id', $providerIds)
            ->pickerOrder()
            ->get(['id', 'name', 'phone'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'phone' => $p->phone ?: null])
            ->values();
    }

    /** Is this supplier one of the options for this fault? (server-side guard for both decision routes) */
    public function isOption(RentalFaultReport $fault, int $supplierId): bool
    {
        return $this->optionsFor($fault)->contains(fn ($o) => $o['id'] === $supplierId);
    }

    /** @return array<int, string> codes of the agency's active service types matching the category */
    private function matchingServiceTypeCodes(int $agencyId, string $category): array
    {
        $needle = $this->normalise($category);
        if ($needle === '') {
            return [];
        }

        return AgencyServiceType::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get(['code', 'label'])
            ->filter(function ($t) use ($needle) {
                foreach ([$this->normalise((string) $t->code), $this->normalise((string) $t->label)] as $hay) {
                    if ($hay === '') {
                        continue;
                    }
                    if ($hay === $needle || (strlen($needle) >= 4 && str_contains($hay, $needle)) || (strlen($hay) >= 4 && str_contains($needle, $hay))) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('code')
            ->map(fn ($c) => (string) $c)
            ->values()
            ->all();
    }

    private function normalise(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    }
}
