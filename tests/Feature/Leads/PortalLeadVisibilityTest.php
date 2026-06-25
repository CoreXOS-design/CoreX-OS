<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Agency;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Portal Leads list/poll is scoped by PortalLead::visibleTo() so an agent
 * only sees leads tied to their own listings, while a role with the "All" Data
 * Scope (or an owner) sees every lead in the agency.
 */
final class PortalLeadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_leads_for_their_own_listings(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agentA = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $agentB = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $admin  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $listing = new Property();
        $listing->forceFill([
            'title' => 'Beachfront Villa', 'agent_id' => $agentA->id,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'status' => 'active',
        ])->save();

        $lead = new PortalLead([
            'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'Email', 'name' => 'Jane Buyer',
            'listing_id' => $listing->id, 'received_at' => now(), 'lead_source_raw' => [],
        ]);
        $lead->agency_id = $agency->id;
        $lead->save();

        // role_permissions is unseeded here → PermissionService falls back to
        // role defaults: agent => 'own', admin => 'all'.
        $this->assertSame(1, PortalLead::query()->visibleTo($agentA)->count(), 'listing agent sees their lead');
        $this->assertSame(0, PortalLead::query()->visibleTo($agentB)->count(), 'other agent sees nothing');
        $this->assertSame(1, PortalLead::query()->visibleTo($admin)->count(), 'admin (All scope) sees the lead');
    }
}
