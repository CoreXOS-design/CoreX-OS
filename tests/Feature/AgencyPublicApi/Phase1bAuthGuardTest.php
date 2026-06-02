<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 1b (auth guard + scope/live middleware + rate limit).
 *
 * Exercises the real token flow end to end against inline routes wired exactly
 * like the Phase 2 website routes will be: auth:agency-api → website.live →
 * website.scope. Proves 401/403/200 behaviour, the master switch, scope
 * enforcement, per-key rate limiting, and — critically — that the authenticated
 * key only ever sees its own agency's data through AgencyScope.
 *
 * Spec: .ai/specs/agency-public-api.md §4, §11
 */
class Phase1bAuthGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyA = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty', 'website_enabled' => true]);
        $this->agencyB = Agency::create(['name' => 'Beachfront Props', 'slug' => 'beachfront-props', 'website_enabled' => true]);

        // Routes mirror the Phase 2 wiring.
        Route::middleware(['auth:agency-api', 'website.live', 'website.scope:listings:read'])
            ->get('/_test/website/listings', function () {
                return response()->json([
                    'key_id'             => auth()->id(),
                    'agency_id'          => auth()->user()->agency_id,
                    'visible_properties' => Property::count(),
                ]);
            });

        Route::middleware(['auth:agency-api', 'website.live', 'throttle:website-api'])
            ->get('/_test/website/ping', fn () => response()->json(['ok' => true]));
    }

    public function test_missing_token_is_unauthorised(): void
    {
        $this->getJson('/_test/website/listings')->assertStatus(401);
    }

    public function test_malformed_and_wrong_secret_are_unauthorised(): void
    {
        // No dot separator.
        $this->withToken('garbage')->getJson('/_test/website/listings')->assertStatus(401);

        // Valid prefix, wrong secret.
        $minted = AgencyApiKey::mintSecret();
        $this->makeKey($this->agencyA, $minted);
        $this->withToken($minted['prefix'] . '.totally-wrong-secret')
            ->getJson('/_test/website/listings')->assertStatus(401);
    }

    public function test_revoked_and_expired_keys_are_unauthorised(): void
    {
        $revoked = AgencyApiKey::mintSecret();
        $this->makeKey($this->agencyA, $revoked, ['revoked_at' => now()]);
        $this->withToken($revoked['plaintext'])->getJson('/_test/website/listings')->assertStatus(401);

        $expired = AgencyApiKey::mintSecret();
        $this->makeKey($this->agencyA, $expired, ['expires_at' => now()->subDay()]);
        $this->withToken($expired['plaintext'])->getJson('/_test/website/listings')->assertStatus(401);
    }

    public function test_master_switch_off_blocks_even_valid_key(): void
    {
        $this->agencyA->update(['website_enabled' => false]);

        $minted = AgencyApiKey::mintSecret();
        $this->makeKey($this->agencyA, $minted);

        $this->withToken($minted['plaintext'])->getJson('/_test/website/listings')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'This agency website is not currently live.']);
    }

    public function test_missing_scope_is_forbidden(): void
    {
        $minted = AgencyApiKey::mintSecret();
        // Key has agents:read but NOT listings:read.
        $this->makeKey($this->agencyA, $minted, ['scopes' => [AgencyApiKey::SCOPE_AGENTS_READ]]);

        $this->withToken($minted['plaintext'])->getJson('/_test/website/listings')->assertStatus(403);
    }

    public function test_valid_key_passes_and_is_scoped_to_its_agency(): void
    {
        // Two properties in A, three in B.
        $this->seedProperties($this->agencyA, 2);
        $this->seedProperties($this->agencyB, 3);

        $minted = AgencyApiKey::mintSecret();
        $key = $this->makeKey($this->agencyA, $minted);

        $this->withToken($minted['plaintext'])->getJson('/_test/website/listings')
            ->assertStatus(200)
            ->assertJson([
                'key_id'             => $key->id,
                'agency_id'          => $this->agencyA->id,
                'visible_properties' => 2, // ONLY agency A's — never B's 3
            ]);

        // last_used_at stamped once the request cleared the live gate.
        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_per_key_rate_limit_returns_429(): void
    {
        $minted = AgencyApiKey::mintSecret();
        $this->makeKey($this->agencyA, $minted, ['rate_limit_per_min' => 2]);

        $this->withToken($minted['plaintext'])->getJson('/_test/website/ping')->assertStatus(200);
        $this->withToken($minted['plaintext'])->getJson('/_test/website/ping')->assertStatus(200);
        $this->withToken($minted['plaintext'])->getJson('/_test/website/ping')->assertStatus(429);
    }

    // ---- helpers -----------------------------------------------------------

    private function makeKey(Agency $agency, array $minted, array $overrides = []): AgencyApiKey
    {
        return AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create(array_merge([
            'agency_id'   => $agency->id,
            'name'        => 'Website',
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ], $overrides));
    }

    private function seedProperties(Agency $agency, int $count): void
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $agency->slug . ' branch']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        for ($i = 0; $i < $count; $i++) {
            Property::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
                'agency_id'     => $agency->id,
                'agent_id'      => $user->id,
                'branch_id'     => $branch->id,
                'external_id'   => (string) Str::uuid(),
                'title'         => $agency->slug . " listing {$i}",
                'suburb'        => 'Margate',
                'property_type' => 'house',
                'status'        => 'active',
                'price'         => 1000000 + $i,
            ]);
        }
    }
}
