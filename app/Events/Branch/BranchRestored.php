<?php

declare(strict_types=1);

namespace App\Events\Branch;

use App\Events\AbstractDomainEvent;
use App\Models\Branch;

/**
 * An archived branch was restored to active. No agents move on restore —
 * they stay wherever the archive wizard put them.
 *
 * Spec: .ai/specs/branch-archive-reassignment.md §7.5, §8 (AT-420)
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5
 */
final class BranchRestored extends AbstractDomainEvent
{
    public function __construct(
        public readonly Branch $branch,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->branch->agency_id ? (int) $this->branch->agency_id : null;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [Branch::class, $this->branch->id];
    }

    public function context(): array
    {
        return ['branch_name' => $this->branch->name];
    }
}
