<?php

namespace Tests\Feature\Testimonials;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AgentArticle;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 2 — agent public profile (about + personal socials) + agent articles,
 * their flow into the agent preview and the public website API.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class AgentProfileArticlesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
            'name' => 'Thandi Mbeki', 'email' => 'thandi@coastal.example', 'cell' => '0825550100',
            'show_on_website' => true,
        ]);
    }

    public function test_agent_can_save_about_and_personal_socials(): void
    {
        $this->actingAs($this->agent)->patch(route('agent.portal.profile.update'), [
            'name' => 'Thandi Mbeki', 'email' => 'thandi@coastal.example', 'cell' => '0825550100',
            'about_me' => 'KZN South Coast specialist with 12 years on the beachfront.',
            'website_social_facebook'  => 'https://facebook.com/thandi',
            'website_social_instagram' => 'thandi.sells',
        ])->assertRedirect();

        $this->agent->refresh();
        $this->assertSame('KZN South Coast specialist with 12 years on the beachfront.', $this->agent->about_me);
        $this->assertSame('https://facebook.com/thandi', $this->agent->website_social_facebook);
        $this->assertSame('thandi.sells', $this->agent->website_social_instagram);
    }

    public function test_agent_can_add_publish_and_delete_an_article(): void
    {
        // Add (starts unpublished).
        $this->actingAs($this->agent)->post(route('agent.portal.articles.store'), [
            'title' => 'Why Margate is booming', 'excerpt' => 'Coastal demand surge', 'body' => 'Long form content here.',
        ])->assertRedirect();

        $article = AgentArticle::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertNotNull($article);
        $this->assertFalse($article->is_published);
        $this->assertSame($this->agent->id, $article->user_id);

        // Publish.
        $this->actingAs($this->agent)->patch(route('agent.portal.articles.publish', $article), ['is_published' => '1'])->assertRedirect();
        $article->refresh();
        $this->assertTrue($article->is_published);
        $this->assertNotNull($article->published_at);

        // Delete (soft).
        $this->actingAs($this->agent)->delete(route('agent.portal.articles.destroy', $article))->assertRedirect();
        $this->assertSoftDeleted('agent_articles', ['id' => $article->id]);
    }

    public function test_agent_cannot_manage_another_agents_article(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $article = AgentArticle::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'user_id' => $other->id, 'title' => 'Theirs', 'is_published' => true, 'published_at' => now(),
        ]);

        $this->actingAs($this->agent)->delete(route('agent.portal.articles.destroy', $article))->assertNotFound();
    }

    public function test_public_api_exposes_about_and_socials_on_agent(): void
    {
        $this->agent->update([
            'about_me' => 'Beachfront specialist.',
            'website_social_instagram' => 'thandi.sells',
        ]);
        $token = $this->keyToken([AgencyApiKey::SCOPE_AGENTS_READ]);

        $resp = $this->withToken($token)->getJson('/api/v1/website/agents')->assertOk();
        $card = collect($resp->json('data'))->firstWhere('name', 'Thandi Mbeki');
        $this->assertSame('Beachfront specialist.', $card['about']);
        $this->assertSame('thandi.sells', $card['socials']['instagram']);
    }

    public function test_public_api_returns_only_published_articles_and_filters_by_agent(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Other']);
        AgentArticle::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Live one', 'is_published' => true, 'published_at' => now()]);
        AgentArticle::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Draft one', 'is_published' => false]);
        AgentArticle::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'user_id' => $other->id, 'title' => 'Others live', 'is_published' => true, 'published_at' => now()]);

        $token = $this->keyToken([AgencyApiKey::SCOPE_ARTICLES_READ]);

        $titles = collect($this->withToken($token)->getJson('/api/v1/website/articles')->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Live one'));
        $this->assertTrue($titles->contains('Others live'));
        $this->assertFalse($titles->contains('Draft one'));

        // Filter to this agent only.
        $mine = collect($this->withToken($token)->getJson('/api/v1/website/articles?agent_id=' . $this->agent->id)->json('data'))->pluck('title');
        $this->assertTrue($mine->contains('Live one'));
        $this->assertFalse($mine->contains('Others live'));
    }

    public function test_articles_scope_enforced(): void
    {
        $token = $this->keyToken([AgencyApiKey::SCOPE_AGENTS_READ]); // wrong scope
        $this->withToken($token)->getJson('/api/v1/website/articles')->assertStatus(403);
    }

    public function test_preview_shows_about_and_published_articles(): void
    {
        $this->agent->update(['about_me' => 'My bio line.']);
        AgentArticle::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Preview article', 'is_published' => true, 'published_at' => now()]);
        AgentArticle::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Hidden draft', 'is_published' => false]);

        $this->actingAs($this->agent)->get(route('corex.agents.preview', $this->agent))
            ->assertOk()
            ->assertSee('My bio line.')
            ->assertSee('Preview article')
            ->assertDontSee('Hidden draft');
    }

    public function test_article_store_persists_link_tags_and_computes_readtime(): void
    {
        $this->actingAs($this->agent)->post(route('agent.portal.articles.store'), [
            'title'    => 'Bond approval explained',
            'body'     => str_repeat('word ', 400),
            'link_url' => 'https://hfcoastal.co.za/bonds',
            'tags'     => 'BondApproval, HomeBuying',
        ])->assertRedirect();

        $a = AgentArticle::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertSame('https://hfcoastal.co.za/bonds', $a->link_url);
        $this->assertSame(['BondApproval', 'HomeBuying'], $a->tagList());
        $this->assertSame(400, $a->wordCount());
        $this->assertSame(2, $a->readMinutes()); // 400 / 200 wpm
    }

    public function test_article_cover_image_upload(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        // A large (~10 MB) image — proves there is no size cap.
        $this->actingAs($this->agent)->post(route('agent.portal.articles.store'), [
            'title'       => 'With cover',
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg', 800, 600)->size(10240),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $a = AgentArticle::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertNotNull($a->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($a->cover_image_path);
    }

    public function test_public_api_article_exposes_advanced_fields(): void
    {
        AgentArticle::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'T',
            'body' => str_repeat('word ', 250), 'link_url' => 'https://x.co', 'tags' => 'A, B',
            'is_published' => true, 'published_at' => now(),
        ]);
        $token = $this->keyToken([AgencyApiKey::SCOPE_ARTICLES_READ]);

        $this->withToken($token)->getJson('/api/v1/website/articles')->assertOk()
            ->assertJsonPath('data.0.read_minutes', 2)
            ->assertJsonPath('data.0.word_count', 250)
            ->assertJsonPath('data.0.link_url', 'https://x.co')
            ->assertJsonPath('data.0.tags', ['A', 'B']);
    }

    public function test_article_preview_page_renders(): void
    {
        $a = AgentArticle::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id,
            'title' => 'Bond approval explained', 'body' => 'Some body content here.',
            'tags' => 'BondApproval', 'is_published' => true, 'published_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->get(route('corex.agents.article.preview', [$this->agent, $a, $a->previewSlug()]))
            ->assertOk()
            ->assertSee('Bond approval explained')
            ->assertSee('Thandi Mbeki')
            ->assertSee('#BondApproval')
            ->assertSee('View My Profile');
    }

    public function test_mixed_case_email_is_accepted(): void
    {
        $this->actingAs($this->agent)->patch(route('agent.portal.profile.update'), [
            'name' => 'Thandi Mbeki', 'email' => 'Thandi.Mbeki@Coastal.Example', 'cell' => '0825550100',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Thandi.Mbeki@Coastal.Example', $this->agent->fresh()->email);
    }

    public function test_about_me_saved_via_profile_form_shows_on_preview(): void
    {
        // Save through the real profile route (the form an agent submits)…
        $this->actingAs($this->agent)->patch(route('agent.portal.profile.update'), [
            'name' => 'Thandi Mbeki', 'email' => 'thandi@coastal.example', 'cell' => '0825550100',
            'about_me' => 'KZN South Coast specialist — Get to Know Me text.',
        ])->assertRedirect();

        // …then it appears in the Get to Know Me section of the preview.
        $this->actingAs($this->agent)->get(route('corex.agents.preview', $this->agent))
            ->assertOk()
            ->assertSee('Get to Know Me')
            ->assertSee('KZN South Coast specialist — Get to Know Me text.', false);
    }

    public function test_preview_shows_social_icon_link(): void
    {
        $this->agent->update(['website_social_facebook' => 'https://facebook.com/thandi']);

        $this->actingAs($this->agent)->get(route('corex.agents.preview', $this->agent))
            ->assertOk()
            ->assertSee('https://facebook.com/thandi');
    }

    private function keyToken(array $scopes): string
    {
        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Site',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'], 'scopes' => $scopes,
        ]);
        return $minted['plaintext'];
    }
}
