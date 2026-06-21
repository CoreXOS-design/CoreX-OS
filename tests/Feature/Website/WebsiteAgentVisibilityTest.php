<?php

namespace Tests\Feature\Website;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-1 — a deactivated (departed) agent must never be served by the public
 * website API, even with show_on_website still set.
 *
 * Audit: .ai/audits/syndication-bug-sweep-2026-06-20.md (WEB-1)
 */
class WebsiteAgentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_agent_is_excluded_from_agents_endpoint(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $active = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
            'name' => 'Active Agent', 'show_on_website' => true, 'is_active' => true,
        ]);
        $departed = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
            'name' => 'Departed Agent', 'show_on_website' => true, 'is_active' => false,
        ]);

        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'name' => 'Site',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_AGENTS_READ],
        ]);

        $resp = $this->withToken($minted['plaintext'])->getJson('/api/v1/website/agents')->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($departed->id, $ids, 'Deactivated agent must not be served publicly.');

        // And the single-agent endpoint 404s for the departed agent.
        $this->withToken($minted['plaintext'])->getJson("/api/v1/website/agents/{$departed->id}")->assertNotFound();
    }
}
