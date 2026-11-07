<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionEnquiryReceived;
use App\Models\Auction;
use App\Models\AuctionEnquiry;
use App\Models\AuctionLot;
use App\Models\ClientUser;
use App\Services\Buyers\BuyerLeadCascadeService;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Support\Facades\Log;

/**
 * An enquiry from the public auction site (.ai/specs/auctions.md §8.1). Match-or-creates the Contact, routes the enquiry
 * to the lot's listing agent, seeds the buyer pipeline and emits AuctionEnquiryReceived — all server-side; nothing the
 * visitor posts decides who it is routed to.
 */
class AuctionEnquiryService
{
    public function __construct(private AuctionContactResolver $contacts, private BuyerLeadCascadeService $cascade) {}

    /**
     * @param array{name: string, email?: ?string, phone?: ?string, message?: ?string} $data
     */
    public function capture(Auction $auction, ?AuctionLot $lot, array $data, ?ClientUser $clientUser = null): AuctionEnquiry
    {
        $agencyId = (int) $auction->agency_id;
        $name = trim((string) $data['name']);
        $email = $this->clean($data['email'] ?? null);
        $phone = $this->clean($data['phone'] ?? null);
        $message = $this->clean($data['message'] ?? null);

        $property = $lot?->property;
        $agentId = $property?->agent_id ? (int) $property->agent_id : null;

        // a double click / retry inside two minutes is the same enquiry
        $recent = AuctionEnquiry::queryWithoutAgencyScope()->where('agency_id', $agencyId)
            ->where('auction_id', $auction->id)->where('lot_id', $lot?->id)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->where(fn ($q) => $q->when($email, fn ($qq) => $qq->orWhere('email', $email))->when($phone, fn ($qq) => $qq->orWhere('phone', $phone)))
            ->first();
        if ($recent && ($email || $phone)) {
            return $recent;
        }

        [$contact] = $this->contacts->resolve($agencyId, $name, $email, $phone, $agentId, $clientUser?->id, $property?->branch_id ? (int) $property->branch_id : null);

        $enquiry = AuctionEnquiry::withoutAgencyStamping(fn () => AuctionEnquiry::create([
            'agency_id' => $agencyId,
            'auction_id' => $auction->id,
            'lot_id' => $lot?->id,
            'property_id' => $property?->id,
            'client_user_id' => $clientUser?->id,
            'contact_id' => $contact->id,
            'name' => mb_substr($name, 0, 190),
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'status' => AuctionEnquiry::STATUS_NEW,
            'assigned_user_id' => $agentId,
            'source' => 'public_site',
            'consent_popia_at' => now(),
        ]));

        if ($property) {
            try {
                ContactPropertyLinker::link($contact->id, $property->id, 'lead');
                if ($agentId) {
                    $this->cascade->seedFromListing($contact, $property, $agentId, BuyerLeadCascadeService::SOURCE_PORTAL_WEBSITE, $message);
                }
            } catch (\Throwable $e) {
                Log::warning('Auction enquiry contact/buyer seeding failed: ' . $e->getMessage(), ['enquiry' => $enquiry->id]);
            }
        }

        AuctionEnquiryReceived::dispatch($enquiry);

        return $enquiry;
    }

    private function clean(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
