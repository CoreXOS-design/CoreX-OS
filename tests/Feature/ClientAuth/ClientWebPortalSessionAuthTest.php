<?php

namespace Tests\Feature\ClientAuth;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AT-445 — .ai/specs/rental-portal-access.md §9/§6. The web portal
 * authenticates via Sanctum's cookie-based stateful (SPA) mode against the
 * SAME /api/v1/client-auth/* + /api/v1/client/* endpoints the mobile app
 * reaches with bearer tokens — never by storing a token where JS can read
 * it. These tests exercise exactly that branch (ClientAuthController's
 * isStatefulRequest() check) and confirm the rest of the mobile API is
 * unaffected.
 */
class ClientWebPortalSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    private const STATEFUL_ORIGIN = ['Origin' => 'http://localhost'];

    private function makeAgency(string $name = 'Agency A'): Agency
    {
        $agency = Agency::create(['name' => $name, 'slug' => str()->slug($name . '-' . uniqid())]);
        Branch::create(['agency_id' => $agency->id, 'name' => $name . ' Main', 'code' => 'MAIN-' . $agency->id, 'is_active' => true]);

        return $agency;
    }

    private function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');

        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branchId,
            'first_name' => 'Portal',
            'last_name' => 'Tenant',
            'phone' => '0820000000',
            'email' => 'tenant+' . uniqid() . '@example.com',
        ], $overrides));
    }

    private function makeClientUserWithPassword(string $email, string $password): ClientUser
    {
        return ClientUser::create([
            'email' => $email,
            'password' => Hash::make($password),
            'password_set_at' => now(),
            'activated_at' => now(),
        ]);
    }

    public function test_stateful_login_establishes_a_session_and_never_returns_a_bearer_token(): void
    {
        $agency = $this->makeAgency();
        $email = 'tenant@example.com';
        $contact = $this->makeContact($agency, ['email' => $email]);
        $clientUser = $this->makeClientUserWithPassword($email, 'Sup3rSecret!');
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        $login = $this->withHeaders(self::STATEFUL_ORIGIN)->postJson('/api/v1/client-auth/login', [
            'email' => $email,
            'password' => 'Sup3rSecret!',
        ]);

        $login->assertOk();
        $login->assertJsonMissingPath('token');
        $this->assertTrue(Auth::guard('client-web')->check(), 'Expected a client-web session to be established.');
        $this->assertSame($clientUser->id, Auth::guard('client-web')->id());

        // Same browser session now reaches the protected client API with no
        // bearer token at all — proves auth:sanctum resolves the session via
        // the 'client-web' guard entry in config/sanctum.php's guard list.
        $me = $this->withHeaders(self::STATEFUL_ORIGIN)->getJson('/api/v1/client/me');
        $me->assertOk()->assertJsonPath('client.id', $clientUser->id);
    }

    public function test_non_stateful_login_is_completely_unchanged_and_still_issues_a_bearer_token(): void
    {
        $agency = $this->makeAgency();
        $email = 'mobile-tenant@example.com';
        $contact = $this->makeContact($agency, ['email' => $email]);
        $clientUser = $this->makeClientUserWithPassword($email, 'Sup3rSecret!');
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        // No Origin/Referer header — exactly what the mobile app sends.
        $login = $this->postJson('/api/v1/client-auth/login', [
            'email' => $email,
            'password' => 'Sup3rSecret!',
        ]);

        $login->assertOk();
        $login->assertJsonStructure(['token']);
        $this->assertFalse(Auth::guard('client-web')->check());
    }

    public function test_unauthenticated_stateful_request_to_a_protected_client_route_is_rejected(): void
    {
        $res = $this->withHeaders(self::STATEFUL_ORIGIN)->getJson('/api/v1/client/me');

        $res->assertStatus(401);
    }

    public function test_unauthenticated_non_stateful_request_is_also_rejected(): void
    {
        $res = $this->getJson('/api/v1/client/me');

        $res->assertStatus(401);
    }
}
