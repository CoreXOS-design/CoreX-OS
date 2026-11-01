<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Services\Auctions\BidEngine;
use App\Support\ColorContrast;
use Illuminate\Support\Facades\Auth;

/** The new public lot page: hero, facts, "Bidding activity", the agency's own colours (.ai/specs/auctions.md §20). */
final class AuctionLotPageTest extends BiddingTestCase
{
    private function lotUrl(): string
    {
        return '/auctions/' . $this->agency->slug . "/a/{$this->auc->id}/lots/{$this->lot->id}";
    }

    private function asGuest(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
    }

    private function bid(string $email, float $amount): void
    {
        app(BidEngine::class)->place($this->lot->fresh(), $this->registration($this->buyer($email)), $amount);
    }

    public function test_the_lot_page_has_the_hero_the_bid_card_and_the_agencys_own_colours(): void
    {
        $this->agency->forceFill(['default_color' => '#123456', 'button_color' => '#ffd400'])->save();
        $this->lot->property->forceFill(['beds' => 3, 'baths' => 2, 'garages' => 1, 'size_m2' => 112, 'suburb' => 'Pennington', 'description' => 'Near the beach.'])->save();
        $this->asGuest();

        $html = $this->get($this->lotUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-lot-hero', $html);
        $this->assertStringContainsString('Live · bidding open', $html);
        $this->assertStringContainsString('Bid Cottage', $html);
        $this->assertStringContainsString('Pennington', $html);
        $this->assertStringContainsString('Near the beach.', $html);
        $this->assertStringContainsString('data-facts', $html);
        $this->assertStringContainsString('112', $html);
        $this->assertStringContainsString('id="bidpanel"', $html);
        $this->assertStringContainsString('Manrope', $html);
        $this->assertStringContainsString('--brand: #123456', $html, "the agency's brand colour drives the page");
        $this->assertStringContainsString('--button: #ffd400', $html);
        $this->assertMatchesRegularExpression('/--on-button: #101828/', $html, 'dark text on a yellow button, not white');
        $this->assertDoesNotMatchRegularExpression('/hfc|home finders/i', strip_tags($html), 'nothing belongs to one agency');
    }

    public function test_property_figures_the_property_does_not_have_are_not_shown(): void
    {
        $this->lot->property->forceFill(['beds' => 0, 'baths' => 0, 'garages' => 0, 'size_m2' => 0])->save();
        $this->asGuest();

        $this->get($this->lotUrl())->assertOk()->assertDontSee('data-facts', false);
    }

    public function test_the_text_colour_on_a_button_stays_readable_whatever_colour_the_agency_picked(): void
    {
        $this->assertSame('#ffffff', ColorContrast::textOn('#0b2a4a'));
        $this->assertSame('#101828', ColorContrast::textOn('#00b4d8'));
        $this->assertSame('#101828', ColorContrast::textOn('#ffd400'));
        $this->assertSame('#ffffff', ColorContrast::textOn('#7a1f1f'));
        $this->assertSame('#101828', ColorContrast::textOn('#fff'));
        $this->assertSame('#ffffff', ColorContrast::textOn('not-a-colour'));
    }

    public function test_bidding_activity_lists_the_latest_bids_newest_first_with_bidder_numbers_and_no_names(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'show_bidder_numbers' => true]);
        $this->bid('first@example.com', 100000);
        $this->bid('second@example.com', 105000);
        $this->asGuest();

        $html = $this->get($this->lotUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Bidding activity', $html);
        $this->assertLessThan(strpos($html, 'R 100 000', strpos($html, 'Bidding activity')), strpos($html, 'R 105 000', strpos($html, 'Bidding activity')), 'highest first');
        $this->assertStringContainsString('Bidder #2', $html);
        $this->assertStringNotContainsString('first@example.com', $html);

        $feed = $this->getJson("/api/v1/auctions/public/lots/{$this->lot->id}/feed")->assertOk()->assertJsonPath('recent_bids.0.amount', 105000)->assertJsonPath('recent_bids.0.highest', true)->assertJsonPath('recent_bids.0.who', 2)->assertJsonPath('recent_bids.1.highest', false);
        $this->assertStringNotContainsString('@example.com', $feed->getContent());
    }

    public function test_when_the_agency_hides_bidder_numbers_the_activity_says_a_bidder(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'show_bidder_numbers' => false]);
        $this->bid('first@example.com', 100000);
        $this->asGuest();

        $this->get($this->lotUrl())->assertSee('A bidder')->assertDontSee('Bidder #1');
        $this->getJson("/api/v1/auctions/public/lots/{$this->lot->id}/feed")->assertJsonPath('recent_bids.0.who', null);
    }

    public function test_the_activity_marks_your_own_bids_and_is_gone_when_the_agency_switches_it_off(): void
    {
        $buyer = $this->buyer('me@example.com');
        $mine = $this->registration($buyer);
        app(BidEngine::class)->place($this->lot->fresh(), $mine, 100000);
        $this->asGuest();
        Auth::guard('client-web')->login($buyer);

        $this->getJson("/api/v1/auctions/public/lots/{$this->lot->id}/feed")->assertJsonPath('recent_bids.0.mine', true);

        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'show_bid_history' => false]);
        $this->get($this->lotUrl())->assertOk()->assertDontSee('data-activity', false)->assertDontSee('Bidding activity');
        $this->assertArrayNotHasKey('recent_bids', $this->getJson("/api/v1/auctions/public/lots/{$this->lot->id}/feed")->json());
    }

    public function test_an_empty_activity_list_invites_the_first_bid(): void
    {
        $this->asGuest();

        $this->get($this->lotUrl())->assertOk()->assertSee('No bids yet');
    }

    public function test_the_activity_switch_is_on_the_settings_screen_and_round_trips(): void
    {
        $this->get(route('corex.settings.auctions.show'))->assertOk()->assertSee('Bidding activity');
        $this->post(route('corex.settings.auctions.update'), ['show_bid_history' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(AgencyAuctionSettings::showBidHistoryFor($this->agency->id));
        $this->post(route('corex.settings.auctions.update'), ['show_bid_history' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(AgencyAuctionSettings::showBidHistoryFor($this->agency->id));
    }
}
