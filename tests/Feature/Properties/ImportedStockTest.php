<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Console\Commands\BackfillP24ImportedStock;
use App\Jobs\ConfirmP24PropertyRowJob;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-419 — Imported Stock. Spec: .ai/specs/at419-imported-stock.md
 *
 * The split: active P24-imported stock stays on Properties, unchanged; every
 * off-market status from an import (withdrawn, sold, expired, …) lives on the
 * new Imported Stock page instead, tagged "Imported" with an Imported Date.
 * Non-imported properties are never affected, whatever their status.
 */
final class ImportedStockTest extends TestCase
{
    use RefreshDatabase;

    // ── The partition itself ────────────────────────────────────────────

    public function test_imported_off_market_property_appears_on_imported_stock_not_properties(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Imported', [
            'status' => 'withdrawn',
            'p24_imported_at' => now(),
        ]);

        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Imported');
        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertSee('ZZZ-Withdrawn-Imported');
    }

    public function test_imported_active_property_stays_on_properties_unchanged(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Active-Imported', [
            'status' => 'for_sale',
            'p24_imported_at' => now(),
        ]);

        $res = $this->get(route('corex.properties.index'))->assertOk();
        $res->assertSee('ZZZ-Active-Imported');
        // Unchanged means no "Imported" tag leaks onto the Properties page.
        $res->assertDontSee('Imported from Property24', false);

        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertDontSee('ZZZ-Active-Imported');
    }

    public function test_non_imported_off_market_property_stays_on_properties(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Off-market, but never touched by the P24 importer — must be totally unaffected.
        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Manual', [
            'status' => 'withdrawn',
            'p24_imported_at' => null,
        ]);

        $this->get(route('corex.properties.index'))->assertOk()->assertSee('ZZZ-Withdrawn-Manual');
        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Manual');
    }

    /**
     * Real P24 imports often store status capitalised ("Withdrawn") rather than
     * the model's lowercase snake_case OFF_MARKET_STATUSES. The split must not
     * strand these on the wrong page.
     */
    public function test_capitalised_import_status_still_partitions_to_imported_stock(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Capitalised', [
            'status' => 'Withdrawn',
            'p24_imported_at' => now(),
        ]);

        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Capitalised');
        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertSee('ZZZ-Withdrawn-Capitalised');
    }

    public function test_imported_stock_page_shows_tag_and_imported_date(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Sold-Imported', [
            'status' => 'sold',
            'p24_imported_at' => now()->setDate(2026, 8, 1),
        ]);

        $res = $this->get(route('corex.properties.imported-stock'))->assertOk();
        $res->assertSee('Imported from Property24', false);
        $res->assertSee('1 Aug 2026', false);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_imported_stock_route_denied_without_its_own_permission(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        // Seed SOME grants (so the "unseeded → allow all" test fallback doesn't
        // apply) but deliberately withhold access_imported_stock. properties.view
        // (with a scope) is paired alongside access_properties in every real role
        // grant — without it, PropertyController::index()'s dataScope resolves to
        // null and the page 500s regardless of this test's concern.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => $agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'agency_id' => $agencyId, 'scope' => 'own']);

        $this->actingAs($agent);

        $this->get(route('corex.properties.imported-stock'))->assertForbidden();
        // access_properties alone must not leak into the new page.
        $this->get(route('corex.properties.index'))->assertOk();
    }

    // ── ConfirmP24PropertyRowJob stamping ────────────────────────────────

    public function test_confirm_job_stamps_p24_imported_at_on_first_confirm(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'importing',
        ]);
        $row = P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'pending', 'resolved_agent_id' => $admin->id,
            'mapped_json' => [
                'p24_listing_number' => (string) random_int(1000000, 9999999),
                'title' => 'Confirm Job Test', 'status' => 'Withdrawn', 'price' => 900000,
            ],
        ]);

        (new ConfirmP24PropertyRowJob($row->id, $admin->id))->handle();

        $property = Property::withoutGlobalScopes()->findOrFail($row->fresh()->target_id);
        $this->assertNotNull($property->p24_imported_at);
        $this->assertTrue($property->p24_imported_at->diffInMinutes(now()) < 1);
    }

    public function test_reconfirm_does_not_reset_an_already_stamped_imported_date(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $original = now()->subDays(30);
        $property = $this->property($agencyId, $admin, 'ZZZ-Reimport', [
            'status' => 'for_sale',
            'p24_listing_number' => '5551234',
            'p24_ref' => '5551234',
            'p24_imported_at' => $original,
        ]);

        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'importing',
        ]);
        $row = P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => '5551234', 'status' => 'pending', 'resolved_agent_id' => $admin->id,
            'mapped_json' => ['p24_listing_number' => '5551234', 'title' => 'ZZZ-Reimport', 'status' => 'Withdrawn'],
        ]);

        (new ConfirmP24PropertyRowJob($row->id, $admin->id))->handle();

        $property->refresh();
        $this->assertSame($original->toDateTimeString(), $property->p24_imported_at->toDateTimeString());
        // The re-import DID update the status though (a real refresh) — confirms
        // this wasn't just a job no-op.
        $this->assertSame('Withdrawn', $property->status);
    }

    // ── Backfill command ─────────────────────────────────────────────────

    public function test_backfill_dry_run_makes_no_changes(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $property = $this->property($agencyId, $admin, 'ZZZ-Backfill-Candidate', [
            'status' => 'sold', 'p24_imported_at' => null,
        ]);
        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'completed',
        ]);
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $property->id,
            'confirmed_at' => now()->subDays(10), 'mapped_json' => [],
        ]);

        $this->artisan(BackfillP24ImportedStock::class)->assertSuccessful();

        $this->assertNull($property->fresh()->p24_imported_at);
    }

    public function test_backfill_apply_stamps_only_null_rows_with_earliest_confirmed_at(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $property = $this->property($agencyId, $admin, 'ZZZ-Backfill-Apply', [
            'status' => 'sold', 'p24_imported_at' => null,
        ]);
        $alreadyStamped = $this->property($agencyId, $admin, 'ZZZ-Already-Stamped', [
            'status' => 'sold', 'p24_imported_at' => now(),
        ]);
        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'completed',
        ]);
        $earliest = now()->subDays(20);
        // Two confirm rows for the SAME property (a re-import) — earliest wins.
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $property->id,
            'confirmed_at' => $earliest, 'mapped_json' => [],
        ]);
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $property->id,
            'confirmed_at' => now()->subDays(5), 'mapped_json' => [],
        ]);
        // A row pointing at the ALREADY-stamped property — must be left alone.
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $alreadyStamped->id,
            'confirmed_at' => now()->subDays(1), 'mapped_json' => [],
        ]);
        $untouchedOriginal = $alreadyStamped->p24_imported_at->toDateTimeString();

        $this->artisan(BackfillP24ImportedStock::class, ['--apply' => true])->assertSuccessful();

        $this->assertSame($earliest->toDateTimeString(), $property->fresh()->p24_imported_at->toDateTimeString());
        $this->assertSame($untouchedOriginal, $alreadyStamped->fresh()->p24_imported_at->toDateTimeString());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'admin',
        ])];
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
        ], $attrs));
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
