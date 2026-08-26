<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for a real production bug (2026-08-13): MobilePortalLeadController
 * queried PortalLead::query() directly on every endpoint, never applying
 * PortalLead::scopeVisibleTo() — so every mobile user saw (and could open /
 * mark-read) every other agent's leads regardless of their configured Portal
 * Leads Data Scope. The web PortalLeadController has always scoped correctly;
 * this brings the mobile API in line with it.
 */
final class MobilePortalLeadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;
    private User $agentB;
    private User $admin;
    private PortalLead $leadOnA;
    private PortalLead $leadOnB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);

        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role,
        ]);
        $this->agentA = $mk('agent');
        $this->agentB = $mk('agent');
        $this->admin  = $mk('admin');

        $listingA = new Property();
        $listingA->forceFill([
            'title' => 'A Listing', 'status' => 'active', 'agent_id' => $this->agentA->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ])->save();

        $listingB = new Property();
        $listingB->forceFill([
            'title' => 'B Listing', 'status' => 'active', 'agent_id' => $this->agentB->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ])->save();

        $this->leadOnA = new PortalLead([
            'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'Email', 'name' => 'Buyer On A',
            'listing_id' => $listingA->id, 'received_at' => now(), 'lead_source_raw' => [],
        ]);
        $this->leadOnA->agency_id = $this->agency->id;
        $this->leadOnA->save();

        $this->leadOnB = new PortalLead([
            'portal' => PortalLead::PORTAL_PP, 'lead_type' => 'Email', 'name' => 'Buyer On B',
            'listing_id' => $listingB->id, 'received_at' => now(), 'lead_source_raw' => [],
        ]);
        $this->leadOnB->agency_id = $this->agency->id;
        $this->leadOnB->save();
    }

    public function test_index_only_returns_own_leads_for_an_agent(): void
    {
        $ids = $this->actingAs($this->agentA)
            ->getJson('/api/v1/mobile/portal-leads?date=' . now()->toDateString())
            ->assertOk()
            ->json('leads.*.id');

        $this->assertSame([$this->leadOnA->id], $ids);
    }

    public function test_index_returns_all_leads_for_admin(): void
    {
        $ids = $this->actingAs($this->admin)
            ->getJson('/api/v1/mobile/portal-leads?date=' . now()->toDateString())
            ->assertOk()
            ->json('leads.*.id');

        sort($ids);
        $expected = [$this->leadOnA->id, $this->leadOnB->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_dates_only_counts_own_leads_for_an_agent(): void
    {
        $res = $this->actingAs($this->agentA)
            ->getJson('/api/v1/mobile/portal-leads/dates')
            ->assertOk();

        $today = collect($res->json('dates'))->firstWhere('date', now()->toDateString());
        $this->assertSame(1, $today['total']);
    }

    public function test_show_is_forbidden_for_a_lead_outside_the_agents_scope(): void
    {
        $this->actingAs($this->agentA)
            ->getJson('/api/v1/mobile/portal-leads/' . $this->leadOnB->id)
            ->assertStatus(403);
    }

    public function test_show_succeeds_for_own_lead(): void
    {
        $this->actingAs($this->agentA)
            ->getJson('/api/v1/mobile/portal-leads/' . $this->leadOnA->id)
            ->assertOk()
            ->assertJsonPath('lead.id', $this->leadOnA->id);
    }

    public function test_mark_read_is_forbidden_for_a_lead_outside_the_agents_scope(): void
    {
        $this->actingAs($this->agentA)
            ->postJson('/api/v1/mobile/portal-leads/' . $this->leadOnB->id . '/mark-read')
            ->assertStatus(403);

        $this->assertNull($this->leadOnB->fresh()->notified_at);
    }

    public function test_mark_read_succeeds_for_own_lead(): void
    {
        $this->actingAs($this->agentA)
            ->postJson('/api/v1/mobile/portal-leads/' . $this->leadOnA->id . '/mark-read')
            ->assertOk();

        $this->assertNotNull($this->leadOnA->fresh()->notified_at);
    }
}
