<?php

declare(strict_types=1);

namespace App\Events\Website;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * A listing's website presence changed and websites should be notified.
 *
 *   action = 'published' | 'removed'  → targets the specific website (apiKeyId)
 *                                        whose toggle/bulk-activate fired.
 *   action = 'updated'                → apiKeyId null; the listener fans out to
 *                                        every website the listing is currently
 *                                        enabled on.
 *
 * Drives the agency-website webhooks. Spec: .ai/specs/agency-public-api.md §6.
 */
class ListingSyndicationChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly string $action,
        public readonly ?int $agencyApiKeyId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->property->agency_id ? (int) $this->property->agency_id : null;
    }

    public function subject(): ?array
    {
        return [Property::class, $this->property->id];
    }

    /** Map the internal action to the public webhook event name. */
    public function webhookEvent(): string
    {
        return match ($this->action) {
            'published' => 'listing.published',
            'removed'   => 'listing.removed',
            default     => 'listing.updated',
        };
    }
}
