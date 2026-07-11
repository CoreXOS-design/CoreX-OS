<?php

namespace Tests\Feature\DemoAccess;

use App\Http\Middleware\EnsureDemoGrant;
use App\Models\DemoConnector;
use App\Models\DevSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\Instance;
use Database\Seeders\DemoTncVersionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The universal demo connector: minting, rotation, bearer auth, and the two
 * escape hatches that stop a bad token bricking the demo.
 *
 * Spec: .ai/specs/demo-access-control.md §5.1, §5.2
 */
class DemoConnectorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $agencyAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();

        $this->seed(DemoTncVersionSeeder::class);

        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();

        $this->owner       = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
        $this->agencyAdmin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function asDemoInstance(): void
    {
        config()->set('corex.instance.role', 'demo');
    }

    // ── Minting & rotation ───────────────────────────────────────────────────

    public function test_minting_returns_the_plaintext_once_and_stores_only_a_hash(): void
    {
        [$connector, $plaintext] = DemoConnector::mint('CoreX Demo Host', $this->owner->id);

        $this->assertStringStartsWith('cx_demo_', $plaintext);
        $this->assertStringContainsString('.', $plaintext);

        // The row holds sha256 of the secret half — never the token.
        $row = collect(DemoConnector::find($connector->id)->getAttributes())->implode(' ');
        $this->assertStringNotContainsString(explode('.', $plaintext, 2)[1], $row);

        $this->assertSame($connector->id, DemoConnector::resolve($plaintext)->id);
    }

    /**
     * Rotation REVOKES the predecessor. A rotation that left the old token working
     * would be worthless as a response to a leak — which is the only reason to rotate.
     */
    public function test_minting_a_new_connector_revokes_the_old_one(): void
    {
        [$old, $oldToken] = DemoConnector::mint('First', $this->owner->id);
        [$new, $newToken] = DemoConnector::mint('Second', $this->owner->id);

        $this->assertNotNull(DemoConnector::resolve($newToken));
        $this->assertNull(DemoConnector::resolve($oldToken), 'The rotated-out token must stop working immediately.');

        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNull($new->fresh()->revoked_at);

        // Exactly one active, always.
        $this->assertSame(1, DemoConnector::active()->count());

        // ...but BOTH rows survive: the table is the audit trail of every credential
        // the demo has ever held.
        $this->assertSame(2, DemoConnector::count());
    }

    public function test_a_revoked_connector_stops_resolving(): void
    {
        [$connector, $token] = DemoConnector::mint('CoreX Demo Host', $this->owner->id);

        $connector->revoke();

        $this->assertNull(DemoConnector::resolve($token));
        $this->assertNull(DemoConnector::current());
    }

    /** Every failure mode resolves to null — no oracle telling an attacker which part was wrong. */
    public function test_malformed_unknown_and_wrong_secret_tokens_all_fail_identically(): void
    {
        [$connector, $token] = DemoConnector::mint('CoreX Demo Host', $this->owner->id);
        $prefix = explode('.', $token, 2)[0];

        $this->assertNull(DemoConnector::resolve(null));
        $this->assertNull(DemoConnector::resolve(''));
        $this->assertNull(DemoConnector::resolve('no-dot-at-all'));
        $this->assertNull(DemoConnector::resolve('cx_demo_unknown.somesecret'));
        $this->assertNull(DemoConnector::resolve($prefix . '.wrongsecret'), 'Right prefix, wrong secret.');
    }

    // ── Bearer auth on the control API ───────────────────────────────────────

    public function test_the_control_api_rejects_a_request_with_no_token(): void
    {
        DemoConnector::mint('CoreX Demo Host', $this->owner->id);

        $this->getJson('/api/v1/demo-access/ping')->assertStatus(401);
    }

    public function test_the_control_api_rejects_a_revoked_token(): void
    {
        [$connector, $token] = DemoConnector::mint('CoreX Demo Host', $this->owner->id);
        $connector->revoke();

        $this->withToken($token)->getJson('/api/v1/demo-access/ping')->assertStatus(401);
    }

    public function test_a_valid_token_pings_successfully_and_reports_the_tnc_version(): void
    {
        [$connector, $token] = DemoConnector::mint('CoreX Demo Host', $this->owner->id);

        $this->withToken($token)->getJson('/api/v1/demo-access/ping')
            ->assertOk()
            ->assertJson([
                'ok'            => true,
                'instance'      => 'primary',
                'connector'     => 'CoreX Demo Host',
                'tnc_version'   => 1,
                'tnc_published' => true,
            ]);

        $this->assertNotNull($connector->fresh()->last_used_at, 'A successful call must stamp last_used_at — it is the only "is the demo actually talking to us?" signal.');
    }

    /** The demo host must never SERVE the control API — its DB is disposable. */
    public function test_the_control_api_404s_on_a_demo_instance(): void
    {
        [, $token] = DemoConnector::mint('CoreX Demo Host', $this->owner->id);

        $this->asDemoInstance();

        $this->withToken($token)->getJson('/api/v1/demo-access/ping')->assertNotFound();
    }

    // ── The LIVE connector admin page ────────────────────────────────────────

    public function test_an_owner_mints_a_connector_and_sees_the_token_once(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('admin.demo-access.connection.mint'), ['name' => 'CoreX Demo Host']);

        $response->assertRedirect(route('admin.demo-access.connection'));
        $response->assertSessionHas('demo_connector_token');

        $token = session('demo_connector_token');
        $this->assertNotNull(DemoConnector::resolve($token));

        $this->actingAs($this->owner)
            ->withSession(['demo_connector_token' => $token])
            ->get(route('admin.demo-access.connection'))
            ->assertOk()
            ->assertSee($token)
            ->assertSee('will not be shown again', false);
    }

    public function test_a_non_owner_cannot_mint_or_revoke_a_connector(): void
    {
        $this->actingAs($this->agencyAdmin)
            ->get(route('admin.demo-access.connection'))
            ->assertForbidden();

        $this->actingAs($this->agencyAdmin)
            ->post(route('admin.demo-access.connection.mint'), ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertSame(0, DemoConnector::count());
    }

    /** No connector = the demo cannot reach us = nobody gets in. Say so on the list page. */
    public function test_the_grant_list_warns_when_no_connector_exists(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.demo-access.index'))
            ->assertOk()
            ->assertSee('not set up');
    }

    // ── The DEMO connection page ─────────────────────────────────────────────

    public function test_the_connection_page_404s_on_primary(): void
    {
        $this->assertTrue(Instance::isPrimary());

        $this->actingAs($this->owner)
            ->get(route('admin.demo-connection.edit'))
            ->assertNotFound();
    }

    public function test_an_owner_on_the_demo_saves_the_connection_and_the_token_is_encrypted_at_rest(): void
    {
        $this->asDemoInstance();

        $this->actingAs($this->owner)
            ->put(route('admin.demo-connection.update'), [
                'control_url'   => 'https://corex.hfcoastal.co.za/',
                'control_token' => 'cx_demo_abcd1234.thesecretpart',
            ])
            ->assertRedirect(route('admin.demo-connection.edit'));

        // Trailing slash normalised.
        $this->assertSame('https://corex.hfcoastal.co.za', Instance::controlUrl());
        $this->assertSame('cx_demo_abcd1234.thesecretpart', Instance::controlToken());
        $this->assertSame('cx_demo_abcd1234', Instance::controlTokenPrefix());
        $this->assertTrue(Instance::isDemoWired());

        // At rest it is CIPHERTEXT, not the token. The demo box is disposable and
        // frequently rebuilt; a DB dump of it must not hand anyone a working
        // credential into primary's control API.
        $stored = DevSetting::get('demo_control_token_encrypted');
        $this->assertNotSame('cx_demo_abcd1234.thesecretpart', $stored);
        $this->assertSame('cx_demo_abcd1234.thesecretpart', Crypt::decryptString($stored));
    }

    /**
     * A BLANK token field means "I did not touch it", not "clear it".
     *
     * The form never renders the secret back, so treating blank as clear would wipe
     * the token every time someone edited only the URL — silently breaking the demo.
     */
    public function test_saving_with_a_blank_token_keeps_the_existing_one(): void
    {
        $this->asDemoInstance();
        Instance::setControlToken('cx_demo_abcd1234.thesecretpart');

        $this->actingAs($this->owner)
            ->put(route('admin.demo-connection.update'), [
                'control_url'   => 'https://corex-new.hfcoastal.co.za',
                'control_token' => '',
            ])
            ->assertRedirect();

        $this->assertSame('https://corex-new.hfcoastal.co.za', Instance::controlUrl());
        $this->assertSame('cx_demo_abcd1234.thesecretpart', Instance::controlToken(), 'A blank field must not wipe the saved token.');
    }

    public function test_a_malformed_url_is_rejected_clearly(): void
    {
        $this->asDemoInstance();

        $this->actingAs($this->owner)
            ->put(route('admin.demo-connection.update'), ['control_url' => 'corex.hfcoastal.co.za'])
            ->assertSessionHasErrors('control_url');
    }

    /**
     * A non-owner never reaches the connection page — but note WHICH layer stops them.
     *
     * On a demo instance EnsureDemoGrant runs BEFORE owner_only (it is hoisted above
     * Authenticate in the priority list), so a non-owner is bounced to the demo gate
     * rather than 403'd. Both are refusals; the gate simply gets there first. The
     * owner_only layer is proven independently on primary, where the gate is inert —
     * see test_a_non_owner_cannot_mint_or_revoke_a_connector.
     */
    public function test_a_non_owner_cannot_reach_the_connection_page_on_the_demo(): void
    {
        $this->asDemoInstance();

        $response = $this->actingAs($this->agencyAdmin)->get(route('admin.demo-connection.edit'));

        $response->assertRedirect(route('demo.gate'));
        $response->assertDontSee('Connector token');
    }

    // ── The escape hatches: a bad token must never brick the demo ────────────

    /**
     * A signed-in System Owner bypasses the gate on a LOCAL role check.
     *
     * This is the whole reason a UI-configured connector is safe. The gate fails
     * closed; if the owner were also locked out, the only way to fix a broken
     * connection would be the connection itself — and the fix would need SSH.
     */
    public function test_a_signed_in_owner_bypasses_the_gate_even_with_no_connector_at_all(): void
    {
        $this->asDemoInstance();
        config()->set('corex.instance.control_url', null);
        config()->set('corex.instance.control_token', null);

        $this->assertFalse(Instance::isDemoWired(), 'Precondition: the demo is NOT wired up.');

        // No demo grant cookie, and primary is unreachable — yet the owner gets in.
        $this->actingAs($this->owner)
            ->get(route('admin.demo-connection.edit'))
            ->assertOk()
            ->assertSee('Not configured');
    }

    /** A non-owner gets no such bypass — they still meet the gate. */
    public function test_a_signed_in_non_owner_does_not_bypass_the_gate(): void
    {
        $this->asDemoInstance();
        config()->set('corex.instance.control_url', null);
        config()->set('corex.instance.control_token', null);

        $this->actingAs($this->agencyAdmin)
            ->get('/dashboard')
            ->assertRedirect(route('demo.gate'));
    }

    /** The staff door stays open when everything else is shut. */
    public function test_the_owner_login_page_is_never_gated(): void
    {
        $this->asDemoInstance();
        DevSetting::set('demo_mode_enabled', '1');
        Cache::flush();

        $this->get('/demo-owner-login')->assertOk();
    }

    // ── The passwordless demo-role login IS gated ────────────────────────────

    /**
     * demo-login/{role} signs you in as a real demo user with NO password. If it
     * were not grant-gated, a prospect could skip /demo/gate entirely and the whole
     * feature would be decoration.
     */
    public function test_the_passwordless_demo_role_login_requires_a_grant(): void
    {
        $this->asDemoInstance();
        DevSetting::set('demo_mode_enabled', '1');
        Cache::flush();

        User::factory()->create(['role' => 'agent', 'is_active' => true]);

        // No grant cookie → bounced to the gate, NOT signed in.
        $this->post('/demo-login/agent')
            ->assertRedirect(route('demo.gate'));

        $this->assertGuest();
    }

    /**
     * With a grant that primary confirms, the demo-role login works.
     *
     * Note the Http::fake: demo-login/{role} is NOT exempt from EnsureDemoGrant, so
     * the middleware verifies the cookie against primary before the controller is
     * ever reached. That is the real gate; the controller's own cookie check is the
     * belt to its braces.
     */
    public function test_the_passwordless_demo_role_login_works_once_a_grant_is_verified(): void
    {
        $this->asDemoInstance();
        config()->set('corex.instance.control_url', 'https://primary.corexos.co.za');
        config()->set('corex.instance.control_token', 'cx_demo_test.secret');
        DevSetting::set('demo_mode_enabled', '1');
        Cache::flush();

        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        // Primary says: live grant, terms accepted.
        Http::fake([
            '*/api/v1/demo-access/session/*' => Http::response([
                'ok'     => true,
                'status' => 'active',
                'grant'  => ['id' => 1, 'company_name' => 'Seaside Realty', 'email' => 'thabo@seasiderealty.co.za'],
                'tnc'    => ['accepted' => true, 'current_version' => 1],
            ], 200),
        ]);

        $this->withCookie(EnsureDemoGrant::COOKIE, 'a-grant-cookie-from-the-gate')
            ->post('/demo-login/agent')
            ->assertRedirect();

        $this->assertAuthenticatedAs($agent);
    }

    /**
     * The demo-mode surfaces must be reachable on the REAL demo host, which runs
     * APP_ENV=production. The old !environment('production') check made them 404
     * there — on the very box they exist for.
     */
    public function test_demo_mode_is_enabled_on_a_demo_instance_despite_app_env_production(): void
    {
        $this->app['env'] = 'production';
        $this->asDemoInstance();
        DevSetting::set('demo_mode_enabled', '1');
        Cache::flush();

        $this->assertTrue(
            \App\Http\Controllers\Auth\DemoLoginController::isEnabled(),
            'COREX_INSTANCE_ROLE=demo must enable demo mode even under APP_ENV=production.'
        );
    }
}
