<?php

namespace App\Exceptions;

use App\Models\Lease;
use RuntimeException;

/**
 * Johan's ruling (2026-10-05) — a property with an active lease must never
 * be archived (soft-deleted). Thrown from PropertyObserver::deleting(), the
 * single choke point every Eloquent delete() call passes through, so no
 * archive path — present or future — can bypass the guard. See
 * .ai/specs/leases.md "Archive guard" and Property::blockingActiveLease().
 */
class PropertyHasActiveLeaseException extends RuntimeException
{
    private Lease $lease;

    public function __construct(Lease $lease)
    {
        $this->lease = $lease;

        $tenant = $lease->tenantNames();
        $term = $lease->end_date
            ? 'ends ' . $lease->end_date->format('Y-m-d')
            : ($lease->is_month_to_month ? 'month-to-month, no fixed end date' : 'no end date set');

        parent::__construct(
            "This property has an active lease — {$tenant}, {$term}. "
            . 'End or cancel the lease before archiving.'
        );
    }

    public function lease(): Lease
    {
        return $this->lease;
    }
}
