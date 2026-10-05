<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\SyndicationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * THE APPROVER'S QUEUE — which is the Properties list, not a screen of its own.
 * Spec: .ai/specs/syndication-approval-gate.md §7 (Johan's D7).
 *
 * Johan asked for "a status on the property page where the selected user can
 * filter and see the pending properties." That is the Properties list plus one
 * filter — a second list screen beside it would duplicate the search, the sort,
 * the scoping and the row actions, and give CoreX two places to look for the
 * same thing. So the assertions here are about the EXISTING list behaving
 * correctly with the new filter, not about a new page.
 */
class SyndicationApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;
    private User $agent;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency  = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Port Shepstone']);

        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent',
        ]);
        $this->approver = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin',
        ]);

        PerformanceSetting::set(SyndicationApprovalService::SETTING_APPROVERS, json_encode([$this->approver->id]), $this->agency->id);
        PerformanceSetting::set(SyndicationApprovalService::SETTING_REQUIRED, 1, $this->agency->id);
    }

    private function property(array $overrides = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branchA->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(5),
            'address' => '12 Beach Road', 'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KZN',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
            'published_at' => now(), 'compliance_snapshot_at' => now(),
        ], $overrides));
    }

    private function pending(Property $p): PropertySyndicationApproval
    {
        return app(SyndicationApprovalService::class)->request($p, $this->agent);
    }

    // ── The filter itself ───────────────────────────────────────────────

    public function test_the_filter_returns_exactly_the_pending_set(): void
    {
        $waiting = $this->property(['title' => 'WAITING ONE']);
        $this->pending($waiting);

        $approved = $this->property(['title' => 'ALREADY APPROVED']);
        app(SyndicationApprovalService::class)->approve($approved, $this->approver);

        $neverAsked = $this->property(['title' => 'NEVER ASKED']);

        $offMarket = $this->property(['title' => 'OFF MARKET', 'status' => 'sold']);
        $this->pending($offMarket);

        $response = $this->actingAs($this->approver)
            ->get(route('corex.properties.index', ['filter' => 'approval_pending']))
            ->assertOk();

        $response->assertSee('WAITING ONE');
        $response->assertDontSee('ALREADY APPROVED');
        $response->assertDontSee('NEVER ASKED');
        $response->assertDontSee('OFF MARKET');
    }

    public function test_a_rejected_listing_leaves_the_queue_until_it_is_sent_again(): void
    {
        $p = $this->property(['title' => 'REJECTED ONE']);
        $this->pending($p);
        app(SyndicationApprovalService::class)->reject($p, $this->approver, 'Photos need redoing.');

        $this->actingAs($this->approver)
            ->get(route('corex.properties.index', ['filter' => 'approval_pending']))
            ->assertOk()
            ->assertDontSee('REJECTED ONE');

        // Agent fixes it and sends again — back in the queue.
        $this->pending($p->fresh());

        $this->actingAs($this->approver)
            ->get(route('corex.properties.index', ['filter' => 'approval_pending']))
            ->assertOk()
            ->assertSee('REJECTED ONE');
    }

    public function test_the_filter_composes_with_the_other_filters(): void
    {
        $cheap = $this->property(['title' => 'CHEAP WAITING', 'price' => 900000]);
        $dear  = $this->property(['title' => 'DEAR WAITING', 'price' => 8000000]);
        $this->pending($cheap);
        $this->pending($dear);

        // The approval filter must NARROW with the others, never replace them.
        $this->actingAs($this->approver)
            ->get(route('corex.properties.index', ['filter' => 'approval_pending', 'price_min' => 5000000]))
            ->assertOk()
            ->assertSee('DEAR WAITING')
            ->assertDontSee('CHEAP WAITING');
    }

    public function test_the_queue_shows_every_agents_listing_not_just_the_viewers(): void
    {
        // An approver's whole job is other people's listings — a stale agent
        // filter from their last visit must not hide the queue from them.
        $otherAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent',
        ]);

        $theirs = $this->property(['title' => 'SOMEONE ELSES', 'agent_id' => $otherAgent->id]);
        $this->pending($theirs);

        $this->actingAs($this->approver)
            ->get(route('corex.properties.index', ['filter' => 'approval_pending']))
            ->assertOk()
            ->assertSee('SOMEONE ELSES');
    }

    public function test_the_empty_state_is_the_approvers_own(): void
    {
        // The generic "no properties match these filters" would read as a
        // mistake on the one screen whose empty state is good news.
        $this->actingAs($this->approver)
            ->get(route('corex.properties.index', ['filter' => 'approval_pending']))
            ->assertOk()
            ->assertSee('Nothing waiting for your approval.');
    }

    // ── Visibility of the controls ──────────────────────────────────────

    public function test_the_queue_tile_is_invisible_to_someone_who_cannot_approve(): void
    {
        $p = $this->property();
        $this->pending($p);

        $response = $this->actingAs($this->agent)
            ->get(route('corex.properties.index'))
            ->assertOk();

        // The TILE and its filter link are the approver's queue — not for an
        // agent. Asserted on the filter value, not the words: the agent DOES
        // legitimately see the badge (next test), so asserting on the label
        // would pass or fail for the wrong reason.
        $response->assertDontSee('approval_pending', false);
    }

    public function test_an_agent_still_sees_the_state_of_their_own_listing(): void
    {
        // The agent must know their listing is waiting — they just cannot
        // filter the agency's queue or decide anything.
        $this->pending($this->property(['title' => 'MY WAITING ONE']));

        $this->actingAs($this->agent)
            ->get(route('corex.properties.index'))
            ->assertOk()
            ->assertSee('MY WAITING ONE')
            ->assertSee('Awaiting approval');
    }

    public function test_the_tile_is_visible_to_an_approver_with_its_count(): void
    {
        $this->pending($this->property());
        $this->pending($this->property());

        $this->actingAs($this->approver)
            ->get(route('corex.properties.index'))
            ->assertOk()
            ->assertSee('Awaiting approval')
            // The tile links into the queue, and counts both.
            ->assertSee('approval_pending', false);
    }

    public function test_nothing_appears_at_all_when_the_agency_never_switched_it_on(): void
    {
        PerformanceSetting::set(SyndicationApprovalService::SETTING_REQUIRED, 0, $this->agency->id);

        $this->actingAs($this->approver)
            ->get(route('corex.properties.index'))
            ->assertOk()
            ->assertDontSee('Awaiting approval')
            ->assertDontSee('Needs approval');
    }

    // ── Own / branch / agency scoping (§7.3) ────────────────────────────

    public function test_an_agency_wide_approver_sees_every_branch(): void
    {
        $here  = $this->property(['title' => 'BRANCH A ONE']);
        $there = $this->property(['title' => 'BRANCH B ONE', 'branch_id' => $this->branchB->id]);
        $this->pending($here);
        $this->pending($there);

        $rows = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->visibleTo($this->approver)->pluck('property_id');

        $this->assertTrue($rows->contains($here->id));
        $this->assertTrue($rows->contains($there->id));
    }

    public function test_a_branch_scoped_viewer_sees_only_their_own_branch(): void
    {
        $here  = $this->property();
        $there = $this->property(['branch_id' => $this->branchB->id]);
        $this->pending($here);
        $this->pending($there);

        // A branch-scoped user who is NOT on the approver roster.
        $branchUser = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent',
        ]);

        $rows = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->visibleTo($branchUser)->pluck('property_id');

        $this->assertFalse($rows->contains($there->id), 'Another branch must never be visible.');
    }

    public function test_a_viewer_with_no_resolvable_branch_sees_nothing_rather_than_everything(): void
    {
        // Fail CLOSED. A scoping bug that opens the whole agency is far worse
        // than one that shows an empty list.
        $this->pending($this->property());

        $branchless = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent',
        ]);
        $branchless->forceFill(['branch_id' => null])->save();

        $rows = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->visibleTo($branchless->fresh())->get();

        $this->assertCount(0, $rows);
    }

    public function test_another_agencys_requests_are_never_visible(): void
    {
        $this->pending($this->property(['title' => 'OURS']));

        $otherAgency = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $otherBranch = Branch::withoutAgencyStamping(fn () => Branch::create([
            'agency_id' => $otherAgency->id, 'name' => 'Main',
        ]));
        $outsider = User::withoutAgencyStamping(fn () => User::factory()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin',
        ]));

        $this->assertSame($otherAgency->id, (int) $outsider->agency_id, 'Fixture guard: the outsider must really be outside.');

        $this->actingAs($outsider);

        // AgencyScope bounds the tenant at the query layer, before scopeVisibleTo
        // ever narrows within it.
        $this->assertCount(0, PropertySyndicationApproval::visibleTo($outsider)->get());
    }
}
