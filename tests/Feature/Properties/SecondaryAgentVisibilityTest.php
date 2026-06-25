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
 * Properties index — co-listing (secondary agent) visibility.
 *
 * A property may carry a secondary agent on `pp_second_agent_id`. The secondary
 * agent must see that listing in their OWN "My Listings" (badged "Secondary"),
 * and an admin filtering by an agent must surface listings where that agent is
 * either the primary OR the secondary — without ever showing the row twice when
 * both the primary and secondary are in the selected set.
 */
final class SecondaryAgentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_agent_sees_co_listing_in_their_own_listings(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $primary   = $this->agencyUser($agency, 'agent');
        $secondary = $this->agencyUser($agency, 'agent');

        $coListed = $this->property($agency, $primary, 'ZZZ-CoListed-House', $secondary);
        $ownedBySecondary = $this->property($agency, $secondary, 'ZZZ-SecondaryOwn-House');
        $unrelated = $this->property($agency, $primary, 'ZZZ-Unrelated-House');

        // Secondary agent's own listings: sees the co-listing + their own,
        // not the unrelated primary-only listing.
        $this->actingAs($secondary)
            ->get(route('corex.properties.index'))
            ->assertOk()
            ->assertSee('ZZZ-CoListed-House')
            ->assertSee('ZZZ-SecondaryOwn-House')
            ->assertDontSee('ZZZ-Unrelated-House')
            ->assertSee('Secondary'); // the co-listing badge
    }

    public function test_admin_agent_filter_matches_primary_or_secondary(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $primary   = $this->agencyUser($agency, 'agent');
        $secondary = $this->agencyUser($agency, 'agent');
        $other     = $this->agencyUser($agency, 'agent');

        $coListed  = $this->property($agency, $primary, 'ZZZ-CoListed-House', $secondary);
        $otherOnly = $this->property($agency, $other, 'ZZZ-OtherOnly-House');

        $this->actingAs($admin);

        // Filter by the SECONDARY agent alone → the co-listing appears, the
        // unrelated other-agent listing does not.
        $this->get(route('corex.properties.index', ['agent_ids' => (string) $secondary->id]))
            ->assertOk()
            ->assertSee('ZZZ-CoListed-House')
            ->assertDontSee('ZZZ-OtherOnly-House');

        // Filter by BOTH primary + secondary → the co-listing still appears.
        // It cannot duplicate: the scope is where/orWhere on the single
        // `properties` row (no JOIN), so one row matches at most once.
        $this->get(route('corex.properties.index', [
            'agent_ids' => $primary->id . ',' . $secondary->id,
        ]))
            ->assertOk()
            ->assertSee('ZZZ-CoListed-House')
            ->assertDontSee('ZZZ-OtherOnly-House');
    }

    public function test_co_listed_property_counts_once_in_the_kpi_totals(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $primary   = $this->agencyUser($agency, 'agent');
        $secondary = $this->agencyUser($agency, 'agent');

        // A single co-listed property carrying BOTH agents.
        $this->property($agency, $primary, 'ZZZ-CoListed-House', $secondary);

        $this->actingAs($admin);

        // Filter by both agents — the property matches the scope on two grounds
        // (primary AND secondary) but is one row, so it must count exactly once
        // in Total and On Market, not twice.
        $stats = $this->get(route('corex.properties.index', [
            'agent_ids' => $primary->id . ',' . $secondary->id,
        ]))->assertOk()->viewData('stats');

        $this->assertSame(1, $stats['total'], 'Co-listed property must count once in Total.');
        $this->assertSame(1, $stats['active'], 'Co-listed property must count once in On Market.');
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

    private function property(int $agencyId, User $agent, string $title, ?User $secondAgent = null): Property
    {
        return Property::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'agent_id'           => $agent->id,
            'pp_second_agent_id' => $secondAgent?->id,
            'title'              => $title,
            'status'             => 'active',
            'listing_type'       => 'sale',
            'property_type'      => 'house',
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
