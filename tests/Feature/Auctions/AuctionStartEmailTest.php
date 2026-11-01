<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\ClientAccessLog;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Services\Auctions\AuctionStartNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/** "Live now" label and the start-of-auction email (.ai/specs/auctions.md §19.7). */
final class AuctionStartEmailTest extends AuctionsV2TestCase
{
    private function signUp(string $email, string $name, ?Agency $agency = null): ClientUser
    {
        $agency ??= $this->agency;
        $user = ClientUser::firstOrCreate(['email' => $email], ['password' => 'a-strong-pass-1', 'activated_at' => now()]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $contact = Contact::withoutAgencyStamping(fn () => Contact::create(['agency_id' => $agency->id, 'first_name' => $first, 'last_name' => $last, 'client_user_id' => $user->id]));
        ClientAccessLog::withoutAgencyStamping(fn () => ClientAccessLog::create(['client_user_id' => $user->id, 'agency_id' => $agency->id, 'contact_id' => $contact->id, 'event' => 'auction_signup']));

        return $user;
    }

    private function published(array $extra = []): Auction
    {
        return $this->auction(array_merge(['status' => 'published', 'published_at' => now(), 'format' => 'timed', 'starts_at' => now()->subMinutes(3), 'ends_at' => now()->addHours(5)], $extra));
    }

    // ── the label ──────────────────────────────────────────────────────────

    public function test_an_auction_that_has_started_says_live_now_even_while_its_stored_status_is_still_published(): void
    {
        $this->assertSame('live', $this->published()->publicPhase());
        $this->assertSame('Live now', $this->published()->publicPhaseLabel());
        $this->assertSame('upcoming', $this->published(['starts_at' => now()->addHour()])->publicPhase());
        $this->assertSame('finished', $this->published(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()])->publicPhase());
        $this->assertSame('finished', $this->published(['status' => 'completed'])->publicPhase());
        $this->assertSame('live', $this->published(['format' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()])->publicPhase(), 'a live auction in the room runs until it is completed');
    }

    public function test_the_public_home_page_shows_live_now_or_upcoming(): void
    {
        $this->published(['title' => 'Running Now']);
        $this->published(['title' => 'Coming Soon', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        Auth::guard('web')->forgetUser();
        $this->flushSession();

        $html = $this->get('/auctions/' . $this->agency->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-auction-phase="live">Live now<.*?Running Now/s', $html);
        $this->assertMatchesRegularExpression('/data-auction-phase="upcoming">Upcoming<.*?Coming Soon/s', $html);
    }

    // ── the email ──────────────────────────────────────────────────────────

    public function test_everyone_who_signed_up_is_emailed_once_when_the_auction_starts(): void
    {
        $this->signUp('thandi@example.com', 'Thandi Nkosi');
        $this->signUp('lerato@example.com', 'Lerato Mokoena');
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-' . uniqid()]);
        $this->signUp('stranger@example.com', 'Stranger Danger', $rival);
        $auction = $this->published(['title' => 'Spring Sale']);
        Mail::fake();

        $this->assertSame(2, app(AuctionStartNotifier::class)->run());
        Mail::assertQueued(AuctionBidderMail::class, 2);
        Mail::assertQueued(AuctionBidderMail::class, fn ($m) => $m->hasTo('thandi@example.com') && $m->kind === 'started' && $m->toName === 'Thandi Nkosi');
        Mail::assertNotQueued(AuctionBidderMail::class, fn ($m) => $m->hasTo('stranger@example.com'));
        $this->assertNotNull($auction->fresh()->start_notified_at);

        $html = (new AuctionBidderMail('started', 'Thandi', $auction, null, 'https://x.test', ['closes' => '1 Nov 2026, 23:00', 'lots' => 3]))->render();
        $this->assertStringContainsString('Spring Sale is now live', $html);
        $this->assertStringContainsString('signed up on Cape Auctions', $html);
        $this->assertDoesNotMatchRegularExpression('/core\s*x/i', strip_tags($html));
        $this->assertSame('Spring Sale is now live', (new AuctionBidderMail('started', 'T', $auction, null, 'u'))->envelope()->subject);

        // a second run (the job runs every minute) sends nothing more
        Mail::fake();
        $this->assertSame(0, app(AuctionStartNotifier::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_an_auction_that_has_not_started_sends_nothing(): void
    {
        $this->signUp('thandi@example.com', 'Thandi Nkosi');
        $auction = $this->published(['starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]);
        Mail::fake();

        $this->assertSame(0, app(AuctionStartNotifier::class)->run());
        Mail::assertNothingQueued();
        $this->assertNull($auction->fresh()->start_notified_at);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, app(AuctionStartNotifier::class)->run(), 'the minute it starts, it goes out');
        Carbon::setTestNow();
    }

    public function test_a_draft_or_cancelled_auction_sends_nothing(): void
    {
        $this->signUp('thandi@example.com', 'Thandi Nkosi');
        $this->published(['status' => 'draft']);
        $this->published(['status' => 'cancelled']);
        Mail::fake();

        $this->assertSame(0, app(AuctionStartNotifier::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_the_agency_can_switch_it_off_and_turning_it_on_later_does_not_mail_about_an_auction_already_running(): void
    {
        $this->signUp('thandi@example.com', 'Thandi Nkosi');
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'start_emails_enabled' => false]);
        $auction = $this->published();
        Mail::fake();

        $this->assertSame(0, app(AuctionStartNotifier::class)->run());
        $this->assertNotNull($auction->fresh()->start_notified_at, 'marked done, so it is never sent late');

        AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->update(['start_emails_enabled' => true]);
        $this->assertSame(0, app(AuctionStartNotifier::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_an_auction_that_started_long_ago_is_marked_done_without_mailing_anyone(): void
    {
        $this->signUp('thandi@example.com', 'Thandi Nkosi');
        $old = $this->published(['starts_at' => now()->subDays(3), 'ends_at' => now()->addDay()]);
        Mail::fake();

        $this->assertSame(0, app(AuctionStartNotifier::class)->run());
        Mail::assertNothingQueued();
        $this->assertNotNull($old->fresh()->start_notified_at);
    }

    public function test_the_settings_screen_and_wizard_carry_the_switch(): void
    {
        $this->get(route('corex.settings.auctions.show'))->assertOk()->assertSee('data-start-emails', false)->assertSee('Email everyone who signed up when an auction starts');
        $this->post(route('corex.settings.auctions.update'), ['start_emails_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(AgencyAuctionSettings::startEmailsEnabledFor($this->agency->id));

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['soft_close_minutes' => 4]);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);
        $this->assertFalse(AgencyAuctionSettings::startEmailsEnabledFor($this->agency->id), 'a wizard step that never showed the switch must not change it');
    }
}
