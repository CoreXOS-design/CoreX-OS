<?php

declare(strict_types=1);

namespace App\Events\Demo;

use App\Events\AbstractDomainEvent;
use App\Models\DemoAccessGrant;
use App\Models\DemoTncVersion;

/**
 * A prospect accepted a specific, immutable version of the demo T&C.
 *
 * Spec: .ai/specs/demo-access-control.md §7
 *
 * Fired only on a NEWLY created acceptance row (firstOrCreate → wasRecentlyCreated),
 * so a double-click does not double-log.
 *
 * The version is on the payload because "they accepted the T&C" is not a fact —
 * "they accepted v1 of the T&C, whose text is frozen" is.
 */
class DemoTncAccepted extends AbstractDomainEvent
{
    public function __construct(
        public readonly DemoAccessGrant $grant,
        public readonly DemoTncVersion $version,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function subject(): ?array
    {
        return [DemoAccessGrant::class, $this->grant->getKey()];
    }

    public function context(): array
    {
        return [
            'company_name' => $this->grant->company_name,
            'tnc_version'  => $this->version->version,
        ];
    }
}
