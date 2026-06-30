<?php

namespace App\Notifications\Communications;

use App\Models\Communications\CommsAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-118 — sent to the owning agent + communications.grant_access holders when a
 * non-owner requests access to a contact's threads. Either may authorise from the
 * Communications Access inbox (in-app; database channel).
 */
class CommsAccessRequested extends Notification
{
    use Queueable;

    public function __construct(protected CommsAccessRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $req      = $this->request;
        $reqName  = $req->requester?->name ?? 'A colleague';
        $contact  = $req->contact;
        $contactName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'a contact';

        // AT-132 — name the SPECIFIC thread (subject unless the owner hid it, else
        // channel + date). threadLabel() respects hide_subject.
        $threadLabel = $req->threadLabel();

        return [
            'type'        => 'comms_access_requested',
            'title'       => "{$reqName} requests communications access",
            'body'        => "{$threadLabel} — {$contactName}" . ($req->reason ? " — {$req->reason}" : ''),
            'action_url'  => route('corex.comms-access.inbox'),
            'icon'        => 'lock-open',
            'request_id'  => $req->id,
            'contact_id'  => $req->contact_id,
            'thread_key'  => $req->thread_key,
        ];
    }
}
