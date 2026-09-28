<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Deal;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase F/H — .ai/specs/ppra-inspection-pack.md §6.8a.
 * Backs the shared sample picker (deal/rental/listing modes) consumed by
 * items k/l/m (Phases G/H/I). Every result row is normalised to the same
 * shape so the picker component renders identically regardless of mode:
 *   ['id', 'label', 'sub_label', 'date', 'status']
 *
 * "rental" mode changed in Phase H (2026-09-28, Johan's ruling) from
 * `App\Models\Rental` (the `rentals` table — a thin commission-tracking
 * record: branch, free-text address, dates, no FK to property/contact/
 * anything) to `App\Models\Lease` — the real anchor an inspector actually
 * samples, with a direct property_id, tenants() -> Contact, and (when
 * present) a linked RentalApplication. Rental's old ids are semantically
 * meaningless as Lease ids; any pre-existing sample_rental_ids were
 * cleared as part of this change (only test packs existed — see the
 * spec's own note on this deviation).
 */
class PpraSamplePickerService
{
    public const MODES = ['deal', 'rental', 'listing'];

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function search(Agency $agency, string $mode, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $rows = match ($mode) {
            'deal'    => $this->deals($agency, $filters),
            'rental'  => $this->rentals($agency, $filters),
            'listing' => $this->listings($agency, $filters),
            default   => abort(422, 'Invalid sample-picker mode.'),
        };

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
        );
    }

    /** "Select N most recent" quick-action (§6.8a) — ids only, most-recent-first, unfiltered. */
    public function mostRecentIds(Agency $agency, string $mode, int $n): array
    {
        $rows = match ($mode) {
            'deal'    => $this->deals($agency, []),
            'rental'  => $this->rentals($agency, []),
            'listing' => $this->listings($agency, []),
            default   => abort(422, 'Invalid sample-picker mode.'),
        };

        return $rows->take($n)->pluck('id')->all();
    }

    public function sampleSizeFor(Agency $agency, string $mode): int
    {
        return (int) match ($mode) {
            'deal'    => $agency->ppra_pack_sales_sample_size ?: 5,
            'rental'  => $agency->ppra_pack_rental_sample_size ?: 5,
            'listing' => $agency->ppra_pack_mandate_sample_size ?: 5,
            default   => 5,
        };
    }

    /** @return Collection<int, array<string,mixed>> newest-closed first */
    private function deals(Agency $agency, array $filters): Collection
    {
        $query = Deal::where('agency_id', $agency->id)->with('agents');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = "%{$search}%";
            $query->where(function ($q) use ($needle) {
                $q->where('property_address', 'like', $needle)
                    ->orWhere('buyer_name', 'like', $needle)
                    ->orWhere('seller_name', 'like', $needle)
                    ->orWhereHas('agents', fn ($a) => $a->where('name', 'like', $needle));
            });
        }

        if ($from = $filters['date_from'] ?? null) {
            $query->whereDate('registration_date', '>=', $from);
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->whereDate('registration_date', '<=', $to);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('accepted_status', $status);
        }
        if ($agentId = $filters['agent_id'] ?? null) {
            $query->whereHas('agents', fn ($a) => $a->where('users.id', $agentId));
        }

        return $query->orderByDesc('registration_date')->orderByDesc('id')->get()->map(fn (Deal $deal) => [
            'id'        => $deal->id,
            'label'     => $deal->property_address ?: ('Deal #' . $deal->deal_no),
            'sub_label' => $deal->agents->pluck('name')->filter()->implode(', ') ?: ($deal->buyer_name ?: ''),
            'date'      => optional($deal->registration_date)->format('Y-m-d') ?? optional($deal->deal_date)->format('Y-m-d'),
            'status'    => $deal->accepted_status ?? $deal->commission_status ?? '',
        ]);
    }

    /**
     * @return Collection<int, array<string,mixed>>
     *
     * Search/filter runs in PHP over the agency's whole lease set (matching
     * this class's own existing precedent — deals()/listings() below also
     * ->get() the full agency set and paginate the in-memory collection in
     * search(), never at the SQL level; agency lease counts here are small
     * — 13 for HFC at the time this was written), because the search
     * fields (tenant/landlord name, agent name) live behind relations
     * (LeaseTenant -> Contact, Property -> agent/sellerOwnerContact) that
     * would need a fragile multi-join to filter in SQL for no real benefit
     * at this scale. Never fuzzy-matches address text against anything —
     * every field here is a real FK relation.
     */
    private function rentals(Agency $agency, array $filters): Collection
    {
        $query = Lease::where('agency_id', $agency->id)->with(['property.agent', 'tenants.contact']);

        if ($from = $filters['date_from'] ?? null) {
            $query->whereDate('start_date', '>=', $from);
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->whereDate('start_date', '<=', $to);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($agentId = $filters['agent_id'] ?? null) {
            $query->whereHas('property', fn ($p) => $p->where('agent_id', $agentId));
        }

        $leases = $query->orderByDesc('start_date')->orderByDesc('id')->get();

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = strtolower($search);
            $leases = $leases->filter(function (Lease $lease) use ($needle) {
                $address = strtolower((string) ($lease->property->address ?? ''));
                $agentName = strtolower((string) ($lease->property->agent->name ?? ''));
                $landlordName = strtolower($this->contactDisplayName($lease->property?->sellerOwnerContact()));
                $tenantNames = $lease->tenants->map(fn ($t) => strtolower($this->contactDisplayName($t->contact)))->implode(' ');

                return str_contains($address, $needle)
                    || str_contains($agentName, $needle)
                    || str_contains($landlordName, $needle)
                    || str_contains($tenantNames, $needle);
            })->values();
        }

        return $leases->map(fn (Lease $lease) => [
            'id'        => $lease->id,
            'label'     => $lease->property->address ?? ('Lease #' . $lease->id),
            'sub_label' => $lease->tenants->map(fn ($t) => $this->contactDisplayName($t->contact))->filter()->implode(', '),
            'date'      => optional($lease->start_date)->format('Y-m-d'),
            'status'    => $lease->status ?? '',
        ]);
    }

    private function contactDisplayName(?\App\Models\Contact $contact): string
    {
        if (! $contact) {
            return '';
        }

        return trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
    }

    /** @return Collection<int, array<string,mixed>> */
    private function listings(Agency $agency, array $filters): Collection
    {
        $query = Property::where('agency_id', $agency->id)->onMarket()->with('agent');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = "%{$search}%";
            $query->where(function ($q) use ($needle) {
                $q->where('address', 'like', $needle)
                    ->orWhereHas('agent', fn ($a) => $a->where('name', 'like', $needle));
            });
        }

        if ($from = $filters['date_from'] ?? null) {
            $query->whereDate('listed_date', '>=', $from);
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->whereDate('listed_date', '<=', $to);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($agentId = $filters['agent_id'] ?? null) {
            $query->where('agent_id', $agentId);
        }

        return $query->orderByDesc('listed_date')->orderByDesc('id')->get()->map(fn (Property $property) => [
            'id'        => $property->id,
            'label'     => $property->address ?: ('Property #' . $property->id),
            'sub_label' => optional($property->agent)->name ?? '',
            'date'      => optional($property->listed_date)->format('Y-m-d'),
            'status'    => $property->status ?? '',
        ]);
    }
}
