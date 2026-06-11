<?php

namespace Tests\Feature\Testimonials;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactTestimonial;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent live-preview page — the CoreX preview of an agent's public website
 * profile (profile + listings + published testimonials).
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class AgentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_preview_their_own_public_page(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
            'name' => 'Thandi Mbeki', 'designation' => 'Principal Property Practitioner',
            'cell' => '0825550100', 'email' => 'thandi@coastal.example', 'show_on_website' => true,
        ]);

        // A live listing for this agent.
        Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Sea-view stunner', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 2495000, 'beds' => 3, 'baths' => 2,
            'published_at' => now(),
        ]);

        // A published testimonial tagged to this agent.
        $contact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Andre', 'last_name' => 'Roets', 'phone' => '0825550111',
        ]);
        ContactTestimonial::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'contact_id' => $contact->id, 'agent_id' => $agent->id,
            'body' => 'Sold our home in a week — superb service.', 'display_name' => 'Andre R.',
            'rating' => 5, 'published' => true, 'published_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get(route('corex.agents.preview', $agent))
            ->assertOk()
            ->assertSee('Thandi Mbeki')
            ->assertSee('Principal Property Practitioner')
            ->assertSee('For Sale')        // status badge on the listing card
            ->assertSee('Uvongo')          // listing suburb
            ->assertSee('House')           // listing property type
            ->assertSee('Sold our home in a week — superb service.', false)
            ->assertSee('Andre R.');
    }

    public function test_preview_renders_with_no_listings_or_testimonials(): void
    {
        $agency = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin', 'name' => 'New Agent',
        ]);

        $this->actingAs($agent)
            ->get(route('corex.agents.preview', $agent))
            ->assertOk()
            ->assertSee('New Agent')
            ->assertSee('No listings yet.');
    }

    public function test_public_profile_is_reachable_without_auth(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
            'name' => 'Andre Roets', 'is_active' => true,
        ]);
        $slug = $agent->ensureQrSlug();

        $this->get(route('corex.agents.public', [$agent->nameSlug(), $slug]))
            ->assertOk()
            ->assertSee('Andre Roets');

        $this->assertSame('andre-roets', $agent->nameSlug());
    }

    public function test_public_profile_404_for_unknown_slug(): void
    {
        $this->get('/corex/agents/ghost-agent/zzzzzzzzzz')->assertNotFound();
    }

    public function test_public_profile_redirects_to_canonical_name_slug(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
            'name' => 'Andre Roets', 'is_active' => true,
        ]);
        $slug = $agent->ensureQrSlug();

        $this->get('/corex/agents/wrong-name/' . $slug)
            ->assertRedirect(route('corex.agents.public', ['andre-roets', $slug]));
    }

    public function test_legacy_qr_url_redirects_to_public_profile(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
            'name' => 'Andre Roets', 'is_active' => true,
        ]);
        $slug = $agent->ensureQrSlug();

        $this->get('/r/a/' . $slug)
            ->assertRedirect(route('corex.agents.public', ['andre-roets', $slug]));
    }
}
