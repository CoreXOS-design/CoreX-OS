<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 4 rental-domain event classes:
 *   lease_expiry             — lease_records.lease_end_date (canonical, from
 *                              e-sign) + rentals.lease_end_date (rental mgmt
 *                              fallback for rentals without a lease_record)
 *   rent_escalation          — rental_amount_versions.effective_from (future)
 *   rent_due                 — computed: next 1st-of-month per active rental
 *   commercial_lease_expiry  — commercial_evaluation_units.lease_end
 *
 * Schema notes:
 *   - lease_records has property_id but no agency/branch — resolve via properties
 *   - rentals has branch_id but no agency/property — resolve via branches
 *   - commercial_evaluations has branch_id but no agency — resolve via branches
 */
class RentalCalendarSource implements CalendarSourceContract
{
    public function name(): string
    {
        return 'RentalCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->leaseExpiryFromLeaseRecords())
            ->merge($this->leaseExpiryFromRentals())
            ->merge($this->rentEscalation())
            ->merge($this->rentDue())
            ->merge($this->commercialLeaseExpiry());
    }

    /**
     * Canonical lease_expiry from lease_records (e-sign generated).
     * Agency/branch resolved via properties join.
     */
    private function leaseExpiryFromLeaseRecords(): Collection
    {
        return DB::table('lease_records as lr')
            ->whereNull('lr.deleted_at')
            ->whereNotNull('lr.lease_end_date')
            ->where('lr.lease_end_date', '>=', now()->subDays(30))
            ->leftJoin('properties as p', 'p.id', '=', 'lr.property_id')
            ->select(
                'lr.id',
                'lr.lease_end_date',
                'lr.property_id',
                'lr.tenant_name',
                'p.agent_id',
                'p.agency_id',
                'p.branch_id',
                'p.address',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'lease_expiry',
                'title'       => 'Lease expires — ' . ($r->address ?: ($r->tenant_name ?: "lease #{$r->id}")),
                'event_date'  => Carbon::parse($r->lease_end_date)->startOfDay(),
                'source_type' => \App\Models\Docuperfect\LeaseRecord::class,
                'source_id'   => $r->id,
                'user_id'     => $r->agent_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
            ]);
    }

    /**
     * Fallback lease_expiry from rentals table (rental management module).
     * Uses a different source_type (Rental vs LeaseRecord) so reconciliation
     * keys don't collide with leaseExpiryFromLeaseRecords.
     * Agency resolved via branches.agency_id.
     */
    private function leaseExpiryFromRentals(): Collection
    {
        return DB::table('rentals as r')
            ->whereNull('r.deleted_at')
            ->whereNotNull('r.lease_end_date')
            ->where('r.lease_end_date', '>=', now()->subDays(30))
            ->where('r.is_active', true)
            ->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')
            ->select(
                'r.id',
                'r.lease_end_date',
                'r.lease_address',
                'r.branch_id',
                'r.created_by_user_id',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'lease_expiry',
                'title'       => 'Lease expires — ' . ($r->lease_address ?: "rental #{$r->id}"),
                'event_date'  => Carbon::parse($r->lease_end_date)->startOfDay(),
                'source_type' => \App\Models\Rental::class,
                'source_id'   => $r->id,
                'user_id'     => $r->created_by_user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => null,
            ]);
    }

    /**
     * Future rent escalation effective dates.
     * rental_amount_versions has rent_incl (not amount).
     */
    private function rentEscalation(): Collection
    {
        return DB::table('rental_amount_versions as rav')
            ->whereNull('rav.deleted_at')
            ->whereNotNull('rav.effective_from')
            ->where('rav.effective_from', '>=', now()->startOfDay())
            ->where('rav.effective_from', '<=', now()->addDays(30))
            ->leftJoin('rentals as r', 'r.id', '=', 'rav.rental_id')
            ->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')
            ->select(
                'rav.id',
                'rav.effective_from',
                'rav.rent_incl',
                'rav.rental_id',
                'r.lease_address',
                'r.branch_id',
                'r.created_by_user_id',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'rent_escalation',
                'title'       => 'Rent escalation — ' . ($r->lease_address ?: "rental #{$r->rental_id}"),
                'event_date'  => Carbon::parse($r->effective_from)->startOfDay(),
                'source_type' => \App\Models\RentalAmountVersion::class,
                'source_id'   => $r->id,
                'user_id'     => $r->created_by_user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => null,
                'metadata'    => ['new_rent_incl' => $r->rent_incl],
            ]);
    }

    /**
     * Rent due — one event per active rental, dated next upcoming 1st.
     * Rolls forward each month as reconciliation runs nightly.
     */
    private function rentDue(): Collection
    {
        $nextFirst = $this->nextFirstOfMonth();

        return DB::table('rentals as r')
            ->whereNull('r.deleted_at')
            ->where('r.is_active', true)
            ->whereNotNull('r.lease_end_date')
            ->where('r.lease_end_date', '>=', now())
            ->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')
            ->select(
                'r.id',
                'r.lease_address',
                'r.branch_id',
                'r.created_by_user_id',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'rent_due',
                'title'       => 'Rent due — ' . ($r->lease_address ?: "rental #{$r->id}"),
                'event_date'  => $nextFirst,
                'source_type' => \App\Models\Rental::class,
                'source_id'   => $r->id,
                'user_id'     => $r->created_by_user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => null,
            ]);
    }

    /**
     * Commercial lease expiry per unit.
     * Agency resolved via commercial_evaluations → branches.
     */
    private function commercialLeaseExpiry(): Collection
    {
        return DB::table('commercial_evaluation_units as ceu')
            ->whereNull('ceu.deleted_at')
            ->whereNotNull('ceu.lease_end')
            ->leftJoin('commercial_evaluations as ce', 'ce.id', '=', 'ceu.commercial_evaluation_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ce.branch_id')
            ->select(
                'ceu.id',
                'ceu.lease_end',
                'ceu.unit_name',
                'ce.branch_id',
                'ce.created_by_user_id',
                'ce.address',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($u) => [
                'event_type'  => 'lease',
                'category'    => 'commercial_lease_expiry',
                'title'       => 'Commercial lease expires — ' . ($u->unit_name ?: ($u->address ?: "unit #{$u->id}")),
                'event_date'  => Carbon::parse($u->lease_end)->startOfDay(),
                'source_type' => \App\Models\CommercialEvaluationUnit::class,
                'source_id'   => $u->id,
                'user_id'     => $u->created_by_user_id,
                'agency_id'   => $u->agency_id,
                'branch_id'   => $u->branch_id,
                'property_id' => null,
            ]);
    }

    private function nextFirstOfMonth(): Carbon
    {
        $today = now()->startOfDay();
        if ($today->day === 1) {
            return $today;
        }
        return $today->copy()->addMonthNoOverflow()->startOfMonth();
    }
}
