<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §22 ruling (Johan, 2026-09-29) — proves migration
 * 2026_10_06_090000_relabel_sale_inventory_landlord_signatures_to_seller
 * does exactly what it claims: relabels a SALE property's property-level
 * inventory signature from 'landlord' to 'seller', leaves a RENTAL
 * property's own vacant-between-tenancies inventory ('landlord', also
 * lease_id null) untouched, and is idempotent (a second run touches
 * nothing).
 *
 * RefreshDatabase runs every migration — including this one — BEFORE the
 * test body executes, so the legacy 'landlord' rows this migration exists
 * to fix cannot be seeded through the normal model layer (RentalInventorySignature
 * ::capture() already refuses the wrong role by the time this test runs).
 * This test therefore writes the pre-migration-shaped row directly via
 * DB::table (bypassing model validation, matching exactly what the real
 * legacy data looks like) and re-runs the migration's own up() directly.
 */
final class RentalInventorySaleSignatureRelabelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_relabels_only_the_sale_propertys_property_level_signature(): void
    {
        $agency = Agency::create(['name' => 'Relabel Migration Agency', 'slug' => 'relabel-mig-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $saleProperty = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Sale Property For Relabel', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $vacantRentalProperty = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Vacant Rental For Relabel', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $saleInventory = RentalInventory::startForProperty($saleProperty, $agent);
        $vacantRentalInventory = RentalInventory::startForProperty($vacantRentalProperty, $agent);

        // Pre-migration-shaped legacy rows — both stored as 'landlord',
        // exactly what every property-level inventory's owner signature
        // looked like before §18.
        $saleSigId = DB::table('rental_inventory_signatures')->insertGetId([
            'agency_id' => $agency->id, 'rental_inventory_id' => $saleInventory->id,
            'party_role' => 'landlord', 'party_contact_id' => null,
            'disposition' => 'signed', 'party_signature_path' => '/fake/sig.png',
            'disposition_recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rentalSigId = DB::table('rental_inventory_signatures')->insertGetId([
            'agency_id' => $agency->id, 'rental_inventory_id' => $vacantRentalInventory->id,
            'party_role' => 'landlord', 'party_contact_id' => null,
            'disposition' => 'signed', 'party_signature_path' => '/fake/sig.png',
            'disposition_recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_10_06_090000_relabel_sale_inventory_landlord_signatures_to_seller.php');
        $migration->up();

        self::assertSame('seller', DB::table('rental_inventory_signatures')->find($saleSigId)->party_role,
            'The sale property\'s owner signature must be relabelled to seller.');
        self::assertSame('landlord', DB::table('rental_inventory_signatures')->find($rentalSigId)->party_role,
            'A vacant RENTAL property\'s owner signature must stay landlord — it is not a sale.');

        // Idempotent: running it again changes nothing further.
        $migration->up();
        self::assertSame('seller', DB::table('rental_inventory_signatures')->find($saleSigId)->party_role);
        self::assertSame('landlord', DB::table('rental_inventory_signatures')->find($rentalSigId)->party_role);
    }
}
