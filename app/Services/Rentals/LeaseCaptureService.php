<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\User;

/**
 * .ai/specs/leases.md §15.2 / §15.4 (Build L1 — shell). The ONE writer behind the single capture
 * screen: lease + tenants + agreement terms in one transaction, for new lease and renewal, for
 * "Create lease only", the signed paper copy and (via LeaseSigningLauncher) "Create lease & prepare
 * for signing". Its real body is Build L2 (paths a and paper copy) and Build L3a (path b); the final
 * signature is fixed here so those builds fill it in without changing a caller.
 */
class LeaseCaptureService
{
    public const INTENT_LEASE_ONLY = 'lease_only';
    public const INTENT_LEASE_AND_SIGN = 'lease_and_sign';
    public const INTENT_PAPER_COPY = 'paper_copy';

    /**
     * @param array<string,mixed> $input    the validated capture-screen fields (§15.3)
     * @param string              $intent   one of the INTENT_* constants
     * @param Lease|null          $previous the lease being renewed, null for a new lease
     */
    public function capture(array $input, string $intent, User $user, ?Lease $previous = null): Lease
    {
        throw new \LogicException('LeaseCaptureService::capture() is built in Builds L2/L3a (leases.md §15.21).');
    }
}
