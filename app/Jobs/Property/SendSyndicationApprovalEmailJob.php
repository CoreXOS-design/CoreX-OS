<?php

declare(strict_types=1);

namespace App\Jobs\Property;

use App\Mail\SyndicationApprovalRequestedMail;
use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use App\Models\Role;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails every chosen approver that a listing is waiting for them.
 * Spec: .ai/specs/syndication-approval-gate.md §5.4 (D4).
 *
 * Carries SCALARS only (ids), never the event or the models — a queued
 * listener on a domain event cannot be restored (AbstractDomainEvent's
 * readonly $eventId), so the listener stays sync and dispatches this.
 *
 * Runs on the DEFAULT queue deliberately: the named queues each need their
 * own Supervisor worker on live, and an approval email silently stranded in
 * an unserved queue is exactly the failure this feature cannot afford.
 *
 * Stamps `notified_at` only on a successful send, so a mail failure stays
 * visible instead of being assumed. The gate never depends on the email
 * arriving — the property is already `pending` before this runs.
 */
class SendSyndicationApprovalEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private int $propertyId,
        private int $approvalId,
        /** @var list<int> */
        private array $approverUserIds,
    ) {
    }

    public function handle(): void
    {
        if (empty($this->approverUserIds)) {
            Log::warning('Syndication approval requested but the agency has no approvers configured.', [
                'property_id' => $this->propertyId,
                'approval_id' => $this->approvalId,
            ]);

            return;
        }

        $property = Property::withoutGlobalScope(AgencyScope::class)->find($this->propertyId);
        $approval = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)->find($this->approvalId);

        if (! $property || ! $approval) {
            return;
        }

        $approvers = User::withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $this->approverUserIds)
            ->where('agency_id', $property->agency_id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        // The roster is empty / all inactive: owners and agency admins are the
        // standing fallback approvers (SyndicationApprovalService::canApprove),
        // so they must hear about the request too or it sits unseen.
        if ($approvers->isEmpty()) {
            $fallbackRoles = array_values(array_unique(array_merge(
                ['admin', 'super_admin'],
                Role::where('is_owner', true)->pluck('name')->all()
            )));

            $approvers = User::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $property->agency_id)
                ->whereIn('role', $fallbackRoles)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->get();
        }

        $sent = false;

        foreach ($approvers as $approver) {
            try {
                Mail::to($approver->email)->send(new SyndicationApprovalRequestedMail($property, $approval));
                $sent = true;
            } catch (\Throwable $e) {
                // One bad address must not stop the rest of the roster.
                Log::error('Syndication approval email failed.', [
                    'property_id' => $this->propertyId,
                    'approval_id' => $this->approvalId,
                    'approver_id' => $approver->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        if ($sent) {
            $approval->forceFill(['notified_at' => now()])->save();
        }
    }
}
