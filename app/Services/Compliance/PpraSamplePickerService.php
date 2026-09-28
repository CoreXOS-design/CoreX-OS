<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Deal;
use App\Models\Property;
use App\Models\Rental;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack Phase F — .ai/specs/ppra-inspection-pack.md §6.8a.
 * Backs the shared sample picker (deal/rental/listing modes) consumed by
 * items k/l/m (Phases G/H/I). Every result row is normalised to the same
 * shape so the picker component renders identically regardless of mode:
 *   ['id', 'label', 'sub_label', 'date', 'status']
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

    /** @return Collection<int, array<string,mixed>> */
    private function rentals(Agency $agency, array $filters): Collection
    {
        $query = Rental::whereHas('branch', fn ($b) => $b->where('agency_id', $agency->id))->with('agents');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = "%{$search}%";
            $query->where(function ($q) use ($needle) {
                $q->where('lease_address', 'like', $needle)
                    ->orWhereHas('agents', fn ($a) => $a->where('name', 'like', $needle));
            });
        }

        if ($from = $filters['date_from'] ?? null) {
            $query->whereDate('lease_start_date', '>=', $from);
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->whereDate('lease_start_date', '<=', $to);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }
        if ($agentId = $filters['agent_id'] ?? null) {
            $query->whereHas('agents', fn ($a) => $a->where('users.id', $agentId));
        }

        return $query->orderByDesc('lease_start_date')->orderByDesc('id')->get()->map(fn (Rental $rental) => [
            'id'        => $rental->id,
            'label'     => $rental->lease_address ?: ('Rental #' . $rental->id),
            'sub_label' => $rental->agents->pluck('name')->filter()->implode(', '),
            'date'      => optional($rental->lease_start_date)->format('Y-m-d'),
            'status'    => $rental->is_active ? 'active' : 'ended',
        ]);
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
