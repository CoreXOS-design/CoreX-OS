<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency Public API — Phase 1c (API Access panel: key CRUD + master switch).
 *
 * Exercises the AgencyApiKeyController web actions through the real routes,
 * the agency-edit panel rendering, validation, secret reveal-once, soft
 * delete, and cross-agency 404 protection.
 *
 * Permission gating is enforced by the `permission:agency_api.manage` route
 * middleware (verified via route:list); PermissionService allows access when
 * role_permissions is unseeded, which is the whole suite's test convention.
 *
 * Spec: .ai/specs/agency-public-api.md §7.1, §11
 */
class Phase1cKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
        ]);
    }

    public function test_api_access_panel_renders_on_agency_edit(): void
    {
        $this->actingAs($this->user)
            ->get(route('agencies.edit', $this->agency))
            ->assertStatus(200)
            ->assertSee('API Access')
            ->assertSee('Generate API Key')
            ->assertSee('Make website live');
    }

    public function test_generate_key_creates_and_reveals_secret_once(): void
    {
        $resp = $this->actingAs($this->user)->post(route('agencies.api-keys.store', $this->agency), [
            'name'        => 'Home Finders Coastal',
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://hfc.example.co.za/api/corex-webhook',
        ]);

        $resp->assertRedirect();
        $resp->assertSessionHas('new_api_key');

        $key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->where('agency_id', $this->agency->id)->first();
        $this->assertNotNull($key);
        $this->assertSame('Home Finders Coastal', $key->name);

        // Reveal-once plaintext verifies against the stored hash; plaintext not stored.
        $plaintext = session('new_api_key')['plaintext'];
        [$prefix, $secret] = explode('.', $plaintext, 2);
        $this->assertSame($key->key_prefix, $prefix);
        $this->assertTrue($key->verifySecret($secret));

        // webhooks:receive scope → a webhook signing secret was minted.
        $this->assertNotEmpty($key->webhook_secret);
    }

    public function test_generate_key_requires_a_name(): void
    {
        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.store', $this->agency), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, AgencyApiKey::withoutGlobalScope(AgencyScope::class)->count());
    }

    public function test_invalid_webhook_url_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.store', $this->agency), ['name' => 'Site', 'webhook_url' => 'not-a-url'])
            ->assertSessionHasErrors('webhook_url');
    }

    public function test_edit_updates_scopes_and_webhook_without_a_new_key(): void
    {
        $key = $this->makeKey(['scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ], 'webhook_url' => null]);

        $this->actingAs($this->user)->put(route('agencies.api-keys.update', [$this->agency, $key]), [
            'name'        => 'Coastal Website',
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://coastal.example/hook',
        ])->assertRedirect();

        $key->refresh();
        $this->assertSame('Coastal Website', $key->name);
        $this->assertEqualsCanonicalizing(
            [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            $key->scopes
        );
        $this->assertSame('https://coastal.example/hook', $key->webhook_url);
        // webhooks:receive now granted → a signing secret was minted.
        $this->assertNotEmpty($key->webhook_secret);
        // Same key — no new row created.
        $this->assertSame(1, AgencyApiKey::withoutGlobalScope(AgencyScope::class)->where('agency_id', $this->agency->id)->count());
    }

    public function test_edit_requires_a_name(): void
    {
        $key = $this->makeKey();
        $this->actingAs($this->user)
            ->put(route('agencies.api-keys.update', [$this->agency, $key]), ['name' => '', 'scopes' => []])
            ->assertSessionHasErrors('name');
    }

    public function test_regenerate_rotates_secret_and_reactivates(): void
    {
        $key = $this->makeKey(['revoked_at' => now()]);
        $oldHash = $key->secret_hash;

        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.regenerate', [$this->agency, $key]))
            ->assertRedirect()
            ->assertSessionHas('new_api_key');

        $key->refresh();
        $this->assertNotSame($oldHash, $key->secret_hash);
        $this->assertNull($key->revoked_at); // regenerate reactivates
    }

    public function test_revoke_disables_without_deleting(): void
    {
        $key = $this->makeKey();
        $this->actingAs($this->user)->post(route('agencies.api-keys.revoke', [$this->agency, $key]))->assertRedirect();

        $key->refresh();
        $this->assertNotNull($key->revoked_at);
        $this->assertFalse($key->isActive());
        $this->assertSame('revoked', $key->statusLabel());
    }

    public function test_delete_soft_deletes_the_key(): void
    {
        $key = $this->makeKey();
        $this->actingAs($this->user)->delete(route('agencies.api-keys.destroy', [$this->agency, $key]))->assertRedirect();

        $this->assertSoftDeleted('agency_api_keys', ['id' => $key->id]);
    }

    public function test_master_switch_toggles_website_enabled(): void
    {
        $this->assertFalse((bool) $this->agency->website_enabled);

        $this->actingAs($this->user)
            ->post(route('agencies.website.toggle', $this->agency), ['website_enabled' => 1])
            ->assertRedirect();
        $this->assertTrue((bool) $this->agency->fresh()->website_enabled);

        $this->actingAs($this->user)
            ->post(route('agencies.website.toggle', $this->agency), ['website_enabled' => 0])
            ->assertRedirect();
        $this->assertFalse((bool) $this->agency->fresh()->website_enabled);
    }

    public function test_cannot_act_on_another_agencys_key(): void
    {
        $otherAgency = Agency::create(['name' => 'Beachfront Props', 'slug' => 'beachfront-props']);
        $minted = AgencyApiKey::mintSecret();
        $foreignKey = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $otherAgency->id, 'name' => 'Foreign', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);

        // Foreign key under THIS agency's route → 404 (tenancy-safe).
        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.revoke', [$this->agency, $foreignKey]))
            ->assertStatus(404);

        $this->assertNull($foreignKey->fresh()->revoked_at);
    }

    private function makeKey(array $overrides = []): AgencyApiKey
    {
        $minted = AgencyApiKey::mintSecret();

        return AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id'   => $this->agency->id,
            'name'        => 'Website',
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ], $overrides));
    }
}
