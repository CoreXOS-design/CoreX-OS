<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\ClientAuthOtpMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AgencyApiKey;
use App\Models\AgencyFeature;
use App\Models\AuctionBuyerToken;
use App\Models\AuctionDocument;
use App\Models\AuctionEnquiry;
use App\Models\AuctionRegistration;
use App\Models\AuctionSellerSubmission;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\BidEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** The Auction Website API: an external site doing what the public page does (.ai/specs/auctions-website-api.md, auctions.md §24). */
final class AuctionWebsiteApiTest extends BiddingTestCase
{
    private const ALL = [AgencyApiKey::SCOPE_AGENCY_READ, AgencyApiKey::SCOPE_AUCTIONS_READ, AgencyApiKey::SCOPE_AUCTIONS_BUYERS, AgencyApiKey::SCOPE_AUCTIONS_LEADS];

    private string $keyToken;
    private AgencyApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency->forceFill(['website_enabled' => true])->save();
        [$this->key, $this->keyToken] = $this->makeKey($this->agency, 'Auction Website', self::ALL);
        Auth::guard('web')->forgetUser();
        $this->flushSession();
        Cache::flush();
    }

    /** @return array{0: AgencyApiKey, 1: string} */
    private function makeKey(Agency $agency, string $name, array $scopes): array
    {
        $m = AgencyApiKey::mintSecret();
        $key = AgencyApiKey::withoutAgencyStamping(fn () => AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $agency->id, 'name' => $name, 'key_prefix' => $m['prefix'], 'secret_hash' => $m['hash'], 'scopes' => $scopes]));

        return [$key, $m['plaintext']];
    }

    private function api(string $path = ''): string
    {
        return '/api/v1/website/auctions' . $path;
    }

    private function hit(string $method, string $path, array $data = [], ?string $buyer = null, array $headers = [], ?string $token = null)
    {
        $this->fresh();
        $req = $this->withToken($token ?? $this->keyToken);
        if ($buyer) {
            $headers['X-Buyer-Token'] = $buyer;
        }

        return $req->withHeaders($headers)->json($method, $this->api($path), $data);
    }

    /** Each call is a separate HTTP request: nothing (headers, resolved key or buyer) may carry over from the one before. */
    private function fresh(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function enableAuctions(Agency $agency): void
    {
        \Illuminate\Support\Facades\DB::table('agency_features')->updateOrInsert(['agency_id' => $agency->id, 'feature_key' => 'auctions'], ['enabled' => 1, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($agency->id);
    }

    /** sign a buyer up through the API; returns the buyer token */
    private function signUp(string $email = 'thandi@example.com', string $name = 'Thandi Nkosi'): string
    {
        Mail::fake();
        $this->hit('POST', '/buyers/signup/send', ['email' => $email])->assertOk();
        $code = Mail::sent(ClientAuthOtpMail::class)->last()->code;
        $res = $this->hit('POST', '/buyers/signup/verify', ['email' => $email, 'code' => $code, 'name' => $name, 'phone' => '0829998888', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1', 'popia_consent' => true])->assertCreated();

        return (string) $res->json('token');
    }

    private function approved(string $token, string $email = 'thandi@example.com'): AuctionRegistration
    {
        $buyer = ClientUser::where('email', $email)->firstOrFail();
        $reg = $this->registration($buyer);   // approved, with a bidder number

        return $reg;
    }

    // ── access ─────────────────────────────────────────────────────────────

    public function test_a_call_needs_a_valid_key_the_right_permission_and_a_live_website(): void
    {
        $this->getJson($this->api('/site'))->assertUnauthorized();
        $this->withToken('cx_live_nope.nothing')->getJson($this->api('/site'))->assertUnauthorized();

        [, $listingsOnly] = $this->makeKey($this->agency, 'Listings Site', [AgencyApiKey::SCOPE_LISTINGS_READ]);
        $this->hit('GET', '/site', [], null, [], $listingsOnly)->assertForbidden();

        [, $readOnly] = $this->makeKey($this->agency, 'Read Only', [AgencyApiKey::SCOPE_AUCTIONS_READ]);
        $this->hit('GET', '/site', [], null, [], $readOnly)->assertOk();
        $this->hit('POST', '/buyers/login', ['email' => 'a@b.co', 'password' => 'x'], null, [], $readOnly)->assertForbidden();
        $this->hit('POST', '/sell', [], null, [], $readOnly)->assertForbidden();

        $this->agency->forceFill(['website_enabled' => false])->save();
        $this->hit('GET', '/site')->assertForbidden();
    }

    public function test_an_agency_with_auctions_off_is_a_404_and_a_key_only_ever_sees_its_own_agency(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oth-' . uniqid(), 'website_enabled' => true]);
        $this->enableAuctions($other);
        [, $otherKey] = $this->makeKey($other, 'Other Site', self::ALL);

        $this->hit('GET', "/{$this->auc->id}", [], null, [], $otherKey)->assertNotFound();
        $this->hit('GET', "/{$this->auc->id}/lots/{$this->lot->id}", [], null, [], $otherKey)->assertNotFound();
        $this->hit('POST', "/lots/{$this->lot->id}/watch", [], null, [], $otherKey)->assertUnauthorized();
        $this->assertSame([], $this->hit('GET', '', [], null, [], $otherKey)->assertOk()->json('data'), 'it lists only its own');

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
        $this->hit('GET', '/site')->assertNotFound();
    }

    // ── what it serves ─────────────────────────────────────────────────────

    public function test_the_site_endpoint_gives_the_agencys_branding_settings_and_the_seller_form_options(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'site_title' => 'Cape Auctions Online', 'intro_text' => 'Bid from home.', 'accent_color' => '#123456', 'seller_response_text' => 'within 2 days']);

        $res = $this->hit('GET', '/site')->assertOk();
        $res->assertJsonPath('agency.name', 'Cape Auctions')->assertJsonPath('site.title', 'Cape Auctions Online')->assertJsonPath('site.intro', 'Bid from home.')
            ->assertJsonPath('colors.brand', '#123456')->assertJsonPath('colors.on_brand', '#ffffff')->assertJsonPath('features.seller_questionnaire', true)
            ->assertJsonPath('seller_questionnaire.response_text', 'within 2 days')->assertJsonPath('seller_questionnaire.property_types.house', 'House')
            ->assertJsonPath('site.currency', 'ZAR');
        $this->assertNotEmpty($res->json('seller_questionnaire.features'));

        AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->update(['seller_form_enabled' => false]);
        $this->hit('GET', '/site')->assertJsonPath('features.seller_questionnaire', false)->assertJsonPath('seller_questionnaire', null);
    }

    public function test_the_auction_list_the_auction_and_the_lot_match_what_the_page_shows_and_never_leak_the_reserve(): void
    {
        $this->auction(['title' => 'Secret Draft', 'status' => 'draft']);
        $this->lot->property->forceFill(['beds' => 3, 'baths' => 2, 'suburb' => 'Pennington', 'description' => 'Near the beach.', 'street_name' => 'Hidden Street'])->save();

        $list = $this->hit('GET', '')->assertOk()->json('data');
        $this->assertCount(1, $list, 'drafts are never listed');
        $this->assertSame(['live', 'Live now', 1], [$list[0]['phase'], $list[0]['phase_label'], $list[0]['lots_count']]);
        $this->hit('GET', '?phase=upcoming')->assertOk()->assertJsonCount(0, 'data');

        $a = $this->hit('GET', "/{$this->auc->id}")->assertOk()->assertJsonPath('data.title', 'October Auction')->assertJsonPath('data.registration.open', true)->assertJsonCount(1, 'data.lots');
        $this->assertSame('Bid Cottage', $a->json('data.lots.0.title'));

        $lot = $this->hit('GET', "/{$this->auc->id}/lots/{$this->lot->id}")->assertOk();
        $lot->assertJsonPath('data.suburb', 'Pennington')->assertJsonPath('data.bedrooms', 3)->assertJsonPath('data.description', 'Near the beach.')
            ->assertJsonPath('data.opening_bid', 100000)->assertJsonPath('data.bidding.phase', 'open')->assertJsonPath('data.bidding.min_next', 100000);
        $body = $lot->getContent();
        $this->assertStringNotContainsString('reserve_price', $body);
        $this->assertStringNotContainsString('150000', $body, 'the reserve amount never leaves CoreX');
        $this->assertStringNotContainsString('Hidden Street', $body, 'no street address, as on the page');
        $this->hit('GET', "/{$this->auc->id}/lots/999999")->assertNotFound();
    }

    public function test_the_feed_carries_the_latest_bids_and_no_personal_data(): void
    {
        $this->bidAs('first@example.com', 100000);
        $this->bidAs('second@example.com', 105000);

        $res = $this->hit('GET', "/{$this->auc->id}/lots/{$this->lot->id}/feed")->assertOk();
        $res->assertJsonPath('data.bidding.current_bid', 105000)->assertJsonPath('data.bidding.recent_bids.0.amount', 105000)->assertJsonPath('data.bidding.recent_bids.0.highest', true);
        $this->assertStringNotContainsString('@example.com', $res->getContent());
        $res->assertJsonPath('data.my_registration', null);
    }

    public function test_registered_only_documents_need_a_buyer_token(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('auction-docs/a.pdf', 'PDF');
        Storage::disk('local')->put('auction-docs/b.pdf', 'PDF');
        $pub = AuctionDocument::withoutAgencyStamping(fn () => AuctionDocument::create(['agency_id' => $this->agency->id, 'auction_id' => $this->auc->id, 'kind' => 'other', 'name' => 'Conditions', 'path' => 'auction-docs/a.pdf', 'visibility' => 'public', 'sort_order' => 1]));
        $reg = AuctionDocument::withoutAgencyStamping(fn () => AuctionDocument::create(['agency_id' => $this->agency->id, 'auction_id' => $this->auc->id, 'kind' => 'other', 'name' => 'Title Deed', 'path' => 'auction-docs/b.pdf', 'visibility' => 'registered', 'sort_order' => 2]));

        $a = $this->hit('GET', "/{$this->auc->id}")->assertOk();
        $this->assertSame(['Conditions'], collect($a->json('data.documents'))->pluck('name')->all());
        $this->assertSame(1, $a->json('data.hidden_documents'));
        $this->hit('GET', "/{$this->auc->id}/documents/{$pub->id}")->assertOk();
        $this->hit('GET', "/{$this->auc->id}/documents/{$reg->id}")->assertNotFound();

        $token = $this->signUp();
        $this->hit('GET', "/{$this->auc->id}/documents/{$reg->id}", [], $token)->assertOk();
        $this->assertSame(0, $this->hit('GET', "/{$this->auc->id}", [], $token)->json('data.hidden_documents'));
    }

    // ── buyers: the same account as the page ───────────────────────────────

    public function test_a_buyer_signs_up_through_the_api_becomes_a_contact_for_the_admin_and_can_log_in_on_the_page_too(): void
    {
        $token = $this->signUp('thandi@example.com');
        $this->assertStringStartsWith('cxb_', $token);

        $contact = Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'thandi@example.com')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $contact->agent_id, 'assigned to the admin, exactly like a sign-up on the page');
        $this->assertNotNull($contact->client_user_id);
        $this->assertDatabaseHas('client_access_logs', ['event' => 'auction_signup', 'agency_id' => $this->agency->id]);

        // the SAME account: the page's own login accepts the password chosen on the website
        $this->flushSession();
        $this->post('/auctions/' . $this->agency->slug . '/login', ['email' => 'thandi@example.com', 'password' => 'a-strong-pass-1'])->assertSessionHasNoErrors();
        $this->assertTrue(Auth::guard('client-web')->check());
        Auth::guard('client-web')->forgetUser();

        // and the API login works for an account made on the page
        $this->hit('POST', '/buyers/login', ['email' => 'thandi@example.com', 'password' => 'a-strong-pass-1'])->assertOk()->assertJsonPath('buyer.email', 'thandi@example.com')->assertJsonPath('buyer.name', 'Thandi Nkosi');
        $this->hit('POST', '/buyers/login', ['email' => 'thandi@example.com', 'password' => 'wrong-password'])->assertStatus(422);
    }

    public function test_someone_corex_already_knows_is_linked_not_duplicated_when_they_sign_up_through_the_api(): void
    {
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent', 'branch_id' => $this->branch->id]);
        Contact::withoutAgencyStamping(fn () => Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi', 'email' => 'thandi@example.com', 'agent_id' => $agent->id, 'branch_id' => $this->branch->id]));

        $this->signUp('thandi@example.com');

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'thandi@example.com')->count());
        $this->assertSame($agent->id, (int) Contact::withoutGlobalScopes()->where('email', 'thandi@example.com')->value('agent_id'));
    }

    public function test_a_wrong_code_a_missing_consent_and_a_weak_password_are_refused(): void
    {
        Mail::fake();
        $this->hit('POST', '/buyers/signup/send', ['email' => 'x@example.com'])->assertOk();
        $good = ['email' => 'x@example.com', 'code' => Mail::sent(ClientAuthOtpMail::class)->first()->code, 'name' => 'X Y', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1', 'popia_consent' => true];

        $this->hit('POST', '/buyers/signup/verify', array_merge($good, ['code' => '000000']))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->hit('POST', '/buyers/signup/verify', array_merge($good, ['popia_consent' => false]))->assertStatus(422)->assertJsonValidationErrors('popia_consent');
        $this->hit('POST', '/buyers/signup/verify', array_merge($good, ['password' => 'short', 'password_confirmation' => 'short']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertSame(0, ClientUser::where('email', 'x@example.com')->count());
    }

    public function test_the_password_reset_never_reveals_who_has_an_account_and_signs_the_buyer_in_with_a_token(): void
    {
        $this->signUp('thandi@example.com');
        Mail::fake();
        Cache::flush();

        $this->hit('POST', '/buyers/forgot/send', ['email' => 'nobody@example.com'])->assertOk();
        Mail::assertNotSent(ClientAuthOtpMail::class);
        Cache::flush();
        $this->hit('POST', '/buyers/forgot/send', ['email' => 'thandi@example.com'])->assertOk()->assertJsonPath('ok', true);
        $code = Mail::sent(ClientAuthOtpMail::class)->first()->code;

        $res = $this->hit('POST', '/buyers/forgot/reset', ['email' => 'thandi@example.com', 'code' => $code, 'password' => 'brand-new-pass-9', 'password_confirmation' => 'brand-new-pass-9'])->assertOk();
        $this->assertStringStartsWith('cxb_', $res->json('token'));
        $this->hit('POST', '/buyers/login', ['email' => 'thandi@example.com', 'password' => 'brand-new-pass-9'])->assertOk();
    }

    public function test_the_buyer_token_is_checked_expires_can_be_revoked_and_opens_nothing_else(): void
    {
        $token = $this->signUp();
        $this->hit('GET', '/buyers/me', [], $token)->assertOk()->assertJsonPath('data.buyer.email', 'thandi@example.com');

        $this->hit('GET', '/buyers/me')->assertUnauthorized()->assertJsonPath('code', 'buyer_token_invalid');
        $this->hit('GET', '/buyers/me', [], 'cxb_' . str_repeat('x', 56))->assertUnauthorized();

        // another agency's key cannot use this agency's token
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oth-' . uniqid(), 'website_enabled' => true]);
        $this->enableAuctions($other);
        [$ok, $otherKey] = $this->makeKey($other, 'Other Site', self::ALL);
        $this->assertSame($other->id, (int) AgencyApiKey::withoutGlobalScopes()->whereKey($ok->id)->value('agency_id'), 'the other site\'s key belongs to the other agency');
        $this->assertSame($this->agency->id, (int) AuctionBuyerToken::query()->value('agency_id'));
        $this->hit('GET', '/buyers/me', [], $token, [], $otherKey)->assertUnauthorized();

        // it expires
        AuctionBuyerToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->hit('GET', '/buyers/me', [], $token)->assertUnauthorized();
        AuctionBuyerToken::query()->update(['expires_at' => now()->addDay()]);
        $this->hit('GET', '/buyers/me', [], $token)->assertOk();

        // it is stored hashed, and logout revokes it
        $this->assertDatabaseMissing('auction_buyer_tokens', ['token_hash' => $token]);
        $this->hit('POST', '/buyers/logout', [], $token)->assertOk();
        $this->hit('GET', '/buyers/me', [], $token)->assertUnauthorized();

        // and it is not a key to any other API (the phone app / rental portal)
        $fresh = $this->signUp('b@example.com', 'B Buyer');
        $this->fresh();
        $this->withToken($fresh)->getJson('/api/v1/client/me')->assertUnauthorized();
    }

    public function test_account_details_can_be_changed_and_my_account_lists_registrations_watching_and_invoices(): void
    {
        $token = $this->signUp();
        $this->hit('PUT', '/buyers/me', ['name' => 'Thandi M Nkosi', 'phone' => '0831112222'], $token)->assertOk()->assertJsonPath('buyer.name', 'Thandi M Nkosi')->assertJsonPath('buyer.phone', '0831112222');
        $this->hit('POST', "/lots/{$this->lot->id}/watch", [], $token)->assertOk()->assertJsonPath('watching', true);

        $me = $this->hit('GET', '/buyers/me', [], $token)->assertOk();
        $me->assertJsonCount(1, 'data.watching')->assertJsonPath('data.watching.0.title', 'Bid Cottage');
        $this->hit('POST', "/lots/{$this->lot->id}/watch", [], $token)->assertJsonPath('watching', false);
    }

    // ── registering and bidding ────────────────────────────────────────────

    public function test_registering_to_bid_through_the_api_with_documents_creates_the_same_pending_registration_and_emails_the_team(): void
    {
        Storage::fake('local');
        $token = $this->signUp();
        $this->agency->forceFill(['email' => 'office@example.com'])->save();

        $show = $this->hit('GET', "/{$this->auc->id}/registration", [], $token)->assertOk();
        $kinds = collect($show->json('data.required_documents.individual'))->pluck('kind')->all();
        $this->assertNotEmpty($kinds);
        $show->assertJsonPath('data.open', true)->assertJsonPath('data.registration', null);

        Mail::fake();
        $files = [];
        foreach ($kinds as $k) {
            $files[$k] = UploadedFile::fake()->create("{$k}.pdf", 50, 'application/pdf');
        }
        $res = $this->withToken($this->keyToken)->withHeaders(['X-Buyer-Token' => $token, 'Accept' => 'application/json', 'X-Visitor-IP' => '41.12.9.9'])
            ->post($this->api("/{$this->auc->id}/registration"), ['bidder_type' => 'individual', 'accept_conditions' => '1', 'documents' => $files])->assertCreated();
        $res->assertJsonPath('data.status', 'pending');

        $reg = AuctionRegistration::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $this->assertSame('41.12.9.9', $reg->accepted_conditions_ip, "the visitor's address, not the website server's");
        Mail::assertSent(\App\Mail\Auctions\AuctionRegistrationMail::class);
        $this->hit('GET', "/{$this->auc->id}/registration", [], $token)->assertJsonPath('data.registration.status', 'pending');

        // missing documents / conditions are refused
        $this->hit('POST', "/{$this->auc->id}/registration", ['bidder_type' => 'individual'], $token)->assertStatus(422);
    }

    public function test_bidding_through_the_api_follows_the_same_rules_as_the_page(): void
    {
        $token = $this->signUp();

        $this->hit('POST', "/lots/{$this->lot->id}/bids", ['amount' => 100000])->assertUnauthorized();
        $this->hit('POST', "/lots/{$this->lot->id}/bids", ['amount' => 100000], $token)->assertForbidden();   // not registered

        $reg = $this->approved($token);
        $this->hit('POST', "/lots/{$this->lot->id}/bids", ['amount' => 99999], $token)->assertStatus(422);       // below the minimum
        $res = $this->hit('POST', "/lots/{$this->lot->id}/bids", ['amount' => 100000], $token)->assertOk();
        $res->assertJsonPath('data.bidding.current_bid', 100000)->assertJsonPath('data.bidding.you', 'winning')->assertJsonPath('data.my_registration.bidder_number', $reg->bidder_number);
        $this->assertSame('online', \App\Models\AuctionBid::withoutGlobalScopes()->latest('id')->value('source'));

        // a leader cannot outbid themselves; the minimum moves
        $this->hit('POST', "/lots/{$this->lot->id}/bids", ['amount' => 105000], $token)->assertStatus(422);

        $this->hit('POST', "/lots/{$this->lot->id}/auto-bid", ['max' => 200000], $token)->assertOk()->assertJsonPath('data.bidding.your_auto_bid', 200000);
        $this->hit('DELETE', "/lots/{$this->lot->id}/auto-bid", [], $token)->assertOk()->assertJsonPath('data.bidding.your_auto_bid', null);

        // the team hears about the bid exactly as for a bid on the page (alert to the listing agent = the admin here)
        $this->assertSame(1, $this->admin->notifications()->get()->filter(fn ($n) => ($n->data['event_key'] ?? null) === 'auction.bid_placed')->count());
    }

    public function test_a_pending_buyer_cannot_bid(): void
    {
        $token = $this->signUp();
        $buyer = ClientUser::where('email', 'thandi@example.com')->firstOrFail();
        $this->registration($buyer, 'pending');

        $this->hit('POST', "/lots/{$this->lot->id}/bids", ['amount' => 100000], $token)->assertForbidden()->assertJsonPath('message', 'Your registration is not approved yet.');
    }

    // ── leads ──────────────────────────────────────────────────────────────

    public function test_an_enquiry_through_the_api_creates_the_enquiry_and_the_contact(): void
    {
        $this->hit('POST', "/{$this->auc->id}/lots/{$this->lot->id}/enquiries", ['name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'message' => 'Is it vacant?'])->assertStatus(422)->assertJsonValidationErrors('popia_consent');
        $this->hit('POST', "/{$this->auc->id}/enquiries", ['name' => 'Sipho', 'popia_consent' => true])->assertStatus(422);   // no way to reach them

        $this->hit('POST', "/{$this->auc->id}/lots/{$this->lot->id}/enquiries", ['name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'message' => 'Is it vacant?', 'popia_consent' => true])->assertCreated();

        $e = AuctionEnquiry::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([$this->lot->id, 'new'], [$e->lot_id, $e->status]);
        $this->assertNotNull($e->contact_id);
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'sipho@example.com')->count());
    }

    public function test_the_seller_questionnaire_through_the_api_does_everything_the_page_does(): void
    {
        $payload = [
            'seller_type' => 'individual', 'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'phone' => '0821234567', 'preferred_contact' => 'whatsapp',
            'property_type' => 'house', 'address' => '12 Ocean Drive', 'suburb' => 'Ramsgate', 'bedrooms' => 3, 'bathrooms' => 2, 'erf_size' => 800, 'floor_size' => 210,
            'features' => ['pool'], 'occupancy' => 'owner', 'condition' => 'good', 'has_bond' => false, 'price_expectation' => 2450000,
            'auction_format' => 'online', 'reserve' => 'confidential', 'timing' => '1_3_months', 'viewing' => 'appointment', 'documents' => ['title_deed'],
            'consent_terms' => true, 'consent_authorised' => true, 'consent_processing' => true,
        ];
        $this->agency->forceFill(['email' => 'office@example.com'])->save();
        Mail::fake();

        $this->hit('POST', '/sell', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'consent_terms']);
        $this->hit('POST', '/sell', array_merge($payload, ['website' => 'spam']))->assertStatus(422);
        $this->hit('POST', '/sell', $payload)->assertCreated()->assertJsonPath('response_text', 'within one business day');

        $row = AuctionSellerSubmission::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(2450000, (int) $row->price_expectation);
        $contact = Contact::withoutGlobalScopes()->findOrFail($row->contact_id);
        $this->assertSame($this->admin->id, (int) $contact->agent_id);
        $property = Property::withoutGlobalScopes()->findOrFail($row->property_id);
        $this->assertSame('auction_enquiry', $property->status);
        $this->assertNotNull($row->tracked_property_id);
        Mail::assertSent(\App\Mail\Auctions\AuctionSellerSubmissionMail::class);

        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['seller_form_enabled' => false]);
        $this->hit('POST', '/sell', $payload)->assertNotFound();
    }

    // ── many websites, one agency ──────────────────────────────────────────

    public function test_one_agency_can_have_many_website_keys_each_with_its_own_permissions_and_revoking_one_leaves_the_others(): void
    {
        [$second, $secondToken] = $this->makeKey($this->agency, 'Second Auction Site', [AgencyApiKey::SCOPE_AUCTIONS_READ]);
        [, $listings] = $this->makeKey($this->agency, 'Property Site', [AgencyApiKey::SCOPE_LISTINGS_READ]);

        $this->assertSame(3, AgencyApiKey::withoutGlobalScope(AgencyScope::class)->where('agency_id', $this->agency->id)->count());
        $this->hit('GET', '/site')->assertOk();
        $this->hit('GET', '/site', [], null, [], $secondToken)->assertOk();
        $this->hit('GET', '/site', [], null, [], $listings)->assertForbidden();

        $second->forceFill(['revoked_at' => now()])->save();
        $this->hit('GET', '/site', [], null, [], $secondToken)->assertUnauthorized();
        $this->hit('GET', '/site')->assertOk();
    }

    public function test_the_key_screen_offers_the_auction_permissions_and_the_auction_website_preset(): void
    {
        foreach (['auctions:read', 'auctions:buyers', 'auctions:leads'] as $scope) {
            $this->assertArrayHasKey($scope, AgencyApiKey::SCOPES);
        }
        $this->assertEqualsCanonicalizing(['agency:read', 'auctions:read', 'auctions:buyers', 'auctions:leads'], AgencyApiKey::AUCTION_WEBSITE_PRESET);

        $this->actingAs($this->admin);
        $html = $this->get(route('agencies.edit', $this->agency))->assertOk()->getContent();
        $this->assertStringContainsString('Auction website', $html);
        $this->assertStringContainsString('/api/v1/website/auctions', $html);
        $this->assertStringContainsString('Second', $html . 'Second');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function bidAs(string $email, float $amount): void
    {
        app(BidEngine::class)->place($this->lot->fresh(), $this->registration($this->buyer($email)), $amount);
    }
}
