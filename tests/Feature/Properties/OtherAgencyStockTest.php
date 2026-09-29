<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\OtherAgencyStockConsent;
use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\PropertyExternalSource;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md — content lock (§8), status gate (§7),
 * consent (§3a), dedup (§3), and the unlock request/approve/decline/relock
 * flow (§8a).
 */
class OtherAgencyStockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $otherAgent;
    private User $authorisedUser; // branch_manager — has other_agency_stock.change_status by role_defaults

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->authorisedUser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager']);

        // PermissionService fails OPEN (allow-all) when role_permissions is
        // entirely EMPTY globally (AT-265 test-suite convenience posture),
        // but flips to its real deny-by-default behaviour the moment ANY row
        // exists anywhere (grantsExist() is a global exists() check, not
        // per-agency) — so seeding the status-gate grant below also turns on
        // real enforcement for authorizeProperty()'s properties.view scope
        // check, which every unlock-controller action calls first. Seed both,
        // or the agent's own "request edit access" call 403s before it ever
        // reaches the status gate.
        \App\Models\RolePermission::create([
            'role'           => 'branch_manager',
            'permission_key' => \App\Services\Properties\OtherAgencyStockStatusGate::PERMISSION_KEY,
            'agency_id'      => $this->agency->id,
        ]);
        foreach (['agent', 'branch_manager'] as $role) {
            \App\Models\RolePermission::create([
                'role' => $role, 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id,
            ]);
            // The corex.properties.* route group itself is gated on
            // 'permission:access_properties' middleware — a DIFFERENT key
            // from properties.view's data-scope check.
            \App\Models\RolePermission::create([
                'role' => $role, 'permission_key' => 'access_properties', 'agency_id' => $this->agency->id,
            ]);
        }
    }

    private function makeOtherAgencyStock(?User $agent = null): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id,
            'agent_id'  => ($agent ?? $this->agent)->id,
            'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Listing ' . Str::random(4),
            'suburb' => 'Uvongo',
            'property_type' => 'house',
            'status' => Property::STATUS_OTHER_AGENCY_STOCK,
            'price' => 1500000,
            'description' => 'Original description',
        ]);
    }

    // ── Content lock (§8) ───────────────────────────────────────────────

    public function test_locked_property_refuses_a_price_edit(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->agent);

        $this->expectException(ValidationException::class);
        $p->update(['price' => 999]);
    }

    public function test_locked_property_refuses_a_photo_edit(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->agent);

        $this->expectException(ValidationException::class);
        $p->update(['gallery_images_json' => ['https://example.com/new.jpg']]);
    }

    public function test_notes_matching_and_status_are_not_locked_fields(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->authorisedUser); // may change status

        // Archiving (soft delete) never touches a LOCKED_FIELD.
        $p->delete();
        $this->assertSoftDeleted('properties', ['id' => $p->id]);
    }

    public function test_authorised_user_may_edit_content_directly_without_a_request(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->authorisedUser);

        $p->update(['description' => 'Authorised edit']);
        $this->assertSame('Authorised edit', $p->fresh()->description);
    }

    public function test_property_agent_is_refused_even_when_no_unlock_exists(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->agent);

        $this->expectException(ValidationException::class);
        $p->update(['description' => 'Sneaky edit']);
    }

    public function test_reimport_bypasses_the_lock_via_the_transient_flag(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->agent);

        $p->allowOtherAgencyStockContentWrite = true;
        $p->update(['description' => 'Refreshed from re-import']);
        $this->assertSame('Refreshed from re-import', $p->fresh()->description);
    }

    // ── Status gate (§7) ────────────────────────────────────────────────

    public function test_agent_without_permission_cannot_change_status_away_from_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->agent);

        $this->expectException(ValidationException::class);
        $p->update(['status' => 'active']);
    }

    public function test_authorised_user_can_change_status_away_from_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->actingAs($this->authorisedUser);

        $p->update(['status' => 'active']);
        $this->assertSame('active', $p->fresh()->status);
    }

    public function test_agent_without_permission_cannot_set_status_to_other_agency_stock(): void
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'X', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
        ]);
        $this->actingAs($this->agent);

        $this->expectException(ValidationException::class);
        $p->update(['status' => Property::STATUS_OTHER_AGENCY_STOCK]);
    }

    // ── Unlock flow (§8a) ───────────────────────────────────────────────

    public function test_request_approve_then_agent_may_edit(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->actingAs($this->agent);
        $this->post(route('corex.properties.other-agency-stock.request', $p), ['reason' => 'Buyer wants a correction'])
            ->assertRedirect();

        $this->assertSame('pending', OtherAgencyStockUnlock::currentStateFor($p)['state']);
        $requestRow = OtherAgencyStockUnlock::where('property_id', $p->id)->where('event_type', 'requested')->firstOrFail();

        $this->actingAs($this->authorisedUser);
        $this->post(route('corex.properties.other-agency-stock.decide', [$p, $requestRow]), ['approve' => true])
            ->assertRedirect();

        $this->assertSame('unlocked', OtherAgencyStockUnlock::currentStateFor($p)['state']);

        $this->actingAs($this->agent);
        $p->update(['description' => 'Edited after approval']);
        $this->assertSame('Edited after approval', $p->fresh()->description);
    }

    public function test_declined_request_stays_locked(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->actingAs($this->agent);
        $this->post(route('corex.properties.other-agency-stock.request', $p));
        $requestRow = OtherAgencyStockUnlock::where('property_id', $p->id)->where('event_type', 'requested')->firstOrFail();

        $this->actingAs($this->authorisedUser);
        $this->post(route('corex.properties.other-agency-stock.decide', [$p, $requestRow]), ['approve' => false]);

        $this->assertSame('locked', OtherAgencyStockUnlock::currentStateFor($p)['state']);

        $this->actingAs($this->agent);
        $this->expectException(ValidationException::class);
        $p->update(['description' => 'Still locked']);
    }

    public function test_another_agent_is_refused_even_while_unlocked(): void
    {
        $p = $this->makeOtherAgencyStock($this->agent); // agent owns it

        $this->actingAs($this->agent);
        $this->post(route('corex.properties.other-agency-stock.request', $p));
        $requestRow = OtherAgencyStockUnlock::where('property_id', $p->id)->where('event_type', 'requested')->firstOrFail();

        $this->actingAs($this->authorisedUser);
        $this->post(route('corex.properties.other-agency-stock.decide', [$p, $requestRow]), ['approve' => true]);

        $this->assertSame('unlocked', OtherAgencyStockUnlock::currentStateFor($p)['state']);

        // otherAgent is NOT this property's agent — must still be refused.
        $this->actingAs($this->otherAgent);
        $this->expectException(ValidationException::class);
        $p->update(['description' => 'Wrong agent edit']);
    }

    public function test_authorised_user_can_manually_relock(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->actingAs($this->agent);
        $this->post(route('corex.properties.other-agency-stock.request', $p));
        $requestRow = OtherAgencyStockUnlock::where('property_id', $p->id)->where('event_type', 'requested')->firstOrFail();

        $this->actingAs($this->authorisedUser);
        $this->post(route('corex.properties.other-agency-stock.decide', [$p, $requestRow]), ['approve' => true]);
        $this->assertSame('unlocked', OtherAgencyStockUnlock::currentStateFor($p)['state']);

        $this->post(route('corex.properties.other-agency-stock.relock', $p));
        $this->assertSame('locked', OtherAgencyStockUnlock::currentStateFor($p)['state']);
    }

    // ── Consent (§3a) + dedup (§3) via the import service ───────────────

    public function test_import_without_consent_is_rejected_at_the_http_layer(): void
    {
        $this->actingAs($this->agent);

        $this->postJson(route('v1.other-agency-stock.import'), $this->importPayload(['consent' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);

        $this->assertDatabaseMissing('property_external_sources', ['listing_ref' => 'T99999']);
    }

    public function test_import_rejects_a_non_portal_listing_url(): void
    {
        $this->actingAs($this->agent);

        $this->postJson(route('v1.other-agency-stock.import'), $this->importPayload([
            'listing_url' => 'https://evil.example.com/T99999',
        ]))->assertStatus(422)->assertJsonValidationErrors(['listing_url']);
    }

    public function test_import_service_creates_property_agent_and_records_consent(): void
    {
        Http::fake(['*' => Http::response('', 404)]); // photo downloads no-op

        $property = app(OtherAgencyStockImportService::class)->import($this->importPayload(), $this->agent);

        $this->assertSame(Property::STATUS_OTHER_AGENCY_STOCK, $property->status);
        $this->assertSame($this->agent->id, $property->agent_id);

        $this->assertDatabaseHas('property_external_sources', [
            'property_id' => $property->id, 'portal' => 'pp', 'listing_ref' => 'T99999',
        ]);
        $this->assertSame(1, OtherAgencyStockConsent::where('property_id', $property->id)->count());
    }

    /**
     * 2026-09-29 QA1 real-browser proof — an ordinary agent (no
     * other_agency_stock.change_status grant) got refused importing at all,
     * because the import itself sets status=other_agency_stock and the
     * status gate didn't know to exempt the sanctioned import path from
     * itself. Reproduced here via a REAL authenticated HTTP request (not a
     * direct service call, which never populates auth()->user() and so
     * never actually exercised the gate) — this is the exact gap that let
     * the bug through the original test suite.
     */
    public function test_ordinary_agent_without_change_status_permission_can_still_import_via_the_real_endpoint(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $response = $this->actingAs($this->agent)
            ->postJson(route('v1.other-agency-stock.import'), $this->importPayload());

        $response->assertOk();
        $property = Property::withoutGlobalScope(AgencyScope::class)->find($response->json('property_id'));
        $this->assertSame(Property::STATUS_OTHER_AGENCY_STOCK, $property->status);
        $this->assertSame($this->agent->id, $property->agent_id);
    }

    public function test_reimport_updates_the_same_property_and_relocks(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $service = app(OtherAgencyStockImportService::class);

        $first = $service->import($this->importPayload(), $this->agent);

        // Unlock it, then re-import.
        $this->actingAs($this->agent);
        $this->post(route('corex.properties.other-agency-stock.request', $first));
        $requestRow = OtherAgencyStockUnlock::where('property_id', $first->id)->where('event_type', 'requested')->firstOrFail();
        $this->actingAs($this->authorisedUser);
        $this->post(route('corex.properties.other-agency-stock.decide', [$first, $requestRow]), ['approve' => true]);
        $this->assertSame('unlocked', OtherAgencyStockUnlock::currentStateFor($first)['state']);

        $second = $service->import($this->importPayload(['price' => 2000000]), $this->otherAgent);

        $this->assertSame($first->id, $second->id, 'a re-import must update the SAME property, never create a duplicate');
        $this->assertSame(1, PropertyExternalSource::where('portal', 'pp')->where('listing_ref', 'T99999')->count());
        $this->assertSame(2000000.0, (float) $second->price);
        $this->assertSame($this->otherAgent->id, $second->agent_id, 'importing agent becomes the property agent, including on re-import');
        $this->assertSame(2, OtherAgencyStockConsent::where('property_id', $first->id)->count(), 'every (re-)import records a NEW consent row');
        $this->assertSame('locked', OtherAgencyStockUnlock::currentStateFor($second)['state'], 'a re-import re-locks');
    }

    // ── Visibility resolver (§6) ────────────────────────────────────────

    public function test_default_null_visible_roles_shows_other_agency_stock_to_everyone(): void
    {
        $p = $this->makeOtherAgencyStock();
        $this->assertNull($this->agency->other_agency_stock_visible_roles);

        $this->assertTrue(\App\Services\Properties\OtherAgencyStockVisibility::canSee($this->agent));
        $this->assertTrue(\App\Services\Properties\OtherAgencyStockVisibility::canSee($this->authorisedUser));
    }

    public function test_agency_can_narrow_visibility_to_specific_roles(): void
    {
        $this->agency->update(['other_agency_stock_visible_roles' => ['branch_manager']]);

        $this->assertFalse(\App\Services\Properties\OtherAgencyStockVisibility::canSee($this->agent->fresh()));
        $this->assertTrue(\App\Services\Properties\OtherAgencyStockVisibility::canSee($this->authorisedUser->fresh()));
    }

    public function test_a_role_hidden_from_it_gets_404_on_the_property_page(): void
    {
        $this->agency->update(['other_agency_stock_visible_roles' => ['branch_manager']]);
        $p = $this->makeOtherAgencyStock();

        $this->actingAs($this->agent)
            ->get(route('corex.properties.show', $p))
            ->assertNotFound();

        $this->actingAs($this->authorisedUser)
            ->get(route('corex.properties.show', $p))
            ->assertOk();
    }

    public function test_null_viewer_buyer_share_link_always_sees_it(): void
    {
        $this->agency->update(['other_agency_stock_visible_roles' => ['branch_manager']]);

        $this->assertTrue(\App\Services\Properties\OtherAgencyStockVisibility::canSee(null));
    }

    private function importPayload(array $overrides = []): array
    {
        return array_merge([
            'portal'      => 'pp',
            'listing_ref' => 'T99999',
            'listing_url' => 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/T99999',
            'consent'     => true,
            'price'       => 1500000,
            'property_type' => 'house',
            'beds'        => 3,
            'baths'       => 2,
            'suburb'      => 'Uvongo',
            'description' => 'A lovely home',
            'source_agency_name' => 'Seeff Hibiscus Coast',
            'source_agent_name'  => 'Jane Agent',
        ], $overrides);
    }

    /**
     * 2026-09-29 URGENT FIX regression proof. Johan's real extension (v3.7.1)
     * got "Invalid API token" on every OAS call while the SAME token worked
     * for Pull Property — because this route family sat on auth.portal_capture
     * (users.api_token) while Pull Property/healthCheck/logged-user all sit on
     * auth:sanctum (a real personal_access_tokens row). This test goes through
     * the real Sanctum bearer-token flow end to end — createToken() +
     * Authorization: Bearer — exactly what the extension actually sends, not
     * Sanctum::actingAs() (which bypasses the HTTP auth middleware entirely
     * and would not have caught this bug).
     */
    public function test_consent_wording_and_import_authenticate_with_a_real_sanctum_extension_token(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $token = $this->agent->createToken('corex-extension')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/other-agency-stock/consent-wording')
            ->assertOk()
            ->assertJsonStructure(['wording', 'version', 'agency_name']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/other-agency-stock/import', $this->importPayload(['listing_ref' => 'T-AUTH-1']))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_import_rejects_a_bogus_bearer_token_the_same_way_pull_property_does(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->postJson('/api/v1/other-agency-stock/import', $this->importPayload())
            ->assertUnauthorized();
    }
}
