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
    /**
     * @return Collection<int, array{id:int, name:string, phone:?string}>
     *
     * The contractors that SUIT this fault - one of the answers {@see self::classify()} gives. A fault with NO type (so no category to
     * match) offers ALL the agency's maintenance contractors - never none (Johan, 8 Oct 2026): nothing is known about the trade, so the
     * agent / owner picks.
     */
    public function optionsFor(RentalFaultReport $fault): Collection
    {
        return $this->classify($fault)['suits']->map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'phone' => $r['phone']])->values();
    }

    /**
     * AT-452 item 9 / round 2 (C) - does the agency have at least one active contractor for this fault's type of work? THE one test: the
     * owner portal's "Your agent has no contractor on file for this type of work yet", the agent's "No contractor loaded for <type>"
     * warning before sending, and the agent's contractor picker all read {@see self::classify()}, so they can never disagree.
     */
    public function hasContractorFor(RentalFaultReport $fault): bool
    {
        // "Other - not on the list" has no trade yet: the agent picks the contractor when sending, so this is never a "no contractor for this type" case
        if ($fault->isOtherType()) {
            return true;
        }

        return $this->classify($fault)['suits']->isNotEmpty();
    }

    /**
     * THE single rule for "does a contractor suit this fault" (AT-452 round 2, C). Every active maintenance contractor of the agency is
     * returned once, in `suits` or in `others`:
     *  - the fault has a type with a category: a contractor suits when it is ticked for a service type that matches the category (see
     *    {@see self::matchingServiceTypeCodes()}); every other contractor is in `others` - still selectable, an agency may use a general
     *    handyman;
     *  - the fault has no type / no category: nothing is known about the trade, so every contractor suits.
     * `mapping_gap` is true when the category exists but NO service type of this agency matches it - the case that used to say "nobody"
     * with no way to fix it; the screens then tell the agent to add the trade, not just that nobody exists.
     *
     * @return array{type:?string, category:?string, suits:Collection<int,array<string,mixed>>, others:Collection<int,array<string,mixed>>, mapping_gap:bool}
     */
    public function classify(RentalFaultReport $fault): array
    {
        return $this->classifyFor((int) $fault->agency_id, $fault->faultType?->name, $fault->faultType?->category);
    }

    /** The same rule for a work order that has no fault (or any caller that only knows the type): $category null/blank = nothing is known about the trade. */
    public function classifyFor(int $agencyId, ?string $typeName, ?string $category): array
    {
        $category = trim((string) ($category ?? ''));
        $rows = AgencyServiceProvider::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->maintenanceContractors()->pickerOrder()->with('serviceContacts')->get();

        $codes = $category !== '' ? $this->matchingServiceTypeCodes($agencyId, $category) : [];
        $ticked = $codes === [] ? collect() : DB::table('agency_service_provider_service_types')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('service_type', $codes)->pluck('service_provider_id')->unique();
        $trades = DB::table('agency_service_provider_service_types')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('service_provider_id', $rows->pluck('id'))
            ->get(['service_provider_id', 'service_type'])->groupBy('service_provider_id');
        $labels = AgencyServiceType::withoutGlobalScopes()->where('agency_id', $agencyId)->pluck('label', 'code');
        $jobs = app(RentalContractorJobService::class);

        // a supplier with no ticked service types yet but an old fixed trade (plumber / electrician ...) still suits when that trade IS the matching service type
        $legacyOk = [];
        foreach ($codes as $c) {
            $legacy = AgencyServiceProvider::legacySpecialtyForType($c, $labels[$c] ?? null);
            if ($legacy !== 'other') {
                $legacyOk[$legacy] = true;
            }
        }
        $all = $rows->map(function ($p) use ($category, $ticked, $trades, $labels, $jobs, $legacyOk) {
            $contact = $jobs->contactFor($p);

            return [
                'id' => (int) $p->id, 'name' => $p->displayName(), 'phone' => $p->phone ?: null,
                // what the picker's search box matches: business name, contact person, email, phone, trade (the ONE rule, see AgencyServiceProvider::scopeMatching)
                'search' => mb_strtolower(trim($p->displayName() . ' ' . $p->name . ' ' . $p->company . ' ' . $p->email . ' ' . $p->phone . ' ' . $p->specialty . ' ' . (($trades[$p->id] ?? collect())->map(fn ($t) => ($labels[$t->service_type] ?? '') . ' ' . $t->service_type)->implode(' ')))),
                'email' => $contact['email'], 'mobile' => $contact['phone'], 'has_contact' => $contact['has_contact'],
                'trades' => ($trades[$p->id] ?? collect())->map(fn ($t) => $labels[$t->service_type] ?? $t->service_type)->unique()->implode(', '),
                'suits' => $category === '' ? true : ($ticked->contains((int) $p->id) || (! isset($trades[$p->id]) && isset($legacyOk[(string) $p->specialty]))),
                // .ai/specs/rentals-faults-work-orders.md §20 - the call-out fee, as the picker shows it (the supplier's own methods are the only source)
                'callout' => $p->calloutFeeSuffix(), 'callout_status' => $p->calloutFeeStatus(), 'callout_amount' => $p->calloutFeeAmount(), 'callout_notice' => $p->calloutFeeNotice(),
            ];
        });

        return [
            'type' => $typeName,
            'category' => $category !== '' ? $category : null,
            'suits' => $all->where('suits', true)->values(),
            'others' => $all->where('suits', false)->values(),
            'mapping_gap' => $category !== '' && $codes === [],
        ];
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
            ->maintenanceContractors()
            ->whereIn('id', $providerIds)
            ->pickerOrder()
            ->get(['id', 'name', 'company', 'phone'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->displayName(), 'phone' => $p->phone ?: null])
            ->values();
    }

    /** @return Collection<int, array{id:int, name:string, phone:?string}> every active maintenance contractor of the agency, preferred first */
    public function allContractors(int $agencyId): Collection
    {
        return AgencyServiceProvider::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->maintenanceContractors()->pickerOrder()
            ->get(['id', 'name', 'company', 'phone'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->displayName(), 'phone' => $p->phone ?: null])
            ->values();
    }

    /** The agency's service-type code that matches a category (first match), or null. */
    public function classifyTradeCode(int $agencyId, string $category): ?string
    {
        return $this->matchingServiceTypeCodes($agencyId, $category)[0] ?? null;
    }

    /** The agency's service-type code for this fault's trade (first match), or null - used to label a work order's trade without asking. */
    public function tradeCodeFor(RentalFaultReport $fault): ?string
    {
        $category = $fault->faultType?->category;
        if (! $category) {
            return null;
        }

        return $this->matchingServiceTypeCodes((int) $fault->agency_id, (string) $category)[0] ?? null;
    }

    /**
     * Every active contractor of the fault's agency for the agent's picker: those that suit this type of work first (flagged `suits`),
     * then the rest - the same {@see self::classify()} the owner portal's note and the pre-send warning use.
     *
     * @return Collection<int, array{id:int, name:string, phone:?string, email:?string, mobile:?string, has_contact:bool, trades:string, suits:bool}>
     */
    public function pickerFor(RentalFaultReport $fault): Collection
    {
        $c = $this->classify($fault);
        $rows = $c['suits']->concat($c['others'])->values();

        // rentals-faults-work-orders.md section 21.3 - the people set up to look at this fault first come on top ("Set for this property"), in their order; each
        // is then not repeated below. Every row also carries its kind, so the picker can say "Caretaker" next to a name.
        $kinds = AgencyServiceProvider::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->pluck('kind', 'id');
        $rows = $rows->map(fn ($r) => $r + ['kind' => (string) ($kinds[$r['id']] ?: AgencyServiceProvider::KIND_CONTRACTOR), 'kind_label' => AgencyServiceProvider::kindLabel($kinds[$r['id']] ?? null), 'first_responder' => false]);
        $first = $fault->property
            ? $fault->property->firstRespondersFor($fault->rental_fault_type_id)->map(fn ($f) => $rows->firstWhere('id', $f['supplier_id']))->filter()->map(fn ($r) => ['first_responder' => true] + $r)->values()
            : collect();
        if ($first->isEmpty()) {
            return $rows->values();
        }

        return $first->concat($rows->reject(fn ($r) => $first->contains('id', $r['id'])))->values();
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
            ->whereNull('legacy_specialty')   // the property-sales roles are not trades a fault can need
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
