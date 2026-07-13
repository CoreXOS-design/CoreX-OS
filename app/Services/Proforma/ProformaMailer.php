<?php

namespace App\Services\Proforma;

use App\Mail\Proforma\ProformaInvoiceMail;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\Proforma\ProformaInvoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivery seam for proforma invoices.
 *
 * NOW: a simple attach-email via the app Mailer (Mailpit on qa1). The recipient is
 * the seller's email when known; a proforma is never silently emailed to nowhere.
 *
 * TODO(AT-228): when the compose flow lands, route this through it (draft → review →
 * send with the agency signature/branding + tracked delivery) instead of a raw send.
 * This method is the ONLY place that changes — callers stay the same.
 */
class ProformaMailer
{
    /** Returns true if an email was actually dispatched. */
    public function send(ProformaInvoice $invoice, string $pdfBytes, string $pdfFilename): bool
    {
        $to = $this->recipientEmail($invoice);
        if (! $to) {
            return false; // no address on file — caller records "not emailed" (not an error)
        }

        $agencyName = Agency::withoutGlobalScopes()->find($invoice->agency_id)?->trading_name
            ?? Agency::withoutGlobalScopes()->find($invoice->agency_id)?->name
            ?? 'Home Finders Coastal';

        try {
            Mail::to($to)->send(new ProformaInvoiceMail($invoice, $pdfBytes, $pdfFilename, $agencyName));
            return true;
        } catch (\Throwable $e) {
            // Delivery must never break generation — the record + filed PDF already exist.
            Log::warning('Proforma email failed for ' . $invoice->number . ': ' . $e->getMessage());
            return false;
        }
    }

    private function recipientEmail(ProformaInvoice $invoice): ?string
    {
        if ($invoice->issued_to_contact_id) {
            $email = Contact::withoutGlobalScopes()->find($invoice->issued_to_contact_id)?->email;
            if ($email) {
                return $email;
            }
        }
        return null;
    }
}
