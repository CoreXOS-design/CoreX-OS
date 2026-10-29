<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionRegistration;
use App\Models\AuctionWatch;
use App\Models\BidderDocument;
use App\Models\ClientUser;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\BidEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** The buyer's journey on the public site: register, get approved, bid (.ai/specs/auctions.md §8.3-8.5). */
final class RegistrationAndBiddingFlowTest extends BiddingTestCase
{
    private function base(): string
    {
        return '/auctions/' . $this->agency->slug;
    }

    private function signIn(string $email = 'buyer@example.com'): ClientUser
    {
        Auth::guard('web')->forgetUser();
        $user = $this->buyer($email);
        $this->flushSession();
        // log the buyer into the portal session guard WITHOUT changing the default guard (actingAs(..., 'client-web') would)
        Auth::guard('client-web')->login($user);

        return $user;
    }

    private function registerUrl(): string
    {
        return "{$this->base()}/a/{$this->auc->id}/register";
    }

    private function pdf(string $name = 'id.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    // ── registering ────────────────────────────────────────────────────────

    public function test_registering_needs_an_account(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();

        $this->get($this->registerUrl())->assertRedirect();
        $this->post($this->registerUrl(), [])->assertForbidden();
    }

    public function test_an_individual_registers_with_the_required_documents_and_is_pending(): void
    {
        Storage::fake('local');
        Mail::fake();
        $user = $this->signIn();

        $this->get($this->registerUrl())->assertOk()->assertSee('Register to bid')->assertSee('ID or passport copy')->assertSee('Proof of address');
        $this->post($this->registerUrl(), [
            'bidder_type' => 'individual', 'accept_conditions' => '1',
            'documents' => ['id_or_passport' => $this->pdf('id.pdf'), 'proof_of_address' => $this->pdf('address.pdf')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $reg = AuctionRegistration::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('pending', $reg->status);
        $this->assertNull($reg->bidder_number);
        $this->assertSame($user->id, (int) $reg->client_user_id);
        $this->assertNotNull($reg->accepted_conditions_at);
        $this->assertSame(2, $reg->documents()->count());
        $this->assertSame(2, BidderDocument::withoutGlobalScopes()->where('client_user_id', $user->id)->count());
        Storage::disk('local')->assertExists(BidderDocument::withoutGlobalScopes()->first()->path);
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\BidderRegistered']);
    }

    public function test_a_missing_document_the_conditions_or_company_details_are_refused(): void
    {
        Storage::fake('local');
        $this->signIn();

        $this->post($this->registerUrl(), ['bidder_type' => 'individual', 'accept_conditions' => '1', 'documents' => ['id_or_passport' => $this->pdf()]])
            ->assertSessionHasErrors('documents.proof_of_address');
        $this->post($this->registerUrl(), ['bidder_type' => 'individual', 'documents' => ['id_or_passport' => $this->pdf(), 'proof_of_address' => $this->pdf()]])
            ->assertSessionHasErrors('accept_conditions');
        $this->post($this->registerUrl(), ['bidder_type' => 'company', 'accept_conditions' => '1', 'documents' => ['id_or_passport' => $this->pdf(), 'proof_of_address' => $this->pdf(), 'company_resolution' => $this->pdf()]])
            ->assertSessionHasErrors('company_name');
        $this->post($this->registerUrl(), ['bidder_type' => 'individual', 'accept_conditions' => '1', 'documents' => ['id_or_passport' => UploadedFile::fake()->create('virus.exe', 5), 'proof_of_address' => $this->pdf()]])
            ->assertSessionHasErrors('documents.id_or_passport');

        $this->assertSame(0, AuctionRegistration::withoutGlobalScopes()->count());
    }

    public function test_a_company_registers_with_the_company_documents(): void
    {
        Storage::fake('local');
        $this->signIn();

        $this->post($this->registerUrl(), [
            'bidder_type' => 'company', 'company_name' => 'Surf Holdings (Pty) Ltd', 'company_reg_no' => '2020/123456/07', 'accept_conditions' => '1',
            'documents' => ['id_or_passport' => $this->pdf(), 'proof_of_address' => $this->pdf(), 'company_resolution' => $this->pdf('res.pdf')],
        ])->assertSessionHasNoErrors();

        $reg = AuctionRegistration::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('company', $reg->bidder_type);
        $this->assertSame('Surf Holdings (Pty) Ltd', $reg->displayName());
        $this->assertSame(3, $reg->documents()->count());
    }

    public function test_use_existing_reuses_a_document_already_on_file_with_this_agency(): void
    {
        Storage::fake('local');
        $user = $this->signIn();
        $this->post($this->registerUrl(), ['bidder_type' => 'individual', 'accept_conditions' => '1', 'documents' => ['id_or_passport' => $this->pdf(), 'proof_of_address' => $this->pdf()]]);

        $second = $this->auction(['reference' => 'AUC-T-2', 'status' => 'published', 'starts_at' => now()->addDays(3)]);
        $onFile = BidderDocument::withoutGlobalScopes()->where('kind', 'id_or_passport')->firstOrFail();
        $addr = BidderDocument::withoutGlobalScopes()->where('kind', 'proof_of_address')->firstOrFail();

        $this->get("{$this->base()}/a/{$second->id}/register")->assertOk()->assertSee('Use the one on file');
        $this->post("{$this->base()}/a/{$second->id}/register", [
            'bidder_type' => 'individual', 'accept_conditions' => '1', 'existing' => ['id_or_passport' => $onFile->id, 'proof_of_address' => $addr->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, BidderDocument::withoutGlobalScopes()->where('client_user_id', $user->id)->count(), 'nothing was uploaded twice');
        $reg = AuctionRegistration::withoutGlobalScopes()->where('auction_id', $second->id)->firstOrFail();
        $this->assertEqualsCanonicalizing([$onFile->id, $addr->id], $reg->documents()->pluck('bidder_document_id')->all());
    }

    public function test_a_document_on_file_with_another_agency_or_another_person_cannot_be_reused(): void
    {
        Storage::fake('local');
        $mine = $this->signIn('mine@example.com');
        $other = $this->buyer('other@example.com');
        $stolen = BidderDocument::withoutAgencyStamping(fn () => BidderDocument::create(['agency_id' => $this->agency->id, 'client_user_id' => $other->id, 'kind' => 'id_or_passport', 'original_name' => 'theirs.pdf', 'path' => 'x/theirs.pdf']));

        $this->post($this->registerUrl(), ['bidder_type' => 'individual', 'accept_conditions' => '1', 'existing' => ['id_or_passport' => $stolen->id], 'documents' => ['proof_of_address' => $this->pdf()]])
            ->assertSessionHasErrors('documents.id_or_passport');
        $this->assertSame(0, AuctionRegistration::withoutGlobalScopes()->where('client_user_id', $mine->id)->count());
    }

    public function test_registration_closes_at_the_deadline_and_not_for_a_cancelled_auction(): void
    {
        Storage::fake('local');
        $this->signIn();
        $body = ['bidder_type' => 'individual', 'accept_conditions' => '1', 'documents' => ['id_or_passport' => $this->pdf(), 'proof_of_address' => $this->pdf()]];

        $this->auc->forceFill(['registration_closes_at' => now()->subMinute()])->save();
        $this->post($this->registerUrl(), $body)->assertSessionHasErrors('registration');
        $this->get($this->registerUrl())->assertOk()->assertSee('Registration for this auction is closed');

        $this->auc->forceFill(['registration_closes_at' => null, 'starts_at' => now()->addHours(5)])->save();   // default: 24h before the start
        $this->post($this->registerUrl(), $body)->assertSessionHasErrors('registration');

        $this->auc->forceFill(['starts_at' => now()->addDays(3), 'status' => 'cancelled'])->save();
        $this->get($this->registerUrl())->assertNotFound();
    }

    public function test_a_declined_buyer_can_fix_and_register_again_but_an_approved_one_cannot_re_register(): void
    {
        Storage::fake('local');
        $this->signIn();
        $reg = $this->registration($this->buyer(), 'declined');
        $reg->forceFill(['declined_reason' => 'Your ID is unreadable'])->save();
        $body = ['bidder_type' => 'individual', 'accept_conditions' => '1', 'documents' => ['id_or_passport' => $this->pdf(), 'proof_of_address' => $this->pdf()]];

        $this->get($this->registerUrl())->assertOk()->assertSee('Your ID is unreadable');
        $this->post($this->registerUrl(), $body)->assertSessionHasNoErrors();
        $this->assertSame('pending', $reg->fresh()->status);
        $this->assertSame(1, AuctionRegistration::withoutGlobalScopes()->count(), 'the same registration was reopened, not duplicated');
        $this->assertNull($reg->fresh()->declined_reason);

        $reg->forceFill(['status' => 'approved', 'bidder_number' => 1])->save();
        $this->post($this->registerUrl(), $body)->assertSessionHasErrors('registration');
    }

    // ── the lot page ───────────────────────────────────────────────────────

    private function lotUrl(): string
    {
        return "{$this->base()}/a/{$this->auc->id}/lots/{$this->lot->id}";
    }

    public function test_the_lot_page_shows_the_right_call_to_action_for_each_kind_of_visitor(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
        $this->get($this->lotUrl())->assertOk()->assertSee('Login to bid')->assertDontSee('id="bid-form"', false);

        $user = $this->signIn();
        $this->get($this->lotUrl())->assertSee('Register to bid');

        $reg = $this->registration($user, 'pending');
        $this->get($this->lotUrl())->assertSee('waiting for approval')->assertDontSee('id="bid-form"', false);

        $reg->forceFill(['status' => 'declined', 'declined_reason' => 'Blurry ID'])->save();
        $this->get($this->lotUrl())->assertSee('not approved')->assertSee('Blurry ID');

        $reg->forceFill(['status' => 'approved', 'bidder_number' => 7])->save();
        $this->get($this->lotUrl())->assertSee('id="bid-form"', false)->assertSee('#7')->assertSee('Place bid');
    }

    public function test_a_live_auctions_lot_page_says_bids_are_taken_in_the_room(): void
    {
        $this->auc->forceFill(['format' => 'live', 'venue_name' => 'Town Hall'])->save();
        $this->signIn();

        $this->get($this->lotUrl())->assertOk()->assertSee('Bids are taken in the room')->assertDontSee('id="bid-form"', false);
    }

    // ── the bidding API ────────────────────────────────────────────────────

    private function api(string $suffix = ''): string
    {
        return "/api/v1/auctions/public/lots/{$this->lot->id}{$suffix}";
    }

    public function test_the_feed_is_open_to_anyone_and_carries_no_personal_data(): void
    {
        $reg = $this->registration($this->buyer('private@example.com'));
        app(BidEngine::class)->place($this->lot, $reg, 100000);
        Auth::guard('web')->forgetUser();
        $this->flushSession();

        $res = $this->getJson($this->api('/feed'))->assertOk()->assertJsonPath('current_bid', 100000)->assertJsonPath('phase', 'open');
        $this->assertStringNotContainsString('private@example.com', $res->getContent());
        $this->assertStringNotContainsString('150000', $res->getContent());
        $res->assertJsonPath('you', null);   // a guest is nobody in particular
    }

    public function test_placing_a_bid_needs_a_signed_in_approved_bidder(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
        $this->postJson($this->api('/bids'), ['amount' => 100000])->assertStatus(401);

        $user = $this->signIn();
        $this->postJson($this->api('/bids'), ['amount' => 100000])->assertStatus(403);
        $reg = $this->registration($user, 'pending');
        $this->postJson($this->api('/bids'), ['amount' => 100000])->assertStatus(403)->assertJsonFragment(['message' => 'Your registration is not approved yet.']);

        $reg->forceFill(['status' => 'approved', 'bidder_number' => 1])->save();
        $this->postJson($this->api('/bids'), ['amount' => 100000])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('current_bid', 100000)->assertJsonPath('you', 'winning');
        $this->assertSame(1, AuctionBid::count());
    }

    public function test_a_rejected_bid_returns_a_readable_message(): void
    {
        $user = $this->signIn();
        $this->registration($user);

        $this->postJson($this->api('/bids'), ['amount' => 50000])->assertStatus(422)->assertJsonValidationErrors('amount')->assertJsonFragment(['amount' => ['Your bid must be at least R 100 000.']]);
        $this->postJson($this->api('/bids'), ['amount' => 'lots'])->assertStatus(422);
        $this->assertSame(0, AuctionBid::count());
    }

    public function test_an_auto_bid_can_be_set_and_cancelled_through_the_api(): void
    {
        $user = $this->signIn();
        $this->registration($user);

        $this->postJson($this->api('/auto-bid'), ['max' => 180000])->assertOk()->assertJsonPath('your_auto_bid', 180000)->assertJsonPath('you', 'winning');
        $this->deleteJson($this->api('/auto-bid'))->assertOk()->assertJsonPath('your_auto_bid', null);
    }

    public function test_the_feed_tells_the_signed_in_bidder_where_they_stand(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);
        app(BidEngine::class)->place($this->lot, $b, 105000);

        $this->signIn('a@example.com');
        $this->getJson($this->api('/feed'))->assertJsonPath('you', 'outbid')->assertJsonPath('min_next', 110000);
        $this->signIn('b@example.com');
        $this->getJson($this->api('/feed'))->assertJsonPath('you', 'winning');
    }

    public function test_the_bidding_api_hides_lots_that_are_not_public_or_whose_agency_switched_auctions_off(): void
    {
        $user = $this->signIn();
        $this->registration($user);

        $this->lot->forceFill(['status' => 'draft'])->save();
        $this->getJson($this->api('/feed'))->assertNotFound();
        $this->postJson($this->api('/bids'), ['amount' => 100000])->assertNotFound();

        $this->lot->forceFill(['status' => 'published'])->save();
        $this->auc->forceFill(['status' => 'draft'])->save();
        $this->getJson($this->api('/feed'))->assertNotFound();
    }

    public function test_watching_a_lot_toggles_and_shows_in_my_account(): void
    {
        $user = $this->signIn();

        $this->postJson($this->api('/watch'))->assertOk()->assertJsonPath('watching', true);
        $this->assertSame(1, AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('client_user_id', $user->id)->count());
        $this->get("{$this->base()}/account")->assertOk()->assertSee('Lot 1')->assertSee('Bid Cottage');

        $this->postJson($this->api('/watch'))->assertOk()->assertJsonPath('watching', false);
        $this->assertSame(0, AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('client_user_id', $user->id)->count());

        $this->flushSession();   // signed out (not SessionGuard::logout(): its Logout event is for staff users)
        Auth::guard('client-web')->forgetUser();
        $this->postJson($this->api('/watch'))->assertStatus(401);
    }

    public function test_my_account_lists_my_registrations_with_their_status(): void
    {
        $user = $this->signIn();
        $this->registration($user, 'approved');

        $this->get("{$this->base()}/account")->assertOk()->assertSee('October Auction')->assertSee('Approved (bidder #1)');
    }

    // ── emails ─────────────────────────────────────────────────────────────

    public function test_approving_declining_and_outbidding_email_the_bidder_as_the_agency(): void
    {
        Mail::fake();
        $service = app(AuctionRegistrationService::class);
        $a = $this->registration($this->buyer('a@example.com'), 'pending');
        $a->documents()->getRelated();   // no documents required for this test: clear the requirement
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'docs_required_individual' => [], 'docs_required_company' => []]);

        $service->approve($a, $this->admin);
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'approved' && $m->hasTo('a@example.com') && $m->agencyName === 'Cape Auctions' && $m->facts['bidder_number'] === 1);

        $b = $this->registration($this->buyer('b@example.com'), 'pending');
        $service->decline($b, $this->admin, 'Please send a clearer ID');
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'declined' && $m->hasTo('b@example.com') && $m->facts['reason'] === 'Please send a clearer ID');

        $c = $this->registration($this->buyer('c@example.com'));
        app(BidEngine::class)->place($this->lot, $a->fresh(), 100000);
        app(BidEngine::class)->place($this->lot, $c, 105000);
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'outbid' && $m->hasTo('a@example.com') && $m->facts['amount'] === 'R 105 000');
        Mail::assertNotSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'outbid' && $m->hasTo('c@example.com'));
    }

    public function test_outbid_emails_can_be_switched_off(): void
    {
        Mail::fake();
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'outbid_emails' => false]);
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));

        app(BidEngine::class)->place($this->lot, $a, 100000);
        app(BidEngine::class)->place($this->lot, $b, 105000);

        Mail::assertNotSent(AuctionBidderMail::class);
    }
}
