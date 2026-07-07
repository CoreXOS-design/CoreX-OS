<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 2 (the /api/v1/website/* read endpoints).
 *
 * Proves listings are filtered to the key's enabled syndication pivot rows,
 * agents to show_on_website (tenant-safe), agency branding/settings surface,
 * the resource shapes hide PII, cross-agency isolation holds, and the catalog
 * groups everything under a "Website" section.
 *
 * Spec: .ai/specs/agency-public-api.md §5, §6.5, §11
 */
class Phase2WebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private AgencyApiKey $key;
    private string $token;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty', 'slug' => 'coastal-realty', 'website_enabled' => true,
            'website_tagline' => 'Your coast, your home', 'website_url' => 'https://coastal.example',
            'website_social_facebook' => 'coastalrealty', 'button_color' => '#0ea5e9',
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'name' => 'Thandi Mbeki', 'show_on_website' => true,
            'designation' => 'Principal Property Practitioner',
        ]);
        // Hidden agent — must never surface.
        User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'name' => 'Hidden Harry', 'show_on_website' => false,
        ]);

        $minted = AgencyApiKey::mintSecret();
        $this->token = $minted['plaintext'];
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Coastal Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [
                AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_AGENTS_READ,
                AgencyApiKey::SCOPE_AGENCY_READ, AgencyApiKey::SCOPE_BRANCHES_READ,
            ],
        ]);
    }

    public function test_ping_returns_key_context(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/website/ping')
            ->assertOk()
            ->assertJson(['ok' => true, 'agency_id' => $this->agency->id, 'website' => 'Coastal Website']);
    }

    public function test_agency_endpoint_returns_branding_and_settings(): void
    {
        $this->agency->update([
            'website_address'    => '12 Marina Drive, Uvongo',
            'website_open_hours' => [
                ['days' => 'Monday – Friday', 'hours' => '08:00 – 17:00'],
                ['days' => 'Saturday',        'hours' => '09:00 – 13:00'],
            ],
        ]);

        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertOk()
            ->assertJsonPath('data.name', 'Coastal Realty')
            ->assertJsonPath('data.social.facebook', 'coastalrealty')
            ->assertJsonPath('data.branding.button_color', '#0ea5e9')
            ->assertJsonPath('data.contact.address', '12 Marina Drive, Uvongo')
            ->assertJsonPath('data.open_hours.1.days', 'Saturday')
            // Legacy hero copy fields are no longer exposed.
            ->assertJsonMissingPath('data.website_url')
            ->assertJsonMissingPath('data.tagline')
            ->assertJsonMissingPath('data.about');
    }

    public function test_listings_returns_only_syndicated_enabled_for_this_key(): void
    {
        $live   = $this->makeProperty('Sea-view stunner', 'active', 2495000);
        $offSite = $this->makeProperty('Not syndicated', 'active', 1200000);
        $disabled = $this->makeProperty('Toggled off', 'active', 999000);

        $this->syndicate($live, true);
        $this->syndicate($disabled, false); // pivot exists but disabled
        // $offSite has no pivot row at all.

        $resp = $this->withToken($this->token)->getJson('/api/v1/website/listings')->assertOk();

        $titles = collect($resp->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Sea-view stunner'));
        $this->assertFalse($titles->contains('Not syndicated'));
        $this->assertFalse($titles->contains('Toggled off'));
        $this->assertCount(1, $resp->json('data'));
    }

    public function test_listing_detail_by_id_and_reference_and_hides_pii(): void
    {
        $p = $this->makeProperty('Sea-view stunner', 'active', 2495000);
        $this->syndicate($p, true);

        $byId = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertOk();
        $byId->assertJsonPath('data.price_display', 'R 2,495,000')
            ->assertJsonPath('data.agent.name', 'Thandi Mbeki');
        // No owner/PII leakage in the public shape.
        $this->assertArrayNotHasKey('agent_id', $byId->json('data'));
        $this->assertArrayNotHasKey('address_internal_note', $byId->json('data'));

        // By external reference too.
        $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->external_id}")
            ->assertOk()->assertJsonPath('data.id', $p->id);
    }

    public function test_co_listed_property_sends_both_agents_and_appears_on_both_profiles(): void
    {
        // A second agent co-lists the property alongside Thandi (primary).
        $second = $this->makeAgent('Sipho Dlamini', null);

        $p = $this->makeProperty('Co-listed villa', 'active', 3950000);
        $p->forceFill(['pp_second_agent_id' => $second->id])->save();
        $this->syndicate($p, true);

        // Detail endpoint: `agents` carries BOTH, primary first with is_primary.
        $data = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")
            ->assertOk()
            // `agent` (singular) stays as the primary for backward compatibility.
            ->assertJsonPath('data.agent.name', 'Thandi Mbeki')
            ->assertJsonPath('data.agents.0.name', 'Thandi Mbeki')
            ->assertJsonPath('data.agents.0.is_primary', true)
            ->assertJsonPath('data.agents.1.name', 'Sipho Dlamini')
            ->assertJsonPath('data.agents.1.is_primary', false)
            ->json('data');
        $this->assertCount(2, $data['agents']);

        // The property appears when filtering by EITHER agent's id.
        $primaryList = $this->withToken($this->token)
            ->getJson("/api/v1/website/listings?agent_id={$this->agent->id}")->assertOk()->json('data');
        $this->assertContains('Co-listed villa', collect($primaryList)->pluck('title'));

        $secondList = $this->withToken($this->token)
            ->getJson("/api/v1/website/listings?agent_id={$second->id}")->assertOk()->json('data');
        $this->assertContains('Co-listed villa', collect($secondList)->pluck('title'));
    }

    public function test_single_agent_listing_returns_one_element_agents_array(): void
    {
        $p = $this->makeProperty('Solo listing', 'active', 1500000);
        $this->syndicate($p, true);

        $data = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.agents.0.name', 'Thandi Mbeki')
            ->assertJsonPath('data.agents.0.is_primary', true)
            ->json('data');
        $this->assertCount(1, $data['agents']);
    }

    public function test_listing_exposes_seo_slug_and_canonical_public_url(): void
    {
        config()->set('integrations.public_website_url', 'http://91.99.130.85:1050');

        // Title slugified exactly like Str::slug(): lowercased, accents stripped,
        // every run of non-alphanumerics (spaces, commas) collapsed to one hyphen.
        $p = $this->makeProperty('Apartment For Sale in Margate, KwaZulu Natal', 'active', 1500000);
        $this->syndicate($p, true);

        $data = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'apartment-for-sale-in-margate-kwazulu-natal')
            ->assertJsonPath('data.public_url', "http://91.99.130.85:1050/property/apartment-for-sale-in-margate-kwazulu-natal-{$p->id}")
            ->json('data');
        $this->assertSame("apartment-for-sale-in-margate-kwazulu-natal-{$p->id}", basename($data['public_url']));
    }

    public function test_listing_without_title_falls_back_to_bare_id_url(): void
    {
        config()->set('integrations.public_website_url', 'http://91.99.130.85:1050');

        // A property with no usable title (empty string slugifies to '') falls
        // back to the bare-id URL — still resolvable since the website keys on id.
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => '', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 999000, 'published_at' => now(),
        ]);
        $this->syndicate($p, true);

        $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', '')
            ->assertJsonPath('data.public_url', "http://91.99.130.85:1050/property/{$p->id}");
    }

    public function test_non_syndicated_listing_detail_is_404(): void
    {
        $p = $this->makeProperty('Hidden listing', 'active', 1000000);
        // no syndication row
        $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertStatus(404);
    }

    public function test_agents_returns_only_show_on_website(): void
    {
        $resp = $this->withToken($this->token)->getJson('/api/v1/website/agents')->assertOk();
        $names = collect($resp->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Thandi Mbeki'));
        $this->assertFalse($names->contains('Hidden Harry'));
        // Agent card excludes the FFC number (compliance).
        $this->assertArrayNotHasKey('ffc_number', $resp->json('data.0'));
    }

    public function test_agent_card_includes_designation(): void
    {
        // Designation is a public "meet the team" field (e.g. Principal /
        // Candidate Property Practitioner) — surfaced on the agent card,
        // distinct from the compliance FFC number which stays hidden.
        $resp = $this->withToken($this->token)->getJson('/api/v1/website/agents')->assertOk();

        $thandi = collect($resp->json('data'))->firstWhere('name', 'Thandi Mbeki');
        $this->assertSame('Principal Property Practitioner', $thandi['designation']);

        // And on the detail endpoint.
        $this->withToken($this->token)->getJson("/api/v1/website/agents/{$this->agent->id}")
            ->assertOk()
            ->assertJsonPath('data.designation', 'Principal Property Practitioner');
    }

    public function test_cross_agency_isolation(): void
    {
        // Agency B with its own enabled listing + visible agent.
        $agencyB = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront', 'website_enabled' => true]);
        $branchB = Branch::create(['agency_id' => $agencyB->id, 'name' => 'B']);
        $agentB = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'role' => 'agent', 'name' => 'B Agent', 'show_on_website' => true]);
        $mintB = AgencyApiKey::mintSecret();
        $keyB = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyB->id, 'name' => 'B site', 'key_prefix' => $mintB['prefix'], 'secret_hash' => $mintB['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
        $propB = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyB->id, 'agent_id' => $agentB->id, 'branch_id' => $branchB->id,
            'external_id' => (string) Str::uuid(), 'title' => 'B listing', 'suburb' => 'Ramsgate',
            'property_type' => 'house', 'status' => 'active', 'price' => 800000,
        ]);
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyB->id, 'property_id' => $propB->id, 'agency_api_key_id' => $keyB->id, 'enabled' => true,
        ]);

        // Agency A's key sees none of B's data.
        $this->withToken($this->token)->getJson('/api/v1/website/listings')
            ->assertOk()->assertJsonCount(0, 'data');
        $names = collect($this->withToken($this->token)->getJson('/api/v1/website/agents')->json('data'))->pluck('name');
        $this->assertFalse($names->contains('B Agent'));
    }

    public function test_scope_enforced_on_listings(): void
    {
        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Agents-only', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [AgencyApiKey::SCOPE_AGENTS_READ],
        ]);
        $this->withToken($minted['plaintext'])->getJson('/api/v1/website/listings')->assertStatus(403);
    }

    public function test_catalog_groups_website_routes_under_website_section(): void
    {
        $admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);

        $this->actingAs($admin)->get('/admin/api')
            ->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->has('Website'));
    }

    public function test_listing_detail_exposes_full_p24_parity_fields(): void
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Rental unit', 'suburb' => 'Uvongo',
            'property_type' => 'apartment', 'listing_type' => 'rental', 'status' => 'active',
            'beds' => 2, 'baths' => 1, 'half_baths' => 1, 'garages' => 1, 'size_m2' => 85, 'erf_size_m2' => 0,
            'rental_amount' => 12500, 'deposit_amount' => 25000, 'has_deposit' => true, 'lease_period' => '12 months',
            'rates_taxes' => 800, 'levy' => 1500, 'special_levy' => 200, 'pet_friendly' => true,
            'complex_name' => 'Sea Breeze', 'unit_number' => '4B', 'floor_number' => 4,
            // Features mix categories so grouping is exercised: Pool (uncatalogued
            // → Other), Intercom + CCTV (Security), Fibre (Connectivity).
            'features_json' => ['Pool', 'Intercom', 'CCTV', 'Fibre'],
            // Canonical wrapped spaces shape the editor actually persists.
            'spaces_json' => [
                'spaces' => [
                    ['type' => 'Pool', 'count' => 1, 'featuresAll' => ['Heated'], 'descriptionAll' => 'Sparkling'],
                    ['type' => 'Parking', 'count' => 2, 'featuresAll' => [], 'descriptionAll' => ''],
                    ['type' => 'Bedroom', 'count' => 3, 'featuresAll' => [], 'descriptionAll' => '',
                     'units' => [['label' => 'Bedroom 1', 'features' => ['En-suite']]]],
                ],
                'features' => ['security' => ['Intercom', 'CCTV'], 'connectivity' => ['Fibre']],
            ],
            // Photos live in the dusk bucket — proves allImages() merge (not just images_json).
            'dusk_images_json' => ['https://img.example/dusk1.jpg'],
            'gallery_categories_json' => ['categories' => [['name' => 'Kitchen', 'images' => ['https://img.example/k1.jpg']]]],
            'youtube_video_id' => 'abc123', 'virtual_tour_url' => 'https://tour.example/1',
            'published_at' => now(),
        ]);
        \App\Models\PropertyShowday::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id,
            'start_date' => now()->addDay(), 'end_date' => now()->addDay()->addHours(2),
            'description' => 'Open house', 'active' => true,
        ]);
        $this->syndicate($p, true);

        $r = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertOk();
        $r->assertJsonPath('data.mandate_type', null === $p->mandate_type ? null : $p->mandate_type)
            ->assertJsonPath('data.rental.rental_amount', 12500)
            ->assertJsonPath('data.rental.lease_period', '12 months')
            ->assertJsonPath('data.costs.levy', 1500)
            ->assertJsonPath('data.costs.special_levy', 200)
            ->assertJsonPath('data.pet_friendly', true)
            // Half bathroom (guest toilet) is a first-class scalar in the feed,
            // distinct from full `baths` — the site renders `baths + ½`.
            ->assertJsonPath('data.baths', 1)
            ->assertJsonPath('data.half_baths', 1)
            // Suburb-level location only — street/complex/unit/floor are NEVER
            // syndicated (see test_listing_never_exposes_street_address_or_coordinates).
            ->assertJsonPath('data.suburb', 'Uvongo')
            ->assertJsonPath('data.video.youtube_id', 'abc123')
            ->assertJsonPath('data.video.youtube_url', 'https://www.youtube.com/watch?v=abc123')
            ->assertJsonPath('data.video.virtual_tour_url', 'https://tour.example/1');

        $data = $r->json('data');
        $this->assertContains('Pool', $data['features']);

        // Spaces are unwrapped from the canonical {spaces:[...]} shape — each a
        // typed object with count + features, not the raw wrapper.
        $spaceTypes = collect($data['spaces'])->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['Pool', 'Parking', 'Bedroom'], $spaceTypes);
        $pool = collect($data['spaces'])->firstWhere('type', 'Pool');
        $this->assertSame(1, $pool['count']);
        $this->assertContains('Heated', $pool['features']);
        $bedroom = collect($data['spaces'])->firstWhere('type', 'Bedroom');
        $this->assertSame('Bedroom 1', $bedroom['units'][0]['label']);
        $this->assertContains('En-suite', $bedroom['units'][0]['features']);

        // Features are grouped by catalog category for labelled display.
        $byGroup = collect($data['features_grouped'])->keyBy('label');
        $this->assertEqualsCanonicalizing(['Intercom', 'CCTV'], $byGroup['Security']['items']);
        $this->assertSame(['Fibre'], $byGroup['Connectivity']['items']);
        // Pool isn't a catalog feature → lands in the trailing "Other" group.
        $this->assertSame(['Pool'], $byGroup['Other']['items']);

        $this->assertContains('https://img.example/dusk1.jpg', $data['images']); // allImages() merge
        $this->assertArrayHasKey('Kitchen', $data['gallery']);
        $this->assertContains('https://img.example/k1.jpg', $data['gallery']['Kitchen']);
        $this->assertCount(1, $data['show_days']);
        $this->assertSame('Open house', $data['show_days'][0]['note']);
    }

    public function test_listing_never_exposes_street_address_or_coordinates(): void
    {
        // Privacy guarantee: the public website payload is suburb-level only.
        // Even when a property carries a full street address + GPS internally,
        // NONE of the sub-suburb location fields may cross the public boundary —
        // this holds for both the pull API and the webhook (same resource).
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Located unit', 'status' => 'active',
            'property_type' => 'house', 'listing_type' => 'sale', 'price' => 2000000,
            // Full internal location — all of this must be withheld publicly.
            'address' => '12 Marina Drive, Uvongo', 'street_number' => '12', 'street_name' => 'Marina Drive',
            'complex_name' => 'Sea Breeze', 'unit_number' => '4B', 'floor_number' => 4, 'stand_number' => '778',
            'latitude' => -30.84321, 'longitude' => 30.39871,
            // Only these may surface.
            'suburb' => 'Uvongo', 'town' => 'Margate', 'city' => 'Ray Nkonyeni', 'province' => 'KwaZulu-Natal',
            'published_at' => now(),
        ]);
        $this->syndicate($p, true);

        $data = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertOk()->json('data');

        // Suburb-level location IS present.
        $this->assertSame('Uvongo', $data['suburb']);
        $this->assertSame('Margate', $data['town']);
        $this->assertSame('Ray Nkonyeni', $data['city']);
        $this->assertSame('KwaZulu-Natal', $data['province']);

        // Everything more granular than suburb is absent from the payload entirely.
        foreach ([
            'address', 'street_number', 'street_name', 'complex_name',
            'unit_number', 'floor_number', 'stand_number', 'latitude', 'longitude',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "Public listing leaked '{$forbidden}'.");
        }

        // And the literal street string never appears anywhere in the JSON body.
        $this->assertStringNotContainsString('Marina Drive', json_encode($data));
    }

    public function test_listing_images_do_not_double_the_storage_prefix(): void
    {
        // gallery_images_json stores values exactly as Storage::url() emits them
        // at upload time — i.e. already carrying a leading `/storage/`. The API
        // must NOT re-prefix these into `/storage/storage/...` (which 403s).
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Prefix unit', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1000000,
            'beds' => 3, 'baths' => 2, 'garages' => 1,
            'gallery_images_json' => [
                '/storage/properties/42/already-public.jpg', // upload-time shape
                'properties/42/bare-relative.jpg',           // bare disk path
            ],
            'published_at' => now(),
        ]);
        $this->syndicate($p, true);

        $data = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertOk()->json('data');

        foreach ($data['images'] as $url) {
            $this->assertStringNotContainsString('/storage/storage/', $url, "Doubled storage prefix in: {$url}");
        }
        // Both shapes resolve to the same single-prefixed public URL.
        $expected = \Illuminate\Support\Facades\Storage::disk('public')->url('properties/42/already-public.jpg');
        $this->assertContains($expected, $data['images']);
    }

    public function test_agents_default_alphabetical_order(): void
    {
        // setUp already has visible "Thandi Mbeki".
        $this->makeAgent('Zoe', 1);
        $this->makeAgent('Anna', 2);
        $this->makeAgent('Bob', 3);

        $names = collect($this->withToken($this->token)->getJson('/api/v1/website/agents')->json('data'))->pluck('name')->all();
        $this->assertSame(['Anna', 'Bob', 'Thandi Mbeki', 'Zoe'], $names);
        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertJsonPath('data.agent_order_mode', 'alphabetical');
    }

    public function test_agents_custom_order_partial_numbering_rest_alphabetical(): void
    {
        // Only Bob is numbered; the rest (Anna, Zoe, + setUp's Thandi) have no number.
        $this->agency->update(['website_agent_order_mode' => 'custom']);
        $this->makeAgent('Bob', 1);
        $this->makeAgent('Zoe', null);
        $this->makeAgent('Anna', null);

        // Expected: Bob (numbered) first, then the un-numbered ones A–Z.
        $names = collect($this->withToken($this->token)->getJson('/api/v1/website/agents')->json('data'))->pluck('name')->all();
        $this->assertSame(['Bob', 'Anna', 'Thandi Mbeki', 'Zoe'], $names);
    }

    public function test_agents_custom_order(): void
    {
        // All setup BEFORE any request (the test guard memoizes the key/agency).
        $this->agency->update(['website_agent_order_mode' => 'custom']);
        $zoe = $this->makeAgent('Zoe', 1);
        $anna = $this->makeAgent('Anna', 2);
        $bob = $this->makeAgent('Bob', 3);

        // Custom = by website_order (Zoe=1, Anna=2, Bob=3), null-order (Thandi from setUp) last.
        $ids = collect($this->withToken($this->token)->getJson('/api/v1/website/agents')->json('data'))->pluck('id')->all();
        $this->assertSame([$zoe->id, $anna->id, $bob->id, $this->agent->id], $ids);

        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertJsonPath('data.agent_order_mode', 'custom');
    }

    public function test_branches_default_alphabetical_order(): void
    {
        // setUp already created branch "Main".
        $this->makeBranch('Zinkwazi', 1);
        $this->makeBranch('Amanzimtoti', 2);
        $this->makeBranch('Ballito', 3);

        $names = collect($this->withToken($this->token)->getJson('/api/v1/website/branches')->json('data'))->pluck('trading_name')->all();
        // No trading_name set → BranchResource falls back to name. Default = A–Z.
        $this->assertSame(['Amanzimtoti', 'Ballito', 'Main', 'Zinkwazi'], $names);

        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertJsonPath('data.branch_order_mode', 'alphabetical');
    }

    public function test_branches_custom_order_numbered_first_rest_alphabetical(): void
    {
        $this->agency->update(['website_branch_order_mode' => 'custom']);
        $ballito = $this->makeBranch('Ballito', 1);
        $zinkwazi = $this->makeBranch('Zinkwazi', null);
        $amanzimtoti = $this->makeBranch('Amanzimtoti', null);

        // Numbered branch first, then the un-numbered ones (incl. setUp's Main) A–Z.
        $names = collect($this->withToken($this->token)->getJson('/api/v1/website/branches')->json('data'))->pluck('trading_name')->all();
        $this->assertSame(['Ballito', 'Amanzimtoti', 'Main', 'Zinkwazi'], $names);

        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertJsonPath('data.branch_order_mode', 'custom');
    }

    private function makeBranch(string $name, ?int $order): Branch
    {
        return Branch::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => $name, 'website_order' => $order,
        ]);
    }

    private function makeAgent(string $name, ?int $order): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'name' => $name, 'show_on_website' => true, 'website_order' => $order,
        ]);
    }

    public function test_index_pagination_is_stable_and_returns_every_listing(): void
    {
        // Reproduces the live bug: a collection where EVERY row has a NULL
        // published_at (imported/promoted stock never stamps it). Ordering by
        // that all-NULL column under LIMIT/OFFSET with no unique tiebreaker
        // duplicated rows across pages and dropped others off every page —
        // silently hiding live listings. The deterministic order must page
        // through ALL of them exactly once.
        $ids = [];
        for ($i = 0; $i < 35; $i++) {
            $p = Property::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
                'external_id' => (string) Str::uuid(), 'title' => "Listing {$i}", 'suburb' => 'Uvongo',
                'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1000000 + $i,
                'published_at' => null, // the crux — no publish timestamp, like real stock.
            ]);
            $this->syndicate($p, true);
            $ids[] = $p->id;
        }

        $collected = [];
        for ($page = 1; $page <= 4; $page++) {
            $resp = $this->withToken($this->token)
                ->getJson("/api/v1/website/listings?per_page=10&page={$page}")->assertOk();
            $collected = array_merge($collected, collect($resp->json('data'))->pluck('id')->all());
            $this->assertSame(35, $resp->json('meta.total'));
        }

        // No row appears twice, and every syndicated listing is reachable.
        $this->assertCount(count($collected), array_unique($collected), 'A listing was duplicated across pages.');
        sort($ids);
        $got = array_unique($collected);
        sort($got);
        $this->assertSame($ids, $got, 'Some syndicated listing was missing from every page.');
    }

    public function test_status_filter_narrows_the_result_set(): void
    {
        $active = $this->makeProperty('Live one', 'active', 1500000);
        $sold   = $this->makeProperty('Sold one', 'sold', 2500000);
        $this->syndicate($active, true);
        $this->syndicate($sold, true);

        // No filter → both (sold is showcased by default, per Johan's call).
        $all = $this->withToken($this->token)->getJson('/api/v1/website/listings')->assertOk();
        $this->assertEqualsCanonicalizing(['Live one', 'Sold one'], collect($all->json('data'))->pluck('title')->all());

        // ?status=active → only the active one.
        $only = $this->withToken($this->token)->getJson('/api/v1/website/listings?status=active')->assertOk();
        $this->assertSame(['Live one'], collect($only->json('data'))->pluck('title')->all());
        $this->assertSame(1, $only->json('meta.total'));

        // CSV honours multiple states.
        $both = $this->withToken($this->token)->getJson('/api/v1/website/listings?status=active,sold')->assertOk();
        $this->assertSame(2, $both->json('meta.total'));
    }

    public function test_status_filter_cannot_surface_a_never_public_status(): void
    {
        // A status the guard forbids (expired/withdrawn/draft) must not be
        // reachable even when requested explicitly — the whitelist wins.
        $expired = $this->makeProperty('Dead mandate', 'expired', 999000);
        $this->syndicate($expired, true);

        $this->withToken($this->token)->getJson('/api/v1/website/listings?status=expired')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_draft_listing_never_appears_even_with_enabled_pivot(): void
    {
        // Legacy data: a draft whose website pivot was enabled before the draft
        // guard existed must be invisible on BOTH index and detail.
        $draft = $this->makeProperty('Half-built draft', 'draft', 1200000);
        $this->syndicate($draft, true);

        $this->withToken($this->token)->getJson('/api/v1/website/listings')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->token)->getJson("/api/v1/website/listings/{$draft->id}")
            ->assertStatus(404);
    }

    public function test_listing_type_filter(): void
    {
        $sale   = $this->makeProperty('A sale', 'active', 2000000);
        $rental = $this->makeProperty('A rental', 'active', 0);
        $rental->forceFill(['listing_type' => 'rental'])->save();
        $sale->forceFill(['listing_type' => 'sale'])->save();
        $this->syndicate($sale, true);
        $this->syndicate($rental, true);

        $r = $this->withToken($this->token)->getJson('/api/v1/website/listings?listing_type=rental')->assertOk();
        $this->assertSame(['A rental'], collect($r->json('data'))->pluck('title')->all());
    }

    public function test_sort_param_changes_the_order(): void
    {
        // Three listings created out of id/price order; ?sort must reorder them.
        $a = $this->makeProperty('Cheapest', 'active', 500000);
        $b = $this->makeProperty('Dearest', 'active', 9000000);
        $c = $this->makeProperty('Middle', 'active', 3000000);
        foreach ([$a, $b, $c] as $p) {
            $this->syndicate($p, true);
        }

        $byPriceAsc = collect($this->withToken($this->token)
            ->getJson('/api/v1/website/listings?sort=price')->json('data'))->pluck('id')->all();
        $this->assertSame([$a->id, $c->id, $b->id], $byPriceAsc);

        $byPriceDesc = collect($this->withToken($this->token)
            ->getJson('/api/v1/website/listings?sort=-price')->json('data'))->pluck('id')->all();
        $this->assertSame([$b->id, $c->id, $a->id], $byPriceDesc);

        $byIdDesc = collect($this->withToken($this->token)
            ->getJson('/api/v1/website/listings?sort=-id')->json('data'))->pluck('id')->all();
        $this->assertSame([$c->id, $b->id, $a->id], $byIdDesc);
    }

    // ---- helpers -----------------------------------------------------------

    private function makeProperty(string $title, string $status, int $price): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => $title, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => $price,
            'beds' => 3, 'baths' => 2, 'published_at' => now(),
        ]);
    }

    private function syndicate(Property $p, bool $enabled): void
    {
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'agency_api_key_id' => $this->key->id,
            'enabled' => $enabled, 'status' => $enabled ? PropertyWebsiteSyndication::STATUS_ACTIVE : PropertyWebsiteSyndication::STATUS_DEACTIVATED,
        ]);
    }
}
