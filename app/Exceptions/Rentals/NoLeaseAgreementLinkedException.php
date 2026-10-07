<?php

namespace App\Exceptions\Rentals;

use RuntimeException;

/**
 * .ai/specs/leases.md §15.4 / §15.16 (Build L2) — "Create lease & prepare for signing" was asked for, but the
 * agency has no linked, ready lease agreement (or the one posted is not the agency's own). The screen
 * disables the button for this case; this is the server-side refusal behind it, so a forged POST cannot
 * get past. The API turns it into `409 {code: "no_lease_agreement_linked"}`.
 */
class NoLeaseAgreementLinkedException extends RuntimeException
{
    public const CODE = 'no_lease_agreement_linked';

    public function __construct(string $message = 'Your agency has not set up a lease agreement yet.')
    {
        parent::__construct($message);
    }
}
