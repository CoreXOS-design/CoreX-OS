<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhone;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-125 — THE canonical sync point for a contact's identifier set.
 *
 * Two guarantees, enforced no matter who changes a ContactPhone/ContactEmail
 * (form, importer, API, console, test):
 *   1. EXACTLY ONE is_primary per kind when any rows exist (auto-promote the
 *      oldest if none; collapse to the most-recent if several).
 *   2. The synced-primary MIRROR: contacts.phone/email always equals the primary
 *      child row's raw value (NULL when the contact has none of that kind). The
 *      ~77 existing readers depend on this mirror, so it must always be correct.
 *
 * Reconcile is invoked by ContactPhoneObserver/ContactEmailObserver on every
 * save/delete/restore. All internal writes are quiet (saveQuietly / mass update)
 * so reconcile never re-triggers an observer — no recursion. Drops AgencyScope
 * (console/job/cross-agency safe, like the comms resolvers) but KEEPS SoftDeletes
 * so trashed identifiers never count toward the primary or the mirror.
 */
class ContactIdentifierService
{
    public function reconcilePhones(int $contactId): void
    {
        $this->reconcile($contactId, ContactPhone::class, 'phone', 'phone');
    }

    public function reconcileEmails(int $contactId): void
    {
        $this->reconcile($contactId, ContactEmail::class, 'email', 'email');
    }

    /** Make $phone the contact's primary phone (clears siblings) and re-sync the mirror. */
    public function setPrimaryPhone(ContactPhone $phone): void
    {
        $this->setPrimary($phone, ContactPhone::class);
        $this->reconcilePhones((int) $phone->contact_id);
    }

    /** Make $email the contact's primary email (clears siblings) and re-sync the mirror. */
    public function setPrimaryEmail(ContactEmail $email): void
    {
        $this->setPrimary($email, ContactEmail::class);
        $this->reconcileEmails((int) $email->contact_id);
    }

    /**
     * @param class-string $modelClass
     */
    private function setPrimary(\Illuminate\Database\Eloquent\Model $identifier, string $modelClass): void
    {
        DB::transaction(function () use ($identifier, $modelClass) {
            $modelClass::withoutGlobalScope(AgencyScope::class)
                ->where('contact_id', $identifier->contact_id)
                ->where('id', '!=', $identifier->id)
                ->update(['is_primary' => false]); // mass update — fires no events

            $identifier->is_primary = true;
            $identifier->saveQuietly();
        });
    }

    /**
     * @param class-string $modelClass
     */
    private function reconcile(int $contactId, string $modelClass, string $rawColumn, string $mirrorColumn): void
    {
        DB::transaction(function () use ($contactId, $modelClass, $rawColumn, $mirrorColumn) {
            $contact = Contact::withoutGlobalScopes()->find($contactId);
            if (! $contact) {
                return;
            }

            /** @var Collection $rows */
            $rows = $modelClass::withoutGlobalScope(AgencyScope::class) // keep SoftDeletes
                ->where('contact_id', $contactId)
                ->orderBy('id')
                ->get();

            $primary = $this->ensureSinglePrimary($rows);
            $mirror = $primary?->{$rawColumn};

            if ($contact->{$mirrorColumn} !== $mirror) {
                $contact->{$mirrorColumn} = $mirror;
                $contact->saveQuietly(); // mirror only — no Contact observer side effects
            }
        });
    }

    /**
     * Collapse the rows to exactly one primary and return it (null if no rows).
     * No primary → promote the oldest. Several → keep the most-recently-updated
     * (id desc tiebreak), demote the rest. All writes quiet.
     */
    private function ensureSinglePrimary(Collection $rows): ?\Illuminate\Database\Eloquent\Model
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $primaries = $rows->filter(fn ($r) => (bool) $r->is_primary)->values();

        if ($primaries->count() === 1) {
            return $primaries->first();
        }

        if ($primaries->isEmpty()) {
            $first = $rows->first(); // oldest by id
            $first->is_primary = true;
            $first->saveQuietly();

            return $first;
        }

        // More than one primary — keep the freshest, demote the others.
        $keep = $primaries
            ->sortByDesc(fn ($r) => [optional($r->updated_at)->getTimestamp() ?? 0, $r->id])
            ->first();

        foreach ($primaries as $row) {
            if ($row->id !== $keep->id && $row->is_primary) {
                $row->is_primary = false;
                $row->saveQuietly();
            }
        }

        return $keep;
    }
}
