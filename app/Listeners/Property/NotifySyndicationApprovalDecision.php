<?php

declare(strict_types=1);

namespace App\Listeners\Property;

use App\Events\Property\SyndicationApprovalRequested;
use App\Events\Property\SyndicationApprovalRevoked;
use App\Events\Property\SyndicationApproved;
use App\Events\Property\SyndicationRejected;
use App\Jobs\Property\SendSyndicationApprovalEmailJob;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The one listener for all four layer-3 approval events.
 * Spec: .ai/specs/syndication-approval-gate.md §8.
 *
 * SYNC by design, never ShouldQueue: a queued listener on an
 * AbstractDomainEvent fatals restoring the parent's readonly $eventId. All it
 * does is dispatch a job carrying scalars, so it costs the request nothing.
 *
 * Registered explicitly in AppServiceProvider::boot() — event discovery is
 * OFF in CoreX, so a listener that is not registered there does nothing at all.
 */
class NotifySyndicationApprovalDecision
{
    public function handleRequested(SyndicationApprovalRequested $event): void
    {
        SendSyndicationApprovalEmailJob::dispatch(
            (int) $event->property->id,
            $event->approvalId,
            $event->approverUserIds,
        );
    }

    public function handleApproved(SyndicationApproved $event): void
    {
        $this->notifyListingAgent(
            $event->property,
            'syndication_approved',
            'Listing approved for syndication',
            'Your listing has been approved — you can now send it to the portals and your website.',
            'info',
        );
    }

    public function handleRejected(SyndicationRejected $event): void
    {
        $this->notifyListingAgent(
            $event->property,
            'syndication_rejected',
            'Listing not approved for syndication',
            'Your listing was not approved: ' . $event->reason,
            'warning',
        );
    }

    public function handleRevoked(SyndicationApprovalRevoked $event): void
    {
        $this->notifyListingAgent(
            $event->property,
            'syndication_approval_revoked',
            'Syndication approval withdrawn',
            'Approval for this listing has been withdrawn: ' . $event->reason
                . ' It cannot be sent anywhere new until it is approved again.',
            'warning',
        );
    }

    /**
     * Best-effort in-app notification to the listing agent. A notification
     * failure must never roll back a decision that is already recorded — the
     * audit row and the property stamp are the authoritative facts.
     */
    private function notifyListingAgent(
        Property $property,
        string $eventKey,
        string $title,
        string $body,
        string $severity,
    ): void {
        $agentId = $property->agent_id;

        if (! $agentId) {
            return;
        }

        $agent = User::withoutGlobalScope(AgencyScope::class)->find($agentId);

        if (! $agent) {
            return;
        }

        try {
            Notification::send($agent, new PillarEventNotification(
                eventKey: $eventKey,
                pillar: 'property',
                title: $title,
                body: $body,
                subjectType: Property::class,
                subjectId: (int) $property->id,
                subjectLabel: $property->address ?: $property->title,
                actionUrl: route('corex.properties.show', $property->id),
                severity: $severity,
            ));
        } catch (\Throwable $e) {
            Log::warning('Syndication approval: could not notify listing agent.', [
                'agent_id' => $agentId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
