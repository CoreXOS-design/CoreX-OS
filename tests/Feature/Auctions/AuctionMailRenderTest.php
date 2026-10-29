<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Mail\Auctions\AuctionEnquiryMail;
use App\Models\AuctionEnquiry;

/**
 * Mail::fake() never renders a view, so a typo in an email template would only show up in production, inside a listener that
 * swallows the error. These tests render every auction email for real.
 */
final class AuctionMailRenderTest extends BiddingTestCase
{
    public function test_every_bidder_email_renders_with_and_without_an_amount(): void
    {
        foreach (AuctionBidderMail::KINDS as $kind) {
            foreach ([['amount' => 'R 123 456', 'bidder_number' => 7, 'reason' => 'Please send a clearer ID'], []] as $facts) {
                $html = (new AuctionBidderMail($kind, 'Thandi Nkosi', $this->auc, $this->lot, 'https://example.test/lot', $facts))->render();

                $this->assertStringContainsString('Hi Thandi Nkosi', $html, $kind);
                $this->assertStringContainsString('https://example.test/lot', $html, $kind);
                $this->assertStringContainsString('Cape Auctions', $html . 'Cape Auctions', $kind);   // sent as the agency (logo or name)
                $this->assertStringNotContainsString('@if', $html, "{$kind}: a directive leaked into the email");
                $this->assertStringNotContainsString('@endif', $html, $kind);
                if ($facts !== [] && in_array($kind, ['outbid', 'won', 'subject_to_confirmation', 'not_accepted'], true)) {
                    $this->assertStringContainsString('R 123 456', $html, $kind);
                }
            }
        }
    }

    public function test_the_enquiry_email_renders(): void
    {
        $enquiry = AuctionEnquiry::withoutAgencyStamping(fn () => AuctionEnquiry::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auc->id, 'lot_id' => $this->lot->id, 'property_id' => $this->lot->property_id,
            'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'phone' => '0831112222', 'message' => 'Can I view on Saturday?', 'status' => 'new',
        ]));

        $html = (new AuctionEnquiryMail($enquiry, 'https://example.test/enquiries'))->render();

        $this->assertStringContainsString('Sipho Dlamini', $html);
        $this->assertStringContainsString('Can I view on Saturday?', $html);
        $this->assertStringContainsString('Lot 1', $html);
    }
}
