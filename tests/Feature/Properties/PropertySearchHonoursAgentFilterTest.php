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
 * Properties index — a typed search honours the active agent filter.
 *
 * Reported 2026-09-13: an admin filtered the list to one agent, typed a
 * property name into the search box, and the results came back for EVERY
 * agent. The second cut of AT-394 had made a search discard any agent filter
 * and widen to the whole agency. Ruling (2026-09-13): a chosen filter — the
 * "My Properties" default included — is honoured until the user removes it;
 * search narrows WITHIN it. "All Agents" is the only thing that widens.
 *
 * The original AT-394 case is preserved: a plain agent (no agent picker, so
 * no filter to remove) still finds a colleague's listing by search, rendered
 * read-only, so they don't re-create a duplicate.
 */
final class PropertySearchHonoursAgentFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_stays_within_an_explicitly_picked_agent(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $agentA = $this->agencyUser($agency, 'agent');
        $agentB = $this->agencyUser($agency, 'agent');

        $this->property($agency, $agentA, 'ZZZ-Beachfront-Alpha');
        $this->property($agency, $agentB, 'ZZZ-Beachfront-Bravo');
        $this->property($agency, $admin,  'ZZZ-Beachfront-Admin');

        $this->actingAs($admin)
            ->get(route('corex.properties.index', [
                'agent_ids' => (string) $agentA->id,
                'search'    => 'ZZZ-Beachfront',
            ]))
            ->assertOk()
            ->assertSee('ZZZ-Beachfront-Alpha')
            ->assertDontSee('ZZZ-Beachfront-Bravo')
            ->assertDontSee('ZZZ-Beachfront-Admin');
    }

    public function test_search_stays_within_the_my_properties_default(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $agentA = $this->agencyUser($agency, 'agent');

        $this->property($agency, $agentA, 'ZZZ-Beachfront-Alpha');
        $this->property($agency, $admin,  'ZZZ-Beachfront-Admin');

        // Fresh visit, no agent signal at all: the page lands on "My Properties",
        // and a search from there stays on "My Properties".
        $this->actingAs($admin)
            ->get(route('corex.properties.index', ['search' => 'ZZZ-Beachfront']))
            ->assertOk()
            ->assertSee('ZZZ-Beachfront-Admin')
            ->assertDontSee('ZZZ-Beachfront-Alpha');
    }

    public function test_search_covers_the_agency_once_the_agent_filter_is_removed(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $agentA = $this->agencyUser($agency, 'agent');
        $agentB = $this->agencyUser($agency, 'agent');

        $this->property($agency, $agentA, 'ZZZ-Beachfront-Alpha');
        $this->property($agency, $agentB, 'ZZZ-Beachfront-Bravo');
        $this->property($agency, $admin,  'ZZZ-Beachfront-Admin');

        $this->actingAs($admin)
            ->get(route('corex.properties.index', [
                'agent_ids' => 'all',
                'search'    => 'ZZZ-Beachfront',
            ]))
            ->assertOk()
            ->assertSee('ZZZ-Beachfront-Alpha')
            ->assertSee('ZZZ-Beachfront-Bravo')
            ->assertSee('ZZZ-Beachfront-Admin');
    }

    public function test_search_narrows_within_a_multi_agent_pick(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $agentA = $this->agencyUser($agency, 'agent');
        $agentB = $this->agencyUser($agency, 'agent');
        $agentC = $this->agencyUser($agency, 'agent');

        $this->property($agency, $agentA, 'ZZZ-Beachfront-Alpha');
        $this->property($agency, $agentB, 'ZZZ-Beachfront-Bravo');
        $this->property($agency, $agentC, 'ZZZ-Beachfront-Charlie');

        $this->actingAs($admin)
            ->get(route('corex.properties.index', [
                'agent_ids' => $agentA->id . ',' . $agentB->id,
                'search'    => 'ZZZ-Beachfront',
            ]))
            ->assertOk()
            ->assertSee('ZZZ-Beachfront-Alpha')
            ->assertSee('ZZZ-Beachfront-Bravo')
            ->assertDontSee('ZZZ-Beachfront-Charlie');
    }

    public function test_plain_agent_search_still_finds_a_colleagues_listing_read_only(): void
    {
        [$agency] = $this->agencyWithAdmin();
        $me        = $this->agencyUser($agency, 'agent');
        $colleague = $this->agencyUser($agency, 'agent');

        $mine   = $this->property($agency, $me,        'ZZZ-Beachfront-Mine');
        $theirs = $this->property($agency, $colleague, 'ZZZ-Beachfront-Theirs');

        // AT-394 — no agent picker for a plain agent, so nothing to remove: a
        // search widens to the agency, the colleague's row flagged read-only.
        $response = $this->actingAs($me)
            ->get(route('corex.properties.index', ['search' => 'ZZZ-Beachfront']))
            ->assertOk()
            ->assertSee('ZZZ-Beachfront-Mine')
            ->assertSee('ZZZ-Beachfront-Theirs');

        $rows = $response->viewData('properties')->getCollection()->keyBy('id');
        $this->assertFalse((bool) $rows[$mine->id]->owned_by_other,   'own listing must not be read-only');
        $this->assertTrue((bool) $rows[$theirs->id]->owned_by_other,  'colleague listing must be read-only');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();
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
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
