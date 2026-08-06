<?php

namespace App\Services\Performance;

/**
 * AT-366 — what slice of the org the report is being run for:
 * company (agency only), a branch, or a single agent.
 */
class PerformanceScope
{
    public function __construct(
        public readonly int $agencyId,
        public readonly ?int $branchId = null,
        public readonly ?int $userId = null,
    ) {}

    public function level(): string
    {
        return $this->userId !== null ? 'user' : ($this->branchId !== null ? 'branch' : 'company');
    }
}
