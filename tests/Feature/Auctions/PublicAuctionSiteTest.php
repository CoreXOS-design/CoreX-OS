<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionEnquiryMail;
use App\Mail\ClientAuthOtpMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\AuctionDocument;
use App\Models\AuctionEnquiry;
use App\Models\AuctionLot;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Document;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** The public auction site, buyer accounts and enquiries (.ai/specs/auctions.md §8, phase 2). */
final class PublicAuctionSiteTest extends AuctionsV2TestCase
{
    private Auction $auction;
    private AuctionLot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auction = $this->auction(['status' => 'published', 'published_at' => now(), 'venue_name' => 'The Boardwalk']);
        $this->lot = app(AuctionLotService::class)->saveForProperty(
            $this->property('Hilltop Cottage'),
            ['auction_id' => $this->auction->id, 'opening_bid' => 750000, 'reserve_price' => 1234567, 'guide_price_min' => 800000, 'guide_price_max' => 950000],
            $this->admin
        );
        $this->lot->forceFill(['status' => 'published'])->save();
        $this->guest();
    }

    /** Public visitors have no staff login. */
    private function guest(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
    }

    private function base(): string
    {
        return '/auctions/' . $this->agency->slug;
    }

    // ── the site ───────────────────────────────────────────────────────────

    public function test_an_unknown_agency_or_one_with_auctions_off_is_a_404(): void
    {
        $this->get('/auctions/nobody-here')->assertNotFound();

        AgencyFeature::where('agency_id', $this->agency->id)->where('feature_key', 'auctions')->update(['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
        $this->get($this->base())->assertNotFound();
    }

    public function test_the_home_page_lists_published_auctions_of_this_agency_only(): void
    {
        $this->auction(['reference' => 'AUC-T-DRAFT', 'title' => 'Hidden Draft Auction', 'status' => 'draft']);
        $this->auction(['reference' => 'AUC-T-CANC', 'title' => 'Cancelled Auction', 'status' => 'cancelled']);
        $other = Agency::create(['name' => 'Rival Agency', 'slug' => 'rival-'.uniqid()]);
        Auction::withoutAgencyStamping(fn () => Auction::create(['agency_id' => $other->id, 'reference' => 'AUC-R-1', 'title' => 'Rival Auction', 'format' => 'timed', 'status' => 'published']));

        $this->get($this->base())->assertOk()
            ->assertSee('October Auction')
            ->assertDontSee('Hidden Draft Auction')->assertDontSee('Cancelled Auction')->assertDontSee('Rival Auction')
            ->assertSee('Cape Auctions');
    }

    public function test_the_page_uses_the_agencys_own_wording_never_another_brand(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'site_title' => 'Cape Property Auctions', 'intro_text' => 'Find your next home under the hammer.']);

        $html = $this->get($this->base())->assertOk()->assertSee('Cape Property Auctions')->assertSee('Find your next home')->getContent();
        $visible = strip_tags(preg_replace('/<(style|script).*?<\/\1>/s', '', $html));   // what a person reads (links may carry the server's own host name)
        $this->assertStringNotContainsStringIgnoringCase('corex', $visible);
        $this->assertStringNotContainsStringIgnoringCase('home finders', $visible);
    }

    public function test_an_unpublished_auction_or_lot_is_a_404_and_the_reserve_never_appears(): void
    {
        $draftAuction = $this->auction(['reference' => 'AUC-T-D2', 'status' => 'draft']);
        $this->get("{$this->base()}/a/{$draftAuction->id}")->assertNotFound();

        $html = $this->get("{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}")->assertOk()
            ->assertSee('Hilltop Cottage')->assertSee('R 750 000')->assertSee('Login to bid')->getContent();
        $this->assertStringNotContainsString('1234567', $html);
        $this->assertStringNotContainsString('1 234 567', $html);

        $this->lot->forceFill(['status' => 'withdrawn'])->save();
        $this->get("{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}")->assertNotFound();
        $this->get("{$this->base()}/a/{$this->auction->id}")->assertOk()->assertDontSee('Hilltop Cottage');
    }

    public function test_a_lot_cannot_be_read_through_another_auctions_url(): void
    {
        $otherAuction = $this->auction(['reference' => 'AUC-T-O2', 'status' => 'published']);

        $this->get("{$this->base()}/a/{$otherAuction->id}/lots/{$this->lot->id}")->assertNotFound();
    }

    // ── gated documents ────────────────────────────────────────────────────

    public function test_registered_only_documents_need_a_signed_in_buyer(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('x/public.pdf', 'public');
        Storage::disk('local')->put('x/gated.pdf', 'gated');
        $pub = AuctionDocument::create(['agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'kind' => 'conditions_of_sale', 'name' => 'Conditions of Sale', 'path' => 'x/public.pdf', 'visibility' => 'public']);
        $gated = AuctionDocument::create(['agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'kind' => 'commission_breakdown', 'name' => 'Commission Breakdown', 'path' => 'x/gated.pdf', 'visibility' => 'registered']);

        $this->get("{$this->base()}/a/{$this->auction->id}")->assertOk()->assertSee('Conditions of Sale')->assertDontSee('Commission Breakdown')->assertSee('more document is available');
        $this->get("{$this->base()}/a/{$this->auction->id}/documents/{$pub->id}")->assertOk();
        $this->get("{$this->base()}/a/{$this->auction->id}/documents/{$gated->id}")->assertNotFound();

        $this->signUpBuyer();
        $this->get("{$this->base()}/a/{$this->auction->id}")->assertOk()->assertSee('Commission Breakdown');
        $this->get("{$this->base()}/a/{$this->auction->id}/documents/{$gated->id}")->assertOk();
    }

    public function test_the_property_auction_pack_is_gated_per_file(): void
    {
        Storage::fake('local');
        $p = $this->lot->property;
        $mk = function (string $name, ?string $vis) use ($p) {
            Storage::disk('local')->put("p/{$name}", $name);
            $d = Document::create(['original_name' => $name, 'storage_path' => "p/{$name}", 'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 5, 'source_type' => 'upload', 'uploaded_by' => $this->admin->id]);
            $p->documents()->attach($d->id, ['auction_visibility' => $vis]);

            return $d;
        };
        $everyone = $mk('Title deed.pdf', 'public');
        $members = $mk('Levy statement.pdf', 'registered');
        $secret = $mk('Seller ID.pdf', null);
        $lotUrl = "{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}";

        $this->get($lotUrl)->assertOk()->assertSee('Title deed.pdf')->assertDontSee('Levy statement.pdf')->assertDontSee('Seller ID.pdf');
        $this->get("{$lotUrl}/documents/{$everyone->id}")->assertOk();
        $this->get("{$lotUrl}/documents/{$members->id}")->assertNotFound();
        $this->get("{$lotUrl}/documents/{$secret->id}")->assertNotFound();

        $this->signUpBuyer();
        $this->get($lotUrl)->assertOk()->assertSee('Levy statement.pdf')->assertDontSee('Seller ID.pdf');
        $this->get("{$lotUrl}/documents/{$members->id}")->assertOk();
        $this->get("{$lotUrl}/documents/{$secret->id}")->assertNotFound();
    }

    // ── buyer accounts ─────────────────────────────────────────────────────

    /** Sign up through the real two-step flow and return the buyer. */
    private function signUpBuyer(string $email = 'thandi@example.com', string $name = 'Thandi Nkosi'): ClientUser
    {
        Mail::fake();
        $this->post("{$this->base()}/signup", ['email' => $email])->assertRedirect("{$this->base()}/signup/verify");
        $code = Mail::sent(ClientAuthOtpMail::class)->first()->code;

        $this->post("{$this->base()}/signup/verify", [
            'code' => $code, 'name' => $name, 'phone' => '0821234567',
            'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1', 'popia_consent' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return ClientUser::where('email', $email)->firstOrFail();
    }

    public function test_sign_up_sends_an_agency_branded_code_then_creates_the_account_contact_and_signs_in(): void
    {
        Mail::fake();
        $this->post("{$this->base()}/signup", ['email' => 'Thandi@Example.com'])->assertRedirect();
        $mail = Mail::sent(ClientAuthOtpMail::class)->first();
        $this->assertSame('Cape Auctions', $mail->agencyName, 'the code goes out as the agency, never as CoreX');

        $this->post("{$this->base()}/signup/verify", [
            'code' => $mail->code, 'name' => 'Thandi Nkosi', 'phone' => '0821234567',
            'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1', 'popia_consent' => '1',
        ])->assertSessionHasNoErrors();

        $user = ClientUser::where('email', 'thandi@example.com')->firstOrFail();
        $this->assertNotNull($user->activated_at);
        $this->assertTrue($user->hasPassword());
        $this->assertSame($this->agency->id, (int) $user->created_by_agency_id);
        $this->assertTrue(Auth::guard('client-web')->check());

        $contact = Contact::withoutGlobalScopes()->where('client_user_id', $user->id)->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('Thandi', $contact->first_name);
        $this->assertSame('Nkosi', $contact->last_name);
        $this->assertSame('0821234567', $contact->phone);
        $this->assertDatabaseHas('client_access_logs', ['client_user_id' => $user->id, 'event' => 'auction_signup']);
    }

    public function test_a_wrong_code_or_missing_consent_creates_nothing(): void
    {
        Mail::fake();
        $this->post("{$this->base()}/signup", ['email' => 'lerato@example.com']);
        $good = [
            'code' => '000000', 'name' => 'Lerato M', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1', 'popia_consent' => '1',
        ];

        $this->post("{$this->base()}/signup/verify", $good)->assertSessionHasErrors('code');
        $this->post("{$this->base()}/signup/verify", array_merge($good, ['code' => Mail::sent(ClientAuthOtpMail::class)->first()->code, 'popia_consent' => null]))->assertSessionHasErrors('popia_consent');
        $this->post("{$this->base()}/signup/verify", array_merge($good, ['code' => Mail::sent(ClientAuthOtpMail::class)->first()->code, 'password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('client_users', ['email' => 'lerato@example.com']);
        $this->assertFalse(Auth::guard('client-web')->check());
    }

    public function test_login_logout_and_wrong_password(): void
    {
        $this->signUpBuyer();
        $this->post("{$this->base()}/logout")->assertRedirect();
        $this->assertFalse(Auth::guard('client-web')->check());

        $this->post("{$this->base()}/login", ['email' => 'thandi@example.com', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('client-web')->check());

        $this->post("{$this->base()}/login", ['email' => 'thandi@example.com', 'password' => 'a-strong-pass-1'])->assertSessionHasNoErrors();
        $this->assertTrue(Auth::guard('client-web')->check());
        $this->get("{$this->base()}/account")->assertOk()->assertSee('thandi@example.com');
    }

    public function test_login_is_rate_limited(): void
    {
        $this->signUpBuyer();
        $this->post("{$this->base()}/logout");

        $key = 'auction-buyer-login:127.0.0.1:thandi@example.com';
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        for ($i = 0; $i < 10; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 300);
        }
        // ten failed attempts already: even the right password is refused until the window passes
        $this->post("{$this->base()}/login", ['email' => 'thandi@example.com', 'password' => 'a-strong-pass-1'])->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('client-web')->check());
    }

    public function test_an_existing_buyer_from_another_agency_gets_a_contact_here_on_first_login(): void
    {
        $user = $this->signUpBuyer();
        $second = Agency::create(['name' => 'Second Agency', 'slug' => 'second-'.uniqid()]);
        \App\Models\Branch::create(['agency_id' => $second->id, 'name' => 'Head office']);
        AgencyFeature::updateOrCreate(['agency_id' => $second->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($second->id);
        $this->post("{$this->base()}/logout");

        $r = $this->post("/auctions/{$second->slug}/login", ['email' => 'thandi@example.com', 'password' => 'a-strong-pass-1']);
        $r->assertSessionHasNoErrors();
        $this->assertTrue($r->isRedirection(), 'login at the second agency succeeds, status '.$r->getStatusCode());

        $this->assertSame(2, Contact::withoutGlobalScopes()->where('client_user_id', $user->id)->count(), 'one Contact per agency');
        $this->assertTrue(Contact::withoutGlobalScopes()->where('client_user_id', $user->id)->where('agency_id', $second->id)->exists());
    }

    public function test_forgot_password_resets_with_a_code_and_never_reveals_who_has_an_account(): void
    {
        $this->signUpBuyer();
        $this->post("{$this->base()}/logout");
        Mail::fake();
        \Illuminate\Support\Facades\Cache::flush();

        $this->post("{$this->base()}/forgot", ['email' => 'nobody@example.com'])->assertRedirect("{$this->base()}/forgot/reset");
        Mail::assertNotSent(ClientAuthOtpMail::class);   // same page, same message — nothing sent, nothing revealed

        \Illuminate\Support\Facades\Cache::flush();
        $this->post("{$this->base()}/forgot", ['email' => 'thandi@example.com'])->assertRedirect("{$this->base()}/forgot/reset");
        $code = Mail::sent(ClientAuthOtpMail::class)->first()->code;

        $this->post("{$this->base()}/forgot/reset", ['code' => $code, 'password' => 'brand-new-pass-9', 'password_confirmation' => 'brand-new-pass-9'])->assertSessionHasNoErrors();
        $this->assertTrue(Auth::guard('client-web')->check());

        $this->post("{$this->base()}/logout");
        $this->post("{$this->base()}/login", ['email' => 'thandi@example.com', 'password' => 'brand-new-pass-9'])->assertSessionHasNoErrors();
        $this->assertTrue(Auth::guard('client-web')->check());
    }

    public function test_a_login_redirect_only_returns_to_this_auction_site(): void
    {
        $this->signUpBuyer();
        $this->post("{$this->base()}/logout");

        $this->get("{$this->base()}/login?back=" . urlencode('https://evil.example/phish'));
        $this->post("{$this->base()}/login", ['email' => 'thandi@example.com', 'password' => 'a-strong-pass-1'])->assertRedirect($this->base());
    }

    public function test_the_lot_page_offers_register_to_bid_once_signed_in(): void
    {
        $url = "{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}";
        $this->get($url)->assertSee('Login to bid');

        $this->signUpBuyer();
        $this->get($url)->assertOk()->assertDontSee('Login to bid')->assertSee('Register to bid');
    }

    // ── enquiries ──────────────────────────────────────────────────────────

    public function test_a_guest_enquiry_creates_a_contact_routes_to_the_listing_agent_and_emails_them(): void
    {
        Mail::fake();
        $this->lot->property->forceFill(['agent_id' => $this->admin->id])->save();

        $this->post("{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}/enquire", [
            'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'phone' => '0831112222', 'message' => 'Can I view it on Saturday?', 'popia_consent' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $e = AuctionEnquiry::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->agency->id, (int) $e->agency_id);
        $this->assertSame($this->lot->id, (int) $e->lot_id);
        $this->assertSame($this->admin->id, (int) $e->assigned_user_id);
        $this->assertSame('new', $e->status);
        $this->assertNotNull($e->consent_popia_at);
        $this->assertNull($e->client_user_id);

        $contact = Contact::withoutGlobalScopes()->findOrFail($e->contact_id);
        $this->assertSame('Sipho', $contact->first_name);
        $this->assertSame('sipho@example.com', $contact->email);

        Mail::assertSent(AuctionEnquiryMail::class, fn ($m) => $m->hasTo($this->admin->email));
    }

    public function test_an_enquiry_needs_consent_and_a_way_to_reach_the_sender(): void
    {
        $url = "{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}/enquire";

        $this->post($url, ['name' => 'A', 'email' => 'a@example.com'])->assertSessionHasErrors('popia_consent');
        $this->post($url, ['name' => 'A', 'popia_consent' => '1'])->assertSessionHasErrors('email');
        $this->assertSame(0, AuctionEnquiry::withoutGlobalScopes()->count());
    }

    public function test_the_same_enquiry_sent_twice_is_stored_once_and_a_known_person_is_not_duplicated(): void
    {
        $url = "{$this->base()}/a/{$this->auction->id}/enquire";
        $body = ['name' => 'Sipho D', 'email' => 'sipho@example.com', 'message' => 'Hello', 'popia_consent' => '1'];

        $this->post($url, $body);
        $this->post($url, $body);
        $this->assertSame(1, AuctionEnquiry::withoutGlobalScopes()->count(), 'a double click is one enquiry');

        $this->post("{$this->base()}/a/{$this->auction->id}/lots/{$this->lot->id}/enquire", $body);
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'sipho@example.com')->count(), 'one Contact per person');
    }

    public function test_a_signed_in_buyers_enquiry_is_linked_to_their_account(): void
    {
        $user = $this->signUpBuyer();

        $this->post("{$this->base()}/a/{$this->auction->id}/enquire", ['name' => 'Thandi Nkosi', 'email' => 'thandi@example.com', 'message' => 'Question', 'popia_consent' => '1'])
            ->assertSessionHasNoErrors();

        $e = AuctionEnquiry::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($user->id, (int) $e->client_user_id);
    }

    public function test_a_staff_member_of_another_agency_cannot_stamp_a_public_enquiry_into_their_own_agency(): void
    {
        $rival = Agency::create(['name' => 'Rival Agency', 'slug' => 'rival-'.uniqid()]);
        $rivalBranch = \App\Models\Branch::create(['agency_id' => $rival->id, 'name' => 'Main']);
        $staff = \App\Models\User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin']);
        $this->actingAs($staff);   // a rival's staff member browsing this agency's public site

        $this->post("{$this->base()}/a/{$this->auction->id}/enquire", ['name' => 'Visitor', 'email' => 'v@example.com', 'popia_consent' => '1'])->assertSessionHasNoErrors();

        $e = AuctionEnquiry::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->agency->id, (int) $e->agency_id, 'the enquiry belongs to the agency whose site it was sent from');
        $this->assertSame($this->agency->id, (int) Contact::withoutGlobalScopes()->findOrFail($e->contact_id)->agency_id);
    }

    public function test_a_one_word_name_still_creates_the_contact(): void
    {
        $this->post("{$this->base()}/a/{$this->auction->id}/enquire", ['name' => 'Madonna', 'email' => 'm@example.com', 'popia_consent' => '1'])->assertSessionHasNoErrors();

        $c = Contact::withoutGlobalScopes()->where('email', 'm@example.com')->firstOrFail();
        $this->assertSame('Madonna', $c->first_name);
        $this->assertSame('', (string) $c->last_name);
    }

    public function test_a_public_contact_is_filed_in_a_branch_of_the_agency_it_belongs_to(): void
    {
        $rival = Agency::create(['name' => 'Rival Agency', 'slug' => 'rival-'.uniqid()]);
        $rivalBranch = \App\Models\Branch::create(['agency_id' => $rival->id, 'name' => 'Rival HQ']);
        $this->actingAs(\App\Models\User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin']));

        $this->post("{$this->base()}/a/{$this->auction->id}/enquire", ['name' => 'Visitor One', 'email' => 'v1@example.com', 'popia_consent' => '1'])->assertSessionHasNoErrors();

        $c = Contact::withoutGlobalScopes()->where('email', 'v1@example.com')->firstOrFail();
        $this->assertSame($this->agency->id, (int) \App\Models\Branch::withoutGlobalScopes()->findOrFail($c->branch_id)->agency_id, 'never a branch of the browsing staff member\'s agency');
    }

    // ── staff: the Enquiries screen ────────────────────────────────────────

    private function enquiry(array $extra = []): AuctionEnquiry
    {
        return AuctionEnquiry::withoutAgencyStamping(fn () => AuctionEnquiry::create(array_merge([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'lot_id' => $this->lot->id, 'property_id' => $this->lot->property_id,
            'name' => 'Buyer One', 'email' => 'one@example.com', 'message' => 'Is it still available?', 'status' => 'new',
            'assigned_user_id' => $this->admin->id, 'consent_popia_at' => now(),
        ], $extra)));
    }

    public function test_the_enquiries_screen_lists_searches_filters_and_shows_an_empty_state(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('corex.auctions.enquiries.index'))->assertOk()->assertSee('No enquiries yet');

        $this->enquiry(['name' => 'Alice Zulu', 'email' => 'alice@example.com']);
        $this->enquiry(['name' => 'Bob Smith', 'email' => 'bob@example.com', 'status' => 'closed']);

        $this->get(route('corex.auctions.enquiries.index'))->assertOk()->assertSee('Alice Zulu')->assertSee('Bob Smith');
        $this->get(route('corex.auctions.enquiries.index', ['search' => 'alice']))->assertSee('Alice Zulu')->assertDontSee('Bob Smith');
        $this->get(route('corex.auctions.enquiries.index', ['status' => 'closed']))->assertSee('Bob Smith')->assertDontSee('Alice Zulu');
        $this->get(route('corex.auctions.enquiries.index', ['search' => 'zzz']))->assertSee('No enquiries match these filters');
    }

    public function test_an_enquiry_is_assigned_marked_contacted_archived_and_restored(): void
    {
        $this->actingAs($this->admin);
        $e = $this->enquiry(['assigned_user_id' => null]);

        $this->put(route('corex.auctions.enquiries.update', $e->id), ['assigned_user_id' => $this->admin->id, 'status' => 'contacted'])->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame($this->admin->id, (int) $e->assigned_user_id);
        $this->assertSame('contacted', $e->status);
        $this->assertNotNull($e->contacted_at);

        $this->delete(route('corex.auctions.enquiries.archive', $e->id))->assertRedirect();
        $this->assertSoftDeleted('auction_enquiries', ['id' => $e->id]);
        $this->get(route('corex.auctions.enquiries.index', ['archived' => 1]))->assertSee('Buyer One');

        $this->post(route('corex.auctions.enquiries.restore', $e->id))->assertRedirect();
        $this->assertNull($e->fresh()->deleted_at);
    }

    public function test_an_enquiry_cannot_be_assigned_to_someone_outside_the_agency(): void
    {
        $this->actingAs($this->admin);
        $e = $this->enquiry();
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid()]);
        $stranger = \App\Models\User::withoutAgencyStamping(fn () => \App\Models\User::factory()->create(['agency_id' => $rival->id, 'role' => 'agent']));

        $this->put(route('corex.auctions.enquiries.update', $e->id), ['assigned_user_id' => $stranger->id])->assertSessionHasErrors('assigned_user_id');
        $this->assertSame($this->admin->id, (int) $e->fresh()->assigned_user_id);
    }

    public function test_enquiry_visibility_is_own_branch_agency_and_other_agencies_are_unreachable(): void
    {
        // the real grants, not the test-suite's open posture: an agent must not hold view_all
        \Illuminate\Support\Facades\Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mine = $this->enquiry(['name' => 'Assigned To Agent', 'assigned_user_id' => $agent->id]);
        $notMine = $this->enquiry(['name' => 'Assigned To Admin', 'assigned_user_id' => $this->admin->id]);

        $visible = AuctionEnquiry::visibleTo($agent)->pluck('id')->all();
        $this->assertContains($mine->id, $visible);
        $this->assertNotContains($notMine->id, $visible, 'an agent sees only enquiries assigned to them or on their listings');
        $this->assertCount(2, AuctionEnquiry::visibleTo($this->admin)->get());

        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv2-'.uniqid()]);
        $foreign = AuctionEnquiry::withoutAgencyStamping(fn () => AuctionEnquiry::create(['agency_id' => $rival->id, 'name' => 'Foreign', 'status' => 'new']));
        $this->actingAs($this->admin);
        $this->put(route('corex.auctions.enquiries.update', $foreign->id), ['status' => 'closed'])->assertNotFound();
        $this->get(route('corex.auctions.enquiries.index'))->assertDontSee('Foreign');
    }
}
