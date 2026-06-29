<?php

namespace App\Observers;

use App\Models\ContactPhone;
use App\Services\Contacts\ContactIdentifierService;

/**
 * AT-125 — keeps the one-primary invariant + the contacts.phone mirror correct
 * on every ContactPhone change, no matter the caller. Delegates to the single
 * canonical sync point (ContactIdentifierService::reconcilePhones), which uses
 * quiet writes internally so this never re-fires.
 */
class ContactPhoneObserver
{
    public function __construct(private ContactIdentifierService $identifiers)
    {
    }

    public function saved(ContactPhone $phone): void
    {
        $this->identifiers->reconcilePhones((int) $phone->contact_id);
    }

    public function deleted(ContactPhone $phone): void
    {
        $this->identifiers->reconcilePhones((int) $phone->contact_id);
    }

    public function restored(ContactPhone $phone): void
    {
        $this->identifiers->reconcilePhones((int) $phone->contact_id);
    }
}
