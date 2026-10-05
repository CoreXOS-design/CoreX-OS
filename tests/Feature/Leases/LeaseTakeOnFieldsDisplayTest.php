<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §6 — escalation %/next date, opening
 * arrears, and last inspection date captured on a take-on import are
 * read-only facts on the Lease Hub screen. Not shown at all when none were
 * captured (BUILD_STANDARD §2 — optional-and-empty must never show a bare
 * label with nothing after it).
 */
final class LeaseTakeOnFieldsDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_lease_hub_shows_the_takeon_facts_when_captured(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'source' => Lease::SOURCE_MIGRATED_TAKEON,
            'migrated_from_table' => 'rental_take_on_import_rows',
            'migrated_from_id' => 1,
            'migrated_escalation_percent' => 8.0,
            'migrated_next_escalation_date' => '2027-04-01',
            'migrated_opening_arrears' => 1500.50,
            'migrated_last_inspection_date' => '2026-07-01',
        ]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('As captured at take-on');
        $response->assertSee('8.00%', false);
        $response->assertSee('2027-04-01');
        $response->assertSee('1,500.50', false);
        $response->assertSee('not posted to any ledger');
        $response->assertSee('2026-07-01');
    }

    public function test_lease_hub_hides_the_takeon_block_entirely_when_nothing_was_captured(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertDontSee('As captured at take-on');
    }

    /**
     * @return array{0: Agency, 1: Branch, 2: Property}
     */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function baseLeaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);
    }
}
