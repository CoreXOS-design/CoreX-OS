<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 5a (agent show_on_website + agent.* webhook sync).
 *
 * Spec: .ai/specs/agency-public-api.md §6.1, §2 (layer 3).
 */
class Phase5aAgentSyncTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->keyWithWebhook();
    }

    public function test_turning_on_show_on_website_fires_agent_published(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $agent = $this->agent(false);

        $agent->update(['show_on_website' => true]);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'agent.published']);
        $delivery = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->where('event_name', 'agent.published')->first();
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_creating_a_visible_agent_fires_agent_published(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->agent(true);
        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'agent.published']);
    }

    public function test_editing_a_visible_agents_profile_fires_agent_updated(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $agent = $this->agent(true);

        $agent->update(['name' => 'Thandi Mbeki-Ndlovu']);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'agent.updated']);
    }

    public function test_turning_off_fires_agent_removed(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $agent = $this->agent(true);

        $agent->update(['show_on_website' => false]);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'agent.removed']);
    }

    public function test_soft_deleting_a_visible_agent_fires_agent_removed(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $agent = $this->agent(true);
        AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->delete(); // clear the create event

        $agent->delete();

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'agent.removed']);
    }

    public function test_hidden_agent_changes_fire_nothing(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $agent = $this->agent(false);

        $agent->update(['name' => 'Still Hidden']);

        $this->assertSame(0, AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
    }

    // ---- helpers -----------------------------------------------------------

    private function keyWithWebhook(): AgencyApiKey
    {
        return AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Coastal Website',
            'key_prefix' => 'cx_live_' . Str::lower(Str::random(8)), 'secret_hash' => hash('sha256', 'x'),
            'scopes' => [AgencyApiKey::SCOPE_AGENTS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://site.example/hook', 'webhook_secret' => 'whsec',
        ]);
    }

    private function agent(bool $visible): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'name' => 'Thandi Mbeki', 'show_on_website' => $visible,
        ]);
    }
}
