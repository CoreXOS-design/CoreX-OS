<?php

namespace App\Services\Rentals;

use App\Models\Lease;

/**
 * .ai/specs/leases.md §15.8 (Build L1 — shell). Compares what the lease agreement PRINTS with the
 * lease record, so a value changed in e-sign is never absorbed silently (R6). In L1 it reports "no
 * differences" for every lease — nothing calls it yet, and the one listener that will
 * (UpdateLeaseSigningState) is inert. The real comparison, the fingerprint and the confirm screen are
 * Build L3c.
 */
class LeaseAgreementCheck
{
    /**
     * @return array{has_differences: bool, differences: array<int,array<string,mixed>>, cannot_verify: array<int,string>, fingerprint: ?string}
     */
    public function verdict(Lease $lease): array
    {
        return [
            'has_differences' => false,
            'differences' => [],
            'cannot_verify' => [],
            'fingerprint' => null,
        ];
    }
}
