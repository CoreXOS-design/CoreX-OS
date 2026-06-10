<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\Clause;
use Database\Seeders\DocuperfectSystemClauseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ES-9 residue — clause-library category/is_system schema + system-default
 * seeder. Proves the migration columns exist, the seeder is idempotent
 * (re-runnable without duplicating), and every system clause is categorised.
 */
final class ClauseLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_category_and_is_system_columns(): void
    {
        $cols = \Schema::getColumnListing('docuperfect_clauses');
        $this->assertContains('category', $cols);
        $this->assertContains('is_system', $cols);
    }

    public function test_seeder_seeds_categorised_system_clauses(): void
    {
        $this->seed(DocuperfectSystemClauseSeeder::class);

        $system = Clause::where('is_system', true)->get();
        $this->assertGreaterThanOrEqual(20, $system->count(), 'expected ~20+ system clauses');

        // Every system clause is global, has a valid category, and no owner.
        foreach ($system as $clause) {
            $this->assertTrue($clause->is_global, "{$clause->name} must be global");
            $this->assertNull($clause->owner_id, "{$clause->name} must have no owner");
            $this->assertArrayHasKey(
                Clause::normaliseCategory($clause->category),
                Clause::CATEGORIES,
                "{$clause->name} must carry a valid category",
            );
        }

        // Every category bucket is represented.
        $cats = $system->pluck('category')->unique();
        foreach (array_keys(Clause::CATEGORIES) as $key) {
            $this->assertTrue($cats->contains($key), "category {$key} must have at least one system clause");
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DocuperfectSystemClauseSeeder::class);
        $afterFirst = Clause::where('is_system', true)->count();

        $this->seed(DocuperfectSystemClauseSeeder::class);
        $afterSecond = Clause::where('is_system', true)->count();

        $this->assertSame($afterFirst, $afterSecond, 're-running the seeder must not duplicate system clauses');
    }

    public function test_archived_system_clause_is_not_revived_by_reseed(): void
    {
        $this->seed(DocuperfectSystemClauseSeeder::class);
        $clause = Clause::where('is_system', true)->first();
        $clause->delete(); // soft delete (admin archive)

        $this->seed(DocuperfectSystemClauseSeeder::class);

        $this->assertSoftDeleted('docuperfect_clauses', ['id' => $clause->id]);
        // And no active duplicate was created in its place.
        $this->assertSame(
            1,
            Clause::withTrashed()->where('name', $clause->name)->where('is_system', true)->count(),
            're-seed must update the archived row in place, not create a duplicate',
        );
    }

    public function test_normalise_category_defaults_to_general(): void
    {
        $this->assertSame('general', Clause::normaliseCategory(null));
        $this->assertSame('general', Clause::normaliseCategory('nonsense'));
        $this->assertSame('bond', Clause::normaliseCategory('BOND'));
    }
}
