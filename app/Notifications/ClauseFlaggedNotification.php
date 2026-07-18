<?php

namespace App\Notifications;

use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AT-299 — a recipient FLAGGED a clause during signing, which freezes the
 * ceremony (AT-291 ⑤). The agent who sent the document must be told, or a
 * frozen seller sits behind an uninformed agent — a dead deal. Notifies the
 * sending agent with a deep-link to the resolve view.
 *
 * Rides the AT-235 gateway (NotificationDispatcher::send); via() is only the
 * fallback for a stray ->notify(). Defines toArray (in-app) + toMail (email) so
 * the gateway can deliver both when the user/event allow.
 */
class ClauseFlaggedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected DocumentAmendment $amendment,
        protected SignatureRequest $signingRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    private function documentName(): string
    {
        return $this->amendment->template?->document?->name ?? 'a document';
    }

    private function clauseRef(): string
    {
        return (string) ($this->amendment->flag_clause_ref ?? $this->amendment->section_reference ?? '—');
    }

    private function signerName(): string
    {
        return $this->signingRequest->signer_name ?: 'A signing party';
    }

    private function resolveUrl(): string
    {
        return route('docuperfect.amendments.review', $this->amendment->id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'clause_flagged',
            'title'       => 'A clause was flagged — review required',
            'body'        => $this->signerName() . ' flagged Clause ' . $this->clauseRef()
                . ' on "' . $this->documentName() . '". Signing is paused until you resolve it.',
            'amendment_id' => $this->amendment->id,
            'deep_link'   => $this->resolveUrl(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A clause was flagged on "' . $this->documentName() . '" — review required')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('**' . $this->signerName() . '** flagged **Clause ' . $this->clauseRef() . '** on **'
                . $this->documentName() . '** during signing.')
            ->line('Signing is now paused for all parties until you review the flag and resolve or re-send the document.')
            ->action('Review the flagged clause', $this->resolveUrl())
            ->line('The signing party has been told their proposal is under review.');
    }
}
