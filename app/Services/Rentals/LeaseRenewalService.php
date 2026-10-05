<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-renewals.md §1/§5/§7 — AT-444. Every lease TERM is its
 * own row (leases.md §3.1); a renewal is a NEW Lease chained via
 * previous_lease_id, never the same row extended in place.
 *
 * LeaseActivationService::activate() ALREADY closes the previous term
 * (expires it, sets its renewed_lease_id) the moment a lease naming it as
 * previous_lease_id is activated — this service does not duplicate that,
 * it only builds the draft row and the one-click outcome writes, then
 * calls the existing activation service when a term is ready to go live.
 */
class LeaseRenewalService
{
    /**
     * rental-renewals.md §5/§7 — builds the new draft TERM for a renewal,
     * copying property + tenants from the current lease. Used by:
     *  - the manual-upload path (§5(c)) directly, once the agent has a
     *    signed document in hand;
     *  - the copy-forward e-sign path (§5(a)) as the Lease row the draft
     *    Flow is built against (RenewalDraftService, not duplicated here).
     *
     * Does NOT activate — the caller decides when (immediately for the
     * manual-upload path once the document is attached; on e-sign
     * completion for the copy-forward/template paths, §6).
     *
     * @param array{start_date:string,end_date?:?string,rental_amount:float,deposit_amount?:?float,is_month_to_month?:bool,lease_type?:?string} $terms
     */
    public function createRenewalTerm(Lease $current, array $terms, User $user): Lease
    {
        if ($current->status !== Lease::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'lease' => 'Only an active lease can be renewed.',
            ]);
        }

        return DB::transaction(function () use ($current, $terms, $user) {
            $newTerm = Lease::create([
                'agency_id' => $current->agency_id,
                'branch_id' => $current->branch_id,
                'property_id' => $current->property_id,
                'status' => Lease::STATUS_DRAFT,
                'rental_amount' => $terms['rental_amount'],
                'deposit_amount' => $terms['deposit_amount'] ?? $current->deposit_amount,
                'start_date' => $terms['start_date'],
                'end_date' => $terms['end_date'] ?? null,
                'is_month_to_month' => (bool) ($terms['is_month_to_month'] ?? false),
                'lease_type' => $terms['lease_type'] ?? $current->lease_type,
                'source' => 'manual',
                'previous_lease_id' => $current->id,
                'created_by_user_id' => $user->id,
            ]);

            foreach ($current->tenants as $tenant) {
                LeaseTenant::create([
                    'lease_id' => $newTerm->id,
                    'contact_id' => $tenant->contact_id,
                    'is_primary' => $tenant->is_primary,
                ]);
            }

            $this->logEvent($current, LeaseEvent::TYPE_RENEWAL_DRAFT_CREATED, "Renewal draft created (new term #{$newTerm->id})", $user);

            return $newTerm->fresh(['tenants']);
        });
    }

    /**
     * rental-renewals.md §7 — activates a renewal term: records the
     * escalation on the NEW row (reusing LeaseEscalation, append-only),
     * then calls the existing LeaseActivationService, which atomically
     * expires the previous term and chains both pointers. Idempotent
     * guard: refuses a term with no previous_lease_id (that is just a
     * normal lease activation, not a renewal — callers should use
     * LeaseActivationService directly for that case).
     */
    public function activateRenewalTerm(Lease $newTerm, User $user): Lease
    {
        if (!$newTerm->previous_lease_id) {
            throw ValidationException::withMessages([
                'lease' => 'This lease has no previous term to renew — use the normal activate action instead.',
            ]);
        }

        return DB::transaction(function () use ($newTerm, $user) {
            $previous = Lease::withoutGlobalScopes()->findOrFail($newTerm->previous_lease_id);

            if ((float) $previous->rental_amount !== (float) $newTerm->rental_amount) {
                LeaseEscalation::create([
                    'lease_id' => $newTerm->id,
                    'effective_date' => $newTerm->start_date,
                    'previous_rental_amount' => $previous->rental_amount,
                    'new_rental_amount' => $newTerm->rental_amount,
                    'escalation_rate_percent' => LeaseEscalation::computeRatePercent((float) $previous->rental_amount, (float) $newTerm->rental_amount),
                    'note' => 'Renewal escalation',
                    'created_by_user_id' => $user->id,
                ]);
            }

            $activated = app(LeaseActivationService::class)->activate($newTerm);

            $this->logEvent($activated, LeaseEvent::TYPE_RENEWAL_ACTIVATED, "Renewal activated — succeeds lease #{$previous->id}", $user);

            return $activated;
        });
    }

    /**
     * rental-renewals.md §7 — "Month-to-month": end_date cleared, flag set.
     * No e-sign cycle. Reversible (§7's own "all reversible by an
     * authorised user").
     */
    public function recordMonthToMonth(Lease $lease, ?string $note, User $user): Lease
    {
        $lease->update(['is_month_to_month' => true, 'end_date' => null]);

        $this->logEvent($lease, LeaseEvent::TYPE_MONTH_TO_MONTH_SET, 'Lease set to month-to-month' . ($note ? " — {$note}" : ''), $user, ['note' => $note]);

        return $lease->fresh();
    }

    public function reverseMonthToMonth(Lease $lease, User $user): Lease
    {
        $lease->update(['is_month_to_month' => false]);

        $this->logEvent($lease, LeaseEvent::TYPE_MONTH_TO_MONTH_REVERSED, 'Month-to-month reversed', $user);

        return $lease->fresh();
    }

    /**
     * rental-renewals.md §7 — "Tenant gave notice" / "Landlord not
     * renewing": same mechanism, distinguished only by $givenBy for the
     * tenancy log and any future landlord-communication content (§7's own
     * wording — the outcome is identical either way).
     *
     * §19 — Johan's ruling 2026-10-05: $outcome is the agent's explicit,
     * every-time, nothing-pre-selected choice of what happens to the
     * PROPERTY — one of Lease::NOTICE_OUTCOME_{READVERTISE,WITHDRAW,LEAVE}.
     * The caller (controller) validates it is present and one of the three;
     * this service re-validates it too so no caller can slip an invalid
     * value through. Recorded on the lease (notice_outcome) so
     * reverseNotice()/changeNoticeOutcome() below know exactly which
     * property-side effect (if any) must be undone/replaced.
     */
    public function recordNotice(Lease $lease, string $givenBy, string $moveOutDate, ?string $note, User $user, string $outcome, ?bool $showAvailableFromOnPortals = null): Lease
    {
        if (!in_array($givenBy, [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
            throw ValidationException::withMessages(['notice_given_by' => 'Invalid notice source.']);
        }
        $this->guardNoticeOutcome($outcome);

        return DB::transaction(function () use ($lease, $givenBy, $moveOutDate, $note, $user, $outcome, $showAvailableFromOnPortals) {
            $lease->update([
                'notice_date' => now()->toDateString(),
                'notice_given_by' => $givenBy,
                'move_out_date' => $moveOutDate,
                'notice_note' => $note,
                'notice_outcome' => $outcome,
            ]);

            $who = $givenBy === Lease::NOTICE_BY_TENANT ? 'Tenant gave notice' : 'Landlord not renewing';
            $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_RECORDED, "{$who} — move-out {$moveOutDate} — {$this->outcomeLabel($outcome)}" . ($note ? " — {$note}" : ''), $user, ['given_by' => $givenBy, 'move_out_date' => $moveOutDate, 'note' => $note, 'outcome' => $outcome]);

            $this->applyOutcome($lease, $outcome, $moveOutDate, $user, $showAvailableFromOnPortals);

            return $lease->fresh();
        });
    }

    /**
     * §19 — "let the agent change the choice later from the Lease actions
     * menu while the notice is active" (Johan's ruling). Reverses whatever
     * property-side effect the CURRENT outcome applied, then applies the
     * new one — the lease's own notice_date/move_out_date/notice_given_by
     * are untouched, only notice_outcome changes. A no-op (still logged,
     * for an honest tenancy-log trail) when the new choice matches the
     * current one.
     */
    public function changeNoticeOutcome(Lease $lease, string $newOutcome, User $user): Lease
    {
        $this->guardNoticeOutcome($newOutcome);
        if (!$lease->hasActiveNotice()) {
            throw ValidationException::withMessages(['notice_outcome' => 'This lease has no active notice to change.']);
        }

        return DB::transaction(function () use ($lease, $newOutcome, $user) {
            $previousOutcome = (string) $lease->notice_outcome;

            $this->reverseOutcomeEffect($lease, $previousOutcome, $user);
            $lease->update(['notice_outcome' => $newOutcome]);
            $this->applyOutcome($lease, $newOutcome, (string) $lease->move_out_date?->toDateString(), $user, null);

            $this->logEvent(
                $lease,
                LeaseEvent::TYPE_NOTICE_OUTCOME_CHANGED,
                "Notice outcome changed: {$this->outcomeLabel($previousOutcome)} → {$this->outcomeLabel($newOutcome)}",
                $user,
                ['from' => $previousOutcome, 'to' => $newOutcome],
            );

            return $lease->fresh();
        });
    }

    public function reverseNotice(Lease $lease, User $user): Lease
    {
        return DB::transaction(function () use ($lease, $user) {
            $outcome = (string) $lease->notice_outcome;

            $lease->update([
                'notice_date' => null,
                'notice_given_by' => null,
                'move_out_date' => null,
                'notice_note' => null,
                'notice_outcome' => null,
            ]);

            $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_REVERSED, 'Notice reversed', $user);

            $this->reverseOutcomeEffect($lease, $outcome, $user);

            return $lease->fresh();
        });
    }

    private function guardNoticeOutcome(string $outcome): void
    {
        if (!in_array($outcome, [Lease::NOTICE_OUTCOME_READVERTISE, Lease::NOTICE_OUTCOME_WITHDRAW, Lease::NOTICE_OUTCOME_LEAVE], true)) {
            throw ValidationException::withMessages(['notice_outcome' => 'Choose what happens to the property.']);
        }
    }

    private function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            Lease::NOTICE_OUTCOME_READVERTISE => 'Back on the market',
            Lease::NOTICE_OUTCOME_WITHDRAW => 'Withdrawn',
            Lease::NOTICE_OUTCOME_LEAVE => 'Left as is',
            default => $outcome,
        };
    }

    private function applyOutcome(Lease $lease, string $outcome, ?string $moveOutDate, User $user, ?bool $showAvailableFromOnPortals): void
    {
        match ($outcome) {
            Lease::NOTICE_OUTCOME_READVERTISE => app(PropertyStatusFollowsLeaseService::class)->readvertiseOnNotice($lease, (string) $moveOutDate, $user, $showAvailableFromOnPortals),
            Lease::NOTICE_OUTCOME_WITHDRAW => app(PropertyStatusFollowsLeaseService::class)->withdrawOnNotice($lease, $user),
            Lease::NOTICE_OUTCOME_LEAVE => null,
            default => null,
        };
    }

    private function reverseOutcomeEffect(Lease $lease, string $outcome, User $user): void
    {
        match ($outcome) {
            Lease::NOTICE_OUTCOME_READVERTISE => app(PropertyStatusFollowsLeaseService::class)->reverseReadvertise($lease, $user),
            Lease::NOTICE_OUTCOME_WITHDRAW => app(PropertyStatusFollowsLeaseService::class)->reverseWithdraw($lease, $user),
            default => null,
        };
    }

    private function logEvent(Lease $lease, string $type, string $description, User $user, ?array $metadata = null): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => $type,
            'description' => $description,
            'actor_user_id' => $user->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
