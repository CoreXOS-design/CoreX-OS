<?php

namespace App\Services\Compliance;

use App\Models\Contact;
use App\Models\FicaSubmission;

/**
 * The ONE FICA gate — Johan's ruling, 2026-10-08 (relayed by the conductor, paraphrased): the FICA gate lifts on SUBMITTED, not only
 * on approved. A person who has done their part (completed and submitted the FICA form) no longer
 * holds anything up; what happens to that submission afterwards (agent check, compliance officer
 * approval) is our staff's own work and shows as a status, never as a stop in front of them.
 *
 * Sales (the e-sign signer gate, the e-sign wizard's FICA kick-off) and rentals (sending an
 * application to the authoriser, the lease flow's warnings, the signer gate on a lease agreement)
 * ALL ask this class. There is no second, rentals-only rule — before this class existed the list of
 * statuses was copy-pasted into the signing controller and the wizard, and the rentals side asked a
 * different question (Contact::ficaStatus(), which only says "complete" for an APPROVED submission),
 * which is how a tenant who had submitted FICA still read as "FICA outstanding" on the rentals side.
 *
 * "Open" means the gate is open: the contact may proceed. Not open is a WARNING with a link to
 * request/complete FICA, except at the places listed in .ai/specs/compliance.md §"The FICA gate"
 * where sales genuinely hard-blocks (the external signer's own signing page) — those mirror sales
 * exactly and nothing else is allowed to block on FICA.
 *
 * Soft-deleted submissions never count (FicaSubmission uses SoftDeletes; the Eloquent query
 * excludes them, same rule as FicaSubmission::applyGenuineRecordFilter()). FICA validity (12 months) is NOT
 * part of this gate — sales' signer gate never checked it either; an approved submission is approved.
 */
final class FicaGate
{
    /**
     * FicaSubmission statuses that mean "the contact has submitted FICA and it has not been
     * turned back". submitted → under_review → agent_approved → referred_to_co → approved is the
     * whole review pipeline; every stage after the contact's own submit keeps the gate open.
     */
    public const OPEN_STATUSES = ['submitted', 'under_review', 'agent_approved', 'referred_to_co', 'approved'];

    /** Statuses that mean the contact's own FICA was sent back to them ("redo it"). */
    public const RETURNED_STATUSES = ['corrections_requested', 'rejected'];

    public const STATE_NONE = 'none';                  // no FICA request exists for this contact
    public const STATE_WAITING_CLIENT = 'waiting_client'; // a request was sent, the contact has not submitted yet
    public const STATE_RETURNED = 'returned';          // sent back to the contact for corrections / rejected
    public const STATE_SUBMITTED = 'submitted';        // submitted, in our staff's review pipeline
    public const STATE_APPROVED = 'approved';          // fully approved

    /** Is the gate open for this contact (submitted — or further — FICA on file)? */
    public static function isOpen(?int $contactId): bool
    {
        return in_array(self::stateFor($contactId), [self::STATE_SUBMITTED, self::STATE_APPROVED], true);
    }

    /** The contact's FICA state, best submission wins (an approved one outranks a newer draft). */
    public static function stateFor(?int $contactId): string
    {
        if (! $contactId) {
            return self::STATE_NONE;
        }

        $statuses = FicaSubmission::query()
            ->where('contact_id', $contactId)
            ->pluck('status')
            ->all();

        return self::stateFromStatuses($statuses);
    }

    /**
     * @param array<int, string> $statuses every non-deleted FicaSubmission status of one contact
     */
    public static function stateFromStatuses(array $statuses): string
    {
        if (in_array('approved', $statuses, true)) {
            return self::STATE_APPROVED;
        }
        if (array_intersect($statuses, self::OPEN_STATUSES) !== []) {
            return self::STATE_SUBMITTED;
        }
        if (in_array('draft', $statuses, true)) {
            return self::STATE_WAITING_CLIENT;
        }
        if (array_intersect($statuses, self::RETURNED_STATUSES) !== []) {
            return self::STATE_RETURNED;
        }

        return self::STATE_NONE;
    }

    /** Short label for a state — the same words everywhere a state is shown. */
    public static function labelFor(string $state): string
    {
        return match ($state) {
            self::STATE_APPROVED => 'FICA approved',
            self::STATE_SUBMITTED => 'FICA submitted — with us for review',
            self::STATE_WAITING_CLIENT => 'FICA requested — not yet submitted',
            self::STATE_RETURNED => 'FICA sent back — needs redoing',
            default => 'No FICA on file',
        };
    }

    /**
     * Everything a screen needs to WARN about one person: whether the gate is open, the one-line
     * state, a plain-language warning (null when open) and where an agent goes to request/complete
     * the FICA (the existing submission when there is one, otherwise the contact's own page, where
     * an agent can send the FICA request).
     *
     * @return array{contact_id:int|null,name:string,state:string,open:bool,label:string,warning:?string,url:?string}
     */
    public static function describe(?Contact $contact, string $role = ''): array
    {
        $name = $contact ? trim((string) $contact->full_name) : '';
        $state = self::stateFor($contact?->id);
        $open = in_array($state, [self::STATE_SUBMITTED, self::STATE_APPROVED], true);
        $who = ($role !== '' ? $role . ' ' : '') . ($name !== '' ? $name : 'this contact');

        $warning = $open ? null : match ($state) {
            self::STATE_WAITING_CLIENT => "FICA has been requested from {$who} but not yet submitted. You can carry on — they will need to complete it before they can sign.",
            self::STATE_RETURNED => "{$who}'s FICA was sent back and has not been resubmitted. You can carry on — it needs completing before they can sign.",
            default => "No FICA has been requested from {$who}. You can carry on — request it now so they can sign without a hold-up.",
        };

        $url = null;
        if ($contact && ! $open) {
            $existing = FicaSubmission::query()->where('contact_id', $contact->id)->orderByDesc('id')->first();
            $url = $existing
                ? route('compliance.fica.show', $existing)
                : route('corex.contacts.show', $contact);
        }

        return [
            'contact_id' => $contact?->id,
            'name' => $name,
            'state' => $state,
            'open' => $open,
            'label' => self::labelFor($state),
            'warning' => $warning,
            'url' => $url,
        ];
    }
}
