<?php

namespace Tests\Feature\Session;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-220 — Session Armour global mount (spec: .ai/specs/session-armour.md §4/§5/§8).
 *
 * The connection indicator + heartbeat must mount on EVERY long-lived
 * authenticated screen (not just the two DocuPerfect editors), via the reusable
 * partial included in the corex-app shell — and must NEVER render for a guest.
 */
class SessionGuardMountTest extends TestCase
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

    /** A real long-lived authenticated screen (the calendar) carries the guard globally. */
    public function test_authenticated_long_lived_screen_mounts_the_session_guard(): void
    {
        $resp = $this->actingAs($this->agent())->get(route('command-center.calendar'));

        $resp->assertStatus(200);
        $resp->assertSee('js/corex-session-guard.js', false);   // the reusable guard asset
        $resp->assertSee('startHeartbeat', false);              // heartbeat init
        $resp->assertSee('corex:csrf-refreshed', false);        // global token sink → meta refresh
    }

    /** The reusable partial is auth-gated — present for a user, absent for a guest. */
    public function test_partial_is_auth_gated(): void
    {
        $this->actingAs($this->agent());
        $authed = view('layouts.partials._session-guard')->render();
        $this->assertStringContainsString('corex-session-guard.js', $authed);
        $this->assertStringContainsString('startHeartbeat', $authed);

        auth()->logout();
        $guest = view('layouts.partials._session-guard')->render();
        $this->assertStringNotContainsString('corex-session-guard.js', $guest);
    }
}
