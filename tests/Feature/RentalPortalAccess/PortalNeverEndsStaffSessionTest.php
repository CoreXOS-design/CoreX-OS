<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Support\PortalDemoSigninGate;
use App\Support\PortalLink;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * 14 Oct 2026 (cc5's walk of QA1): opening an owner / tenant link in the same browser signed the AGENT out of CoreX. Cause: the QA auto sign-in route
 * (`POST /api/v1/client-auth/qa-link-signin`) sat inside routes/web.php's `web` group AND carried Sanctum's own session stack, so the browser's session
 * cookie was read twice, the second time as an unknown session - a NEW empty session was started and its cookie overwrote the staff one.
 *
 * Rule (.ai/specs/rental-portal-access.md 28.5 "The staff login is never touched"): the portal guard (`client-web`) and the staff guard (`web`) live in
 * ONE browser session under separate keys, and nothing a portal does - sign in, tab token, link sign-in, logout - may replace the session id or remove the staff key.
 * These tests send a REAL staff session cookie (the encrypted cookie a browser holds), not an acting-as shortcut, because the bug only exists on that path.
 */
final class PortalNeverEndsStaffSessionTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private ClientUser $login;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->login = ClientUser::create([
            'email' => 'both.' . uniqid() . '@example.invalid', 'password' => Hash::make('Sup3rSecret!'), 'password_set_at' => now(),
            'activated_at' => now(), 'current_agency_id' => $this->agency->id,
        ]);
        $this->contact = $this->makeContact($this->agency, ['email' => $this->login->email, 'first_name' => 'Portal', 'last_name' => 'Person']);
        $this->contact->forceFill(['client_user_id' => $this->login->id])->saveQuietly();
    }

    /** Register the QA gate the way production does: routes/web.php calls it inside the `web` middleware group. */
    private function openGateLikeProduction(): void
    {
        $this->app['env'] = 'qa';   // (env=qa turns the harness's CSRF skip off, so this test skips the CSRF check itself)
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['clientauth.demo_signin_enabled' => true]);
        Route::middleware('web')->group(fn () => PortalDemoSigninGate::registerRoutes());
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    /** A staff browser: a saved session holding the staff login, and the encrypted cookie that points at it. @return array{0:string,1:string,2:string} cookie name, value, session id */
    private function staffBrowser(): array
    {
        $this->app['auth']->forgetGuards();
        $store = $this->app['session']->driver();
        $store->setId($store->getId());
        $store->start();
        $store->put(Auth::guard('web')->getName(), $this->admin->id);
        $store->put('password_hash_web', $this->admin->getAuthPassword());
        $store->put('_token', 'STAFF-PAGE-TOKEN-0123456789012345678901234567');
        $store->save();
        $name = (string) config('session.cookie');
        $enc = $this->app['encrypter'];

        return [$name, $enc->encrypt(CookieValuePrefix::create($name, $enc->getKey()) . $store->getId(), false), $store->getId()];
    }

    /** @param array<string,mixed> $json */
    private function portalCall(string $method, string $uri, array $cookies, array $json = [], ?string $bearer = null)
    {
        $server = ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_REFERER' => 'http://localhost/portal', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        $this->app['auth']->forgetGuards();

        return $this->call($method, $uri, [], $cookies, [], $server, $json ? json_encode($json) : null);
    }

    /**
     * After the call the browser's session (the cookie the response hands back, or the original one) still holds the staff login. With `$sameId`
     * the id must not even have changed - true for every per-tab path (a new/empty replacement session is exactly the bug); the legacy cookie login
     * regenerates the id on purpose (SessionGuard::login) but carries the data - the staff key included - into the new id.
     */
    private function assertStaffSessionIntact($response, string $cookieName, string $sid, string $what, bool $sameId = true): void
    {
        $effective = $sid;
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getName() === $cookieName && $c->getValue()) {
                $effective = CookieValuePrefix::remove($this->app['encrypter']->decrypt($c->getValue(), false));
            }
        }
        if ($sameId) {
            $this->assertSame($sid, $effective, $what . ': the response must not hand the browser a different session cookie');
        }
        $payload = @unserialize((string) $this->app['session']->driver()->getHandler()->read($effective)) ?: [];
        $this->assertArrayHasKey(Auth::guard('web')->getName(), $payload, $what . ': the staff login is still in the browser\'s session');
        $this->assertSame($this->admin->id, $payload[Auth::guard('web')->getName()]);
    }

    private function linkRef(): string
    {
        parse_str((string) parse_url(PortalLink::forContact($this->contact, PortalLink::VIEW_TENANT), PHP_URL_QUERY), $q);

        return (string) $q['r'];
    }

    // ── structure: no route may run two session stacks ───────────────────────

    public function test_no_route_runs_the_web_group_and_sanctums_own_session_stack_together(): void
    {
        $this->openGateLikeProduction();
        $router = $this->app['router'];
        $offenders = [];
        foreach ($router->getRoutes() as $route) {
            $stack = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
            $hasStateful = in_array(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, $stack, true);
            $hasWebSession = in_array(\Illuminate\Session\Middleware\StartSession::class, $stack, true);
            if ($hasStateful && $hasWebSession) {
                $offenders[] = $route->methods()[0] . ' ' . $route->uri();
            }
        }
        $this->assertSame([], $offenders, 'a route with both stacks starts a second session over the browser\'s cookie and signs staff out');
        $this->assertNotNull($router->getRoutes()->getByName('client-auth.qa-link-signin'), 'the QA route exists inside the gate');
    }

    // ── behaviour: a real staff cookie survives every portal call ────────────

    public function test_the_qa_link_sign_in_leaves_the_staff_session_alone(): void
    {
        $this->openGateLikeProduction();
        [$cookie, $value, $sid] = $this->staffBrowser();

        $res = $this->portalCall('POST', '/api/v1/client-auth/qa-link-signin', [$cookie => $value], ['r' => $this->linkRef()]);
        $res->assertOk()->assertJsonStructure(['token']);
        $this->assertStaffSessionIntact($res, $cookie, $sid, 'qa-link-signin');
    }

    public function test_the_tab_token_and_a_tab_login_leave_the_staff_session_alone(): void
    {
        [$cookie, $value, $sid] = $this->staffBrowser();

        $res = $this->portalCall('POST', '/api/v1/client-auth/tab-token', [$cookie => $value], []);
        $res->assertStatus(401);   // nobody signed in on this browser yet
        $this->assertStaffSessionIntact($res, $cookie, $sid, 'tab-token');

        $res = $this->portalCall('POST', '/api/v1/client-auth/login', [$cookie => $value], ['email' => $this->login->email, 'password' => 'Sup3rSecret!', 'tab' => true]);
        $res->assertOk()->assertJsonStructure(['token']);
        $this->assertStaffSessionIntact($res, $cookie, $sid, 'login (tab)');
        $token = (string) $res->json('token');

        $res = $this->portalCall('POST', '/api/v1/client-auth/logout', [$cookie => $value], [], $token);
        $res->assertOk();
        $this->assertStaffSessionIntact($res, $cookie, $sid, 'logout (tab token)');
    }

    public function test_the_old_cookie_login_and_logout_leave_the_staff_session_alone_too(): void
    {
        [$cookie, $value, $sid] = $this->staffBrowser();

        $res = $this->portalCall('POST', '/api/v1/client-auth/login', [$cookie => $value], ['email' => $this->login->email, 'password' => 'Sup3rSecret!']);
        $res->assertOk();
        $this->assertStaffSessionIntact($res, $cookie, $sid, 'login (session cookie)', sameId: false);

        // (the cookie login moved the browser to the regenerated session: carry on from there, as the browser does)
        foreach ($res->headers->getCookies() as $c) {
            if ($c->getName() === $cookie) {
                $value = $c->getValue();
                $sid = CookieValuePrefix::remove($this->app['encrypter']->decrypt($value, false));
            }
        }
        $res = $this->portalCall('POST', '/api/v1/client-auth/logout', [$cookie => $value]);
        $res->assertOk();
        $this->assertStaffSessionIntact($res, $cookie, $sid, 'logout (session cookie)', sameId: false);
    }
}
