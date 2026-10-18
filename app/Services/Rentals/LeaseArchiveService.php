<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §3.8 — Archive and Restore for leases (soft delete only — never a hard delete).
 *
 * The one place a lease is archived or restored, so the property's let status can never disagree with the lease:
 *
 *  - Archiving a DRAFT or ACTIVE lease CANCELS it in the same step (status -> cancelled, who/when/why recorded on
 *    the lease), because every scheduler, portal-access rule and activation guard in CoreX reads `status`, and many
 *    of them look past soft-deletes on purpose. A cancelled-and-archived lease cannot keep charging escalations,
 *    sending expiry alerts or holding the property. What the lease WAS is kept in `archived_from_status` so Restore
 *    can put it back exactly.
 *  - A cancelled or expired lease is just hidden (nothing about its status changes).
 *  - Archiving an ACTIVE lease releases the property through the same restore-pre-let-status mechanism a cancel
 *    uses (PropertyStatusFollowsLeaseService::restorePreLetStatus — which keeps the property let if ANOTHER live
 *    lease is still active on it), under the same agency toggle as a cancel.
 *  - Restoring a lease that was active re-checks, under a lock on the property, that no other live lease is
 *    active; if one is, the restore is refused and the lease stays archived. When it goes through, the property
 *    goes back to "Let out" through the same path activation uses.
 */
class LeaseArchiveService
{
    /** @throws ValidationException */
    public function archive(Lease $lease, User $user, string $reason): Lease
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['archive_reason' => 'Give a reason for archiving this lease.']);
        }

        if ($lease->trashed()) {
            throw ValidationException::withMessages(['lease' => 'This lease is already archived.']);
        }

        $endsTenancy = in_array($lease->status, [Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE], true);

        // An agreement still open in e-sign goes with the lease — the same close a cancel does (leases.md §15.13).
        if ($endsTenancy) {
            app(LeaseSigningLauncher::class)->closeOpenAgreement($lease, $user, $reason);
            $lease->refresh();
        }

        return DB::transaction(function () use ($lease, $user, $reason, $endsTenancy) {
            Property::withoutGlobalScopes()->withTrashed()->whereKey($lease->property_id)->lockForUpdate()->first();

            $locked = Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
            if (!$locked || $locked->trashed()) {
                throw ValidationException::withMessages(['lease' => 'This lease is already archived.']);
            }

            $from = $locked->status;
            $wasActive = $from === Lease::STATUS_ACTIVE;

            $attributes = [
                'archived_by_user_id' => $user->id,
                'archive_reason' => mb_substr($reason, 0, 500),
                'archived_from_status' => $from,
            ];
            if ($endsTenancy) {
                $attributes += [
                    'status' => Lease::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $user->id,
                    'cancel_reason' => mb_substr($reason, 0, 500),
                ];
            }
            $locked->forceFill($attributes)->save();

            LeaseEvent::create([
                'lease_id' => $locked->id,
                'event_type' => LeaseEvent::TYPE_LEASE_ARCHIVED,
                'description' => 'Lease archived — ' . $reason,
                'actor_user_id' => $user->id,
                'metadata' => ['reason' => $reason, 'was' => $from],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $locked->delete();

            if ($wasActive && LeaseSetting::autoRestoreStatusOnLeaseCancelledFor($locked->agency_id)) {
                app(PropertyStatusFollowsLeaseService::class)->restorePreLetStatus(
                    $locked,
                    "Lease #{$locked->id} archived",
                    now()->toDateString(),
                    $user,
                );
            }

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function restore(Lease $lease, User $user): Lease
    {
        return DB::transaction(function () use ($lease, $user) {
            $locked = Lease::withoutGlobalScopes()->withTrashed()->whereKey($lease->id)->lockForUpdate()->first();
            if (!$locked || !$locked->trashed()) {
                throw ValidationException::withMessages(['lease' => 'This lease is not archived.']);
            }

            // Leases archived before this build carry no archived_from_status — they come back as they are.
            $target = $locked->archived_from_status ?: $locked->status;
            $reopens = in_array($locked->archived_from_status, [Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE], true);
            $property = Property::withoutGlobalScopes()->withTrashed()->whereKey($locked->property_id)->lockForUpdate()->first();

            if ($target === Lease::STATUS_ACTIVE) {
                if (!$property || $property->trashed()) {
                    throw ValidationException::withMessages([
                        'lease' => 'The property for this lease is archived. Restore the property first, then restore the lease.',
                    ]);
                }

                $other = Lease::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('property_id', $locked->property_id)
                    ->where('status', Lease::STATUS_ACTIVE)
                    ->where('id', '!=', $locked->id)
                    ->first();
                if ($other) {
                    throw ValidationException::withMessages([
                        'lease' => "This property already has an active lease (#{$other->id}), so lease #{$locked->id} cannot be made active again. End or cancel that lease first.",
                    ]);
                }
            }

            $attributes = [
                'archived_by_user_id' => null,
                'archive_reason' => null,
                'archived_from_status' => null,
            ];
            if ($reopens) {
                $attributes += [
                    'status' => $target,
                    'cancelled_at' => null,
                    'cancelled_by_user_id' => null,
                    'cancel_reason' => null,
                ];
            }

            $locked->restore();
            $locked->forceFill($attributes)->save();

            LeaseEvent::create([
                'lease_id' => $locked->id,
                'event_type' => LeaseEvent::TYPE_LEASE_RESTORED,
                'description' => 'Lease restored' . ($reopens ? ' — back to ' . $target : ''),
                'actor_user_id' => $user->id,
                'metadata' => ['status' => $locked->status],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            if ($target === Lease::STATUS_ACTIVE) {
                app(LeaseActivationService::class)->flipPropertyToLeasedOut($property, $locked);
            }

            return $locked;
        });
    }
}
