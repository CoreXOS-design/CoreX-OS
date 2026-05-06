<?php

namespace App\Observers;

use App\Models\Contact;
use App\Models\ContactConsentRecord;

/**
 * Recomputes denormalised channel opt-out flags on contacts
 * whenever a consent record is created, updated, or deleted.
 */
class ContactConsentRecordObserver
{
    public function saved(ContactConsentRecord $record): void
    {
        $this->recompute($record->contact_id);
    }

    public function deleted(ContactConsentRecord $record): void
    {
        $this->recompute($record->contact_id);
    }

    private function recompute(int $contactId): void
    {
        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact) {
            return;
        }

        $contact->recomputeChannelConsent();
    }
}
