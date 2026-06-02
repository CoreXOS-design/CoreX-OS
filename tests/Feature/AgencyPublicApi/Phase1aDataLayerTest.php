<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 1a (data layer) verification.
 *
 * Proves the migrations apply on the schema snapshot, the three new models
 * behave (casts, soft deletes, secret minting/verification, state helpers),
 * the new agency/user columns default safely, and — critically — that
 * AgencyApiKey is tenant-isolated by the existing AgencyScope.
 *
 * Spec: .ai/specs/agency-public-api.md §3, §11
 */
class Phase1aDataLayerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyA = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $this->agencyB = Agency::create(['name' => 'Beachfront Props', 'slug' => 'beachfront-props']);

        $branchA = Branch::create(['agency_id' => $this->agencyA->id, 'name' => 'A Main']);
        $branchB = Branch::create(['agency_id' => $this->agencyB->id, 'name' => 'B Main']);

        $this->userA = User::factory()->create([
            'agency_id' => $this->agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin',
        ]);
        $this->userB = User::factory()->create([
            'agency_id' => $this->agencyB->id, 'branch_id' => $branchB->id, 'role' => 'admin',
        ]);
    }

    /** Migrations created every new table. */
    public function test_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('agency_api_keys'));
        $this->assertTrue(Schema::hasTable('agency_webhook_deliveries'));
        $this->assertTrue(Schema::hasTable('property_website_syndication'));
        $this->assertTrue(Schema::hasColumn('agencies', 'website_enabled'));
        $this->assertTrue(Schema::hasColumn('agencies', 'website_url'));
        $this->assertTrue(Schema::hasColumn('users', 'show_on_website'));
    }

    /** New flags default to the safe (off) value — no accidental exposure. */
    public function test_new_flags_default_off(): void
    {
        $this->assertFalse((bool) $this->agencyA->fresh()->website_enabled);
        $this->assertFalse((bool) $this->userA->fresh()->show_on_website);
    }

    /** mintSecret produces a prefix + secret whose hash verifies, plaintext never stored. */
    public function test_secret_minting_and_verification(): void
    {
        $minted = AgencyApiKey::mintSecret();

        $this->assertStringStartsWith('cx_live_', $minted['prefix']);
        $this->assertSame($minted['prefix'] . '.' . $minted['secret'], $minted['plaintext']);
        $this->assertSame(hash('sha256', $minted['secret']), $minted['hash']);

        $this->actingAs($this->userA);
        $key = AgencyApiKey::create([
            'name'        => 'Production website',
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_AGENTS_READ],
            'created_by'  => $this->userA->id,
        ]);

        $this->assertTrue($key->verifySecret($minted['secret']));
        $this->assertFalse($key->verifySecret('wrong-secret'));

        // Hash stored, plaintext never persisted.
        $this->assertDatabaseHas('agency_api_keys', ['id' => $key->id, 'secret_hash' => $minted['hash']]);
        $this->assertDatabaseMissing('agency_api_keys', ['secret_hash' => $minted['secret']]);
    }

    /** scopes casts to array; hasScope reflects membership. */
    public function test_scopes_cast_and_membership(): void
    {
        $this->actingAs($this->userA);
        $key = $this->makeKey($this->agencyA, ['scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ]]);

        $this->assertIsArray($key->fresh()->scopes);
        $this->assertTrue($key->hasScope(AgencyApiKey::SCOPE_LISTINGS_READ));
        $this->assertFalse($key->hasScope(AgencyApiKey::SCOPE_AGENTS_READ));
    }

    /** isActive / revoked / expired state machine. */
    public function test_state_helpers(): void
    {
        $this->actingAs($this->userA);

        $active = $this->makeKey($this->agencyA);
        $this->assertTrue($active->isActive());
        $this->assertSame('active', $active->statusLabel());

        $revoked = $this->makeKey($this->agencyA, ['revoked_at' => now()]);
        $this->assertFalse($revoked->isActive());
        $this->assertSame('revoked', $revoked->statusLabel());

        $expired = $this->makeKey($this->agencyA, ['expires_at' => now()->subDay()]);
        $this->assertFalse($expired->isActive());
        $this->assertSame('expired', $expired->statusLabel());
    }

    /** AgencyApiKey is tenant-isolated: user A never sees agency B's keys. */
    public function test_keys_are_tenant_isolated(): void
    {
        $this->actingAs($this->userA);
        $this->makeKey($this->agencyA, ['name' => 'A site']);

        $this->actingAs($this->userB);
        $this->makeKey($this->agencyB, ['name' => 'B site']);

        $this->actingAs($this->userA);
        $visible = AgencyApiKey::all();
        $this->assertCount(1, $visible);
        $this->assertSame('A site', $visible->first()->name);

        $this->actingAs($this->userB);
        $this->assertCount(1, AgencyApiKey::all());
        $this->assertSame('B site', AgencyApiKey::first()->name);
    }

    /** Soft deletes archive rather than hard-delete (non-negotiable #1). */
    public function test_soft_deletes(): void
    {
        $this->actingAs($this->userA);
        $key = $this->makeKey($this->agencyA);
        $id = $key->id;

        $key->delete();

        $this->assertSoftDeleted('agency_api_keys', ['id' => $id]);
        $this->assertCount(0, AgencyApiKey::all());
        $this->assertCount(1, AgencyApiKey::withTrashed()->get());
    }

    /** Webhook delivery + per-(property×website) syndication rows persist and cast. */
    public function test_related_models_persist(): void
    {
        $this->actingAs($this->userA);
        $key = $this->makeKey($this->agencyA);

        $property = Property::create([
            'agency_id' => $this->agencyA->id,
            'agent_id'  => $this->userA->id,
            'branch_id' => $this->userA->branch_id,
            'external_id' => (string) Str::uuid(),
            'title'     => 'Sea-view 3 bed',
            'suburb'    => 'Uvongo',
            'property_type' => 'house',
            'status'    => 'active',
            'price'     => 2495000,
        ]);

        $syn = PropertyWebsiteSyndication::create([
            'property_id'       => $property->id,
            'agency_api_key_id' => $key->id,
            'enabled'           => true,
            'status'            => PropertyWebsiteSyndication::STATUS_ACTIVE,
        ]);
        $this->assertTrue($syn->fresh()->enabled);
        $this->assertSame($this->agencyA->id, (int) $syn->fresh()->agency_id);

        $delivery = AgencyWebhookDelivery::create([
            'agency_api_key_id' => $key->id,
            'event_name'        => 'listing.published',
            'payload'           => ['id' => $property->id, 'ref' => 'CX123'],
            'attempts'          => 1,
        ]);
        $this->assertIsArray($delivery->fresh()->payload);
        $this->assertSame('CX123', $delivery->fresh()->payload['ref']);
        $this->assertSame(1, $key->webhookDeliveries()->count());
    }

    private function makeKey(Agency $agency, array $overrides = []): AgencyApiKey
    {
        $minted = AgencyApiKey::mintSecret();

        return AgencyApiKey::create(array_merge([
            'agency_id'   => $agency->id,
            'name'        => 'Website',
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ], $overrides));
    }
}
