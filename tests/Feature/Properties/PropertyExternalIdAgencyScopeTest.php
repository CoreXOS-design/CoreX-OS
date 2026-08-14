<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * properties.external_id doubles as the P24 listing number for P24-sourced
 * stock. P24 listing numbers are only unique within P24's own catalogue —
 * two different agencies can legitimately be handed the same P24 listing
 * (dual mandate, or overlapping sandbox/test data). A plain global
 * UNIQUE(external_id) made a second agency's import of an already-imported
 * external_id fail with a 1062 duplicate-entry error on every such row.
 *
 * Reproduced live 2026-08-14: a P24 import for Demo Agency Test (agency 17)
 * confirmed 0 of 4,753 rows, all colliding with agency_id=1's pre-existing
 * stock. Fixed by scoping the unique index to (agency_id, external_id) —
 * migration 2026_08_14_162800_scope_properties_external_id_unique_to_agency,
 * matching the pattern already used by communications.external_id.
 */
final class PropertyExternalIdAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);

        return [$agency, $branch, $agent];
    }

    /** THE bug: a second agency importing a P24 listing the first agency already has. */
    public function test_two_agencies_can_each_hold_a_property_with_the_same_external_id(): void
    {
        [$agencyA, $branchA, $agentA] = $this->makeAgency();
        [$agencyB, $branchB, $agentB] = $this->makeAgency();

        Property::create([
            'title'       => 'Listing on Agency A',
            'agency_id'   => $agencyA->id,
            'agent_id'    => $agentA->id,
            'branch_id'   => $branchA->id,
            'price'       => 1_000_000,
            'external_id' => '104223686',
        ]);

        // Must NOT throw — this is the exact 1062 that blocked the live import.
        $b = Property::create([
            'title'       => 'Same P24 listing on Agency B',
            'agency_id'   => $agencyB->id,
            'agent_id'    => $agentB->id,
            'branch_id'   => $branchB->id,
            'price'       => 1_050_000,
            'external_id' => '104223686',
        ]);

        $this->assertSame('104223686', $b->external_id);
        $this->assertSame(2, Property::where('external_id', '104223686')->count());
    }

    /** The constraint still guards what it must: one agency, one row per external_id. */
    public function test_the_same_agency_cannot_hold_two_properties_with_the_same_external_id(): void
    {
        [$agency, $branch, $agent] = $this->makeAgency();

        Property::create([
            'title'       => 'First import',
            'agency_id'   => $agency->id,
            'agent_id'    => $agent->id,
            'branch_id'   => $branch->id,
            'price'       => 1_000_000,
            'external_id' => '104223686',
        ]);

        $this->expectException(QueryException::class);
        Property::create([
            'title'       => 'Re-import of the same listing',
            'agency_id'   => $agency->id,
            'agent_id'    => $agent->id,
            'branch_id'   => $branch->id,
            'price'       => 1_000_000,
            'external_id' => '104223686',
        ]);
    }
}
