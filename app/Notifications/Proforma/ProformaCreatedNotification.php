<?php

namespace App\Notifications\Proforma;

use App\Models\Proforma\ProformaInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) alert to agency admins on each proforma creation.
 */
class ProformaCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ProformaInvoice $invoice,
        public string $generatedByName,
        public string $reference,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'proforma_created',
            'title'      => "Proforma {$this->invoice->number} generated",
            'body'       => "{$this->generatedByName} generated proforma {$this->invoice->number} — {$this->reference}.",
            'action_url' => route('deals-dr2.pipeline', $this->invoice->deal_id),
            'icon'       => 'file-invoice',
            'invoice_id' => $this->invoice->id,
        ];
    }
}
