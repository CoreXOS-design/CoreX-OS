<?php

namespace Tests\Feature\Tools;

use App\Models\Role;
use App\Models\User;
use App\Services\FlowMap\FlowMapBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flow Map — read-only, permission-aware interconnection guide.
 * Spec: .ai/specs/flows-map.md
 */
class FlowMapTest extends TestCase
{
    use RefreshDatabase;

    /** A user with no role permissions must be blocked by route middleware. */
    public function test_route_requires_access_flow_map_permission(): void
    {
        $user = User::factory()->create(['role' => 'agent']);

        $this->actingAs($user)
            ->get(route('tools.flow-map'))
            ->assertForbidden();
    }

    /** An owner-role user (bypasses permission checks) sees the page. */
    public function test_owner_sees_flow_map_page(): void
    {
        $role = Role::create(['name' => 'fm_owner', 'label' => 'FM Owner']);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        $user = User::factory()->create(['role' => 'fm_owner']);

        $response = $this->actingAs($user)->get(route('tools.flow-map'));

        $response->assertOk();
        $response->assertSee('Flow Map');
        $response->assertSee('Properties (Agency Stock)'); // a permissioned node — owner sees it
    }

    /**
     * The builder hides nodes the user cannot access and prunes any edge
     * that would point at a hidden node (map stays coherent).
     */
    public function test_builder_filters_nodes_and_prunes_edges(): void
    {
        // Plain agent with no role_permissions rows → holds nothing.
        $user = User::factory()->create(['role' => 'agent']);

        $map  = app(FlowMapBuilder::class)->build($user);
        $keys = collect($map['nodes'])->pluck('key')->all();

        // Only null-permission nodes survive (e.g. dashboard).
        $this->assertContains('dashboard', $keys);
        foreach ($map['nodes'] as $node) {
            $config = collect(config('flow-map.nodes'))->firstWhere('key', $node['key']);
            $this->assertEmpty(
                $config['permission'],
                "Permissioned node '{$node['key']}' leaked to a user without access."
            );
            // No dangling edges.
            foreach ($node['next'] as $next) {
                $this->assertContains($next, $keys, "Edge to hidden node '{$next}' not pruned.");
            }
        }
    }

    /** Live catalogue reflects real event classes under app/Events. */
    public function test_event_catalogue_reflects_app_events(): void
    {
        $catalogue = app(FlowMapBuilder::class)->eventCatalogue();

        $this->assertIsArray($catalogue);
        $this->assertArrayHasKey('TrackedPropertyCreated', $catalogue);
        $this->assertArrayNotHasKey('AbstractDomainEvent', $catalogue);
    }
}
