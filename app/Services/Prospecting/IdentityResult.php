<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedProperty;

/**
 * What PropertyIdentityResolver::resolve() decided (.ai/specs/property-identity.md §5).
 *
 * confidence: exact | high | probable | weak for a link; 'none' for a property file that was created (no link) or
 * not found (find-only). Candidates that were NOT linked are in $possible — recorded for an agent to confirm.
 */
final class IdentityResult
{
    /**
     * @param  list<array{id:int, confidence:string, method:string, reason:string, score:?int}>  $possible
     */
    public function __construct(
        public readonly ?TrackedProperty $trackedProperty,
        public readonly bool $created,
        public readonly string $confidence,
        public readonly ?string $method,
        public readonly array $possible = [],
        public readonly ?string $canonicalRef = null,
        public readonly ?string $strategy = null,
    ) {
    }

    public function linked(): bool
    {
        return $this->trackedProperty !== null && ! $this->created;
    }

    public function trackedPropertyId(): ?int
    {
        return $this->trackedProperty?->id;
    }
}
