<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\RentalInspection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-renewals.md §22 (Johan, 14 Oct 2026) - a lease term changes state ON ITS DATE, never earlier.
 *
 *   1. A renewal that was signed and approved before its start date is a draft shown as "Signed - starts <date>". The lease it renews
 *      stays active (portal, faults, inspections, rent, property status all keep reading it) until its own end date; on the renewal's
 *      start date the renewal goes active and the old term is expired by LeaseActivationService, exactly as a same-day approval always did.
 *   2. A tenancy ends on its move-out date (the notice's move-out date, else the lease end date) - not when the out-inspection is
 *      completed. The out-inspection only confirms it is vacant: when that is on file and the date has come, the lease ends and the
 *      property goes back to its pre-let status.
 *
 * Both are done the moment the thing they wait for happens (a completed out-inspection; a signing) when the date has already come, and by the
 * daily `leases:advance-terms` command otherwise.
 */
class LeaseTermAdvanceService
{
    /**
     * Activate every signed renewal whose start date has come.
     *
     * @return array<int, array{lease_id:int, action:string, note:?string}>
     */
    public function activateDueRenewals(?string $today = null, bool $dryRun = false, ?int $leaseId = null): array
    {
        $today ??= now()->toDateString();
        $hub = app(LeaseHubService::class);
        $out = [];

        $due = Lease::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', Lease::STATUS_DRAFT)
            ->whereNotNull('previous_lease_id')
            ->whereDate('start_date', '<=', $today)
            ->where(function ($q) {
                $q->where(fn ($s) => $s->where('signing_status', Lease::SIGNING_SIGNED)->whereNotNull('accepted_at'))
                    ->orWhere('signing_status', Lease::SIGNING_SIGNED_ON_PAPER);
            })
            ->when($leaseId, fn ($q) => $q->whereKey($leaseId))
            ->orderBy('start_date')->orderBy('id')
            ->get();

        foreach ($due as $renewal) {
            if ($hub->awaitingConfirmation($renewal)) {
                $out[] = ['lease_id' => (int) $renewal->id, 'action' => 'skipped', 'note' => 'signed with a difference nobody has confirmed yet'];
                continue;
            }
            if ($dryRun) {
                $out[] = ['lease_id' => (int) $renewal->id, 'action' => 'would_activate', 'note' => 'starts ' . $renewal->start_date->toDateString()];
                continue;
            }

            try {
                app(LeaseRenewalService::class)->activateRenewalTerm($renewal, null);
                LeaseEvent::create([
                    'lease_id' => $renewal->id, 'event_type' => LeaseEvent::TYPE_LEASE_ACTIVATED_BY_SIGNING,
                    'description' => 'Lease active - the renewal started on ' . $renewal->start_date->format('d M Y'),
                    'metadata' => ['start_date' => $renewal->start_date->toDateString()], 'occurred_at' => now(), 'created_at' => now(),
                ]);
                $out[] = ['lease_id' => (int) $renewal->id, 'action' => 'activated', 'note' => null];
            } catch (ValidationException $e) {
                $note = collect($e->errors())->flatten()->first() ?: 'could not be activated';
                Log::warning('Signed renewal reached its start date but was not activated', ['lease_id' => $renewal->id, 'reason' => $note]);
                $out[] = ['lease_id' => (int) $renewal->id, 'action' => 'blocked', 'note' => $note];
            }
        }

        return $out;
    }

    /**
     * The date a tenancy ends: the move-out date a notice recorded, else the lease's end date. Null for a month-to-month lease with no notice.
     */
    public function endDateOf(Lease $lease): ?string
    {
        $date = $lease->move_out_date ?? $lease->end_date;

        return $date ? $date->toDateString() : null;
    }

    /**
     * Ends the tenancy when it is over AND the out-inspection confirms the property is vacant: the lease expires and the property goes back
     * to the status it had before it was let (rental-renewals.md §15 row 6). A lease whose move-out date is still ahead is left alone -
     * completing the inspection early changes nothing about the tenancy.
     */
    public function endIfVacated(Lease $lease, ?string $today = null, bool $dryRun = false): bool
    {
        $today ??= now()->toDateString();

        $lease = Lease::withoutGlobalScopes()->find($lease->id);
        if (! $lease || $lease->status !== Lease::STATUS_ACTIVE || $lease->renewed_lease_id) {
            // Renewed, cancelled or not a running term: rows 3/4/7 own those paths.
            return false;
        }
        if (! LeaseSetting::autoRestoreStatusOnLeaseEndedFor($lease->agency_id)) {
            return false;
        }

        $ends = $this->endDateOf($lease);
        if ($ends === null || $ends > $today) {
            return false;
        }
        $vacant = $lease->inspections()->where('type', RentalInspection::TYPE_OUT)->where('status', RentalInspection::STATUS_COMPLETED)->exists();
        if (! $vacant) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        $lease->status = Lease::STATUS_EXPIRED;
        $lease->save();

        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_TENANCY_ENDED,
            'description' => 'Tenancy ended - move-out ' . \Illuminate\Support\Carbon::parse($ends)->format('d M Y') . ', out-inspection confirmed the property vacant',
            'metadata' => ['ends' => $ends], 'occurred_at' => now(), 'created_at' => now(),
        ]);

        app(PropertyStatusFollowsLeaseService::class)->restorePreLetStatus(
            $lease,
            "Lease #{$lease->id} ended - out-inspection confirmed vacant",
            $today,
        );

        return true;
    }

    /**
     * Every running lease whose tenancy is over and whose out-inspection is on file (the daily catch-up for an inspection completed before
     * the move-out date).
     *
     * @return array<int, array{lease_id:int, action:string, note:?string}>
     */
    public function endVacatedTenancies(?string $today = null, bool $dryRun = false, ?int $leaseId = null): array
    {
        $today ??= now()->toDateString();
        $out = [];

        $candidates = Lease::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereNull('renewed_lease_id')
            ->whereRaw('COALESCE(move_out_date, end_date) IS NOT NULL AND COALESCE(move_out_date, end_date) <= ?', [$today])
            ->whereHas('inspections', fn ($q) => $q->where('type', RentalInspection::TYPE_OUT)->where('status', RentalInspection::STATUS_COMPLETED))
            ->when($leaseId, fn ($q) => $q->whereKey($leaseId))
            ->orderBy('id')
            ->get();

        foreach ($candidates as $lease) {
            $done = $this->endIfVacated($lease, $today, $dryRun);
            $out[] = ['lease_id' => (int) $lease->id, 'action' => $done ? ($dryRun ? 'would_end' : 'ended') : 'skipped', 'note' => null];
        }

        return $out;
    }
}
