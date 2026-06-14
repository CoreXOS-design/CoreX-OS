<?php

namespace App\Mail;

use App\Models\Compliance\AgencyPolicy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reminder to a staff member that a policy needs acknowledgement (AT-29).
 * Sent from the Policy Register "Remind" action.
 */
class PolicyAcknowledgementReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public AgencyPolicy $policy,
        public User $sentBy,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action required: acknowledge ' . $this->policy->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.policy-acknowledgement-reminder',
            with: [
                'recipientName' => $this->recipient->name,
                'policyName'    => $this->policy->name,
                'sentByName'    => $this->sentBy->name,
                'actionUrl'     => route('agent.portal') . '#compliance',
            ],
        );
    }
}
