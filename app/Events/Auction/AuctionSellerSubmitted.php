<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionSellerSubmission;

/** A seller completed the "Sell at auction" questionnaire on the public auction site (.ai/specs/auctions.md §22). */
final class AuctionSellerSubmitted extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionSellerSubmission $submission, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->submission->agency_id ?? null; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [AuctionSellerSubmission::class, $this->submission->id]; }
    public function context(): array
    {
        return ['property_type' => $this->submission->property_type, 'suburb' => $this->submission->suburb, 'auction_format' => $this->submission->auction_format, 'contact_id' => $this->submission->contact_id, 'tracked_property_id' => $this->submission->tracked_property_id];
    }
}
