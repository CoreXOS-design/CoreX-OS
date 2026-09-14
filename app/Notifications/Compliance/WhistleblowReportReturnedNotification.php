<?php

declare(strict_types=1);

namespace App\Notifications\Compliance;

use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An approver sent a compliance report back to its filer — with their notes. Rides the AT-235
 * gateway. Payload keys are the ones the header bell reads.
 */
class WhistleblowReportReturnedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected WhistleblowComplaint $complaint,
        protected string $outcome,
        protected string $notes,
        protected ?int $returnedByUserId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $ref = 'CDX-WB-' . $this->complaint->id;

        return [
            'type'         => 'whistleblow_report_' . $this->outcome,
            'title'        => $this->outcome === 'rejected'
                ? 'Your compliance report was rejected'
                : 'Changes requested on your compliance report',
            'body'         => $ref . ' — ' . $this->officerName() . ': ' . $this->notes,
            'complaint_id' => $this->complaint->id,
            'action_url'   => route('compliance.whistleblow.show', $this->complaint),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = 'CDX-WB-' . $this->complaint->id;
        $what = $this->outcome === 'rejected' ? 'rejected' : 'sent back for changes';

        return (new MailMessage)
            ->subject('Compliance report ' . $ref . ' ' . $what)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('**' . $this->officerName() . '** has ' . $what . ' your compliance report **' . $ref . '**.')
            ->line('**Their notes:** ' . $this->notes)
            ->action('Open the report', route('compliance.whistleblow.show', $this->complaint))
            ->line($this->outcome === 'rejected'
                ? 'The report will not be sent to the PPRA.'
                : 'Update the report and submit it again when the notes have been addressed.');
    }

    private function officerName(): string
    {
        $u = $this->returnedByUserId ? User::withoutGlobalScopes()->find($this->returnedByUserId) : null;

        return $u?->name ?? 'A compliance officer';
    }
}
