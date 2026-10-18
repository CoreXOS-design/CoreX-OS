<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientAccessLog;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\PortalDemoSignin;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Support\PortalDemoSigninGate;
use App\Support\PortalLink;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PER-TAB PORTAL SIGN-IN - .ai/specs/rental-portal-access.md §28.5 (Johan's ruling: an owner link in one tab and a tenant link in another tab of the
 * SAME browser both stay signed in, for any two logins). Each web portal tab holds its own login as a token and sends it as a bearer; the browser's
 * shared session cookie never answers in its place. Requests with no tab token (the mobile app) behave as before.
 */
final class PortalTabSigninTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = ['Origin' => 'http://localhost'];
    private const PASSWORD = 'Tab-Signin-2026!';

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Tab Agency', 'slug' => 'tb-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    /** A rental: an owner and a tenant, each with their OWN login (password set). @return array{owner:ClientUser, tenant:ClientUser, ownerContact:Contact, tenantContact:Contact, lease:Lease, property:Property} */
    private function rental(string $label): array
    {
        $property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => "Unit {$label}", 'status' => 'active', 'listing_type' => 'rental']);
        $lease = Lease::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(11)->toDateString()]);
        $make = function (string $role) use ($label) {
            $login = ClientUser::create(['email' => strtolower("{$role}-{$label}-") . uniqid() . '@example.invalid', 'password' => Hash::make(self::PASSWORD), 'password_set_at' => now(), 'current_agency_id' => $this->agency->id, 'created_by_agency_id' => $this->agency->id]);
            $contact = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => ucfirst($role), 'last_name' => $label, 'email' => $login->email, 'client_user_id' => $login->id]);

            return [$login, $contact];
        };
        [$owner, $ownerContact] = $make('owner');
        [$tenant, $tenantContact] = $make('tenant');
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantContact->id, 'is_primary' => true]);
        $property->contacts()->attach($ownerContact->id, ['role' => 'landlord']);

        return compact('owner', 'tenant', 'ownerContact', 'tenantContact', 'lease', 'property');
    }

    /** One browser request (like Chrome sends it): Origin set, the "logins remembered here" cookie exactly as the server issued it, optionally a tab's bearer. */
    public function browser(string $method, string $uri, array $data = [], ?string $cookie = null, ?string $bearer = null, ?string $role = null, bool $freshGuards = true)
    {
        if ($freshGuards) {
            $this->app['auth']->forgetGuards();   // every request is a new HTTP request: no guard remembers the last one's user
        }
        $server = ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        if ($role) {
            $server['HTTP_X_PORTAL_ROLE'] = $role;
        }

        return $this->call($method, $uri, [], $cookie !== null ? ['portal_logins' => $cookie] : [], [], $server, $method === 'GET' ? null : json_encode($data));
    }

    /** A browser tab signs in with its password. Returns [token, the browser's remembered-logins cookie (as issued, encrypted) after this sign-in]. */
    private function tabSignIn(ClientUser $login, ?string $browserCookie = null): array
    {
        $res = $this->browser('POST', '/api/v1/client-auth/login', ['email' => $login->email, 'password' => self::PASSWORD, 'tab' => true], $browserCookie)->assertOk();
        $token = $res->json('token');
        $this->assertNotEmpty($token, 'a web tab is handed a token of its own');

        return [$token, $this->rememberedCookie($res) ?? $browserCookie];
    }

    /** The remembered-logins cookie a response sets, exactly as the browser would store and send it back. */
    private function rememberedCookie($res): ?string
    {
        foreach ($res->baseResponse->headers->getCookies() as $c) {
            if ($c->getName() === 'portal_logins') {
                return $c->getValue();
            }
        }

        return null;
    }

    /** The login ids inside an issued remembered-logins cookie (each entry is {i: id, t: signed-in-at, v: password fingerprint}). */
    private function idsIn(string $issued): array
    {
        $entries = json_decode(CookieValuePrefix::remove(app('encrypter')->decrypt($issued, false)), true);

        return array_map(fn ($e) => $e['i'], $entries);
    }

    /** A request as one tab: its own token, and (optionally) the side it is fixed to. */
    private function asTab(string $token, ?string $role = null)
    {
        $t = $this;

        return new class($t, $token, $role) {
            public function __construct(private $t, private string $token, private ?string $role) {}

            public function getJson(string $uri)
            {
                return $this->t->browser('GET', $uri, [], null, $this->token, $this->role);
            }

            public function postJson(string $uri, array $d = [])
            {
                return $this->t->browser('POST', $uri, $d, null, $this->token, $this->role);
            }
        };
    }

    public function test_an_owner_and_a_tenant_in_two_tabs_of_one_browser_both_stay_signed_in(): void
    {
        $r = $this->rental('A');
        [$ownerTab, $cookie] = $this->tabSignIn($r['owner']);
        [$tenantTab, $cookie] = $this->tabSignIn($r['tenant'], $cookie);   // the SAME browser, a second tab

        // the first tab is NOT signed out by the second tab signing in - the bug Johan hit in front of a client
        $this->asTab($ownerTab)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);
        $this->asTab($tenantTab)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['tenant']->id);
        // and again, in the order a person flips between them
        $this->asTab($ownerTab)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);

        // each tab sees its own side's data and nobody else's
        $this->asTab($ownerTab, 'owner')->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->assertJsonCount(1, 'properties');
        $this->asTab($tenantTab, 'tenant')->getJson('/api/v1/client/rentals/leases')->assertOk()->assertJsonCount(1, 'leases');
        $this->asTab($tenantTab, 'tenant')->getJson('/api/v1/client/rentals/landlord/properties')->assertStatus(403)->assertJsonPath('role_mismatch', true);
        $this->asTab($ownerTab, 'owner')->getJson('/api/v1/client/rentals/leases')->assertStatus(403)->assertJsonPath('role_mismatch', true);
    }

    public function test_the_owner_of_one_lease_and_the_tenant_of_another_work_side_by_side(): void
    {
        $a = $this->rental('A');
        $b = $this->rental('B');
        [$ownerOfA, $cookie] = $this->tabSignIn($a['owner']);
        [$tenantOfB, $cookie] = $this->tabSignIn($b['tenant'], $cookie);

        $own = $this->asTab($ownerOfA, 'owner')->getJson('/api/v1/client/rentals/landlord/properties')->assertOk();
        $this->assertSame([$a['property']->id], collect($own->json('properties'))->pluck('id')->all());
        $ten = $this->asTab($tenantOfB, 'tenant')->getJson('/api/v1/client/rentals/leases')->assertOk();
        $this->assertSame([$b['lease']->id], collect($ten->json('leases'))->pluck('id')->all());
        // a third tab, still the owner of A, is unchanged by the others
        $this->asTab($ownerOfA, 'owner')->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->assertJsonCount(1, 'properties');
    }

    public function test_one_login_that_is_both_owner_and_tenant_can_hold_each_side_in_its_own_tab(): void
    {
        $r = $this->rental('C');
        // one person is the owner of this property AND the tenant on the lease: ONE login, two contacts
        $r['tenantContact']->forceFill(['client_user_id' => $r['owner']->id])->saveQuietly();
        [$tab1] = $this->tabSignIn($r['owner']);
        [$tab2] = $this->tabSignIn($r['owner']);   // a second tab, same person

        $this->assertNotSame($tab1, $tab2, 'every tab has its own token');
        $this->asTab($tab1, 'owner')->getJson('/api/v1/client/rentals/landlord/properties')->assertOk();
        $this->asTab($tab2, 'tenant')->getJson('/api/v1/client/rentals/leases')->assertOk();
        // each tab is held to its side
        $this->asTab($tab1, 'owner')->getJson('/api/v1/client/rentals/leases')->assertStatus(403)->assertJsonPath('role_mismatch', true);
        $this->asTab($tab2, 'tenant')->getJson('/api/v1/client/rentals/landlord/properties')->assertStatus(403)->assertJsonPath('role_mismatch', true);
    }

    public function test_signing_out_in_one_tab_does_not_sign_out_the_other(): void
    {
        $r = $this->rental('D');
        [$ownerTab, $cookie] = $this->tabSignIn($r['owner']);
        [$tenantTab, $cookie] = $this->tabSignIn($r['tenant'], $cookie);
        $this->assertSame([$r['owner']->id, $r['tenant']->id], $this->idsIn($cookie), 'both people are remembered on this browser');

        $out = $this->browser('POST', '/api/v1/client-auth/logout', [], $cookie, $tenantTab)->assertOk();

        $this->asTab($tenantTab)->getJson('/api/v1/client/me')->assertStatus(401);   // that tab is signed out
        $this->asTab($ownerTab)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);   // the other is not

        // the tenant is no longer remembered for NEW tabs on this browser; the owner still is
        $after = $this->rememberedCookie($out);
        $this->assertSame([$r['owner']->id], $this->idsIn($after));
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['tenant']->email], $after)->assertStatus(401);
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], $after)->assertOk();
    }

    public function test_the_browsers_session_cookie_never_answers_in_place_of_a_tabs_own_login(): void
    {
        $r = $this->rental('E');
        [$tenantTab] = $this->tabSignIn($r['tenant']);

        // the browser's shared portal session belongs to somebody else (the owner, signed in the old way, or another tab)
        $this->app['auth']->forgetGuards();
        $this->actingAs($r['owner'], 'client-web');
        $this->browser('GET', '/api/v1/client/me', [], null, $tenantTab)->assertOk()->assertJsonPath('client.id', $r['tenant']->id);
    }

    public function test_a_request_with_no_tab_token_behaves_as_before(): void
    {
        $r = $this->rental('F');
        // the cookie session (older tabs) still authenticates when there is no bearer
        $this->actingAs($r['owner'], 'client-web');
        $this->browser('GET', '/api/v1/client/me', [], null, null, null, false)->assertOk()->assertJsonPath('client.id', $r['owner']->id);
        // and the mobile app's own sign-in (no Origin, no tab flag) still returns a normal long-lived token, not a tab token
        $this->app['auth']->forgetGuards();
        $mobile = $this->call('POST', '/api/v1/client-auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['email' => $r['tenant']->email, 'password' => self::PASSWORD, 'device_name' => 'Pixel']))->assertOk();
        $this->assertNotEmpty($mobile->json('token'));
        $this->assertSame('Pixel', $r['tenant']->tokens()->latest('id')->first()->name);
        // asking for a tab token without being a same-origin browser page gets the normal token too
        $this->app['auth']->forgetGuards();
        $viaApp = $this->call('POST', '/api/v1/client-auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['email' => $r['tenant']->email, 'password' => self::PASSWORD, 'tab' => true, 'device_name' => 'Pixel2']))->assertOk();
        $this->assertSame('Pixel2', $r['tenant']->tokens()->latest('id')->first()->name);
        $this->assertNotEmpty($viaApp->json('token'));
    }

    public function test_a_new_tab_for_someone_who_already_signed_in_here_needs_no_password_and_a_stranger_does(): void
    {
        $r = $this->rental('G');
        $stranger = $this->rental('H');   // made first: creating records after a portal request would run as that request's user
        [$t1, $cookie] = $this->tabSignIn($r['owner']);
        [$t2, $cookie] = $this->tabSignIn($r['tenant'], $cookie);

        // a new tab opened from the OWNER's link: named by the link, handed the owner's login
        $new = $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email, 'contact_id' => $r['ownerContact']->id], $cookie)->assertOk();
        $this->assertNotSame($t1, $new->json('token'));
        $this->asTab($new->json('token'))->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);
        // ... and the tab opened from the tenant's link gets the tenant, not the most recent person
        $other = $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['tenant']->email], $cookie)->assertOk();
        $this->asTab($other->json('token'))->getJson('/api/v1/client/me')->assertJsonPath('client.id', $r['tenant']->id);

        // a person who has NOT signed in on this browser is refused: the link alone never signs anybody in
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $stranger['owner']->email, 'contact_id' => $stranger['ownerContact']->id], $cookie)
            ->assertStatus(401)->assertJsonPath('needs_signin', true);
        // a browser that has signed nobody in has nothing to resume
        $this->browser('POST', '/api/v1/client-auth/tab-token', [])->assertStatus(401);
        // the portal page's own PROBE (every first open asks "is anyone signed in here?") gets a plain 200 "nobody yet" - never a red 401 in the console - and still no token
        $probe = $this->browser('POST', '/api/v1/client-auth/tab-token', ['probe' => true])->assertOk()->assertJsonPath('needs_signin', true)->assertJsonPath('token', null);
        $this->assertArrayNotHasKey('client', $probe->json());
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['probe' => true, 'email' => $stranger['owner']->email, 'contact_id' => $stranger['ownerContact']->id], $cookie)->assertOk()->assertJsonPath('token', null);
    }

    public function test_a_portal_session_from_before_per_tab_sign_in_is_still_honoured_for_a_new_tab(): void
    {
        $r = $this->rental('I');
        $this->actingAs($r['owner'], 'client-web');
        $res = $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], null, null, null, false)->assertOk();
        $this->asTab($res->json('token'))->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);
    }

    public function test_a_tab_token_expires(): void
    {
        $r = $this->rental('J');
        [$tab] = $this->tabSignIn($r['owner']);
        $this->travel(config('clientauth.tab.token_hours') + 1)->hours();
        $this->asTab($tab)->getJson('/api/v1/client/me')->assertStatus(401);
    }

    public function test_the_activation_token_is_not_overruled_by_another_persons_session(): void
    {
        // a first-time person sets a password while somebody else is signed in on the browser's session: the password is set for the ACTIVATION token's person
        $r = $this->rental('K');
        $fresh = ClientUser::create(['email' => 'fresh-' . uniqid() . '@example.invalid', 'current_agency_id' => $this->agency->id, 'created_by_agency_id' => $this->agency->id]);
        $activation = app(\App\Services\ClientAuthService::class)->issueActivationToken($fresh);
        $this->actingAs($r['owner'], 'client-web');
        $res = $this->browser('POST', '/api/v1/client-auth/password/set', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'tab' => true], null, $activation)->assertOk();
        $this->assertNotEmpty($res->json('token'));
        $this->assertNotNull($fresh->fresh()->password_set_at);
        $this->app['auth']->shouldUse('web');
        $this->asTab($res->json('token'))->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $fresh->id);
    }

    // ── audit 2026-10-18 A-M1: the browser's session cookie is not an activation token ─────────────────────

    public function test_a_cookie_session_cannot_set_a_new_password_without_the_current_one_and_never_500s(): void
    {
        $r = $this->rental('S1');
        $this->actingAs($r['owner'], 'client-web');
        $hash = $r['owner']->fresh()->password;
        $body = ['password' => 'Brand-New-2026!', 'password_confirmation' => 'Brand-New-2026!'];

        // no current password, and a wrong one: refused with a plain 422, and the password is untouched
        $this->browser('POST', '/api/v1/client-auth/password/set', $body, null, null, null, false)->assertStatus(422);
        $this->browser('POST', '/api/v1/client-auth/password/set', $body + ['current_password' => 'not-it'], null, null, null, false)->assertStatus(422);
        $this->assertSame($hash, $r['owner']->fresh()->password);

        // with the right current password it works (and answers normally - no 500)
        $this->browser('POST', '/api/v1/client-auth/password/set', $body + ['current_password' => self::PASSWORD], null, null, null, false)->assertOk();
        $this->assertTrue(Hash::check('Brand-New-2026!', $r['owner']->fresh()->password));
    }

    public function test_a_real_must_change_token_still_sets_the_password_without_the_current_one(): void
    {
        $r = $this->rental('S2');
        $r['owner']->forceFill(['password_must_change' => true])->save();
        $token = $r['owner']->createToken('login', ['client'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->browser('POST', '/api/v1/client-auth/password/set', ['password' => 'Brand-New-2026!', 'password_confirmation' => 'Brand-New-2026!'], null, $token)->assertOk();
        $fresh = $r['owner']->fresh();
        $this->assertTrue(Hash::check('Brand-New-2026!', $fresh->password));
        $this->assertFalse((bool) $fresh->password_must_change);
    }

    // ── audit 2026-10-18 A-L1: "logins remembered here" is bound to the password and has an absolute age ───

    public function test_changing_the_password_ends_the_remembered_login_and_revokes_the_other_tab_tokens(): void
    {
        $r = $this->rental('L1');
        [$tabA, $cookie] = $this->tabSignIn($r['owner']);
        [$tabB, $cookie] = $this->tabSignIn($r['owner'], $cookie);
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], $cookie)->assertOk();

        // an old copy of the cookie (another person's browser profile / a shared PC)
        $stale = $cookie;
        $res = $this->browser('POST', '/api/v1/client-auth/password/change', ['current_password' => self::PASSWORD, 'password' => 'Brand-New-2026!', 'password_confirmation' => 'Brand-New-2026!'], $cookie, $tabA)->assertOk();

        // the stale cookie can no longer mint a tab for them, the other tab's token is revoked, the tab that made the change keeps working
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], $stale)->assertStatus(401);
        $this->asTab($tabB)->getJson('/api/v1/client/me')->assertStatus(401);
        $this->asTab($tabA)->getJson('/api/v1/client/me')->assertOk();
        // the browser that changed it keeps being remembered, under the new password
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], $this->rememberedCookie($res))->assertOk();
    }

    public function test_a_remembered_login_has_an_absolute_maximum_age_that_reuse_does_not_extend(): void
    {
        $r = $this->rental('L1b');
        [$tab, $cookie] = $this->tabSignIn($r['owner']);
        $days = (int) config('clientauth.tab.remember_max_days');

        $this->travel($days - 1)->days();
        $reuse = $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], $cookie)->assertOk();
        $cookie2 = $this->rememberedCookie($reuse);
        $this->assertNotNull($cookie2);

        $this->travel(2)->days();   // past the absolute age counted from the real sign-in, however recently it was reused
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email], $cookie2)->assertStatus(401);
        $this->travelBack();
    }

    // ── QA1 only: every emailed link signs its tab straight in ───────────────────────────────────────────────

    private function registerGate(string $env, bool $flag): void
    {
        $this->app['env'] = $env;
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);   // the harness only skips CSRF on env "testing"; the real page sends X-XSRF-TOKEN
        config(['clientauth.demo_signin_enabled' => $flag]);
        PortalDemoSigninGate::registerRoutes();
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    private function refOf(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return $q['r'];
    }

    public function test_on_qa_an_emailed_link_signs_its_tab_in_for_any_contact_with_no_code_and_no_password(): void
    {
        $this->registerGate('qa', true);
        // two people on a lease made live during a demo: logins with NO password, never activated
        $r = $this->rental('L');
        foreach (['owner', 'tenant'] as $k) {
            $r[$k]->forceFill(['password' => null, 'password_set_at' => null])->save();
        }
        $ownerRef = $this->refOf(PortalLink::personal($r['owner']->email, PortalLink::VIEW_OWNER, [], $r['ownerContact']->id));
        $tenantRef = $this->refOf(PortalLink::personal($r['tenant']->email, PortalLink::VIEW_TENANT, [], $r['tenantContact']->id));

        $o = $this->browser('POST', '/api/v1/client-auth/qa-link-signin', ['r' => $ownerRef])->assertOk();
        $t = $this->browser('POST', '/api/v1/client-auth/qa-link-signin', ['r' => $tenantRef])->assertOk();

        // both tabs live at once
        $this->asTab($o->json('token'), 'owner')->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->assertJsonCount(1, 'properties');
        $this->asTab($t->json('token'), 'tenant')->getJson('/api/v1/client/rentals/leases')->assertOk()->assertJsonCount(1, 'leases');
        $this->asTab($o->json('token'))->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);
        $this->assertTrue(ClientAccessLog::where('client_user_id', $r['owner']->id)->where('event', 'qa_link_signin')->exists(), 'every QA link sign-in is logged');
    }

    public function test_on_qa_a_contact_with_no_login_yet_gets_one_from_the_link(): void
    {
        $this->registerGate('qa', true);
        $r = $this->rental('M');
        $email = 'newcomer-' . uniqid() . '@example.invalid';
        $c = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'New', 'last_name' => 'Comer', 'email' => $email]);
        $ref = $this->refOf(PortalLink::personal($email, PortalLink::VIEW_TENANT, [], $c->id));

        $res = $this->browser('POST', '/api/v1/client-auth/qa-link-signin', ['r' => $ref])->assertOk();
        $login = ClientUser::where('email', $email)->first();
        $this->assertNotNull($login);
        $this->assertSame($c->fresh()->client_user_id, $login->id);
        $this->assertSame($this->agency->id, (int) $login->current_agency_id, 'a login made by the link starts in its agency');
        $this->asTab($res->json('token'))->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $login->id);
    }

    public function test_on_qa_a_link_that_is_not_signed_signs_nobody_in(): void
    {
        $this->registerGate('qa', true);
        $r = $this->rental('N');
        $this->browser('POST', '/api/v1/client-auth/qa-link-signin', ['r' => 'e30.forged'])->assertStatus(422);
        // a hand-typed address with the signature of another link
        $ref = $this->refOf(PortalLink::personal($r['owner']->email, PortalLink::VIEW_OWNER, [], $r['ownerContact']->id));
        [$payload, $sig] = explode('.', $ref, 2);
        $edited = rtrim(strtr(base64_encode(json_encode(['c' => $r['tenantContact']->id, 'e' => $r['tenant']->email])), '+/', '-_'), '=') . '.' . $sig;
        $this->browser('POST', '/api/v1/client-auth/qa-link-signin', ['r' => $edited])->assertStatus(422);
    }

    public function test_outside_the_qa_gate_the_route_does_not_exist_and_first_time_sign_in_is_unchanged(): void
    {
        foreach ([['testing', true], ['staging', true], ['production', true], ['qa', false]] as [$env, $flag]) {
            $this->app['env'] = $env;
            config(['clientauth.demo_signin_enabled' => $flag]);
            $this->assertFalse(PortalDemoSigninGate::open(), "{$env}/" . var_export($flag, true));
        }
        // the route is not registered on a normal boot, so the QA endpoint answers 404 - not "forbidden"
        $this->app['env'] = 'testing';
        config(['clientauth.demo_signin_enabled' => false]);
        $this->browser('POST', '/api/v1/client-auth/qa-link-signin', ['r' => 'x.y'])->assertStatus(404);

        // a first-time person (no password) is still sent through the emailed code
        $r = $this->rental('O');
        $r['owner']->forceFill(['password' => null, 'password_set_at' => null])->save();
        $look = $this->browser('POST', '/api/v1/client-auth/lookup', ['email' => $r['owner']->email])->assertOk();
        $this->assertTrue($look->json('exists'));
        $this->assertFalse($look->json('requires_password'), 'no password yet: the page goes to "we sent you a code"');
        // and a link alone gets no tab: nobody remembered on this browser
        $this->browser('POST', '/api/v1/client-auth/tab-token', ['email' => $r['owner']->email, 'contact_id' => $r['ownerContact']->id])->assertStatus(401);
    }

    public function test_the_page_asks_for_the_qa_sign_in_only_where_the_gate_is_open(): void
    {
        $this->app['env'] = 'testing';
        config(['clientauth.demo_signin_enabled' => true]);
        $this->get('/portal')->assertOk()->assertSee('qaLinkSignin: false', false);
        $this->app['env'] = 'qa';
        $this->get('/portal')->assertOk()->assertSee('qaLinkSignin: true', false);
    }

    // ── the demo sign-in link now gives its tab a login of its own ───────────────────────────────────────

    public function test_the_demo_link_hands_the_tab_its_own_login_in_the_fragment_and_leaves_the_session_alone(): void
    {
        $this->registerGate('qa', true);
        $r = $this->rental('P');
        $token = PortalDemoSignin::issue($r['ownerContact'], 'owner', $this->agent->id);
        $res = $this->get('/demo-signin/' . $token);
        $res->assertRedirect();
        $to = $res->headers->get('Location');
        $this->assertStringContainsString('as=owner', $to);
        $this->assertMatchesRegularExpression('/#tt=\d+%7C/', $to, 'the tab login travels in the fragment (never sent to a server)');
        preg_match('/#tt=(.+)$/', $to, $m);
        $this->asTab(rawurldecode($m[1]))->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $r['owner']->id);
        $this->assertFalse(auth('client-web')->check(), 'the browser session is not signed in as the demo person');
    }
}
