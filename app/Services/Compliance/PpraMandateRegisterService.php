<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase I — .ai/specs/ppra-inspection-pack.md §6.8e.
 * Mandate/MDF/FICA register — gap-monitoring feed for item m's live
 * checklist status AND the standalone register screen. No new table; reads
 * `Property` + `MarketingReadinessService::documentGateSummaryFor()`.
 */
class PpraMandateRegisterService
{
    public function __construct(
        private MarketingReadinessService $readiness = new MarketingReadinessService(),
        private PpraFinancialYearListService $fyList = new PpraFinancialYearListService(),
    ) {
    }

    /**
     * Every property currently on-market (Property::OFF_MARKET_STATUSES
     * excluded) AND "advertised" per item j's own §6.7 derivation — reused
     * here with an open-ended window (agency creation to now) rather than a
     * single financial year, since the register cares about CURRENT gaps,
     * not one FY's worth. Sale and rental listing types both count — item m
     * is "active listings" generically, not sales-only.
     *
     * @return Collection<int, Property>
     */
    public function activeAdvertisedListings(Agency $agency): Collection
    {
        $from = optional($agency->created_at)->copy() ?? Carbon::create(2000, 1, 1);
        $to = now();

        $sales = $this->fyList->advertisedListings($agency, 'sale', $from, $to);
        $rentals = $this->fyList->advertisedListings($agency, 'rental', $from, $to);

        return $sales->concat($rentals)
            ->filter(fn (Property $p) => ! in_array($p->status, Property::OFF_MARKET_STATUSES, true))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, object> one row per listing: property + gate summary + advertised flag
     */
    private function registerRows(Agency $agency, bool $advertisedOnly): Collection
    {
        $listings = $advertisedOnly
            ? $this->activeAdvertisedListings($agency)
            : Property::where('agency_id', $agency->id)
                ->whereNotIn('status', Property::OFF_MARKET_STATUSES)
                ->with('agent')
                ->orderBy('address')
                ->get();

        $advertisedIds = $advertisedOnly ? null : $this->activeAdvertisedListings($agency)->pluck('id');

        return $listings->map(function (Property $property) use ($advertisedIds) {
            $gates = $this->readiness->documentGateSummaryFor($property);

            return (object) [
                'property'    => $property,
                'mandate'     => $gates['mandate'],
                'mdf'         => $gates['mdf'],
                'fica'        => $gates['fica'],
                'advertised'  => $advertisedIds === null ? true : $advertisedIds->contains($property->id),
                'has_gap'     => ! ($gates['mandate'] && $gates['mdf'] && $gates['fica']),
            ];
        });
    }

    /**
     * Aggregate stats for the checklist's item m row — always computed
     * against ALL active advertised listings (never just the sample).
     *
     * @return array{total: int, gaps: int, status: string}
     */
    public function checklistStats(Agency $agency): array
    {
        $rows = $this->registerRows($agency, true);
        $total = $rows->count();
        $gaps = $rows->where('has_gap', true)->count();

        if ($total === 0) {
            $status = 'green';
        } else {
            $pct = ($gaps / $total) * 100;
            $threshold = $agency->ppra_mandate_register_red_threshold_pct ?: 10;
            $status = $gaps === 0 ? 'green' : ($pct >= $threshold ? 'red' : 'amber');
        }

        return ['total' => $total, 'gaps' => $gaps, 'status' => $status];
    }

    /**
     * The register screen's own list: search/sort/filter/pagination (§6.8e).
     */
    public function search(Agency $agency, array $filters, int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $advertisedOnly = ! array_key_exists('advertised_only', $filters) || $filters['advertised_only'] !== '0';
        $rows = $this->registerRows($agency, $advertisedOnly);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = strtolower($search);
            $rows = $rows->filter(function ($row) use ($needle) {
                $address = strtolower((string) ($row->property->address ?? ''));
                $suburb = strtolower((string) ($row->property->suburb ?? ''));
                $agentName = strtolower((string) ($row->property->agent->name ?? ''));

                return str_contains($address, $needle) || str_contains($suburb, $needle) || str_contains($agentName, $needle);
            });
        }

        if ($branchId = $filters['branch_id'] ?? null) {
            $rows = $rows->filter(fn ($row) => (int) $row->property->branch_id === (int) $branchId);
        }
        if ($agentId = $filters['agent_id'] ?? null) {
            $rows = $rows->filter(fn ($row) => (int) $row->property->agent_id === (int) $agentId);
        }
        if ($from = $filters['date_from'] ?? null) {
            $rows = $rows->filter(fn ($row) => $row->property->listed_date && $row->property->listed_date->gte(Carbon::parse($from)));
        }
        if ($to = $filters['date_to'] ?? null) {
            $rows = $rows->filter(fn ($row) => $row->property->listed_date && $row->property->listed_date->lte(Carbon::parse($to)));
        }

        $status = $filters['status'] ?? 'all';
        $rows = match ($status) {
            'missing_mandate' => $rows->filter(fn ($row) => ! $row->mandate),
            'missing_mdf'      => $rows->filter(fn ($row) => ! $row->mdf),
            'missing_fica'     => $rows->filter(fn ($row) => ! $row->fica),
            'all_clear'        => $rows->filter(fn ($row) => ! $row->has_gap),
            default            => $rows,
        };

        $sort = $filters['sort'] ?? 'address';
        $rows = match ($sort) {
            'status'      => $rows->sortByDesc('has_gap'),
            'listed_date' => $rows->sortByDesc(fn ($row) => $row->property->listed_date),
            default       => $rows->sortBy(fn ($row) => strtolower((string) $row->property->address)),
        };
        $rows = $rows->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * The current filtered view's mandate + MDF documents, capped at the
     * agency's configured max-files-per-ZIP (§9 "no silent caps" — caller
     * reports how many were included vs. how many exist).
     *
     * @return array{documents: \Illuminate\Support\Collection, total_available: int, capped: bool}
     */
    public function mandateMdfDocumentsForZip(Agency $agency, array $filters): array
    {
        $allMatching = $this->search($agency, $filters, 1, PHP_INT_MAX)->items();
        $cap = $agency->ppra_zip_max_files ?: 200;

        $typeIds = \App\Models\DocumentType::whereIn('slug', ['mandate', 'disclosure'])->pluck('id');
        $documents = collect();

        foreach ($allMatching as $row) {
            $docs = $row->property->documents()->whereIn('document_type_id', $typeIds)->get();
            foreach ($docs as $doc) {
                if ($documents->count() >= $cap) {
                    break 2;
                }
                $documents->push($doc);
            }
        }

        $totalAvailable = 0;
        foreach ($allMatching as $row) {
            $totalAvailable += $row->property->documents()->whereIn('document_type_id', $typeIds)->count();
        }

        return [
            'documents'       => $documents,
            'total_available' => $totalAvailable,
            'capped'          => $totalAvailable > $documents->count(),
        ];
    }
}
