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
     */
    public function recordNotice(Lease $lease, string $givenBy, string $moveOutDate, ?string $note, User $user): Lease
    {
        if (!in_array($givenBy, [Lease::NOTICE_BY_TENANT, Lease::NOTICE_BY_LANDLORD], true)) {
            throw ValidationException::withMessages(['notice_given_by' => 'Invalid notice source.']);
        }

        $lease->update([
            'notice_date' => now()->toDateString(),
            'notice_given_by' => $givenBy,
            'move_out_date' => $moveOutDate,
            'notice_note' => $note,
        ]);

        $who = $givenBy === Lease::NOTICE_BY_TENANT ? 'Tenant gave notice' : 'Landlord not renewing';
        $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_RECORDED, "{$who} — move-out {$moveOutDate}" . ($note ? " — {$note}" : ''), $user, ['given_by' => $givenBy, 'move_out_date' => $moveOutDate, 'note' => $note]);

        return $lease->fresh();
    }

    public function reverseNotice(Lease $lease, User $user): Lease
    {
        $lease->update([
            'notice_date' => null,
            'notice_given_by' => null,
            'move_out_date' => null,
            'notice_note' => null,
        ]);

        $this->logEvent($lease, LeaseEvent::TYPE_NOTICE_REVERSED, 'Notice reversed', $user);

        return $lease->fresh();
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
