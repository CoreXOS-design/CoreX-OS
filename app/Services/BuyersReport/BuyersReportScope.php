<?php

namespace App\Services\BuyersReport;

/**
 * Buyers Report — what slice of the org this run is for.
 *
 * Deliberately NOT constructed directly from a request. Always built by
 * BuyersReportScopeResolver, which derives agencyId/branchId/userId from the
 * VIEWING user's own identity and permission ceiling — never trusts a
 * client-supplied branch/agent id beyond what that user is already allowed
 * to see. See BuyersReportScopeResolver's docblock for why.
 */
class BuyersReportScope
{
    public const LEVEL_OWN    = 'own';
    public const LEVEL_BRANCH = 'branch';
    public const LEVEL_AGENCY = 'agency';

    public function __construct(
        public readonly int $agencyId,
        public readonly string $level,
        public readonly ?int $branchId = null,
        public readonly ?int $userId = null,
    ) {}
}
