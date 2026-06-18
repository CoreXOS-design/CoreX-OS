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

        // Domain event — spec .ai/specs/corex-domain-events-spec.md.
        // `channel` carries the consent_type; `granted` is true only for an
        // active 'given' decision (a 'declined' or revoked record is not granted).
        $contact = Contact::withoutGlobalScopes()->find($record->contact_id);
        if ($contact) {
            event(new \App\Events\Contact\ContactConsentChanged(
                contact: $contact,
                channel: (string) $record->consent_type,
                granted: $record->revoked_at === null
                    && $record->decision === ContactConsentRecord::DECISION_GIVEN,
                actorUserId: \Illuminate\Support\Facades\Auth::id(),
            ));
        }
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
