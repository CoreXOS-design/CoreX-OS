<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Events\Auction\AuctionEnquiryReceived;
use App\Events\Auction\BidderRegistered;
use App\Mail\Auctions\AuctionEnquiryMail;
use App\Mail\Auctions\AuctionRegistrationMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionEnquiry;
use App\Models\ClientAccessLog;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\User;
use App\Services\Auctions\AuctionNavCounts;
use App\Services\Auctions\BidEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Signed up tab, registration / enquiry emails, sidebar numbers and in-app alerts (.ai/specs/auctions.md §19). */
final class AuctionActivityTest extends BiddingTestCase
{
    private function enquiry(array $extra = []): AuctionEnquiry
    {
        return AuctionEnquiry::withoutAgencyStamping(fn () => AuctionEnquiry::create(array_merge([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auc->id, 'lot_id' => $this->lot->id, 'property_id' => $this->lot->property_id,
            'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'status' => 'new', 'assigned_user_id' => $this->admin->id,
        ], $extra)));
    }

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'email' => 'agent@example.com']);
    }

    /** A bid typed in at the sale room (the engine only takes these at a live auction, so the row is made directly). */
    private function floorBid(float $amount): \App\Models\AuctionBid
    {
        return \App\Models\AuctionBid::withoutAgencyStamping(fn () => \App\Models\AuctionBid::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auc->id, 'lot_id' => $this->lot->id,
            'registration_id' => $this->registration($this->buyer('floor@example.com'))->id, 'amount' => $amount, 'placed_at' => now(), 'source' => 'floor', 'status' => 'valid',
        ]));
    }

    private function alerts(User $u, string $key): int
    {
        return $u->notifications()->get()->filter(fn ($n) => ($n->data['event_key'] ?? null) === $key)->count();
    }

    // ── emails ─────────────────────────────────────────────────────────────

    public function test_a_registration_emails_one_mailbox_unless_the_agency_chose_addresses(): void
    {
        User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin', 'email' => 'second-admin@example.com']);
        $reg = $this->registration($this->buyer('reg@example.com'), 'pending');

        // nothing set anywhere: every administrator (so nothing is lost)
        Mail::fake();
        BidderRegistered::dispatch($reg);
        Mail::assertSent(AuctionRegistrationMail::class, 2);

        // the agency has a main email: ONE email, not one per administrator
        $this->agency->forceFill(['email' => 'office-main@example.com'])->save();
        Mail::fake();
        BidderRegistered::dispatch($reg);
        Mail::assertSent(AuctionRegistrationMail::class, 1);
        Mail::assertSent(AuctionRegistrationMail::class, fn ($m) => $m->hasTo('office-main@example.com'));

        // an auction enquiries email beats the main email
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'contact_email' => 'auctions@example.com']);
        Mail::fake();
        BidderRegistered::dispatch($reg);
        Mail::assertSent(AuctionRegistrationMail::class, 1);
        Mail::assertSent(AuctionRegistrationMail::class, fn ($m) => $m->hasTo('auctions@example.com'));
        AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->forceDelete();

        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'notify_emails_to' => "office@example.com,\nBOSS@example.com"]);
        Mail::fake();
        BidderRegistered::dispatch($reg);
        Mail::assertSent(AuctionRegistrationMail::class, 2);
        Mail::assertSent(AuctionRegistrationMail::class, fn ($m) => $m->hasTo('office@example.com'));
        Mail::assertSent(AuctionRegistrationMail::class, fn ($m) => $m->hasTo('boss@example.com'));
        Mail::assertNotSent(AuctionRegistrationMail::class, fn ($m) => $m->hasTo($this->admin->email));
    }

    public function test_switching_the_emails_off_stops_both_the_registration_and_the_enquiry_email(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'notify_emails_enabled' => false]);
        Mail::fake();

        BidderRegistered::dispatch($this->registration($this->buyer('off@example.com'), 'pending'));
        AuctionEnquiryReceived::dispatch($this->enquiry());

        Mail::assertNothingSent();
    }

    public function test_an_enquiry_emails_the_chosen_addresses_and_the_listing_agent_once_each(): void
    {
        $agent = $this->agent();
        $this->lot->property->forceFill(['agent_id' => $agent->id])->save();
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'notify_emails_to' => 'office@example.com, ' . $agent->email]);
        Mail::fake();

        AuctionEnquiryReceived::dispatch($this->enquiry(['assigned_user_id' => $agent->id]));

        Mail::assertSent(AuctionEnquiryMail::class, 2);   // office + the agent (listed once, not twice)
        Mail::assertSent(AuctionEnquiryMail::class, fn ($m) => $m->hasTo('office@example.com'));
        Mail::assertSent(AuctionEnquiryMail::class, fn ($m) => $m->hasTo($agent->email));
    }

    // ── settings ───────────────────────────────────────────────────────────

    public function test_the_settings_screen_saves_the_switch_and_the_addresses_and_refuses_a_bad_address(): void
    {
        $this->get(route('corex.settings.auctions.show'))->assertOk()->assertSee('Send them to')->assertSee('data-notify-emails', false);

        $this->post(route('corex.settings.auctions.update'), ['notify_emails_enabled' => '1', 'notify_emails_to' => 'good@example.com, not-an-email'])
            ->assertSessionHasErrors('notify_emails_to');

        $this->post(route('corex.settings.auctions.update'), ['notify_emails_enabled' => '0', 'notify_emails_to' => "a@example.com\nb@example.com"])->assertSessionHasNoErrors();
        $this->assertFalse(AgencyAuctionSettings::notifyEmailsEnabledFor($this->agency->id));
        $this->assertSame(['a@example.com', 'b@example.com'], AgencyAuctionSettings::notifyEmailAddressesFor($this->agency->id));
    }

    public function test_the_wizard_saver_writes_only_the_email_settings_it_was_given(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'notify_emails_enabled' => false, 'notify_emails_to' => 'keep@example.com', 'soft_close_minutes' => 9]);

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['soft_close_minutes' => 4]);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);

        $this->assertFalse(AgencyAuctionSettings::notifyEmailsEnabledFor($this->agency->id), 'a step that never showed the switch must not wipe it');
        $this->assertSame('keep@example.com', AgencyAuctionSettings::notifyEmailsToFor($this->agency->id));
        $this->assertSame(4, AgencyAuctionSettings::softCloseMinutesFor($this->agency->id));

        $both = \Illuminate\Http\Request::create('/x', 'POST', ['notify_emails_enabled' => '1', 'notify_emails_to' => 'new@example.com']);
        $both->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($both);
        $this->assertTrue(AgencyAuctionSettings::notifyEmailsEnabledFor($this->agency->id));
        $this->assertSame(['new@example.com'], AgencyAuctionSettings::notifyEmailAddressesFor($this->agency->id));
    }

    // ── sidebar numbers ────────────────────────────────────────────────────

    public function test_the_enquiries_number_counts_new_enquiries_and_goes_down_when_one_is_dealt_with(): void
    {
        $a = $this->enquiry();
        $this->enquiry(['name' => 'Second', 'email' => 'second@example.com']);
        $this->enquiry(['name' => 'Done', 'email' => 'done@example.com', 'status' => 'contacted']);

        $this->assertSame(2, app(AuctionNavCounts::class)->enquiries($this->admin));
        $a->update(['status' => 'contacted']);
        $this->assertSame(1, (new AuctionNavCounts)->enquiries($this->admin));
    }

    public function test_the_bids_number_counts_new_online_bids_and_clears_when_the_bids_screen_is_opened(): void
    {
        $nav = fn () => (new AuctionNavCounts)->bids($this->admin);
        $this->assertSame(0, $nav(), 'first time: starts from now, not from the start of history');

        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        DB::table('auction_screen_visits')->where('user_id', $this->admin->id)->update(['seen_at' => now()->subMinute()]);

        $engine = app(BidEngine::class);
        $engine->place($this->lot, $a, 100000);
        $engine->place($this->lot->fresh(), $b, 105000);
        $this->floorBid(110000);
        $this->assertSame(2, $nav(), 'two online bids; the floor bid typed in by staff is not counted');

        $this->get(route('corex.auctions.bids.index'))->assertOk();
        $this->assertSame(0, $nav());
    }

    public function test_the_numbers_show_on_the_sidebar_links_and_only_when_above_zero(): void
    {
        $this->get(route('corex.auctions.index'))->assertOk()->assertDontSee('data-auction-count', false);

        $this->enquiry();
        $this->enquiry(['name' => 'Two', 'email' => 'two@example.com']);
        $html = $this->get(route('corex.auctions.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-auction-count="auctionenquiries"', $html);
        $this->assertStringContainsString('data-auction-count="total"', $html);
        $this->assertDoesNotMatchRegularExpression('/data-auction-count="auctionbids"/', $html);
    }

    public function test_the_numbers_respect_what_the_person_may_see(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = $this->agent();
        $this->enquiry(['assigned_user_id' => $this->admin->id]);
        $mine = $this->enquiry(['name' => 'Mine', 'email' => 'mine@example.com', 'assigned_user_id' => $agent->id, 'property_id' => null]);

        $this->assertSame(2, (new AuctionNavCounts)->enquiries($this->admin));
        $this->assertSame(AuctionEnquiry::visibleTo($agent)->where('status', 'new')->count(), (new AuctionNavCounts)->enquiries($agent));
        $this->assertContains($mine->id, AuctionEnquiry::visibleTo($agent)->pluck('id')->all());
    }

    // ── alerts ─────────────────────────────────────────────────────────────

    public function test_an_online_bid_alerts_the_listing_agent_only_and_floor_and_auto_bids_alert_nobody(): void
    {
        $agent = $this->agent();
        $this->lot->property->forceFill(['agent_id' => $agent->id])->save();
        $a = $this->registration($this->buyer('a@example.com'));
        $engine = app(BidEngine::class);

        $engine->place($this->lot, $a, 100000);
        $this->assertSame(1, $this->alerts($agent, 'auction.bid_placed'));
        $this->assertSame(0, $this->alerts($this->admin, 'auction.bid_placed'), 'the lot has an agent, so the admins are not also alerted');
        $note = $agent->notifications()->first()->data;
        $this->assertStringContainsString('R 100,000', $note['title']);
        $this->assertStringContainsString('lot_id=' . $this->lot->id, $note['action_url']);

        \App\Events\Auction\BidPlaced::dispatch($this->floorBid(105000));
        $this->assertSame(1, $this->alerts($agent, 'auction.bid_placed'), 'a floor bid typed in by staff does not alert');
    }

    public function test_a_bid_on_a_lot_whose_agent_has_left_alerts_the_administrators(): void
    {
        $gone = $this->agent();
        $gone->forceFill(['is_active' => false])->save();
        $this->lot->property->forceFill(['agent_id' => $gone->id])->save();
        app(BidEngine::class)->place($this->lot, $this->registration($this->buyer('a@example.com')), 100000);

        $this->assertSame(1, $this->alerts($this->admin, 'auction.bid_placed'));
    }

    public function test_an_enquiry_alerts_the_assigned_agent_else_the_administrators(): void
    {
        $agent = $this->agent();
        AuctionEnquiryReceived::dispatch($this->enquiry(['assigned_user_id' => $agent->id]));
        $this->assertSame(1, $this->alerts($agent, 'auction.enquiry_received'));
        $this->assertSame(0, $this->alerts($this->admin, 'auction.enquiry_received'));

        AuctionEnquiryReceived::dispatch($this->enquiry(['name' => 'Nobody owns me', 'email' => 'n@example.com', 'assigned_user_id' => null]));
        $this->assertSame(1, $this->alerts($this->admin, 'auction.enquiry_received'));
    }

    public function test_another_agencys_administrators_are_never_alerted(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-' . uniqid()]);
        $other = User::withoutAgencyStamping(fn () => User::factory()->create(['agency_id' => $rival->id, 'role' => 'admin']));

        AuctionEnquiryReceived::dispatch($this->enquiry(['assigned_user_id' => null]));

        $this->assertSame(0, $this->alerts($other, 'auction.enquiry_received'));
    }

    // ── Signed up ──────────────────────────────────────────────────────────

    private function signUp(string $email, string $name, ?Agency $agency = null, string $event = 'auction_signup'): ClientUser
    {
        $agency ??= $this->agency;
        $user = ClientUser::firstOrCreate(['email' => $email], ['password' => 'a-strong-pass-1', 'activated_at' => now()]);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $contact = Contact::withoutAgencyStamping(fn () => Contact::create(['agency_id' => $agency->id, 'first_name' => $first, 'last_name' => $last, 'phone' => '082 111 2222', 'client_user_id' => $user->id]));
        ClientAccessLog::withoutAgencyStamping(fn () => ClientAccessLog::create(['client_user_id' => $user->id, 'agency_id' => $agency->id, 'contact_id' => $contact->id, 'event' => $event]));

        return $user;
    }

    public function test_the_signed_up_tab_lists_this_agencys_sign_ups_including_people_who_never_registered(): void
    {
        $registered = $this->signUp('thandi@example.com', 'Thandi Nkosi');
        $this->signUp('lerato@example.com', 'Lerato Mokoena');
        $this->registration($registered, 'pending');

        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-' . uniqid()]);
        $this->signUp('stranger@example.com', 'Stranger Danger', $rival);

        $this->get(route('corex.auctions.bidders.index'))->assertOk()->assertSee('data-bidder-view="signed_up"', false);
        $html = $this->get(route('corex.auctions.bidders.signed-up'))->assertOk()->getContent();
        $this->assertStringContainsString('Thandi Nkosi', $html);
        $this->assertStringContainsString('Lerato Mokoena', $html);
        $this->assertStringContainsString('1 auction', $html);
        $this->assertStringContainsString('Not registered yet', $html);
        $this->assertStringNotContainsString('Stranger Danger', $html, "another agency's sign-ups never appear");
    }

    public function test_the_signed_up_tab_searches_filters_sorts_and_shows_an_empty_state(): void
    {
        $this->get(route('corex.auctions.bidders.signed-up'))->assertOk()->assertSee('No one has signed up on your auction page yet');

        $t = $this->signUp('thandi@example.com', 'Thandi Nkosi');
        $this->signUp('lerato@example.com', 'Lerato Mokoena', null, 'auction_login');
        $this->registration($t, 'pending');
        ClientAccessLog::withoutGlobalScopes()->where('client_user_id', $t->id)->update(['created_at' => now()->subDays(10)]);

        $this->get(route('corex.auctions.bidders.signed-up', ['search' => 'lerato']))->assertSee('Lerato Mokoena')->assertDontSee('Thandi Nkosi');
        $this->get(route('corex.auctions.bidders.signed-up', ['search' => '082 111']))->assertSee('Lerato Mokoena')->assertSee('Thandi Nkosi');
        $this->get(route('corex.auctions.bidders.signed-up', ['registered' => 'yes']))->assertSee('Thandi Nkosi')->assertDontSee('Lerato Mokoena');
        $this->get(route('corex.auctions.bidders.signed-up', ['registered' => 'no']))->assertSee('Lerato Mokoena')->assertDontSee('Thandi Nkosi');
        $this->get(route('corex.auctions.bidders.signed-up', ['date_from' => now()->subDays(2)->toDateString()]))->assertSee('Lerato Mokoena')->assertDontSee('Thandi Nkosi');
        $this->get(route('corex.auctions.bidders.signed-up', ['search' => 'zzz']))->assertSee('No one matches these filters');

        $html = $this->get(route('corex.auctions.bidders.signed-up', ['sort' => 'contact_name', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($html, 'Thandi Nkosi'), strpos($html, 'Lerato Mokoena'), 'sorted by name, A to Z');
    }

    public function test_the_signed_up_tab_needs_the_agency_wide_bidders_permission(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = $this->agent();
        $this->actingAs($agent);

        $this->assertFalse($agent->hasPermission('auctions.bidders.view_all'));
        $this->get(route('corex.auctions.bidders.signed-up'))->assertForbidden();
        if ($agent->hasPermission('auctions.bidders.view')) {
            $this->get(route('corex.auctions.bidders.index'))->assertOk()->assertDontSee('data-bidder-view="signed_up"', false);
        }
    }
}
