<?php

namespace App\Services\Rentals;

/**
 * .ai/specs/rental-work-orders.md §17.7.2 — a decision on a variation must carry the revision the owner saw; the
 * request changed in between (more extra work joined it), so the decision is refused (HTTP 409) and the owner is
 * asked to look at the latest version.
 */
class StaleVariationRevision extends \RuntimeException
{
}
