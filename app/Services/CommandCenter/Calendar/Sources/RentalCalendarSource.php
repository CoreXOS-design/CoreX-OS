<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 3 rental-domain event classes off the single lease store:
 *   lease_expiry             — leases.end_date, active leases only
 *   rent_escalation          — lease_escalations.effective_date (future)
 *   rent_due                 — computed: next 1st-of-month per active lease
 * Plus commercial_lease_expiry (own, unrelated table — unchanged).
 *
 * AT-439 — repointed from the legacy `lease_records` (canonical) /
 * `rentals` (fallback) / `rental_amount_versions` tables to the single
 * `leases` store + its `lease_escalations` child, per Johan's 2026-10-05
 * ruling: repoint the calendar, don't delete it. `leases` carries
 * agency_id/branch_id directly (no branches/properties join needed to
 * resolve them, unlike the legacy tables) — Own/Branch/All scoping
 * downstream (CalendarEvent::scopeVisibleTo()) is unaffected, same fields,
 * simpler resolution. The two legacy lease_expiry sources collapse into
 * one, since `leases` is the only lease store now. Rows previously upserted
 * under the retired source_types (Docuperfect\LeaseRecord, Rental) are
 * soft-deleted by ReconcileCalendarEvents' own cleanup step — upsertEvent()
 * never deletes what a source stops producing on its own.
 *
 * Schema notes:
 *   - leases has agency_id/branch_id/created_by_user_id/property_id directly
 *   - lease_escalations has lease_id only — agency/branch resolved via leases
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
            ->merge($this->leaseExpiryFromLeases())
            ->merge($this->rentEscalation())
            ->merge($this->rentDue())
            ->merge($this->commercialLeaseExpiry());
    }

    /**
     * Canonical lease_expiry from the single lease store — active leases
     * only (a draft/expired/cancelled lease has nothing upcoming to flag).
     * Window matches the legacy sources' own grace: from 30 days ago
     * onward, so a lease that expired just before the nightly run doesn't
     * silently vanish from the board.
     */
    private function leaseExpiryFromLeases(): Collection
    {
        return DB::table('leases as l')
            ->whereNull('l.deleted_at')
            ->where('l.status', \App\Models\Lease::STATUS_ACTIVE)
            ->whereNotNull('l.end_date')
            ->where('l.end_date', '>=', now()->subDays(30))
            ->leftJoin('properties as p', 'p.id', '=', 'l.property_id')
            ->select(
                'l.id',
                'l.end_date',
                'l.property_id',
                'l.agency_id',
                'l.branch_id',
                'l.created_by_user_id',
                'p.address',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'lease_expiry',
                'title'       => 'Lease expires — ' . ($r->address ?: "lease #{$r->id}"),
                'event_date'  => Carbon::parse($r->end_date)->startOfDay(),
                'source_type' => \App\Models\Lease::class,
                'source_id'   => $r->id,
                'user_id'     => $r->created_by_user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
            ]);
    }

    /**
     * Future rent escalation effective dates, from the real lease_escalations
     * record (a rate + the new amount it produces) — no more rental_amount_versions.
     */
    private function rentEscalation(): Collection
    {
        return DB::table('lease_escalations as le')
            ->whereNotNull('le.effective_date')
            ->where('le.effective_date', '>=', now()->startOfDay())
            ->where('le.effective_date', '<=', now()->addDays(30))
            ->join('leases as l', 'l.id', '=', 'le.lease_id')
            ->whereNull('l.deleted_at')
            ->leftJoin('properties as p', 'p.id', '=', 'l.property_id')
            ->select(
                'le.id',
                'le.effective_date',
                'le.new_rental_amount',
                'le.lease_id',
                'l.agency_id',
                'l.branch_id',
                'l.created_by_user_id',
                'l.property_id',
                'p.address',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'rent_escalation',
                'title'       => 'Rent escalation — ' . ($r->address ?: "lease #{$r->lease_id}"),
                'event_date'  => Carbon::parse($r->effective_date)->startOfDay(),
                'source_type' => \App\Models\LeaseEscalation::class,
                'source_id'   => $r->id,
                'user_id'     => $r->created_by_user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
                'metadata'    => ['new_rental_amount' => $r->new_rental_amount],
            ]);
    }

    /**
     * Rent due — one event per active lease with a defined end date, dated
     * next upcoming 1st. Rolls forward each month as reconciliation runs
     * nightly. Same "active + end_date in the future" gate the legacy
     * `rentals` source used — a month-to-month lease with no end_date is
     * unchanged behaviour (never got a rent_due event before either).
     */
    private function rentDue(): Collection
    {
        $nextFirst = $this->nextFirstOfMonth();

        return DB::table('leases as l')
            ->whereNull('l.deleted_at')
            ->where('l.status', \App\Models\Lease::STATUS_ACTIVE)
            ->whereNotNull('l.end_date')
            ->where('l.end_date', '>=', now())
            ->leftJoin('properties as p', 'p.id', '=', 'l.property_id')
            ->select(
                'l.id',
                'l.agency_id',
                'l.branch_id',
                'l.created_by_user_id',
                'l.property_id',
                'p.address',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'lease',
                'category'    => 'rent_due',
                'title'       => 'Rent due — ' . ($r->address ?: "lease #{$r->id}"),
                'event_date'  => $nextFirst,
                'source_type' => \App\Models\Lease::class,
                'source_id'   => $r->id,
                'user_id'     => $r->created_by_user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
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
