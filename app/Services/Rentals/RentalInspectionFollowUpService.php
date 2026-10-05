<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalInspection;
use App\Models\RentalInspectionObservation;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-work-orders.md §15 (AT-447, 2026-10-05) — "where items/
 * rooms were marked as faulty or damaged, the agent must be able to create
 * fault reports, work orders or job cards straight from those marked
 * items, linked back to the inspection, the lease and the property"
 * (Johan). This service is the one place that resolution/derivation logic
 * lives — the controllers it serves (RentalInspectionController's new
 * follow-up action, RentalWorkOrderController::create()/store()) are thin
 * callers, same discipline as every other rentals service in this family.
 *
 * Nothing here invents a new fault-report/work-order/job-card creation
 * path — every create still goes through RentalFaultReportService::report()
 * / RentalWorkOrderService::report() / RentalJobCardService::
 * createForProperty(), so lifecycle, notifications, and the Lease Hub
 * tenancy log (computed from lease_id, LeaseTimelineService) all keep
 * working unchanged.
 */
class RentalInspectionFollowUpService
{
    /**
     * §3.1/AT-439 Part 3 cross-check — lease first, property derived from
     * it. `RentalInspection::property_id` is denormalized FROM the lease at
     * creation (RentalInspection::boot()) and should never disagree with
     * it, but this is a direct-URL/data-edge path, so BUILD_STANDARD §3
     * applies: absorb a disagreement by trusting the lease (the
     * authoritative record), never throw.
     *
     * @return array{lease: ?Lease, property: ?Property}
     */
    public function resolveLeaseAndProperty(RentalInspection $inspection): array
    {
        $lease = $inspection->lease;
        $property = $lease?->property ?? $inspection->property;

        if ($lease && $property && (int) $lease->property_id !== (int) $inspection->property_id) {
            $property = $lease->property;
        }

        return ['lease' => $lease, 'property' => $property];
    }

    /** "<Room> — <Item>: <Condition>" — §15's own stated title format. */
    public function defaultTitleFor(RentalInspectionObservation $observation): string
    {
        $room = $observation->item?->room?->label ?? 'General';
        $item = $observation->item?->label ?? 'Unknown item';
        $condition = ucfirst(str_replace('_', ' ', $observation->condition));

        return "{$room} — {$item}: {$condition}";
    }

    /** @param Collection<int, RentalInspectionObservation> $observations */
    public function combinedTitleFor(Collection $observations, ?Property $property): string
    {
        $address = $property?->buildDisplayAddress() ?: ($property?->title ?: ('Property #' . $property?->id));

        return 'Multiple items (' . $observations->count() . ') — ' . $address;
    }

    /** @param Collection<int, RentalInspectionObservation> $observations */
    public function combinedDescriptionFor(Collection $observations): string
    {
        return $observations
            ->map(fn (RentalInspectionObservation $o) => '- ' . $this->defaultTitleFor($o) . ($o->notes ? ': ' . $o->notes : ''))
            ->implode("\n");
    }

    /**
     * Every recorded (non-photo-anchor) observation on this inspection
     * flagged as needing follow-up — the rows the Follow-up block lists.
     * "Needs follow-up" is never a hardcoded condition value, and never
     * "not this agency's baseline" (an earlier build of this feature used
     * that and wrongly surfaced N/A and a stray unmapped condition value as
     * if they were faults, Johan, 2026-10-05, QA1 property walk): it is
     * RentalInspectionSetting::conditionNeedsFollowUpFor() — the agency's
     * own existing condition-severity configuration, the same vocabulary
     * the rest of rental-inspections.md already reads everywhere else
     * (multi-agency floor, CLAUDE.md #9).
     *
     * @return Collection<int, RentalInspectionObservation>
     */
    public function followUpObservations(RentalInspection $inspection): Collection
    {
        return $inspection->observations
            ->reject(fn (RentalInspectionObservation $o) => $o->isPending())
            ->filter(fn (RentalInspectionObservation $o) => \App\Models\RentalInspectionSetting::conditionNeedsFollowUpFor($inspection->agency_id, $o->condition))
            ->sortBy('id')
            ->values();
    }

    /**
     * Idempotency map for the show page: every fault report / work order
     * already raised from an observation on THIS inspection, keyed by
     * `reported_inspection_observation_id`. A row with an entry here shows
     * the existing record instead of a create action.
     *
     * @return array{fault_reports: Collection, work_orders: Collection}
     */
    public function linkedRecordsFor(RentalInspection $inspection): array
    {
        $observationIds = $inspection->observations->pluck('id');

        $faultReports = RentalFaultReport::whereIn('reported_inspection_observation_id', $observationIds)
            ->get()
            ->groupBy('reported_inspection_observation_id');

        $workOrders = RentalWorkOrder::with('jobCard')
            ->whereIn('reported_inspection_observation_id', $observationIds)
            ->get()
            ->groupBy('reported_inspection_observation_id');

        return ['fault_reports' => $faultReports, 'work_orders' => $workOrders];
    }

