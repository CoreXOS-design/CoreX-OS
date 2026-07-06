<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Http\Controllers\Communications\WhatsAppLinkController;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\User;
use App\Services\Communications\WahaSessionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-158 (2026-07-06) — WAHA session-name generation must be environment-safe.
 *
 * BUG-CLASS: staging is a clone of the live DB, so an agency's DB-stored
 * wa_session_prefix is copied across environments and cannot distinguish them.
 * A fresh link therefore derives the environment marker from CODE/CONFIG
 * (WAHA_SESSION_ENV → APP_ENV; .env is per-environment, never cloned) so a new
 * link on staging can never collide with live's — even right after a refresh.
 * Existing linked devices keep their STORED name (ingest maps by it), so nothing
 * re-links.
 */
final class WaSessionNameGenerationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(5), 'slug' => 'hfc-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_generated_name_is_prefixed_by_the_environment_marker(): void
    {
        $user = $this->user();
        $agency = \App\Models\Agency::find($this->agencyId); // wa_session_prefix null → 'agency{id}'

        config(['communications.waha.session_env' => 'production']);
        $this->assertSame(
            "production-agency{$this->agencyId}-agent-{$user->id}",
            $this->generate($user, $agency)
        );

        config(['communications.waha.session_env' => 'staging']);
        $this->assertSame(
            "staging-agency{$this->agencyId}-agent-{$user->id}",
            $this->generate($user, $agency)
        );
    }

    public function test_agency_prefix_concept_is_retained_with_the_env_component(): void
    {
        $agency = \App\Models\Agency::find($this->agencyId);
        $agency->wa_session_prefix = 'hfc';
        $agency->save();
        $user = $this->user();

        config(['communications.waha.session_env' => 'production']);
        $this->assertSame("production-hfc-agent-{$user->id}", $this->generate($user, $agency->fresh()));
    }

    public function test_refresh_collision_a_fresh_staging_link_cannot_collide_with_cloned_live_rows(): void
    {
        // Simulate a staging refresh: a LIVE-named device row is now present in
        // the (staging) DB — and the same agency prefix carried across.
        $liveUser = $this->user();
        CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $liveUser->id,
            'waha_session' => "production-agency{$this->agencyId}-agent-{$liveUser->id}",
            'active' => true, 'last_seen_at' => now(),
        ]);

        // A DIFFERENT agent links fresh, now under STAGING config.
        config(['communications.waha.session_env' => 'staging']);
        $newUser = $this->user();
        $agency = \App\Models\Agency::find($this->agencyId);
        $generated = $this->generate($newUser, $agency);

        $this->assertStringStartsWith('staging-', $generated);
        $this->assertStringNotContainsString('production-', $generated);
        // And it collides with NO existing (live-named) session.
        $this->assertFalse(
            CommunicationWaDevice::where('waha_session', $generated)->exists(),
            'a fresh staging name never matches a cloned live-named row'
        );
    }

    public function test_existing_linked_device_keeps_its_stored_name_zero_relink(): void
    {
        $user = $this->user();
        // An already-linked device with the OLD (pre-fix) name format.
        CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $user->id,
            'waha_session' => "agency{$this->agencyId}-agent-{$user->id}",
            'active' => true, 'last_seen_at' => now(),
        ]);
        $agency = \App\Models\Agency::find($this->agencyId);

        // Even under a NEW env config, resolution returns the STORED name so the
        // session keeps capturing (ingest maps by stored waha_session) — no re-link.
        config(['communications.waha.session_env' => 'production']);
        $this->assertSame(
            "agency{$this->agencyId}-agent-{$user->id}",
            $this->resolve($user, $agency)
        );

        // Ingest continuity: the stored name still resolves the device.
        $device = CommunicationWaDevice::forWahaSession("agency{$this->agencyId}-agent-{$user->id}")->first();
        $this->assertNotNull($device);
        $this->assertSame($user->id, $device->user_id);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'role' => 'agent', 'is_active' => true,
        ]);
    }

    private function controller(): WhatsAppLinkController
    {
        return new WhatsAppLinkController(app(WahaSessionClient::class));
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/communications/wa-link/status', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function generate(User $user, $agency): string
    {
        $m = new ReflectionMethod(WhatsAppLinkController::class, 'generateSessionName');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $this->requestFor($user), $agency);
    }

    private function resolve(User $user, $agency): string
    {
        $m = new ReflectionMethod(WhatsAppLinkController::class, 'resolveSessionName');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $this->requestFor($user), $agency);
    }
}
