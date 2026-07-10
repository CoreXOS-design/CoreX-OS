<?php

namespace Tests\Feature\Session;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-220 — session-armour token-refresh endpoint. Long-lived authoring pages
 * (document/template editor) poll this to keep their CSRF token + session fresh
 * and to recover a 419 in-place instead of dying. A dead session must yield a
 * clean 401 (JSON) so the client can show the plain-language banner — never a
 * raw code to the agent.
 */
class CsrfTokenEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        return User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
    }

    public function test_authenticated_user_gets_a_fresh_csrf_token(): void
    {
        $res = $this->actingAs($this->user())
            ->getJson('/api/v1/csrf-token');

        $res->assertStatus(200)
            ->assertJsonStructure(['token']);

        $this->assertNotEmpty($res->json('token'));
    }

    public function test_the_returned_token_is_the_live_session_token(): void
    {
        // The token the endpoint returns must be the one VerifyCsrfToken will accept,
        // otherwise the in-place refresh would still 419.
        $this->actingAs($this->user());
        $res = $this->getJson('/api/v1/csrf-token');
        $res->assertStatus(200);
        $this->assertSame(csrf_token(), $res->json('token'));
    }

    public function test_unauthenticated_json_request_gets_401_not_a_redirect(): void
    {
        // Dead session → the guard needs a 401 (JSON) to trigger the "connection
        // lost" banner, not a 302 to /login that a fetch would silently follow.
        $this->getJson('/api/v1/csrf-token')->assertStatus(401);
    }
}
