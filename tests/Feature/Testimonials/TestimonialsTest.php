<?php

namespace Tests\Feature\Testimonials;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactTestimonial;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact Testimonials — capture (agents), publish (settings), public API, and
 * the testimonial.* webhook chain.
 *
 * Spec: .ai/specs/testimonials.md §4, §5, §9, §10.
 */
class TestimonialsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
        $this->contact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Andre', 'last_name' => 'Roets', 'phone' => '0825550100', 'email' => 'andre@example.com',
        ]);
    }

    // ── Capture (agent-facing) ──────────────────────────────────────────────

    public function test_agent_can_capture_a_testimonial_with_rating_and_name(): void
    {
        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body'         => 'Best agent on the South Coast — sold in a week.',
            'display_name' => 'Andre R.',
            'rating'       => 5,
        ])->assertRedirect();

        $t = ContactTestimonial::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertNotNull($t);
        $this->assertSame('Andre R.', $t->display_name);
        $this->assertSame(5, $t->rating);
        $this->assertFalse($t->published, 'Capture must never auto-publish.');
        $this->assertSame($this->user->id, $t->user_id);
        $this->assertSame($this->agency->id, $t->agency_id);
    }

    public function test_lazy_path_quote_only_autofills_name_and_null_rating(): void
    {
        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body' => 'Friendly and professional.',
        ])->assertRedirect();

        $t = ContactTestimonial::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertSame('Andre Roets', $t->display_name, 'Display name defaults to the contact full name.');
        $this->assertNull($t->rating);
    }

    public function test_empty_body_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body' => '',
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, ContactTestimonial::withoutGlobalScope(AgencyScope::class)->count());
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body' => 'Great', 'rating' => 9,
        ])->assertSessionHasErrors('rating');
    }

    public function test_blank_display_name_falls_back_to_client_when_contact_has_no_name(): void
    {
        // Empty names (NOT-NULL columns) → controller must fall back to "Client".
        $nameless = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => '', 'last_name' => '', 'phone' => '0825550111',
        ]);

        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $nameless), [
            'body' => 'Anonymous praise.', 'display_name' => '   ',
        ])->assertRedirect();

        $t = ContactTestimonial::withoutGlobalScope(AgencyScope::class)->where('contact_id', $nameless->id)->first();
        $this->assertSame('Client', $t->display_name);
    }

    public function test_agent_can_update_and_soft_delete_a_testimonial(): void
    {
        $t = $this->makeTestimonial();

        $this->actingAs($this->user)->put(route('corex.contacts.testimonials.update', [$this->contact, $t]), [
            'body' => 'Edited quote.', 'display_name' => 'A. Roets', 'rating' => 4,
        ])->assertRedirect();
        $t->refresh();
        $this->assertSame('Edited quote.', $t->body);
        $this->assertSame(4, $t->rating);

        $this->actingAs($this->user)->delete(route('corex.contacts.testimonials.destroy', [$this->contact, $t]))
            ->assertRedirect();
        $this->assertSoftDeleted('contact_testimonials', ['id' => $t->id]);
    }

    // ── Publish (Company Settings → Website) ─────────────────────────────────

    public function test_settings_toggle_publishes_and_unpublishes(): void
    {
        $t = $this->makeTestimonial();

        $this->actingAs($this->user)->patch(
            route('admin.company-settings.testimonials.toggle', [$this->agency, $t]),
            ['published' => '1']
        )->assertRedirect();

        $t->refresh();
        $this->assertTrue($t->published);
        $this->assertNotNull($t->published_at);
        $this->assertSame($this->user->id, $t->published_by_user_id);

        // Untick → removed from website.
        $this->actingAs($this->user)->patch(
            route('admin.company-settings.testimonials.toggle', [$this->agency, $t]),
            ['published' => '0']
        )->assertRedirect();
        $this->assertFalse($t->fresh()->published);
    }

    public function test_cannot_toggle_a_testimonial_from_another_agency(): void
    {
        $other = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront']);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'B']);
        $otherContact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id,
            'first_name' => 'X', 'last_name' => 'Y', 'phone' => '0825550188',
        ]);
        $foreign = ContactTestimonial::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $other->id, 'contact_id' => $otherContact->id,
            'body' => 'Foreign', 'display_name' => 'X Y',
        ]);

        $this->actingAs($this->user)->patch(
            route('admin.company-settings.testimonials.toggle', [$this->agency, $foreign]),
            ['published' => '1']
        )->assertNotFound();
    }

    // ── Public API ───────────────────────────────────────────────────────────

    public function test_public_api_returns_only_published_newest_first(): void
    {
        $token = $this->keyToken([AgencyApiKey::SCOPE_TESTIMONIALS_READ]);

        $this->makeTestimonial(['body' => 'Older', 'display_name' => 'A', 'rating' => 5, 'published' => true, 'published_at' => now()->subDay()]);
        $this->makeTestimonial(['body' => 'Newer', 'display_name' => 'B', 'rating' => 4, 'published' => true, 'published_at' => now()]);
        $this->makeTestimonial(['body' => 'Unpublished', 'display_name' => 'C', 'published' => false]);

        $resp = $this->withToken($token)->getJson('/api/v1/website/testimonials')->assertOk();

        $bodies = collect($resp->json('data'))->pluck('body');
        $this->assertSame(['Newer', 'Older'], $bodies->all());
        $this->assertFalse($bodies->contains('Unpublished'));

        // Resource shape.
        $first = $resp->json('data.0');
        $this->assertSame('B', $first['author']);
        $this->assertSame(4, $first['rating']);
        $this->assertArrayHasKey('date', $first);
        $this->assertArrayNotHasKey('contact_id', $first);
    }

    public function test_public_api_scope_enforced(): void
    {
        $token = $this->keyToken([AgencyApiKey::SCOPE_AGENTS_READ]); // wrong scope
        $this->withToken($token)->getJson('/api/v1/website/testimonials')->assertStatus(403);
    }

    public function test_public_api_cross_agency_isolation(): void
    {
        $token = $this->keyToken([AgencyApiKey::SCOPE_TESTIMONIALS_READ]);

        // Another agency's published testimonial must never appear.
        $other = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront', 'website_enabled' => true]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'B']);
        $otherContact = Contact::withoutGlobalScopes()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'first_name' => 'X', 'last_name' => 'Y', 'phone' => '0825550199']);
        ContactTestimonial::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $other->id, 'contact_id' => $otherContact->id,
            'body' => 'Foreign praise', 'display_name' => 'X', 'published' => true, 'published_at' => now(),
        ]);
        $this->makeTestimonial(['body' => 'Ours', 'display_name' => 'A', 'published' => true, 'published_at' => now()]);

        $bodies = collect($this->withToken($token)->getJson('/api/v1/website/testimonials')->json('data'))->pluck('body');
        $this->assertTrue($bodies->contains('Ours'));
        $this->assertFalse($bodies->contains('Foreign praise'));
        $this->assertCount(1, $bodies);
    }

    public function test_unpublished_detail_is_404(): void
    {
        $token = $this->keyToken([AgencyApiKey::SCOPE_TESTIMONIALS_READ]);
        $t = $this->makeTestimonial(['published' => false]);
        $this->withToken($token)->getJson("/api/v1/website/testimonials/{$t->id}")->assertStatus(404);
    }

    // ── Webhooks (publish → push) ────────────────────────────────────────────

    public function test_publishing_fires_a_signed_testimonial_published_webhook(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->keyWithWebhook();
        $t = $this->makeTestimonial();

        $t->update(['published' => true, 'published_at' => now()]);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'testimonial.published']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-CoreX-Event', 'testimonial.published')
            && $r->hasHeader('X-CoreX-Signature', hash_hmac('sha256', $r->body(), 'whsec_test')));
    }

    public function test_unpublishing_fires_testimonial_removed(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->keyWithWebhook();
        $t = $this->makeTestimonial(['published' => true, 'published_at' => now()]);

        $t->update(['published' => false]);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'testimonial.removed']);
    }

    public function test_editing_a_published_testimonial_fires_testimonial_updated(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->keyWithWebhook();
        $t = $this->makeTestimonial(['published' => true, 'published_at' => now()]);

        $t->update(['body' => 'Reworded glowing review.']);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'testimonial.updated']);
    }

    public function test_changes_to_unpublished_testimonial_fire_no_webhook(): void
    {
        Http::fake();
        $this->keyWithWebhook();
        $t = $this->makeTestimonial(['published' => false]);

        $t->update(['body' => 'Still private.']);

        $this->assertSame(0, \App\Models\AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
        Http::assertNothingSent();
    }

    public function test_master_switch_off_fires_no_webhook(): void
    {
        Http::fake();
        $this->agency->update(['website_enabled' => false]);
        $this->keyWithWebhook();
        $t = $this->makeTestimonial();

        $t->update(['published' => true, 'published_at' => now()]);

        $this->assertSame(0, \App\Models\AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
        Http::assertNothingSent();
    }

    // ── Agent linkage (website agent profiles) ───────────────────────────────

    public function test_capture_defaults_agent_to_capturing_user(): void
    {
        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body' => 'Tagged to me by default.',
        ])->assertRedirect();

        $t = ContactTestimonial::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertSame($this->user->id, $t->agent_id);
    }

    public function test_capture_honours_a_chosen_agent_in_the_agency(): void
    {
        $agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Thandi',
        ]);

        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body' => 'About Thandi.', 'agent_id' => $agent->id,
        ])->assertRedirect();

        $this->assertSame($agent->id, ContactTestimonial::withoutGlobalScope(AgencyScope::class)->first()->agent_id);
    }

    public function test_capture_rejects_cross_agency_agent_and_falls_back(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other']);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'B']);
        $foreignAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'agent']);

        $this->actingAs($this->user)->post(route('corex.contacts.testimonials.store', $this->contact), [
            'body' => 'Bad tag.', 'agent_id' => $foreignAgent->id,
        ])->assertRedirect();

        $t = ContactTestimonial::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertSame($this->user->id, $t->agent_id, 'Cross-agency agent rejected → falls back to capturing user.');
    }

    public function test_public_api_exposes_agent_and_filters_by_agent_id(): void
    {
        $token = $this->keyToken([AgencyApiKey::SCOPE_TESTIMONIALS_READ]);
        $agentA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent A']);
        $agentB = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent B']);
        $this->makeTestimonial(['body' => 'For A', 'agent_id' => $agentA->id, 'published' => true, 'published_at' => now()]);
        $this->makeTestimonial(['body' => 'For B', 'agent_id' => $agentB->id, 'published' => true, 'published_at' => now()]);

        $all = $this->withToken($token)->getJson('/api/v1/website/testimonials')->assertOk();
        $forA = collect($all->json('data'))->firstWhere('body', 'For A');
        $this->assertSame($agentA->id, $forA['agent_id']);
        $this->assertSame('Agent A', $forA['agent']['name']);

        $filtered = $this->withToken($token)->getJson('/api/v1/website/testimonials?agent_id=' . $agentA->id)->assertOk();
        $bodies = collect($filtered->json('data'))->pluck('body');
        $this->assertTrue($bodies->contains('For A'));
        $this->assertFalse($bodies->contains('For B'));
    }

    public function test_listings_filter_by_agent_id_and_carry_agent_link(): void
    {
        $minted = AgencyApiKey::mintSecret();
        $key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Site', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
        $agentA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'A']);
        $agentB = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'B']);
        $this->makeListing($agentA, $key, 'A listing');
        $this->makeListing($agentB, $key, 'B listing');

        $resp = $this->withToken($minted['plaintext'])->getJson('/api/v1/website/listings?agent_id=' . $agentA->id)->assertOk();
        $titles = collect($resp->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('A listing'));
        $this->assertFalse($titles->contains('B listing'));
        // Each listing carries agent.id so the website can link the card to /agents/{id}.
        $this->assertSame($agentA->id, $resp->json('data.0.agent.id'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeListing(User $agent, AgencyApiKey $key, string $title): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => $title, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000, 'published_at' => now(),
        ]);
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'agency_api_key_id' => $key->id, 'enabled' => true,
        ]);
        return $p;
    }

    private function makeTestimonial(array $attrs = []): ContactTestimonial
    {
        return ContactTestimonial::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id'   => $this->agency->id,
            'contact_id'  => $this->contact->id,
            'user_id'     => $this->user->id,
            'body'        => 'A solid testimonial.',
            'display_name' => 'Andre Roets',
            'published'   => false,
        ], $attrs));
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

    private function keyWithWebhook(): AgencyApiKey
    {
        return AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Hooked site',
            'key_prefix' => 'cx_live_' . Str::lower(Str::random(8)), 'secret_hash' => hash('sha256', 'x'),
            'scopes' => [AgencyApiKey::SCOPE_TESTIMONIALS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://site.example/hook', 'webhook_secret' => 'whsec_test',
        ]);
    }
}
