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
