<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Http\Controllers\Api\V1\ClientLandlordRentalsController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientAccessLog;
use App\Models\ClientOtp;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\ClientAuthService;
use App\Services\Rentals\RentalPortalScopeService;
use App\Support\PortalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Rental portal security fixes - audit 2026-10-19 (/root/rental-e2e-2026-10-19.md section 2, Security group). One test per item, each through
 * the real route or service the item is about. Spec: .ai/specs/rental-portal-access.md "Security fixes 2026-10-19", client-auth.md.
 */
final class PortalSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Portal-Sec-2026!';

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('x');
        $this->agency = $this->makeAgency('Sec Agency');
        $this->branch = Branch::where('agency_id', $this->agency->id)->first();
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────────────────────

    private function makeAgency(string $name): Agency
    {
        $agency = Agency::create(['name' => $name, 'slug' => str()->slug($name . '-' . uniqid())]);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Main', 'code' => 'M-' . $agency->id, 'is_active' => true]);

        return $agency;
    }

    private function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');

        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branchId,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'test+' . uniqid() . '@example.com',
        ], $overrides));
    }

    private function makeProperty(?Agency $agency = null): Property
    {
        $agency ??= $this->agency;

        return Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $this->agent->id, 'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'title' => 'Unit ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function makeLease(Property $property, string $status = 'active'): Lease
    {
        return Lease::withoutGlobalScopes()->create([
            'agency_id' => $property->agency_id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => $status, 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
    }

    /** A login with a password, already linked to a fresh contact in $agency. @return array{0:ClientUser,1:Contact} */
    private function login(?Agency $agency = null, array $contact = []): array
    {
        $agency ??= $this->agency;
        $login = ClientUser::create(['email' => 'p-' . uniqid() . '@example.com', 'password' => Hash::make(self::PASSWORD), 'password_set_at' => now(), 'current_agency_id' => $agency->id, 'created_by_agency_id' => $agency->id]);
        $c = $this->makeContact($agency, ['email' => $login->email, 'client_user_id' => $login->id] + $contact);

        return [$login, $c];
    }

    /** A real Sanctum token (like a phone holds), optionally with its own agency. */
    private function token(ClientUser $login, ?int $agencyId = null, ?\DateTimeInterface $expires = null, string $name = 'Phone'): array
    {
        $new = $login->createToken($name, ['client'], $expires ?? now()->addDays(30));
        if ($agencyId) {
            $new->accessToken->forceFill(['agency_id' => $agencyId])->save();
        }

        return [$new->plainTextToken, $new->accessToken];
    }

    private function as(string $plain): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain);
    }

    private function setting(array $values, ?Agency $agency = null): void
    {
        RentalPortalSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => ($agency ?? $this->agency)->id], $values);
    }

    // ── settings ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_five_new_timings_default_sensibly_and_are_clamped(): void
    {
        $id = $this->agency->id;
        $this->assertSame(7, RentalPortalSetting::portalRememberDaysFor($id));
        $this->assertSame(30, RentalPortalSetting::portalAppTokenDaysFor($id));
        $this->assertSame(120, RentalPortalSetting::portalApiPerMinuteFor($id));
        $this->assertSame(120, RentalPortalSetting::portalPhotoLinkMinutesFor($id));
        $this->assertSame(60, RentalPortalSetting::portalLinkDaysFor($id));
        $this->assertSame(7, RentalPortalSetting::portalRememberDaysFor(null), 'no agency = the default, never an error');

        $this->setting(['portal_remember_days' => 3, 'portal_app_token_days' => 999, 'portal_api_per_minute' => 1, 'portal_photo_link_minutes' => 30, 'portal_link_days' => 10]);
        $this->assertSame(3, RentalPortalSetting::portalRememberDaysFor($id));
        $this->assertSame(90, RentalPortalSetting::portalAppTokenDaysFor($id), 'above the ceiling is held to it');
        $this->assertSame(30, RentalPortalSetting::portalApiPerMinuteFor($id), 'below the floor is lifted to it');
        $this->assertSame(30, RentalPortalSetting::portalPhotoLinkMinutesFor($id));
        $this->assertSame(10, RentalPortalSetting::portalLinkDaysFor($id));
    }

    public function test_settings_page_savers_store_each_timing_and_a_blank_post_leaves_it_alone(): void
    {
        $this->actingAs($this->agent);
        $this->post(route('corex.settings.rental-portal.portal-remember-days'), ['portal_remember_days' => 5])->assertRedirect();
        $this->post(route('corex.settings.rental-portal.portal-api-per-minute'), ['portal_api_per_minute' => 200])->assertRedirect();
        $this->assertSame(5, RentalPortalSetting::portalRememberDaysFor($this->agency->id));
        $this->assertSame(200, RentalPortalSetting::portalApiPerMinuteFor($this->agency->id));

        // the wizard step may post a subset: a field that is absent (or blank) must not wipe what is saved
        $this->post(route('corex.settings.rental-portal.portal-remember-days'), [])->assertRedirect();
        $this->post(route('corex.settings.rental-portal.portal-remember-days'), ['portal_remember_days' => ''])->assertRedirect();
        $this->assertSame(5, RentalPortalSetting::portalRememberDaysFor($this->agency->id));

        $this->post(route('corex.settings.rental-portal.portal-link-days'), ['portal_link_days' => 9999])->assertSessionHasErrors('portal_link_days');
        $this->get(route('corex.settings.rental-portal.edit'))->assertOk()->assertSee('Portal sign-in and security')->assertSee('portal_api_per_minute');
    }

    public function test_the_wizard_offers_the_five_timings(): void
    {
        $keys = collect(config('agency-onboarding-copy'))->flatMap(fn ($step) => collect($step['controls'] ?? [])->pluck('key'))->all();
        foreach (['portal_remember_days', 'portal_app_token_days', 'portal_api_per_minute', 'portal_photo_link_minutes', 'portal_link_days'] as $key) {
            $this->assertContains($key, $keys, "$key must reach the Setup Wizard (non-negotiable #10a)");
        }
    }

    // ── D9 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d9_a_portal_token_never_gets_through_the_staff_api(): void
    {
        [$login] = $this->login();
        [$plain] = $this->token($login);

        // Sanctum's own provider check already refuses a ClientUser token on the staff guard (401) - never a 500
        $this->assertContains($this->as($plain)->getJson('/api/v1/logged-user')->status(), [401, 403]);
    }

    public function test_d9_the_app_access_gate_itself_answers_403_for_a_portal_user_not_a_500(): void
    {
        [$login] = $this->login();
        $request = \Illuminate\Http\Request::create('/api/v1/logged-user');
        $request->setUserResolver(fn () => $login);

        try {
            app(\App\Http\Middleware\EnsureAppAccess::class)->handle($request, fn () => response('through'));
            $this->fail('a portal user must not pass the staff gate');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Staff app token required.', $e->getMessage());
        }
    }

    // ── D1 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d1_confirming_an_email_never_links_a_tenancy_party_but_still_links_a_buyer(): void
    {
        $property = $this->makeProperty();
        $lease = $this->makeLease($property);
        $tenant = $this->makeContact($this->agency, ['email' => 'mistyped@example.com']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $owner = $this->makeContact($this->agency, ['email' => 'owner-typo@example.com']);
        $property->contacts()->attach($owner->id, ['role' => 'landlord']);
        $buyer = $this->makeContact($this->agency, ['email' => 'buyer@example.com']);

        foreach (['mistyped@example.com', 'owner-typo@example.com', 'buyer@example.com'] as $email) {
            ClientOtp::create(['email' => $email, 'purpose' => 'activation', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
            $this->postJson('/api/v1/client-auth/otp/verify', ['email' => $email, 'code' => '123456'])->assertOk();
        }

        $this->assertNull($tenant->fresh()->client_user_id, 'a tenant is linked by the agency, never by a typed address');
        $this->assertNull($owner->fresh()->client_user_id, 'an owner of a let property too');
        $this->assertNotNull($buyer->fresh()->client_user_id, 'a buyer / seller keeps the long-standing email-code way in');
        $this->assertTrue(ClientAccessLog::where('event', 'contact_link_withheld')->where('contact_id', $tenant->id)->exists(), 'the withheld link is audited');
    }

    public function test_d1_a_person_the_agency_has_not_given_access_is_told_so_not_asked_to_pick_an_agency(): void
    {
        $login = ClientUser::create(['email' => 'nobody@example.com', 'password' => Hash::make(self::PASSWORD), 'password_set_at' => now()]);
        [$plain] = $this->token($login);

        $this->as($plain)->getJson('/api/v1/client/rentals/overview')->assertStatus(403)->assertJson(['code' => 'no_portal_access']);
    }

    public function test_d1_the_agency_grant_still_works(): void
    {
        $property = $this->makeProperty();
        $lease = $this->makeLease($property);
        $tenant = $this->makeContact($this->agency, ['email' => 'granted@example.com']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);

        $result = app(\App\Services\Rentals\RentalPortalAccessService::class)->attach($tenant);
        $this->assertSame('created', $result['outcome']);
        $this->assertSame($result['client_user']->id, $tenant->fresh()->client_user_id);
    }

    // ── D2 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d2_lookup_is_throttled_per_address_asked_about_and_per_origin_per_hour(): void
    {
        config(['clientauth.lookup.per_hour_email' => 2, 'clientauth.lookup.per_minute_ip' => 100, 'clientauth.lookup.per_hour_ip' => 4]);
        RateLimiter::clear('clientauth.lookup:127.0.0.1');
        RateLimiter::clear('clientauth.lookup.hour:127.0.0.1');
        RateLimiter::clear('clientauth.lookup.email:' . sha1('probe@example.com'));

        $this->postJson('/api/v1/client-auth/lookup', ['email' => 'probe@example.com'])->assertOk();
        $this->postJson('/api/v1/client-auth/lookup', ['email' => 'probe@example.com'])->assertOk();
        $this->postJson('/api/v1/client-auth/lookup', ['email' => 'probe@example.com'])->assertStatus(429);

        // a crawl of many different addresses from one place runs out on the hourly origin cap
        $this->postJson('/api/v1/client-auth/lookup', ['email' => 'a1@example.com'])->assertOk();
        $this->postJson('/api/v1/client-auth/lookup', ['email' => 'a2@example.com'])->assertOk();
        $this->postJson('/api/v1/client-auth/lookup', ['email' => 'a3@example.com'])->assertStatus(429);
    }

    // ── D3 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d3_a_bare_email_never_earns_the_agency_header_a_sealed_link_does(): void
    {
        [$login, $contact] = $this->login();

        $bare = $this->get('/portal?email=' . urlencode($login->email))->assertOk();
        $this->assertNull($bare->viewData('branding'), 'a hand-typed address is never looked up for an agency header');
        $this->assertSame($login->email, $bare->viewData('linkedEmail'), 'it still pre-fills the sign-in');

        $link = PortalLink::forContact($contact, PortalLink::VIEW_TENANT);
        $response = $this->get($link)->assertOk();
        $this->assertNotNull($response->viewData('branding'), 'a link this system sealed shows the agency header before sign-in');
        $this->assertSame($this->agency->name, $response->viewData('branding')['name']);
    }

    // ── D4 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d4_a_phone_token_lasts_the_agency_setting_from_its_last_use(): void
    {
        [$login] = $this->login();
        $this->setting(['portal_app_token_days' => 10]);
        [$plain, $token] = $this->token($login, $this->agency->id, now()->addDays(2));

        $this->as($plain)->getJson('/api/v1/client/me')->assertOk();

        $expires = $token->fresh()->expires_at;
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $expires->timestamp, 120, 'renewed to the agency\'s own number of days');
    }

    public function test_d4_a_web_tab_token_is_never_extended(): void
    {
        [$login] = $this->login();
        [$plain, $token] = $this->token($login, $this->agency->id, now()->addHours(3), \App\Services\PortalTabSession::tokenName());

        $this->as($plain)->getJson('/api/v1/client/me')->assertOk();

        $this->assertEqualsWithDelta(now()->addHours(3)->timestamp, $token->fresh()->expires_at->timestamp, 5);
    }

    public function test_d4_a_new_password_ends_every_other_token_the_phone_app_included(): void
    {
        [$login] = $this->login();
        [$phone, $phoneToken] = $this->token($login, $this->agency->id);
        [$laptop, $laptopToken] = $this->token($login, $this->agency->id, null, \App\Services\PortalTabSession::tokenName());

        $this->as($laptop)->postJson('/api/v1/client-auth/password/change', ['current_password' => self::PASSWORD, 'password' => 'Brand-New-2026!', 'password_confirmation' => 'Brand-New-2026!'])->assertOk();

        $this->assertNull(PersonalAccessToken::find($phoneToken->id), 'the phone app is signed out by the password change');
        $this->assertNotNull(PersonalAccessToken::find($laptopToken->id), 'the device making the change stays signed in');
    }

    // ── D5 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d5_two_devices_of_one_person_keep_their_own_agency(): void
    {
        $other = $this->makeAgency('Second Agency');
        [$login] = $this->login();
        $this->makeContact($other, ['email' => $login->email, 'client_user_id' => $login->id]);
        [$phone] = $this->token($login);
        [$laptop] = $this->token($login);

        $this->as($phone)->postJson('/api/v1/client-auth/agency/select', ['agency_id' => $this->agency->id])->assertOk();
        $this->as($laptop)->postJson('/api/v1/client-auth/agency/select', ['agency_id' => $other->id])->assertOk();

        $this->assertSame($this->agency->id, $this->as($phone)->getJson('/api/v1/client/me')->json('client.current_agency_id'), 'the phone is not flipped by the laptop');
        $this->assertSame($other->id, $this->as($laptop)->getJson('/api/v1/client/me')->json('client.current_agency_id'));
    }

    // ── D7 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d7_the_portal_switch_is_judged_for_the_agency_the_person_is_adopted_into(): void
    {
        $this->setting(['tenant_portal_enabled' => false]);
        $login = ClientUser::create(['email' => 'adopt@example.com', 'password' => Hash::make(self::PASSWORD), 'password_set_at' => now()]);   // no agency chosen yet
        $this->makeContact($this->agency, ['email' => $login->email, 'client_user_id' => $login->id]);
        [$plain] = $this->token($login);

        $this->as($plain)->getJson('/api/v1/client/rentals/overview')->assertStatus(403);   // before: the default "on" let this first call through
    }

    public function test_d7_a_person_on_two_agencies_with_no_choice_is_asked_to_choose(): void
    {
        $other = $this->makeAgency('Another Agency');
        $login = ClientUser::create(['email' => 'two@example.com', 'password' => Hash::make(self::PASSWORD), 'password_set_at' => now()]);
        $this->makeContact($this->agency, ['email' => $login->email, 'client_user_id' => $login->id]);
        $this->makeContact($other, ['email' => $login->email, 'client_user_id' => $login->id]);
        [$plain] = $this->token($login);

        $this->as($plain)->getJson('/api/v1/client/rentals/overview')->assertStatus(409)->assertJson(['message' => 'Select an agency first.']);
    }

    // ── D8 ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d8_the_link_reference_is_opaque_and_expires_on_the_agency_setting(): void
    {
        [, $contact] = $this->login();
        $this->setting(['portal_link_days' => 5]);

        $url = PortalLink::forContact($contact, PortalLink::VIEW_OWNER);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('e1.', $q['r']);
        $this->assertStringNotContainsString('@', $q['r']);
        $this->assertStringNotContainsString(base64_encode($contact->email), $url, 'the address is not readable in the link');

        $parsed = PortalLink::parse($q['r']);
        $this->assertSame($contact->email, $parsed['email']);
        $this->assertSame($contact->id, $parsed['contact_id']);
        $this->assertArrayNotHasKey('legacy', $parsed, 'a sealed reference is never flagged legacy');

        $this->travel(4)->days();
        $this->assertNotNull(PortalLink::parse($q['r']));
        $this->travel(2)->days();
        $this->assertNull(PortalLink::parse($q['r']), 'past the agency\'s days the reference is no longer recognised');
        $this->travelBack();
    }

    public function test_d8_a_tampered_or_foreign_reference_is_refused_and_an_old_unsealed_one_is_marked_legacy(): void
    {
        [, $contact] = $this->login();
        parse_str((string) parse_url(PortalLink::forContact($contact, PortalLink::VIEW_TENANT), PHP_URL_QUERY), $q);

        $this->assertNull(PortalLink::parse(substr($q['r'], 0, -4) . 'AAAA'), 'a tampered reference');
        $this->assertNull(PortalLink::parse('e1.not-a-real-seal'));
        $this->assertNull(PortalLink::parse(str_repeat('x', 1300)));

        // the pre-2026-10-19 form: base64(json) + '.' + HMAC. Still read for the pre-fill, never as a sign-in
        $payload = rtrim(strtr(base64_encode(json_encode(['c' => $contact->id, 'e' => $contact->email])), '+/', '-_'), '=');
        $sig = substr(hash_hmac('sha256', 'portal-link|' . $payload, (string) config('app.key')), 0, 32);
        $legacy = PortalLink::parse($payload . '.' . $sig);
        $this->assertTrue($legacy['legacy']);
        $this->assertSame($contact->email, $legacy['email']);
    }

    // ── D10 ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d10_a_portal_person_is_slowed_at_the_agency_setting(): void
    {
        [$login] = $this->login();
        $this->setting(['portal_api_per_minute' => 30]);
        [$plain] = $this->token($login, $this->agency->id);
        RateLimiter::clear('client:' . $login->id);

        $status = null;
        for ($i = 0; $i < 31; $i++) {
            $status = $this->as($plain)->getJson('/api/v1/client/me')->status();
        }
        $this->assertSame(429, $status, 'the 31st request in a minute is refused when the agency says 30');
    }

    // ── D11 ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d11_a_code_or_database_fault_never_reaches_the_owner_as_text(): void
    {
        $controller = app(ClientLandlordRentalsController::class);
        $method = new \ReflectionMethod($controller, 'decisionFailure');
        $method->setAccessible(true);

        $leak = $method->invoke($controller, new \RuntimeException('SQLSTATE[HY000]: secret column rental_approvals.evidence_text'));
        $this->assertSame(500, $leak->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $leak->getContent());

        $rule = $method->invoke($controller, new \LogicException('This is not waiting for your decision any more.'));
        $this->assertSame(422, $rule->getStatusCode());
        $this->assertStringContainsString('not waiting for your decision', $rule->getContent());
    }

    // ── D12 ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d12_the_remembered_browser_cookie_lives_the_agency_days_not_the_12_hour_tab_token(): void
    {
        [$login] = $this->login();
        $this->setting(['portal_remember_days' => 3]);

        $response = $this->withHeaders(['Origin' => 'http://localhost'])->postJson('/api/v1/client-auth/login', ['email' => $login->email, 'password' => self::PASSWORD, 'tab' => true])->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'portal_logins');

        $this->assertNotNull($cookie, 'the browser remembers the sign-in');
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $cookie->getExpiresTime(), 120);
    }

    public function test_d12_default_is_seven_days(): void
    {
        [$login] = $this->login();

        $response = $this->withHeaders(['Origin' => 'http://localhost'])->postJson('/api/v1/client-auth/login', ['email' => $login->email, 'password' => self::PASSWORD, 'tab' => true])->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'portal_logins');

        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 120);
    }

    // ── D13 / D17 ───────────────────────────────────────────────────────────────────────────────────────────────

    private function tenantWithWorkOrders(string $leaseStatus = 'active'): array
    {
        $property = $this->makeProperty();
        $lease = $this->makeLease($property, $leaseStatus);
        [$login, $tenant] = $this->login();
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $mk = fn (string $title) => RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id, 'lease_id' => $lease->id,
            'reported_by_type' => 'agent', 'reported_by_user_id' => $this->agent->id, 'title' => $title, 'description' => $title,
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);

        return [$login, $tenant, $property, $lease, $mk('Tenant fault job'), $mk('Owner-raised job')];
    }

    public function test_d13_a_tenant_never_sees_a_work_order_that_came_from_an_owner_raised_fault(): void
    {
        [$login, $tenant, $property, $lease, $own, $ownerRaised] = $this->tenantWithWorkOrders();
        RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_LANDLORD, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Owner request', 'description' => 'x', 'status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'rental_work_order_id' => $ownerRaised->id,
        ]);
        [$plain] = $this->token($login, $this->agency->id);

        $ids = collect($this->as($plain)->getJson('/api/v1/client/rentals/work-orders')->assertOk()->json('work_orders'))->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($ownerRaised->id, $ids);
        $this->as($plain)->getJson('/api/v1/client/rentals/work-orders/' . $ownerRaised->id)->assertNotFound();
        $this->as($plain)->getJson('/api/v1/client/rentals/work-orders/' . $own->id)->assertOk();
    }

    public function test_d17_a_tenant_whose_lease_has_ended_may_read_but_not_act_on_a_work_order(): void
    {
        [$login, , , , $order] = $this->tenantWithWorkOrders('expired');
        [$plain] = $this->token($login, $this->agency->id);

        $this->as($plain)->getJson('/api/v1/client/rentals/work-orders/' . $order->id)->assertOk();   // history stays readable
        $this->as($plain)->postJson('/api/v1/client/rentals/work-orders/' . $order->id . '/progress', ['action' => 'started'])->assertNotFound();
        $this->as($plain)->postJson('/api/v1/client/rentals/work-orders/' . $order->id . '/confirm', ['fixed' => true])->assertNotFound();
        $this->as($plain)->postJson('/api/v1/client/rentals/work-orders/' . $order->id . '/completion-response', ['fixed' => true])->assertNotFound();
    }

    public function test_d17_a_tenant_on_a_live_lease_still_reaches_the_actions(): void
    {
        [$login, $tenant, , , $order] = $this->tenantWithWorkOrders('active');

        $this->assertNotNull(app(RentalPortalScopeService::class)->tenantActionableWorkOrder($tenant, $order->id));
    }

    // ── D16 ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_d16_a_registered_sale_ends_the_old_owners_scope_going_forward(): void
    {
        $property = $this->makeProperty();
        $other = $this->makeProperty();
        [, $owner] = $this->login();
        $this->linkOwner($property, $owner, now()->subYear());
        $this->linkOwner($other, $owner, now()->subYear());
        $scope = app(RentalPortalScopeService::class);

        $this->assertEqualsCanonicalizing([$property->id, $other->id], $scope->landlordPropertyIds($owner));
        $this->assertTrue($scope->isLandlord($owner));

        $this->registerSale($property, $owner, now()->subDay()->toDateString());

        $this->assertSame([$other->id], $scope->landlordPropertyIds($owner), 'the sold property leaves the old owner\'s scope; the other stays');
        $this->assertNull($scope->landlordProperty($owner, $property->id));
    }

    public function test_d16_a_declined_or_unregistered_sale_changes_nothing_and_a_fresh_link_after_the_sale_counts(): void
    {
        $property = $this->makeProperty();
        [, $owner] = $this->login();
        $this->linkOwner($property, $owner, now()->subYear());
        $scope = app(RentalPortalScopeService::class);

        $this->registerSale($property, $owner, null);            // granted, not registered yet
        $this->assertSame([$property->id], $scope->landlordPropertyIds($owner));

        $declined = $this->registerSale($property, $owner, now()->subDay()->toDateString(), 'D');
        $this->assertSame([$property->id], $scope->landlordPropertyIds($owner), 'a declined deal never took the property');
        $declined->delete();

        $this->registerSale($property, $owner, now()->subDays(10)->toDateString());
        $this->assertSame([], $scope->landlordPropertyIds($owner));

        // a link made AFTER the sale (a new row, stamped later than the registration date) is a new ownership and counts
        DB::table('contact_property')->where('contact_id', $owner->id)->where('property_id', $property->id)->update(['created_at' => now()]);
        $this->assertSame([$property->id], $scope->landlordPropertyIds($owner));
    }

    /** Link an owner to a property as landlord, at a stated moment (the framework stamps created_at itself on attach, so set it after). */
    private function linkOwner(Property $property, Contact $owner, \Illuminate\Support\Carbon $at): void
    {
        $property->contacts()->attach($owner->id, ['role' => 'landlord']);
        DB::table('contact_property')->where('contact_id', $owner->id)->where('property_id', $property->id)->whereNull('deleted_at')->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function registerSale(Property $property, Contact $seller, ?string $registrationDate, string $status = 'G'): Deal
    {
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'accepted_status' => $status, 'registration_date' => $registrationDate, 'listing_agent_id' => $this->agent->id,
            'reference' => 'REG-' . uniqid(), 'deal_no' => random_int(1000, 99999), 'deal_type' => 'cash',
        ]));
        if (! DB::table('deal_properties')->where('deal_id', $deal->id)->exists()) {
            DB::table('deal_properties')->insert(['deal_id' => $deal->id, 'property_id' => $property->id, 'is_primary' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $seller->id, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        return $deal;
    }
}
