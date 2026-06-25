<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Properties index — filter persistence + multi-agent select.
 *
 * Guards two behaviours the user reported broken:
 *  1. The active filter set (esp. the chosen agent set) survives navigation
 *     for the life of the session — a bare visit restores it via redirect,
 *     and ?clear=1 wipes it.
 *  2. The agent filter accepts MULTIPLE agents (?agent_ids=a,b) and scopes the
 *     listing to exactly that set; "all" lifts the restriction.
 */
final class PropertyFilterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_ids_filters_to_the_selected_set_and_all_lifts_it(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $agentA = $this->agencyUser($agency, 'agent');
        $agentB = $this->agencyUser($agency, 'agent');
        $agentC = $this->agencyUser($agency, 'agent');

        $this->actingAs($admin);
        $pA = $this->property($agency, $agentA, 'ZZZ-Alpha-House');
        $pB = $this->property($agency, $agentB, 'ZZZ-Bravo-House');
        $pC = $this->property($agency, $agentC, 'ZZZ-Charlie-House');

        // Multi-select A + B → only those two.
        $this->get(route('corex.properties.index', ['agent_ids' => $agentA->id . ',' . $agentB->id]))
            ->assertOk()
            ->assertSee('ZZZ-Alpha-House')
            ->assertSee('ZZZ-Bravo-House')
            ->assertDontSee('ZZZ-Charlie-House');

        // All agents → every listing.
        $this->get(route('corex.properties.index', ['agent_ids' => 'all']))
            ->assertOk()
            ->assertSee('ZZZ-Alpha-House')
            ->assertSee('ZZZ-Bravo-House')
            ->assertSee('ZZZ-Charlie-House');
    }

    public function test_filter_state_persists_across_a_bare_visit_then_clears(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $agentA = $this->agencyUser($agency, 'agent');

        $this->actingAs($admin);

        // 1. Apply an explicit agent filter — this seeds the session.
        $this->get(route('corex.properties.index', ['agent_ids' => (string) $agentA->id]))
            ->assertOk();

        // 2. A bare visit restores the saved set by redirecting to the canonical URL.
        $this->get(route('corex.properties.index'))
            ->assertRedirect()
            ->assertRedirectContains('agent_ids=' . $agentA->id);

        // 3. Clear wipes the session and redirects to the bare index...
        $this->get(route('corex.properties.index', ['clear' => 1]))
            ->assertRedirect(route('corex.properties.index'));

        // 4. ...so the next bare visit no longer redirects (defaults to "my").
        $this->get(route('corex.properties.index'))
            ->assertOk();
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();
        // 'admin' → getDataScope('properties') = 'all' on an unseeded DB, so the
        // agent picker (canPickAgent) is enabled.
        return [$agencyId, $this->agencyUser($agencyId, 'admin')];
    }

    private function agencyUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, User $agent, string $title): Property
    {
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
        ]);
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
