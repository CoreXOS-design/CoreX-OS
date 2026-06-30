<?php

namespace App\Observers;

use App\Models\ContactEmail;
use App\Services\Contacts\ContactIdentifierService;

/**
 * AT-125 — keeps the one-primary invariant + the contacts.email mirror correct
 * on every ContactEmail change. Delegates to the single canonical sync point
 * (ContactIdentifierService::reconcileEmails), which uses quiet writes so this
 * never re-fires.
 */
class ContactEmailObserver
{
    public function __construct(private ContactIdentifierService $identifiers)
    {
    }

    /**
     * AT-125 no-backdoor — an email added to an already-opted-out contact is
     * suppressed too, so a new address can never reach an opted-out person.
     */
    public function created(ContactEmail $email): void
    {
        $contact = \App\Models\Contact::withoutGlobalScopes()->find($email->contact_id);
        if ($contact) {
            app(\App\Services\SellerOutreach\MarketingConsentService::class)
                ->suppressNewIdentifierIfOptedOut($contact, \App\Models\MarketingSuppression::TYPE_EMAIL, $email->email_normalised);
        }
    }

    public function saved(ContactEmail $email): void
    {
        $this->identifiers->reconcileEmails((int) $email->contact_id);
    }

    public function deleted(ContactEmail $email): void
    {
        $this->identifiers->reconcileEmails((int) $email->contact_id);
    }

    public function restored(ContactEmail $email): void
    {
        $this->identifiers->reconcileEmails((int) $email->contact_id);
    }
}
