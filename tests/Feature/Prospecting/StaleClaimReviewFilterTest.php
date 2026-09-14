<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\User;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-393 — Stale claims review list: address search, agent filter, warned/stale
 * filter, and 25-per-page pagination that carries the active filters.
 * Spec: .ai/specs/mic-stale-review-list.md
 *
 * Staleness is computed per claim in PHP (last_updated_at vs now), so the list is
 * filtered and paged on the collection; these tests prove the page honours every
 * filter AND that page 2 of a filtered list stays filtered.
 */
final class StaleClaimReviewFilterTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agentA;
    private User $agentB;
    private int $warnDays;
    private int $releaseDays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);

        $mk = fn (string $role, string $name) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'name' => $name,
        ]);
        $this->admin  = $mk('super_admin', 'Ada Admin');
        $this->agentA = $mk('agent', 'Amos Adams');
        $this->agentB = $mk('agent', 'Bella Botha');

        $t = app(ProspectingConfigurationService::class)->getSuggestedActionThresholds($this->agency->id);
        $this->warnDays    = (int) $t->claim_warn_days;
        $this->releaseDays = (int) $t->claim_release_days;
    }

    /** A prospecting listing + an active claim on it, unworked for $ageDays. Returns the claim id. */
    private function claim(string $address, User $agent, int $ageDays): int
    {
        $listingId = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'           => $this->agency->id,
            'captured_by_user_id' => $agent->id,
            'portal_source'       => 'p24',
            'portal_ref'          => 'REF-' . uniqid(),
            'suburb'              => 'Margate',
            'address'             => $address,
            'first_seen_at'       => now()->subDays($ageDays + 1),
            'last_seen_at'        => now(),
            'created_at'          => now()->subDays($ageDays + 1),
            'updated_at'          => now(),
        ]);

        return (int) DB::table('prospecting_claims')->insertGetId([
            'agency_id'              => $this->agency->id,
            'prospecting_listing_id' => $listingId,
            'user_id'                => $agent->id,
            'status'                 => 'contacted',
            'claimed_at'             => now()->subDays($ageDays),
            'pitched_at'             => now()->subDays($ageDays),
            'last_updated_at'        => now()->subDays($ageDays),
            'is_active'              => true,
            'created_at'             => now()->subDays($ageDays),
            'updated_at'             => now(),
        ]);
    }

    private function visit(array $query = [])
    {
        return $this->actingAs($this->admin)
            ->get(route('market-intelligence.stale-review', $query));
    }

    public function test_default_view_lists_warned_and_stale_but_not_fresh_claims(): void
    {
        $this->claim('12 Stale Street', $this->agentA, $this->releaseDays + 5);
        $this->claim('7 Warned Way', $this->agentB, $this->warnDays);
        $this->claim('3 Fresh Road', $this->agentA, 0);

        $res = $this->visit();
        $res->assertOk()
            ->assertSee('12 Stale Street')
            ->assertSee('7 Warned Way')
            ->assertDontSee('3 Fresh Road');
    }

    public function test_address_search_narrows_the_list(): void
    {
        $this->claim('12 Stale Street', $this->agentA, $this->releaseDays + 5);
        $this->claim('99 Other Avenue', $this->agentB, $this->releaseDays + 5);

        $this->visit(['q' => 'stale str'])
            ->assertOk()
            ->assertSee('12 Stale Street')
            ->assertDontSee('99 Other Avenue');
    }

    public function test_agent_filter_narrows_the_list(): void
    {
        $this->claim('12 Stale Street', $this->agentA, $this->releaseDays + 5);
        $this->claim('99 Other Avenue', $this->agentB, $this->releaseDays + 5);

        $this->visit(['agent_id' => $this->agentB->id])
            ->assertOk()
            ->assertSee('99 Other Avenue')
            ->assertDontSee('12 Stale Street');
    }

    public function test_state_filter_separates_stale_from_warned(): void
    {
        if ($this->releaseDays <= $this->warnDays) {
            $this->markTestSkipped('Agency thresholds leave no warned-only window (release <= warn).');
        }

        $this->claim('12 Stale Street', $this->agentA, $this->releaseDays + 5);
        $this->claim('7 Warned Way', $this->agentB, $this->warnDays);

        $this->visit(['state' => 'stale'])
            ->assertOk()
            ->assertSee('12 Stale Street')
            ->assertDontSee('7 Warned Way');

        $this->visit(['state' => 'warned'])
            ->assertOk()
            ->assertSee('7 Warned Way')
            ->assertDontSee('12 Stale Street');
    }

    public function test_list_is_paged_at_25_and_page_links_carry_the_filters(): void
    {
        // 30 claims for agent A + 1 for agent B; filtered to A the list must page 25 / 5.
        for ($i = 1; $i <= 30; $i++) {
            $this->claim(sprintf('%02d Paged Place', $i), $this->agentA, $this->releaseDays + $i);
        }
        $this->claim('1 Bella Boulevard', $this->agentB, $this->releaseDays + 1);

        $page1 = $this->visit(['agent_id' => $this->agentA->id]);
        $page1->assertOk();
        $this->assertSame(25, substr_count($page1->getContent(), 'Paged Place'), 'page 1 shows exactly 25 rows');
        $page1->assertDontSee('1 Bella Boulevard');
        $this->assertStringContainsString(
            'agent_id=' . $this->agentA->id . '&amp;page=2',
            $page1->getContent(),
            'page-2 link keeps the agent filter',
        );

        $page2 = $this->visit(['agent_id' => $this->agentA->id, 'page' => 2]);
        $page2->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), 'Paged Place'), 'page 2 shows the remaining 5');
        $page2->assertDontSee('1 Bella Boulevard');
    }
}
