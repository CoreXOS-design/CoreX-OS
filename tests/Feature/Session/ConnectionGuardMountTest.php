<?php

namespace Tests\Feature\Session;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-263 — Connection Guard global mount (structural half; behaviour in
 * tests/js/connection-guard.mjs). The reworked guard (light REMOVED, offline
 * popup + save interceptors) must mount on every authenticated screen and never
 * for a guest; the old light asset must be gone.
 */
class ConnectionGuardMountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function agent(): User
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        return User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
    }

    public function test_authenticated_screen_mounts_the_connection_guard_not_the_old_light(): void
    {
        $resp = $this->actingAs($this->agent())->get(route('command-center.calendar'));

        $resp->assertStatus(200);
        $resp->assertSee('js/corex-connection-guard.js', false);   // the reworked guard
        $resp->assertSee('startHeartbeat', false);                 // session keep-alive stays
        $resp->assertDontSee('js/corex-session-guard.js', false);  // old light asset gone
    }

    public function test_guard_is_auth_gated(): void
    {
        $this->actingAs($this->agent());
        $authed = view('layouts.partials._session-guard')->render();
        $this->assertStringContainsString('corex-connection-guard.js', $authed);

        auth()->logout();
        $guest = view('layouts.partials._session-guard')->render();
        $this->assertStringNotContainsString('corex-connection-guard.js', $guest);
    }
}