    /**
     * §15 — the direct, immediate fault-report creation path (no extra
     * decision needed, unlike a work order's "who does the work"). Skips
     * (does not duplicate) any ticked observation that already has a fault
     * report on record — idempotent even under a resubmit/race.
     *
     * @param array<int> $observationIds
     * @return array{created: array<RentalFaultReport>, skipped: int}
     */
    public function createFaultReports(RentalInspection $inspection, array $observationIds, bool $combine, User $by): array
    {
        ['lease' => $lease, 'property' => $property] = $this->resolveLeaseAndProperty($inspection);

        $observations = RentalInspectionObservation::where('rental_inspection_id', $inspection->id)
            ->whereIn('id', $observationIds)
            ->with(['item.room', 'photos'])
            ->get();

        $alreadyRaised = $observations->filter(
            fn (RentalInspectionObservation $o) => RentalFaultReport::where('reported_inspection_observation_id', $o->id)->exists()
        );
        $eligible = $observations->reject(fn ($o) => $alreadyRaised->contains('id', $o->id))->sortBy('id')->values();

        if ($eligible->isEmpty() || !$property) {
            return ['created' => [], 'skipped' => $alreadyRaised->count()];
        }

        $groups = ($combine && $eligible->count() > 1)
            ? collect([$eligible])
            : $eligible->map(fn (RentalInspectionObservation $o) => collect([$o]));

        $reportedByType = $inspection->type === RentalInspection::TYPE_OUT
            ? RentalFaultReport::REPORTED_BY_AGENT_NOTICED
            : RentalFaultReport::REPORTED_BY_TENANT;

        $created = [];
        foreach ($groups as $group) {
            $primary = $group->sortBy('id')->first();
            $isGroup = $group->count() > 1;

            $attributes = [
                'lease_id' => $lease?->id,
                'rental_inspection_item_id' => $primary->rental_inspection_item_id,
                'reported_inspection_observation_id' => $primary->id,
                'reported_by_type' => $reportedByType,
                'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
                'captured_by_user_id' => $by->id,
                'created_by_user_id' => $by->id,
                'title' => $isGroup ? $this->combinedTitleFor($group, $property) : $this->defaultTitleFor($primary),
                'description' => $isGroup ? $this->combinedDescriptionFor($group) : ($primary->notes ?: $this->defaultTitleFor($primary)),
            ];

            if ($reportedByType === RentalFaultReport::REPORTED_BY_TENANT) {
                $attributes['reported_by_contact_id'] = $primary->observed_by_contact_id
                    ?? $lease?->tenants()->orderByDesc('is_primary')->first()?->contact_id;
            } else {
                $attributes['reported_by_user_id'] = $by->id;
            }

            $faultReport = app(RentalFaultReportService::class)->report($property, $attributes);

            foreach ($group as $observation) {
                $this->linkObservationPhotosToFaultReport($observation, $faultReport, $by);
            }

            $created[] = $faultReport;
        }

        return ['created' => $created, 'skipped' => $alreadyRaised->count()];
    }

    /**
     * Links (never re-uploads/duplicates) an observation's own photos onto
     * the new fault report — same `storage_path`, a second DB row pointing
     * at the same physical file. Zero disk-duplication cost, and the photo
     * is genuinely the same evidence, just filed against two records now.
     */
    private function linkObservationPhotosToFaultReport(RentalInspectionObservation $observation, RentalFaultReport $faultReport, User $by): void
    {
        foreach ($observation->photos as $photo) {
            RentalFaultReportPhoto::create([
                'agency_id' => $faultReport->agency_id,
                'rental_fault_report_id' => $faultReport->id,
                'storage_path' => $photo->storage_path,
                'uploaded_by_user_id' => $photo->uploaded_by_user_id ?? $by->id,
                'file_size_bytes' => $photo->file_size_bytes,
            ]);
        }
    }

    /**
     * §15 — "opens the existing work-order create with its 'Who does the
     * work?' choice." Builds what RentalWorkOrderController::create() needs
     * to render that SAME form, either prefilled for one record (single
     * item, or several combined into one) or in batch mode (several items,
     * not combined — one work order per item, sharing only the fields a
     * human must still decide: who does the work, trade type).
     *
     * Already-raised observations (one already has a rental_work_order) are
     * dropped silently here — idempotent, matching createFaultReports()'s
     * own skip behaviour; mode 'none' tells the controller to redirect back
     * with nothing to do rather than render an empty form.
     *
     * @param array<int> $observationIds
     */
    public function buildWorkOrderPrefill(RentalInspection $inspection, array $observationIds, bool $combine): array
    {
        ['lease' => $lease, 'property' => $property] = $this->resolveLeaseAndProperty($inspection);

        $observations = RentalInspectionObservation::where('rental_inspection_id', $inspection->id)
            ->whereIn('id', $observationIds)
            ->with('item.room')
            ->get()
            ->reject(fn (RentalInspectionObservation $o) => RentalWorkOrder::where('reported_inspection_observation_id', $o->id)->exists())
            ->sortBy('id')
            ->values();

        if ($observations->isEmpty() || !$property) {
            return ['mode' => 'none', 'property' => $property, 'lease' => $lease];
        }

        if (!$combine && $observations->count() > 1) {
            return [
                'mode' => 'batch',
                'property' => $property,
                'lease' => $lease,
                'rental_inspection_id' => $inspection->id,
                'items' => $observations->map(fn (RentalInspectionObservation $o) => [
                    'observation_id' => $o->id,
                    'rental_inspection_item_id' => $o->rental_inspection_item_id,
                    'title' => $this->defaultTitleFor($o),
                    'description' => $o->notes ?: $this->defaultTitleFor($o),
                ])->all(),
            ];
        }

        $primary = $observations->first();
        $isGroup = $observations->count() > 1;

        return [
            'mode' => 'single',
            'property' => $property,
            'lease' => $lease,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $primary->rental_inspection_item_id,
            'reported_inspection_observation_id' => $primary->id,
            'title' => $isGroup ? $this->combinedTitleFor($observations, $property) : $this->defaultTitleFor($primary),
            'description' => $isGroup ? $this->combinedDescriptionFor($observations) : ($primary->notes ?: $this->defaultTitleFor($primary)),
        ];
    }
}
