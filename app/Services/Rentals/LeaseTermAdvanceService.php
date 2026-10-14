<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\RentalInspection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            ->where(fn ($q) => $this->signedConstraint($q))
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

    /** "Signed and approved" - the one definition the nightly job and the single-lease run both read. */
    private function signedConstraint($q): void
    {
        $q->where(fn ($s) => $s->where('signing_status', Lease::SIGNING_SIGNED)->whereNotNull('accepted_at'))
            ->orWhere('signing_status', Lease::SIGNING_SIGNED_ON_PAPER);
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
    // ── rental-renewals.md §22.1 - "Run today's lease date checks now": the same job logic, for ONE lease, on a QA / Staging box with no scheduler ──

    /** Who may press it: a system owner or an agency admin (View-As respected) - never an ordinary agent. */
    public function mayRun(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($user->effectiveRole(), ['admin', 'super_admin'], true) || $user->isEffectiveOwner();
    }

    /** APP_ENV=production. The one place the testing aids ask - the lease screen, the setting and the run all read this. */
    public function isProductionInstall(): bool
    {
        return app()->environment('production');
    }

    /** The as-of date is a testing aid: the agency's switch is on AND this is not a production install. Production never, whatever the row holds. */
    public function asOfDateAllowed(?int $agencyId): bool
    {
        return ! $this->isProductionInstall() && LeaseSetting::allowAdvanceAsOfDateFor($agencyId);
    }

    /**
     * What this lease is waiting on a date for, or null when it is not waiting on one:
     *   - a signed renewal (this lease, or the running lease it renews) waits for the renewal's start date;
     *   - a running lease with a completed out-inspection waits for its move-out / end date.
     *
     * @return array{kind:string, date:string, target_id:int, text:string}|null
     */
    public function pendingFor(Lease $lease): ?array
    {
        $lease = Lease::withoutGlobalScopes()->find($lease->id);
        if (! $lease || $lease->deleted_at) {
            return null;
        }

        $renewal = null;
        if ($lease->status === Lease::STATUS_DRAFT && $lease->previous_lease_id) {
            $renewal = Lease::withoutGlobalScopes()->whereKey($lease->id)->whereNotNull('start_date')
                ->where(fn ($q) => $this->signedConstraint($q))->first();
        } elseif ($lease->status === Lease::STATUS_ACTIVE && ! $lease->renewed_lease_id) {
            $renewal = Lease::withoutGlobalScopes()->whereNull('deleted_at')->where('previous_lease_id', $lease->id)
                ->where('status', Lease::STATUS_DRAFT)->whereNotNull('start_date')
                ->where(fn ($q) => $this->signedConstraint($q))->orderBy('start_date')->orderBy('id')->first();
        }
        if ($renewal) {
            $date = $renewal->start_date->toDateString();

            return ['kind' => 'renewal_start', 'date' => $date, 'target_id' => (int) $renewal->id,
                'text' => ($lease->id === $renewal->id ? 'This renewal goes active on ' : 'The signed renewal takes over on ') . $renewal->start_date->format('j M Y')];
        }

        if ($lease->status === Lease::STATUS_ACTIVE && ! $lease->renewed_lease_id
            && LeaseSetting::autoRestoreStatusOnLeaseEndedFor($lease->agency_id)
            && ($date = $this->endDateOf($lease))
            && $lease->inspections()->where('type', RentalInspection::TYPE_OUT)->where('status', RentalInspection::STATUS_COMPLETED)->exists()) {
            return ['kind' => 'tenancy_end', 'date' => $date, 'target_id' => (int) $lease->id,
                'text' => 'The tenancy ends on ' . Carbon::parse($date)->format('j M Y') . ' (move-out inspection done)'];
        }

        return null;
    }

    /**
     * Runs the job's logic for this lease only, in one transaction, and does only what the date allows. With $asOf the lease is checked as if
     * that were today (refused unless asOfDateAllowed()).
     *
     * @return array{outcome:string, message:string}  outcome: done | nothing_due | nothing_pending | blocked
     */
    public function runForLease(Lease $lease, ?string $asOf = null, ?User $by = null): array
    {
        if ($asOf !== null && ! $this->asOfDateAllowed((int) $lease->agency_id)) {
            throw ValidationException::withMessages(['as_of' => 'Testing with a different date is not switched on for this agency.']);
        }
        $today = $asOf ?: now()->toDateString();
        $suffix = $asOf ? ' (checked as if today were ' . Carbon::parse($asOf)->format('j M Y') . ')' : '';

        $pending = $this->pendingFor($lease);
        if (! $pending) {
            return ['outcome' => 'nothing_pending', 'message' => 'Nothing is waiting on a date for this lease.'];
        }
        $dateLabel = Carbon::parse($pending['date'])->format('j M Y');
        if ($pending['date'] > $today) {
            return ['outcome' => 'nothing_due', 'message' => "Nothing due until {$dateLabel}.{$suffix}"];
        }

        DB::beginTransaction();
        try {
            if ($pending['kind'] === 'renewal_start') {
                $row = $this->activateDueRenewals($today, false, $pending['target_id'])[0] ?? null;
                $done = $row && $row['action'] === 'activated';
                $doneMessage = "Done - the renewal that starts on {$dateLabel} is now active and the lease it renews has ended.";
            } else {
                $row = $this->endVacatedTenancies($today, false, $pending['target_id'])[0] ?? null;
                $done = $row && $row['action'] === 'ended';
                $doneMessage = 'Done - the tenancy has ended and the property is back to its earlier status.';
            }

            if (! $done) {
                DB::rollBack();
                $why = $row['note'] ?? null;

                return ['outcome' => 'blocked', 'message' => 'Nothing was changed' . ($why ? ' - ' . $why : '') . '.'];
            }

            if ($asOf) {
                LeaseEvent::create([
                    'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_DATE_CHECKS_RUN,
                    'description' => 'Lease date checks run by ' . ($by?->name ?? 'an administrator') . ' as if today were ' . Carbon::parse($asOf)->format('j M Y') . ' (testing)',
                    'metadata' => ['as_of' => $asOf, 'kind' => $pending['kind']], 'occurred_at' => now(), 'created_at' => now(),
                ]);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['outcome' => 'done', 'message' => $doneMessage . $suffix];
    }
}
