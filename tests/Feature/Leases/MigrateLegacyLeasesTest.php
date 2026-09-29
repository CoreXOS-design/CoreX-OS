<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §6 — "replace, not extend," nothing deleted. Covers
 * the two outcomes that matter: a confidently-matched legacy row becomes a
 * real Lease (source data preserved, nothing lost), and an ambiguous one is
 * left alone rather than guessed — plus that the source row is genuinely
 * never touched, and a second run never duplicates.
 */
final class MigrateLegacyLeasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_confidently_matched_rental_row_migrates_into_a_real_lease(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $uniqueStreet = 'Zzqx Unique Migration Street ' . uniqid();
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Migration test property',
            'status' => 'active', 'listing_type' => 'rental',
            'street_name' => $uniqueStreet, 'street_number' => '42',
        ]);

        $rental = Rental::create([
            'branch_id' => $branch->id,
            'lease_address' => '42 ' . $uniqueStreet,
            'lease_start_date' => '2026-01-01',
            'lease_end_date' => '2026-12-31',
            'is_active' => true,
            'created_by_user_id' => $agent->id,
        ]);

        $this->artisan('leases:migrate-legacy')->assertSuccessful();

        $lease = Lease::where('migrated_from_table', 'rentals')->where('migrated_from_id', $rental->id)->first();
        self::assertNotNull($lease, 'Expected a Lease to be created for the confidently-matched rentals row.');
        self::assertSame($property->id, $lease->property_id);
        self::assertSame('active', $lease->status);
        self::assertSame('migrated_legacy', $lease->source);
        self::assertSame('2026-01-01', $lease->start_date->toDateString());
        self::assertSame('2026-12-31', $lease->end_date->toDateString());

        // The source row is untouched -- non-negotiable #1, nothing deleted.
        self::assertNotNull(Rental::find($rental->id));
        self::assertNull($rental->fresh()->deleted_at);
    }

    public function test_an_unresolvable_address_is_left_unmigrated_not_guessed(): void
    {
        $branch = Branch::create(['agency_id' => Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()])->id, 'name' => 'Branch A']);

        $rental = Rental::create([
            'branch_id' => $branch->id,
            'lease_address' => 'Nonexistent Address That Matches No Property ' . uniqid(),
            'lease_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->artisan('leases:migrate-legacy')->assertSuccessful();

        self::assertNull(Lease::where('migrated_from_table', 'rentals')->where('migrated_from_id', $rental->id)->first());
        self::assertNotNull(Rental::find($rental->id), 'Source row must remain untouched when unresolved.');
    }

    public function test_running_the_migration_twice_does_not_duplicate(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $uniqueStreet = 'Zzqx Idempotency Street ' . uniqid();
        Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Idempotency test property',
            'status' => 'active', 'listing_type' => 'rental',
            'street_name' => $uniqueStreet, 'street_number' => '7',
        ]);

        $rental = Rental::create([
            'branch_id' => $branch->id,
            'lease_address' => '7 ' . $uniqueStreet,
            'lease_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->artisan('leases:migrate-legacy')->assertSuccessful();
        $this->artisan('leases:migrate-legacy')->assertSuccessful();

        self::assertSame(1, Lease::where('migrated_from_table', 'rentals')->where('migrated_from_id', $rental->id)->count());
    }
}
