<?php

namespace App\Exceptions\Rentals;

use RuntimeException;

/**
 * .ai/specs/leases.md §15.9 (Build L3c) — the agent pressed "Confirm lease details" but the confirmation cannot be
 * taken: the agreement was edited again since the screen was drawn (the fingerprint no longer matches), a different
 * person is printed in it, a value the agent had to type is still missing, or an accepted value would not make a
 * valid lease. Nothing has been written when this is thrown. The message is plain words for the agent; `reason`
 * is the machine-readable cause the API returns.
 */
class LeaseAgreementConfirmationRefused extends RuntimeException
{
    public const NOT_APPLICABLE = 'no_agreement_to_confirm';
    public const STALE = 'agreement_changed_again';
    public const BLOCKED = 'different_person_in_agreement';
    public const INCOMPLETE = 'value_needed';
    public const INVALID = 'value_not_acceptable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
