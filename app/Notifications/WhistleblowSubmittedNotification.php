<?php

namespace App\Notifications;

use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * 2026-09-28 — a whistleblow complaint was SUBMITTED and is awaiting
 * approval (spec §6.2). Rides the AT-235 gateway (NotificationDispatcher::
 * send) like every other CoreX alert — mirrors FicaReferredToCoNotification.
 * In-app only: the Compliance Officer's own submission notice is a separate,
 * agent-branded email (WhistleblowSubmittedCoMail) — this is the approver's
 * queue badge, not a duplicate of that email.
 */
class WhistleblowSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected WhistleblowComplaint $complaint,
        protected User $reporter,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $tierNumber = str_replace('tier_', '', $this->complaint->tier);

        return [
            'type'         => 'whistleblow_submitted_for_approval',
            'title'        => 'Compliance report awaiting your approval',
            'body'         => $this->reporter->name . ' filed a Tier ' . $tierNumber . ' compliance report for '
                . $this->complaint->property_address . ' — CDX-WB-' . $this->complaint->id,
            'complaint_id' => $this->complaint->id,
            'reporter_id'  => $this->reporter->id,
            'deep_link'    => '/compliance/whistleblow/' . $this->complaint->id,
        ];
    }
}
