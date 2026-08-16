<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Http\Controllers\DealV2\WorkOrderController;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-319 — a supplier can be MORE THAN ONE type. The directory captures a multi-select of the
 * agency-configurable AgencyServiceType codes; the work-order panel filters its supplier picker by
 * type. This proves the data layer + directory CRUD (the dropdown filter itself is client-side and
 * proven functionally on QA1).
 */
final class SupplierServiceTypesTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        AgencyServiceType::seedDefaultsFor($this->agencyId); // COC, Beetle, Gas, Electric Fence, Plumbing, Other
    }

    public function test_store_supplier_with_multiple_types_creates_pivot_rows(): void
    {
        $resp = $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), [
            'name' => 'Sparky Electrical', 'specialty' => 'electrician',
            'email' => 'sparky@coastal.co.za', 'service_types' => ['COC', 'Gas'],
        ]);
        $resp->assertRedirect();

        $provider = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $this->agencyId)->firstOrFail();
        $this->assertEqualsCanonicalizing(['COC', 'Gas'], $provider->typeCodes());
        $this->assertSame(2, AgencyServiceProviderServiceType::withoutGlobalScopes()
            ->where('service_provider_id', $provider->id)->count());
        // agency stamped on the pivot
        $this->assertSame($this->agencyId, (int) AgencyServiceProviderServiceType::withoutGlobalScopes()
            ->where('service_provider_id', $provider->id)->first()->agency_id);
    }

    public function test_sync_types_adds_present_and_soft_deletes_removed(): void
    {
        $provider = $this->provider(['COC', 'Gas']);

        // Re-sync to {COC, Beetle}: COC stays, Gas removed (soft), Beetle added.
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.types', $provider), [
            'service_types' => ['COC', 'Beetle'],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(['COC', 'Beetle'], $provider->fresh()->typeCodes());
        // Gas is SOFT-deleted, not gone (no hard delete).
        $gas = AgencyServiceProviderServiceType::withTrashed()->withoutGlobalScopes()
            ->where('service_provider_id', $provider->id)->where('service_type', 'Gas')->first();
        $this->assertNotNull($gas);
        $this->assertTrue($gas->trashed(), 'de-selected type is soft-deleted');
    }

    public function test_re_adding_a_removed_type_restores_the_row(): void
    {
        $provider = $this->provider(['COC']);
        // remove all, then re-add COC — should RESTORE the same soft-deleted row (no duplicate).
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.types', $provider), ['service_types' => []])->assertRedirect();
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.types', $provider), ['service_types' => ['COC']])->assertRedirect();

        $this->assertSame(['COC'], $provider->fresh()->typeCodes());
        $this->assertSame(1, AgencyServiceProviderServiceType::withTrashed()->withoutGlobalScopes()
            ->where('service_provider_id', $provider->id)->where('service_type', 'COC')->count(), 'restored, not duplicated');
    }

    public function test_types_less_supplier_is_valid_and_never_500s(): void
    {
        // The lazy-but-valid shortcut: name + specialty, no types.
        $resp = $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), [
            'name' => 'No-Types Co', 'specialty' => 'other',
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHasNoErrors();

        $provider = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'No-Types Co')->firstOrFail();
        $this->assertSame([], $provider->typeCodes());
        $this->assertSame(0, AgencyServiceProviderServiceType::withoutGlobalScopes()
            ->where('service_provider_id', $provider->id)->count());
    }

    public function test_invalid_type_code_is_absorbed_not_persisted(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), [
            'name' => 'Filtered Co', 'specialty' => 'other', 'service_types' => ['COC', 'NOT_A_REAL_CODE'],
        ])->assertRedirect();

        $provider = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Filtered Co')->firstOrFail();
        $this->assertSame(['COC'], $provider->typeCodes(), 'bogus code dropped, valid code kept');
    }

    public function test_work_order_supplier_payload_exposes_types(): void
    {
        $provider = $this->provider(['COC', 'Beetle']);

        $this->actingAs($this->admin); // AgencyScope resolves the acting user's agency
        $ctrl = app(WorkOrderController::class);
        $method = new \ReflectionMethod($ctrl, 'supplierPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($ctrl);

        $row = collect($payload)->firstWhere('id', $provider->id);
        $this->assertNotNull($row, 'supplier present in payload');
        $this->assertEqualsCanonicalizing(['COC', 'Beetle'], $row->getAttribute('types'), 'payload carries the type codes');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function provider(array $codes): AgencyServiceProvider
    {
        $p = AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'name' => 'Multi ' . Str::random(4),
            'specialty' => 'electrician', 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
        foreach ($codes as $c) {
            AgencyServiceProviderServiceType::withoutGlobalScopes()->create([
                'agency_id' => $this->agencyId, 'service_provider_id' => $p->id, 'service_type' => $c,
            ]);
        }

        return $p->fresh();
    }
}
