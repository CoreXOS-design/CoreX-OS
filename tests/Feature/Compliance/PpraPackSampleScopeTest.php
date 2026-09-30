<?php

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PPRA pack job and the sample picker drop ONLY AgencyScope (they resolve
 * the agency explicitly from the pack / effective agency) — soft-deleted rows
 * must stay excluded, and another agency's ids must never count as "owned".
 */
final class PpraPackSampleScopeTest extends TestCase
{
    use RefreshDatabase;

    private function agencyWithProperty(string $name): array
    {
        $agency = Agency::create(['name' => $name, 'slug' => 'ppra-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => $name . ' listing', 'status' => 'active',
        ]);

        return [$agency, $property];
    }

    private function ownedCount(Agency $agency, array $ids): int
    {
        // Exactly the query shape used by GeneratePpraInspectionPackJob / PpraSamplePickerController::store.
        return Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agency->id)->whereIn('id', $ids)->count();
    }

    public function test_a_soft_deleted_sample_is_no_longer_counted_or_bundled(): void
    {
        [$agency, $property] = $this->agencyWithProperty('Coastal');

        $this->assertSame(1, $this->ownedCount($agency, [$property->id]));

        $property->delete(); // soft delete

        $this->assertSame(0, $this->ownedCount($agency, [$property->id]));
    }

    public function test_another_agencys_row_is_never_owned(): void
    {
        [$agency] = $this->agencyWithProperty('Coastal');
        [, $foreign] = $this->agencyWithProperty('Cape Town');

        $this->assertSame(0, $this->ownedCount($agency, [$foreign->id]));
    }
}
