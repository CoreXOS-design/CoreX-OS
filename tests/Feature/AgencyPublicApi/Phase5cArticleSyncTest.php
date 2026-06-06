<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\AgentArticle;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 5c (agent article publish/edit → article.* webhook).
 *
 * The consuming site keys its article cache per agent; every article.* payload
 * carries both `id` and `agent_id` so the site can bust just that agent's cache.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1.
 */
class Phase5cArticleSyncTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'role' => 'agent', 'show_on_website' => true,
        ]);
        $this->keyWithWebhook();
    }

    public function test_publishing_an_article_fires_article_published_with_agent_id(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $article = $this->draft();

        $article->update(['is_published' => true, 'published_at' => now()]);

        $delivery = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)
            ->where('event_name', 'article.published')->first();
        $this->assertNotNull($delivery, 'article.published delivery should exist');
        $this->assertSame($article->id, $delivery->payload['id']);
        $this->assertSame($this->agent->id, $delivery->payload['agent_id']);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_creating_a_published_article_fires_article_published(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->draft(['is_published' => true, 'published_at' => now()]);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'article.published']);
    }

    public function test_editing_a_published_articles_content_fires_article_updated(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $article = $this->draft(['is_published' => true, 'published_at' => now()]);

        $article->update(['title' => 'Updated headline']);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'article.updated']);
    }

    public function test_unpublishing_fires_article_removed_with_agent_id(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $article = $this->draft(['is_published' => true, 'published_at' => now()]);

        $article->update(['is_published' => false]);

        $delivery = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)
            ->where('event_name', 'article.removed')->first();
        $this->assertNotNull($delivery);
        $this->assertSame($this->agent->id, $delivery->payload['agent_id']);
    }

    public function test_soft_deleting_a_published_article_fires_article_removed(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $article = $this->draft(['is_published' => true, 'published_at' => now()]);
        AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->delete(); // clear the create event

        $article->delete();

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'article.removed']);
    }

    public function test_draft_article_changes_fire_nothing(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $article = $this->draft(); // stays unpublished

        $article->update(['title' => 'Still a draft', 'body' => 'changed']);

        $this->assertSame(0, AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
    }

    // ---- helpers -----------------------------------------------------------

    private function keyWithWebhook(): AgencyApiKey
    {
        return AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Coastal Website',
            'key_prefix' => 'cx_live_' . Str::lower(Str::random(8)), 'secret_hash' => hash('sha256', 'x'),
            'scopes' => [AgencyApiKey::SCOPE_ARTICLES_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://site.example/hook', 'webhook_secret' => 'whsec',
        ]);
    }

    private function draft(array $o = []): AgentArticle
    {
        return AgentArticle::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id,
            'title' => 'Beachfront Guide', 'slug' => 'beachfront-guide',
            'excerpt' => 'Coast.', 'body' => '<p>words</p>', 'tags' => 'coast',
            'is_published' => false,
        ], $o));
    }
}
