<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoMatchGroup;
use App\Models\RentalInspectionPhotoMatchGroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §20.16.2 — proves the one-time data
 * migration from the old pairwise `rental_inspection_photo_matches` into
 * the new group tables, specifically its connected-components logic: a
 * transitive chain of pairwise edges (A-B, B-C) must collapse into ONE
 * group of three, not two disconnected groups — the exact case the old
 * pairwise UI could never see (matchFor()/matchPartnerId() only ever
 * compared the two currently-displayed photos directly).
 *
 * The real migration already ran once during RefreshDatabase's own
 * bootstrap (against an empty old table, so it inserted nothing) — these
 * tests seed OLD-shape pairwise rows directly and re-invoke that exact
 * migration file's up() method, the standard way to unit-test a Laravel
 * data migration's logic in isolation.
 */
final class RentalInspectionPhotoMatchGroupMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Property $property;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Migration Agency', 'slug' => 'ri-migration-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id,
            'title' => 'RI Migration Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->in = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->agent->id]);
        $this->out = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->agent->id]);
    }

    private RentalInspection $in;
    private RentalInspection $out;

    private function makePhoto(RentalInspection $inspection, string $name): RentalInspectionPhoto
    {
        return RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'storage_path' => "rental-inspections/{$inspection->id}/{$name}.jpg",
            'uploaded_by_user_id' => $this->agent->id,
        ]);
    }

    private function insertOldPairwiseRow(RentalInspectionPhoto $a, RentalInspectionPhoto $b, \DateTimeInterface $matchedAt): void
    {
        [$idA, $idB] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
        DB::table('rental_inspection_photo_matches')->insert([
            'agency_id' => $this->agency->id,
            'property_id' => $this->property->id,
            'photo_id_a' => $idA,
            'photo_id_b' => $idB,
            'matched_by_user_id' => $this->agent->id,
            'matched_at' => $matchedAt,
            'created_at' => $matchedAt,
            'updated_at' => $matchedAt,
        ]);
    }

    private function runMigration(): void
    {
        (include database_path('migrations/2026_10_03_100200_migrate_pairwise_photo_matches_into_groups.php'))->up();
    }

    public function test_a_single_pairwise_row_becomes_one_group_of_two(): void
    {
        $photoIn = $this->makePhoto($this->in, 'in');
        $photoOut = $this->makePhoto($this->out, 'out');
        $this->insertOldPairwiseRow($photoIn, $photoOut, now()->subDay());

        $this->runMigration();

        $this->assertSame(1, RentalInspectionPhotoMatchGroup::count());
        $group = RentalInspectionPhotoMatchGroup::first();
        $this->assertSame(2, $group->members()->count());
        $this->assertDatabaseHas('rental_inspection_photo_match_group_members', [
            'rental_inspection_photo_match_group_id' => $group->id, 'rental_inspection_photo_id' => $photoIn->id,
        ]);
        $this->assertDatabaseHas('rental_inspection_photo_match_group_members', [
            'rental_inspection_photo_match_group_id' => $group->id, 'rental_inspection_photo_id' => $photoOut->id,
        ]);
    }

    /** The core claim: A-B and B-C, never A-C directly, still collapse into ONE group of three. */
    public function test_a_transitive_chain_collapses_into_one_group_of_three(): void
    {
        $photoA = $this->makePhoto($this->in, 'a');
        $photoB = $this->makePhoto($this->out, 'b');
        $out2 = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->in->lease_id, 'type' => RentalInspection::TYPE_AD_HOC, 'created_by_user_id' => $this->agent->id]);
        $photoC = $this->makePhoto($out2, 'c');

        $this->insertOldPairwiseRow($photoA, $photoB, now()->subDays(2));
        $this->insertOldPairwiseRow($photoB, $photoC, now()->subDay());

        $this->runMigration();

        $this->assertSame(1, RentalInspectionPhotoMatchGroup::count(), 'A-B and B-C must collapse into one group, not two');
        $group = RentalInspectionPhotoMatchGroup::first();
        $this->assertSame(3, $group->members()->count());
        foreach ([$photoA, $photoB, $photoC] as $photo) {
            $this->assertDatabaseHas('rental_inspection_photo_match_group_members', [
                'rental_inspection_photo_match_group_id' => $group->id, 'rental_inspection_photo_id' => $photo->id,
            ]);
        }
    }

    public function test_two_disjoint_pairs_become_two_separate_groups(): void
    {
        $photoA = $this->makePhoto($this->in, 'a');
        $photoB = $this->makePhoto($this->out, 'b');
        $photoC = $this->makePhoto($this->in, 'c');
        $photoD = $this->makePhoto($this->out, 'd');

        $this->insertOldPairwiseRow($photoA, $photoB, now()->subDay());
        $this->insertOldPairwiseRow($photoC, $photoD, now()->subDay());

        $this->runMigration();

        $this->assertSame(2, RentalInspectionPhotoMatchGroup::count());
    }

    /** A soft-deleted (unmatched) old row must not resurrect a link that was deliberately undone. */
    public function test_a_soft_deleted_old_row_is_not_migrated(): void
    {
        $photoIn = $this->makePhoto($this->in, 'in');
        $photoOut = $this->makePhoto($this->out, 'out');
        $this->insertOldPairwiseRow($photoIn, $photoOut, now()->subDay());
        DB::table('rental_inspection_photo_matches')->update(['deleted_at' => now()]);

        $this->runMigration();

        $this->assertSame(0, RentalInspectionPhotoMatchGroup::count());
    }

    /** The old table is a historical record, not a live dependency — the migration must never touch its rows. */
    public function test_the_old_table_is_left_completely_untouched(): void
    {
        $photoIn = $this->makePhoto($this->in, 'in');
        $photoOut = $this->makePhoto($this->out, 'out');
        $this->insertOldPairwiseRow($photoIn, $photoOut, now()->subDay());
        $before = DB::table('rental_inspection_photo_matches')->get();

        $this->runMigration();

        $after = DB::table('rental_inspection_photo_matches')->get();
        $this->assertEquals($before, $after);
    }

    public function test_running_with_no_old_data_creates_no_groups(): void
    {
        $this->runMigration();

        $this->assertSame(0, RentalInspectionPhotoMatchGroup::count());
        $this->assertSame(0, RentalInspectionPhotoMatchGroupMember::count());
    }
}
