<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\PortalDemoSignin;
use App\Support\PortalDemoSigninGate;
use App\Support\StaffSafeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * 13 Oct 2026 (Sherry demo): Johan opened the owner and tenant portals in the same browser as an open staff inspection page,
 * logged one out, and every photo / observation save on the staff page came back 419 until he reloaded - the portal logout
 * had replaced the session's CSRF token. Rule (.ai/specs/rental-portal-access.md "Staff page survives"): while a staff login is
 * in the session, NOTHING a portal does (login, logout, demo sign-in) may change the staff page's CSRF token.
 */
final class StaffPageSurvivesPortalTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private const PAGE_TOKEN = 'STAFF-PAGE-TOKEN-0123456789012345678901234567';

    private ClientUser $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->login = ClientUser::create([
            'email' => 'both.' . uniqid() . '@example.invalid', 'password' => Hash::make('Sup3rSecret!'), 'password_set_at' => now(),
            'activated_at' => now(), 'current_agency_id' => $this->agency->id,
        ]);
        $contact = $this->makeContact($this->agency, ['email' => $this->login->email, 'first_name' => 'Portal', 'last_name' => 'Person']);
        $contact->forceFill(['client_user_id' => $this->login->id])->saveQuietly();
    }

    private function staffPageOpen(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withSession(['_token' => self::PAGE_TOKEN]);
        $this->actingAs($this->admin, 'web');
    }

    private function token(): string
    {
        return (string) $this->app['session.store']->token();
    }

    public function test_portal_login_and_logout_leave_the_staff_pages_token_alone(): void
    {
        $origin = ['Origin' => 'http://localhost'];
        $this->staffPageOpen();

        $this->withHeaders($origin)->postJson('/api/v1/client-auth/login', ['email' => $this->login->email, 'password' => 'Sup3rSecret!'])->assertOk();
        $this->assertSame(self::PAGE_TOKEN, $this->token(), 'a portal login in the same browser must not rotate the staff token');
        $this->assertTrue(Auth::guard('web')->check());

        $this->actingAs($this->admin, 'web');
        $this->withHeaders($origin)->postJson('/api/v1/client-auth/logout')->assertOk();
        $this->assertSame(self::PAGE_TOKEN, $this->token(), 'a portal logout in the same browser must not rotate the staff token');
        $this->assertTrue(Auth::guard('web')->check(), 'the staff login stays');
    }

    public function test_the_qa_demo_sign_in_leaves_the_staff_pages_token_alone(): void
    {
        $this->app['env'] = 'qa';
        config(['clientauth.demo_signin_enabled' => true]);
        PortalDemoSigninGate::registerRoutes();
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
        $contact = Contact::withoutGlobalScopes()->where('client_user_id', $this->login->id)->first();
        $token = PortalDemoSignin::issue($contact, 'tenant');

        $this->staffPageOpen();
        $this->get('/demo-signin/' . $token)->assertRedirect();

        $this->assertSame($this->login->id, Auth::guard('client-web')->id(), 'the portal is signed in');
        $this->assertSame(self::PAGE_TOKEN, $this->token(), 'the demo sign-in must not rotate the staff token');
    }

    public function test_a_portal_only_browser_still_gets_a_fresh_token_and_the_helper_says_so(): void
    {
        $this->app['auth']->forgetGuards();
        $request = Request::create('/x', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $this->app['session.store']->put('_token', self::PAGE_TOKEN);

        $this->assertFalse(StaffSafeSession::staffInSession());
        StaffSafeSession::rotateToken($request);
        $this->assertNotSame(self::PAGE_TOKEN, $this->token(), 'no staff login: the token is replaced as before');

        $this->app['session.store']->put('_token', self::PAGE_TOKEN);
        $this->actingAs($this->admin, 'web');
        $this->assertTrue(StaffSafeSession::staffInSession());
        StaffSafeSession::rotateToken($request);
        StaffSafeSession::loginPortal($request, Auth::guard('client-web'), $this->login);
        $this->assertSame(self::PAGE_TOKEN, $this->token(), 'staff login in the session: token kept through logout and login');
    }
}
