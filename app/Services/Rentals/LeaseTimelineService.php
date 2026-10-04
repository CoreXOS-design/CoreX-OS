<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalApplication;
use Illuminate\Support\Collection;

/**
 * .ai/specs/leases.md §12.2/§12.3 — the Lease Hub's "tenancy log": one
 * chronological, searchable, type-filterable record of everything that has
 * happened on ONE tenancy. This is the evidence pack an out-inspection
 * leans on, so every source below is scoped by `lease_id`, never
 * `property_id` — a property can have many leases over its life, and a
 * previous tenant's faults/work-orders/inspections must never surface on
 * the current (or any other) tenant's log. The one exception is the
 * application source, which is reached via `leases.rental_application_id`
 * (a lease has at most one originating application, not a property-wide
 * list of them).
 *
 * Designed to be extended with more sources later (job cards — AT-442,
 * notices — Stage 6/7, filed documents) without reworking callers: each
 * source is a private builder returning plain arrays of the same shape,
 * merged and sorted once at the end. A new source is one more private
 * method and one more line in buildAllEntries().
 */
class LeaseTimelineService
{
    public const TYPES = ['application', 'lease', 'inspection', 'fault', 'work_order'];

    /**
     * @return array{entries: Collection, total: int}
     */
    public function paginatedFor(Lease $lease, ?string $search = null, array $types = [], ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 50, int $page = 1): array
    {
        $entries = $this->allEntriesFor($lease);

        $search = trim((string) $search);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $entries = $entries->filter(function (array $entry) use ($needle) {
                return str_contains(mb_strtolower($entry['description']), $needle)
                    || str_contains(mb_strtolower($entry['actor'] ?? ''), $needle);
            });
        }

        if (!empty($types)) {
            $entries = $entries->whereIn('type', $types);
        }

        if ($dateFrom) {
            $entries = $entries->filter(fn (array $e) => $e['occurred_at'] >= $dateFrom);
        }
        if ($dateTo) {
            $entries = $entries->filter(fn (array $e) => $e['occurred_at'] <= $dateTo . ' 23:59:59');
        }

        $entries = $entries->values();
        $total = $entries->count();

        // .ai/specs/leases.md §12.3 — "No pagination cap below 500 rows... if
        // this assumption breaks on real data, paginate." A real tenancy's
        // log is small; the slice below is cheap at this scale and keeps the
        // API/PDF/screen all consuming the exact same ordered collection.
        $offset = max(0, ($page - 1) * $perPage);
        $page = $entries->slice($offset, $perPage)->values();

        return ['entries' => $page, 'total' => $total];
    }

    public function allEntriesFor(Lease $lease): Collection
    {
        $entries = collect()
            ->merge($this->applicationEntries($lease))
            ->merge($this->leaseEntries($lease))
            ->merge($this->inspectionEntries($lease))
            ->merge($this->faultEntries($lease))
            ->merge($this->workOrderEntries($lease));

        return $entries->sortByDesc('occurred_at')->values();
    }

    private function entry(string $type, string $occurredAt, string $description, ?string $actor, ?string $status, ?string $routeName, $routeParam): array
    {
        return [
            'type' => $type,
            'occurred_at' => (string) $occurredAt,
            'description' => $description,
            'actor' => $actor,
            'status' => $status,
            'route_name' => $routeName,
            'route_param' => $routeParam,
        ];
    }

    private function applicationEntries(Lease $lease): array
    {
        if (!$lease->rental_application_id) {
            return [];
        }

        /** @var RentalApplication|null $application */
        $application = RentalApplication::withoutGlobalScopes()->find($lease->rental_application_id);
        if (!$application) {
            return [];
        }

        $out = [];
        if ($application->submitted_at) {
            $out[] = $this->entry(
                'application',
                (string) $application->submitted_at,
                'Rental application submitted',
                null,
                $application->status,
                'corex.rental-applications.show',
                $application->id,
            );
        }
        if ($application->status === 'approved') {
            // RentalApplication has no dedicated approved_at column (confirmed
            // by direct inspection) — updated_at is the best available
            // approximation for "when this reached approved", same limitation
            // noted in this feature's own report.
            $out[] = $this->entry(
                'application',
                (string) $application->updated_at,
                'Rental application approved',
                null,
                'approved',
                'corex.rental-applications.show',
                $application->id,
            );
        }

        return $out;
    }

    /**
     * leases.md §12.3 — "a brand-new lease has a genuinely empty log." Plain
     * creation is not itself a tenancy EVENT (nothing has happened on the
     * tenancy yet) — only activation-worth-noting transitions (cancellation)
     * and escalations are logged here. A lease's existence/creation date is
     * already visible in the header, not duplicated into the log.
     */
    private function leaseEntries(Lease $lease): array
    {
        $out = [];

        if ($lease->status === Lease::STATUS_CANCELLED && $lease->cancelled_at) {
            $out[] = $this->entry('lease', (string) $lease->cancelled_at, 'Lease cancelled — ' . $lease->cancel_reason, $lease->cancelledByUser?->name, 'cancelled', 'corex.leases.show', $lease->id);
        }

        foreach ($lease->escalations as $escalation) {
            $out[] = $this->entry(
                'lease',
                (string) $escalation->created_at,
                sprintf('Rent escalated: R%s → R%s (%s%%)', number_format((float) $escalation->previous_rental_amount, 2), number_format((float) $escalation->new_rental_amount, 2), number_format((float) $escalation->escalation_rate_percent, 2)),
                $escalation->createdByUser?->name,
                'escalation',
                'corex.leases.show',
                $lease->id,
            );
        }

        return $out;
    }

    private function inspectionEntries(Lease $lease): array
    {
        return $lease->inspections()->get()->map(function ($inspection) {
            $label = ucfirst(str_replace('_', '-', $inspection->type)) . '-inspection';
            $occurredAt = $inspection->completed_at ?? $inspection->scheduled_for ?? $inspection->created_at;

            return $this->entry(
                'inspection',
                (string) $occurredAt,
                $label . ' — ' . ucfirst(str_replace('_', ' ', $inspection->status)),
                null,
                $inspection->status,
                'corex.rental-inspections.show',
                $inspection->id,
            );
        })->all();
    }

    private function faultEntries(Lease $lease): array
    {
        return $lease->faultReports()->get()->map(function ($report) {
            return $this->entry(
                'fault',
                (string) ($report->reported_at ?? $report->created_at),
                'Fault reported: ' . $report->title,
                null,
                $report->status,
                'corex.rental-fault-reports.show',
                $report->id,
            );
        })->all();
    }

    private function workOrderEntries(Lease $lease): array
    {
        return $lease->workOrders()->get()->map(function ($workOrder) {
            return $this->entry(
                'work_order',
                (string) ($workOrder->reported_at ?? $workOrder->created_at),
                'Work order: ' . $workOrder->title,
                null,
                $workOrder->status,
                'corex.rental-work-orders.show',
                $workOrder->id,
            );
        })->all();
    }
}
