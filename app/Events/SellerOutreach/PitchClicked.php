<?php

declare(strict_types=1);

namespace App\Events\SellerOutreach;

use App\Events\AbstractDomainEvent;
use App\Models\SellerOutreach\SellerOutreachClick;
use App\Models\SellerOutreach\SellerOutreachSend;

/**
 * Fires when a seller clicks the public landing-page link.
 *
 * actorUserId is null because the seller is not a CoreX user.
 */
final class PitchClicked extends AbstractDomainEvent
{
    public function __construct(
        public readonly SellerOutreachClick $click,
        public readonly SellerOutreachSend $send,
        public readonly int $agencyId,
        public readonly bool $isFirstClick,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function actorUserId(): ?int
    {
        return null;
    }

    public function subject(): ?array
    {
        return [SellerOutreachSend::class, $this->send->id];
    }

    public function context(): array
    {
        return [
            'click_id' => $this->click->id,
            'is_first_click' => $this->isFirstClick,
            'tracking_short_code' => $this->send->tracking_short_code,
            'ip_address' => $this->click->ip_address,
            'user_agent_summary' => $this->click->user_agent ? substr((string) $this->click->user_agent, 0, 100) : null,
            'contact_id' => $this->send->contact_id,
            'property_id' => $this->send->property_id,
        ];
    }
}
