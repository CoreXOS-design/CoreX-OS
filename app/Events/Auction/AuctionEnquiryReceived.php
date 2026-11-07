<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionEnquiry;

/** A buyer (or guest) sent an enquiry from the public auction site. */
final class AuctionEnquiryReceived extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionEnquiry $enquiry, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->enquiry->agency_id ?? null; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [AuctionEnquiry::class, $this->enquiry->id]; }

    public function context(): array
    {
        return [
            'auction_id' => $this->enquiry->auction_id,
            'lot_id' => $this->enquiry->lot_id,
            'property_id' => $this->enquiry->property_id,
            'contact_id' => $this->enquiry->contact_id,
            'has_account' => $this->enquiry->client_user_id !== null,
        ];
    }
}
