<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-364 — a supplier firm can be BOTH a transfer attorney AND a bond attorney. Firms like BBB do
 * both, so they must surface in EITHER attorney picker. The legacy `specialty` column is single-
 * valued; the fix adds fixed capability booleans (is_transfer_attorney / is_bond_attorney), backfilled
 * from specialty, and the pickers filter on (capability OR legacy specialty).
 *
 * These tests pin: dual-capable firms surface in both pickers, a single-capable firm does NOT bleed
 * into the other, the legacy-specialty fallback still surfaces an un-flagged row, the directory
 * toggle endpoint flips a transfer firm into the bond picker (Johan's BBB flow), inline-add reuses
 * an existing attorney firm and grants the new capability instead of duplicating, and directory /
 * service creation stamps the capability that matches the chosen specialty.
 */
final class Dr2AttorneyCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Cap Co', 'slug' => 'cap-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => 'super_admin', 'is_admin' => true, 'is_active' => true,
        ]);
    }

    private function makeFirm(array $attrs): AgencyServiceProvider
    {
        return AgencyServiceProvider::create(array_merge([
            'agency_id' => $this->agencyId, 'name' => 'BBB Inc', 'specialty' => 'transfer_attorney', 'is_active' => true,
        ], $attrs));
    }

    /** @return string[] firm names returned by the attorney picker for a specialty. */
    private function searchFirms(string $specialty, string $q = 'BBB'): array
    {
        $resp = $this->actingAs($this->admin)->getJson(route('deals-dr2.attorney.search', ['q' => $q, 'specialty' => $specialty]));
        $resp->assertOk();

        return array_values(array_unique(array_column($resp->json('results'), 'firm')));
    }

    public function test_dual_capable_firm_surfaces_in_both_pickers(): void
    {
        $this->makeFirm(['is_transfer_attorney' => true, 'is_bond_attorney' => true]);

        $this->assertContains('BBB Inc', $this->searchFirms('transfer_attorney'));
        $this->assertContains('BBB Inc', $this->searchFirms('bond_attorney'));
    }

    public function test_transfer_only_firm_is_not_in_the_bond_picker(): void
    {
        $this->makeFirm(['is_transfer_attorney' => true, 'is_bond_attorney' => false, 'specialty' => 'transfer_attorney']);

        $this->assertContains('BBB Inc', $this->searchFirms('transfer_attorney'));
        $this->assertNotContains('BBB Inc', $this->searchFirms('bond_attorney'));
    }

    /** Belt-and-braces: a legacy bond_attorney row whose flag was never set still surfaces (OR specialty). */
    public function test_legacy_specialty_row_without_flag_still_surfaces(): void
    {
        // Raw model create does NOT auto-stamp — mimic a pre-backfill row: specialty set, flag false.
        $firm = $this->makeFirm(['specialty' => 'bond_attorney', 'is_bond_attorney' => false, 'is_transfer_attorney' => false]);
        $this->assertFalse((bool) $firm->fresh()->is_bond_attorney, 'precondition: flag is off');

        $this->assertContains('BBB Inc', $this->searchFirms('bond_attorney'));
    }

    /** Johan's BBB flow: a transfer firm, ticked Bond in the directory, then appears in the bond picker. */
    public function test_capability_toggle_makes_transfer_firm_appear_in_bond_picker(): void
    {
        $firm = $this->makeFirm(['is_transfer_attorney' => true, 'is_bond_attorney' => false]);
        $this->assertNotContains('BBB Inc', $this->searchFirms('bond_attorney'), 'precondition: not yet bond-capable');

        $this->actingAs($this->admin)
            ->postJson(route('deals-v2.suppliers.attorney-capabilities', $firm), [
                'is_transfer_attorney' => true,
                'is_bond_attorney'     => true,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'is_bond_attorney' => true]);

        $this->assertDatabaseHas('agency_service_providers', [
            'id' => $firm->id, 'is_transfer_attorney' => 1, 'is_bond_attorney' => 1,
        ]);
        $this->assertContains('BBB Inc', $this->searchFirms('bond_attorney'));
        $this->assertContains('BBB Inc', $this->searchFirms('transfer_attorney'), 'still a transfer attorney');
    }

    /** The toggle posts BOTH states, so un-ticking one never wipes the other silently. */
    public function test_toggle_off_one_capability_preserves_the_other(): void
    {
        $firm = $this->makeFirm(['is_transfer_attorney' => true, 'is_bond_attorney' => true]);

        $this->actingAs($this->admin)
            ->postJson(route('deals-v2.suppliers.attorney-capabilities', $firm), [
                'is_transfer_attorney' => true,
                'is_bond_attorney'     => false,
            ])->assertOk();

        $this->assertDatabaseHas('agency_service_providers', [
            'id' => $firm->id, 'is_transfer_attorney' => 1, 'is_bond_attorney' => 0,
        ]);
    }

    /** Inline-adding BBB as a bond attorney reuses the existing BBB transfer firm and grants bond. */
    public function test_inline_add_bond_reuses_existing_transfer_firm(): void
    {
        $firm = $this->makeFirm(['is_transfer_attorney' => true, 'is_bond_attorney' => false]);

        $resp = $this->actingAs($this->admin)->postJson(route('deals-dr2.attorney.inline'), [
            'firm' => 'BBB Inc', 'attorney' => 'Attorney Z', 'contact' => 'Bond Clerk', 'specialty' => 'bond_attorney',
        ]);
        $resp->assertCreated();

        $this->assertSame($firm->id, $resp->json('provider_id'), 'reused the existing BBB firm, not duplicated');
        $this->assertSame(1, AgencyServiceProvider::withoutGlobalScopes()->where('name', 'BBB Inc')->count());
        $fresh = $firm->fresh();
        $this->assertTrue((bool) $fresh->is_bond_attorney, 'gained the bond capability');
        $this->assertTrue((bool) $fresh->is_transfer_attorney, 'kept the transfer capability');
    }

    /** Creating a supplier through the directory store stamps the capability that matches the specialty. */
    public function test_directory_store_stamps_capability_from_specialty(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), [
            'name' => 'Cilliers Bond Attorneys', 'specialty' => 'bond_attorney',
        ])->assertRedirect();

        $this->assertDatabaseHas('agency_service_providers', [
            'agency_id' => $this->agencyId, 'name' => 'Cilliers Bond Attorneys',
            'specialty' => 'bond_attorney', 'is_bond_attorney' => 1, 'is_transfer_attorney' => 0,
        ]);
    }

    /** A non-attorney specialty picker is unchanged — capableOf() falls back to plain equality. */
    public function test_non_attorney_specialty_picker_unchanged(): void
    {
        $this->makeFirm(['name' => 'Ori Bonds', 'specialty' => 'bond_originator']);

        $this->assertContains('Ori Bonds', $this->searchFirms('bond_originator', 'Ori'));
        $this->assertNotContains('Ori Bonds', $this->searchFirms('transfer_attorney', 'Ori'));
    }
}
