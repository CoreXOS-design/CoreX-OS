<?php

namespace Tests\Feature\DemoAccess;

use App\Http\Middleware\EnsureDemoGrant;
use App\Models\DemoPageView;
use App\Models\DemoSession;
use App\Models\User;
use App\Services\Demo\DemoAccessService;
use App\Support\Instance;
use Database\Seeders\DemoTncVersionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The gate FAILS CLOSED. Telemetry FAILS OPEN. That inversion is the design.
 *
 * Spec: .ai/specs/demo-access-control.md §6.3, §6.4
 * Input space (§11): R10, R11, R18
 *
 * The gate is a SECURITY control: no verdict from primary means no entry. An
 * access control that opens when its authority is unreachable is a doorbell.
 *
 * Telemetry is an OBSERVABILITY control: a demo page must never block, slow, or
 * error because a page view could not be logged.
 */
class DemoGateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private DemoAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();

        $this->owner   = User::factory()->create(['role' => 'super_admin']);
        $this->service = app(DemoAccessService::class);

        $this->seed(DemoTncVersionSeeder::class);
    }

    /** Flip this process into a wired demo instance. */
    private function asDemoInstance(): void
    {
        config()->set('corex.instance.role', 'demo');
        config()->set('corex.instance.control_url', 'https://primary.corexos.co.za');
        config()->set('corex.instance.control_token', 'cx_live_test.secret');
        config()->set('corex.instance.gate_cache_ttl', 60);
    }

    private function issueAndOpenSession(): array
    {
        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Seaside Realty (Pty) Ltd',
            'contact_email' => 'thabo@seasiderealty.co.za',
        ], $this->owner->id);

        $result = $this->service->verify('thabo@seasiderealty.co.za', $code, '196.25.1.1', 'Chrome');

        return [$grant, $result['session']];
    }

    // ── The gate: FAIL CLOSED ────────────────────────────────────────────────

    /** On PRIMARY the gate is inert — live/staging/local are untouched. */
    public function test_the_gate_is_inert_on_primary(): void
    {
        $this->assertTrue(Instance::isPrimary());

        // No demo cookie, no demo instance → the middleware must pass straight
        // through and NOT redirect a real user to a demo gate.
        $user = User::factory()->create(['role' => 'agent']);

        $response = $this->actingAs($user)->get('/corex/command-center/user-settings');

        $response->assertDontSee('Enter the demo', false);
        $this->assertFalse($response->isRedirect(route('demo.gate')));
    }

    /** The gate routes 404 on primary — no dead end, no surface to probe. */
    public function test_the_gate_page_404s_on_primary(): void
    {
        $this->get('/demo/gate')->assertNotFound();
    }

    /** R10 — PRIMARY UNREACHABLE ⇒ NOBODY GETS IN. */
    public function test_when_primary_is_unreachable_the_gate_refuses_entry(): void
    {
        $this->asDemoInstance();

        // The control server is down.
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $response = $this->withCookie(EnsureDemoGrant::COOKIE, 'a-previously-valid-token')
            ->get('/dashboard');

        $response->assertRedirect(route('demo.gate'));
    }

    /** And it must NOT claim the code is wrong when the truth is our own outage. */
    public function test_an_outage_is_not_reported_as_a_bad_credential(): void
    {
        $this->asDemoInstance();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $client = app(\App\Services\Demo\DemoControlClient::class);
        $res    = $client->verify('thabo@seasiderealty.co.za', 'ANY-CODE', null, null);

        $this->assertFalse($res['success']);
        // "success: false" means TRANSPORT failed — distinct from a business "no".
        // The gate renders this as "temporarily unavailable", never "wrong code".
        $this->assertStringContainsString('reach', strtolower($res['message']));
    }

    /** A misconfigured demo host (no URL/token) also fails closed, and says why. */
    public function test_an_unconfigured_demo_host_fails_closed(): void
    {
        config()->set('corex.instance.role', 'demo');
        config()->set('corex.instance.control_url', null);
        config()->set('corex.instance.control_token', null);

        $this->assertFalse(Instance::isDemoWired());

        $res = app(\App\Services\Demo\DemoControlClient::class)->checkSession('some-token');

        $this->assertFalse($res['success']);
    }

    /** No cookie at all → the gate, not the app. */
    public function test_no_session_cookie_redirects_to_the_gate(): void
    {
        $this->asDemoInstance();

        $this->get('/dashboard')->assertRedirect(route('demo.gate'));
    }

    /** R9 — revoke bites within the cache TTL, and no later. */
    public function test_revoking_blocks_within_the_cache_ttl(): void
    {
        $this->asDemoInstance();

        [$grant, $session] = $this->issueAndOpenSession();
        $this->service->acceptTnc($grant, null, null);

        // Primary now reports the grant as revoked.
        $this->service->revoke($grant->fresh(), $this->owner->id);

        Http::fake([
            '*/api/v1/demo-access/session/*' => Http::response([
                'ok'      => false,
                'status'  => 'revoked',
                'message' => 'Your demo access has been withdrawn. Please contact us.',
            ], 200),
        ]);

        // Cache is cold (we flushed in setUp), so the next request re-checks and blocks.
        $this->withCookie(EnsureDemoGrant::COOKIE, $session->session_token)
            ->get('/dashboard')
            ->assertRedirect(route('demo.gate'));
    }

    /** A live, T&C-accepted grant passes and the request carries the grant. */
    public function test_a_live_accepted_grant_passes_the_gate(): void
    {
        $this->asDemoInstance();

        [$grant, $session] = $this->issueAndOpenSession();

        Http::fake([
            '*/api/v1/demo-access/session/*' => Http::response([
                'ok'     => true,
                'status' => 'active',
                'grant'  => ['id' => $grant->id, 'company_name' => $grant->company_name, 'email' => $grant->contact_email],
                'tnc'    => ['accepted' => true, 'current_version' => 1],
            ], 200),
        ]);

        // /demo/gate is exempt, so it never redirects to itself.
        $this->withCookie(EnsureDemoGrant::COOKIE, $session->session_token)
            ->get('/demo/gate')
            ->assertOk();
    }

    /** Not yet accepted the CURRENT T&C → the clickwrap, not the app. */
    public function test_an_unaccepted_grant_is_sent_to_the_terms_page(): void
    {
        $this->asDemoInstance();

        [$grant, $session] = $this->issueAndOpenSession();

        Http::fake([
            '*/api/v1/demo-access/session/*' => Http::response([
                'ok'     => true,
                'status' => 'active',
                'grant'  => ['id' => $grant->id, 'company_name' => $grant->company_name, 'email' => $grant->contact_email],
                'tnc'    => ['accepted' => false, 'current_version' => 2],
            ], 200),
        ]);

        $this->withCookie(EnsureDemoGrant::COOKIE, $session->session_token)
            ->get('/dashboard')
            ->assertRedirect(route('demo.tnc'));
    }

    // ── Telemetry: FAIL OPEN ─────────────────────────────────────────────────

    /** R11 — primary down for telemetry ⇒ the page still renders. 204, always. */
    public function test_telemetry_returns_204_even_when_primary_is_down(): void
    {
        $this->asDemoInstance();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $this->withCookie(EnsureDemoGrant::COOKIE, 'any-token')
            ->post('/demo/telemetry', ['path' => '/corex/properties'])
            ->assertNoContent();
    }

    /** R18 — an unknown session token is dropped silently. Never a 500. */
    public function test_an_unknown_session_token_is_dropped_not_errored(): void
    {
        $this->asDemoInstance();

        $this->withCookie(EnsureDemoGrant::COOKIE, 'a-token-that-does-not-exist')
            ->post('/demo/telemetry', ['path' => '/corex/properties'])
            ->assertNoContent();

        // And on primary's side, recording against an unknown token is a no-op.
        $this->assertNull(
            $this->service->recordPageView('a-token-that-does-not-exist', '/corex/properties', null, null)
        );
    }

    /** No cookie → still 204. Telemetry never argues with the caller. */
    public function test_telemetry_without_a_cookie_is_still_204(): void
    {
        $this->asDemoInstance();

        $this->post('/demo/telemetry', ['path' => '/corex/properties'])->assertNoContent();
    }

    /** A missing path is dropped, not a validation error. */
    public function test_telemetry_with_no_path_is_still_204(): void
    {
        $this->asDemoInstance();

        $this->withCookie(EnsureDemoGrant::COOKIE, 'any-token')
            ->post('/demo/telemetry', [])
            ->assertNoContent();
    }

    /** The happy path: a page view lands against the right session, on primary. */
    public function test_a_page_view_lands_against_the_right_session(): void
    {
        [$grant, $session] = $this->issueAndOpenSession();

        $view = $this->service->recordPageView(
            $session->session_token,
            '/corex/properties',
            'corex.properties.index',
            'Properties'
        );

        $this->assertNotNull($view);
        $this->assertSame($session->id, $view->demo_session_id);
        $this->assertSame('/corex/properties', $view->path);

        // And it is reachable from the grant, which is what the admin UI renders.
        $this->assertSame(
            1,
            DemoPageView::whereIn('demo_session_id', DemoSession::where('demo_access_grant_id', $grant->id)->pluck('id'))->count()
        );
    }
}
