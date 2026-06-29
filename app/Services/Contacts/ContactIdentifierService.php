<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhone;
use App\Models\Scopes\AgencyScope;
use App\Services\ContactDuplicateService;
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
     * Canonical multi-identifier write for the form / API.
     *
     * Upserts the contact's phones + emails from structured input, soft-deletes
     * the ones the user removed, dedupes within the contact (by normalised key),
     * and sets exactly one primary per kind (explicitly marked, else the first).
     * The step-1 observers + reconcile keep contacts.phone/email mirroring the
     * primary throughout. Transaction-safe; no hard deletes.
     *
     * @param array<int,array{value?:string,label?:?string,is_primary?:bool}> $phones
     * @param array<int,array{value?:string,label?:?string,is_primary?:bool}> $emails
     */
    public function syncIdentifiers(Contact $contact, array $phones, array $emails): void
    {
        DB::transaction(function () use ($contact, $phones, $emails) {
            $this->syncKind($contact, ContactPhone::class, 'phone', $phones,
                fn (string $v) => app(ContactDuplicateService::class)->normalizePhone($v),
                fn (ContactPhone $r) => $this->setPrimaryPhone($r),
                fn () => $this->reconcilePhones($contact->id));

            $this->syncKind($contact, ContactEmail::class, 'email', $emails,
                fn (string $v) => strtolower(trim($v)),
                fn (ContactEmail $r) => $this->setPrimaryEmail($r),
                fn () => $this->reconcileEmails($contact->id));
        });
    }

    /**
     * Reverse mirror-sync: ensure a contact carrying a legacy single
     * contacts.phone/email (written by any single-field path — importers, the
     * mobile API, the e-sign signer create, the form before it sends arrays) has
     * a matching PRIMARY child row, so the child tables are the COMPLETE source
     * of truth for the resolvers (AT-125 step 2). Idempotent — a no-op when an
     * active child row for the value already exists. Driven by the Contact
     * `saved` observer, so it covers EVERY writer with no per-writer change.
     */
    public function ensureMirrorHasChildRows(Contact $contact): void
    {
        $phone = trim((string) ($contact->phone ?? ''));
        if ($phone !== '') {
            $norm = app(ContactDuplicateService::class)->normalizePhone($phone);
            $exists = ContactPhone::withoutGlobalScope(AgencyScope::class)
                ->where('contact_id', $contact->id)
                ->when($norm !== null, fn ($q) => $q->where('phone_normalised', $norm), fn ($q) => $q->where('phone', $phone))
                ->exists();
            if (! $exists) {
                ContactPhone::create([
                    'agency_id' => $contact->agency_id,
                    'contact_id' => $contact->id,
                    'phone' => $phone,
                ]);
            }
        }

        $email = trim((string) ($contact->email ?? ''));
        if ($email !== '') {
            $exists = ContactEmail::withoutGlobalScope(AgencyScope::class)
                ->where('contact_id', $contact->id)
                ->where('email_normalised', strtolower($email))
                ->exists();
            if (! $exists) {
                ContactEmail::create([
                    'agency_id' => $contact->agency_id,
                    'contact_id' => $contact->id,
                    'email' => $email,
                ]);
            }
        }
    }

    /**
     * @param class-string $modelClass
     * @param array<int,array{value?:string,label?:?string,is_primary?:bool}> $items
     */
    private function syncKind(
        Contact $contact,
        string $modelClass,
        string $rawCol,
        array $items,
        callable $normalise,
        callable $setPrimary,
        callable $reconcile
    ): void {
        $normCol = $rawCol . '_normalised';

        // Normalise + dedupe the incoming set within this contact (first wins,
        // but an is_primary flag on any duplicate is carried up).
        $incoming = [];
        foreach ($items as $item) {
            $raw = trim((string) ($item['value'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $norm = $normalise($raw);
            $key = $norm ?? mb_strtolower($raw);
            if (isset($incoming[$key])) {
                if (! empty($item['is_primary'])) {
                    $incoming[$key]['is_primary'] = true;
                }
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $incoming[$key] = [
                'value' => $raw,
                'label' => $label !== '' ? $label : null,
                'is_primary' => ! empty($item['is_primary']),
            ];
        }

        $existing = $modelClass::withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)
            ->get();
        $existingByKey = $existing->keyBy(fn ($r) => $r->{$normCol} ?? mb_strtolower((string) $r->{$rawCol}));

        // Soft-delete identifiers the user removed.
        foreach ($existing as $row) {
            $key = $row->{$normCol} ?? mb_strtolower((string) $row->{$rawCol});
            if (! isset($incoming[$key])) {
                $row->delete();
            }
        }

        // Upsert the incoming set.
        $rows = [];
        foreach ($incoming as $key => $inc) {
            $row = $existingByKey->get($key);
            if ($row) {
                $row->{$rawCol} = $inc['value']; // re-set raw → mutator recomputes the normalised key
                $row->label = $inc['label'];
                $row->save();
            } else {
                $row = $modelClass::create([
                    'agency_id' => $contact->agency_id,
                    'contact_id' => $contact->id,
                    $rawCol => $inc['value'],
                    'label' => $inc['label'],
                    'is_primary' => false,
                ]);
            }
            $rows[] = ['row' => $row, 'is_primary' => $inc['is_primary']];
        }

        if ($rows === []) {
            $reconcile(); // none of this kind → mirror nulls
            return;
        }

        $primary = null;
        foreach ($rows as $r) {
            if ($r['is_primary']) {
                $primary = $r['row'];
                break;
            }
        }
        $primary ??= $rows[0]['row'];
        $setPrimary($primary);
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
