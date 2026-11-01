<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Support\Facades\Auth;

/** The new public home page and the always-at-the-bottom footer (.ai/specs/auctions.md §21). */
final class AuctionHomePageTest extends AuctionsV2TestCase
{
    private function home(): string
    {
        return '/auctions/' . $this->agency->slug;
    }

    private function asGuest(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
    }

    private function published(array $extra = [])
    {
        return $this->auction(array_merge(['status' => 'published', 'published_at' => now(), 'format' => 'timed', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()], $extra));
    }

    public function test_the_footer_is_pushed_to_the_bottom_on_every_page_however_short(): void
    {
        $this->asGuest();
        $html = $this->get($this->home())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/body \{[^}]*min-height:100vh;[^}]*display:flex;[^}]*flex-direction:column/', $html);
        $this->assertMatchesRegularExpression('/main \{[^}]*flex:1 0 auto/', $html);
        $this->assertMatchesRegularExpression('/footer \{[^}]*margin-top:auto/', $html);
        $this->assertStringContainsString('&copy; ' . date('Y') . ' Cape Auctions', str_replace('© ', '&copy; ', $html) );
        $this->get($this->home() . '/signup')->assertOk()->assertSee('margin-top:auto', false);
    }

    public function test_the_home_page_has_the_hero_the_buttons_and_the_open_auction_counts(): void
    {
        $this->published(['title' => 'Running Now']);
        $this->published(['title' => 'Coming Soon', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $this->asGuest();

        $html = $this->get($this->home())->assertOk()->getContent();
        $this->assertStringContainsString('data-hero', $html);
        $this->assertStringContainsString('An auction is live now', $html);
        $this->assertStringContainsString('Sign up to bid', $html);
        $this->assertStringContainsString('2 auctions open', $html);
        $this->assertStringContainsString('data-live-auctions', $html);
        $this->assertStringContainsString('data-coming-auctions', $html);
        $this->assertStringNotContainsString('Want to bid?', $html, 'the old grey sign-up box is gone');
        $this->assertMatchesRegularExpression('/Live now.*Running Now.*Coming up.*Coming Soon/s', $html);
    }

    public function test_a_signed_in_buyer_gets_my_account_instead_of_sign_up(): void
    {
        $this->published();
        $this->asGuest();
        $buyer = \App\Models\ClientUser::create(['email' => 'me@example.com', 'password' => 'a-strong-pass-1', 'activated_at' => now()]);
        Auth::guard('client-web')->login($buyer);

        $this->get($this->home())->assertOk()->assertSee('My account')->assertDontSee('Sign up to bid');
    }

    public function test_an_auction_card_uses_the_cover_picture_else_the_first_lots_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('properties/1/photo-a.jpg', 'x');
        $withCover = $this->published(['title' => 'Has Cover', 'cover_image_path' => 'auction-pages/1/cover-x.jpg']);
        $withPhoto = $this->published(['title' => 'Has Photo']);
        $property = $this->property('Photo House', ['gallery_images_json' => ['/storage/properties/1/photo-a.jpg']]);
        app(AuctionLotService::class)->saveForProperty($property, ['auction_id' => $withPhoto->id, 'opening_bid' => 100000], $this->admin)->forceFill(['status' => 'published'])->save();
        $this->published(['title' => 'Has Nothing']);
        $this->asGuest();

        $html = $this->get($this->home())->assertOk()->getContent();
        $this->assertStringContainsString('storage/auction-pages/1/cover-x.jpg', $html);
        $this->assertStringContainsString('storage/properties/1/photo-a.jpg', $html);
        $this->assertSame(3, substr_count($html, 'data-auction-card'));
    }

    public function test_how_bidding_works_shows_neutral_steps_unless_the_agency_wrote_its_own(): void
    {
        $this->published();
        $this->asGuest();
        $this->get($this->home())->assertOk()->assertSee('data-how-steps', false)->assertSee('Create a free account')->assertDontSee('data-how-it-works', false);

        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'how_it_works' => 'Phone us first, then bid.']);
        $this->get($this->home())->assertOk()->assertSee('data-how-it-works', false)->assertSee('Phone us first, then bid.')->assertDontSee('data-how-steps', false);
    }

    public function test_with_no_auctions_open_the_page_says_so_and_a_banner_picture_is_used_in_the_hero(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'hero_image_path' => 'auction-pages/1/hero-x.jpg']);
        $this->asGuest();

        $html = $this->get($this->home())->assertOk()->assertSee('data-no-auctions', false)->assertSee('There are no auctions open right now')->getContent();
        $this->assertStringContainsString('storage/auction-pages/1/hero-x.jpg', $html);
    }

    public function test_past_auctions_show_when_the_agency_wants_them_and_a_draft_is_never_public(): void
    {
        $this->published(['title' => 'Old One', 'status' => 'completed', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(8)]);
        $this->published(['title' => 'Secret Draft', 'status' => 'draft']);
        $this->asGuest();

        $this->get($this->home())->assertOk()->assertSee('Past auctions')->assertSee('Old One')->assertDontSee('Secret Draft');

        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'show_past_auctions' => false]);
        $this->get($this->home())->assertDontSee('Old One');
    }
}
