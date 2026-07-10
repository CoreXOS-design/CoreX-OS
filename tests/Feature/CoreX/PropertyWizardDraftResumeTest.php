<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-188 — a plain "New Property" visit must ALWAYS start a fresh listing. The
 * wizard only rebinds the user to an existing draft when it is explicitly asked
 * (?resume), which is what the "Drafts" button on the listing does. Before this
 * fix the wizard silently dropped the user back onto their last draft on every
 * "New Property" click.
 */
final class PropertyWizardDraftResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The wizard renders the full corex layout (@vite); stub the manifest so
        // these tests exercise the controller, not the frontend build.
        $this->withoutVite();
    }

    public function test_new_property_visit_does_not_resume_an_existing_draft(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $draft = $this->makeDraft($agent, $suburbId);

        $this->actingAs($agent)
            ->get(route('corex.properties.wizard'))
            ->assertOk()
            // draftId seeds the Alpine component; a fresh visit must be null.
            ->assertSee('draftId: null', false)
            ->assertDontSee('draftId: ' . $draft->id, false);
    }

    public function test_resume_param_rebinds_the_latest_draft(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $draft = $this->makeDraft($agent, $suburbId);

        $this->actingAs($agent)
            ->get(route('corex.properties.wizard', ['resume' => 1]))
            ->assertOk()
            ->assertSee('draftId: ' . $draft->id, false)
            ->assertSee('unfinished draft', false);
    }

    public function test_resume_with_a_specific_id_rebinds_that_exact_draft(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $older = $this->makeDraft($agent, $suburbId);
        $newer = $this->makeDraft($agent, $suburbId);

        // Picking the OLDER draft from the popup must resume it, not the newest.
        $this->actingAs($agent)
            ->get(route('corex.properties.wizard', ['resume' => $older->id]))
            ->assertOk()
            ->assertSee('draftId: ' . $older->id, false)
            ->assertDontSee('draftId: ' . $newer->id, false);
    }

    public function test_resume_with_a_foreign_draft_id_falls_back_to_own_latest(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        [$other] = $this->seedAgencyAgent();
        $mine    = $this->makeDraft($agent, $suburbId);
        $foreign = $this->makeDraft($other, $suburbId);

        // A hand-tampered id belonging to another agent must never resolve to
        // their draft — it falls back to the caller's own latest draft.
        $this->actingAs($agent)
            ->get(route('corex.properties.wizard', ['resume' => $foreign->id]))
            ->assertOk()
            ->assertSee('draftId: ' . $mine->id, false)
            ->assertDontSee('draftId: ' . $foreign->id, false);
    }

    public function test_resume_only_returns_the_users_own_draft(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        [$other] = $this->seedAgencyAgent();
        // A draft owned by a different agent must never be resumed.
        $this->makeDraft($other, $suburbId);

        $this->actingAs($agent)
            ->get(route('corex.properties.wizard', ['resume' => 1]))
            ->assertOk()
            ->assertSee('draftId: null', false);
    }

    // ---- AT-210: step 1 is idempotent — resuming/editing updates in place ----

    public function test_step1_with_property_id_updates_the_draft_in_place_not_a_duplicate(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $draft  = $this->makeDraft($agent, $suburbId);
        $before = Property::where('agent_id', $agent->id)->count();

        $resp = $this->actingAs($agent)->postJson(
            route('corex.properties.wizard.draft'),
            $this->step1Payload($suburbId, [
                'property_id' => $draft->id,
                'title'       => 'Updated Headline',
                'price'       => 2_750_000,
            ])
        );

        $resp->assertOk()->assertJsonPath('property.id', $draft->id);
        // No second row minted, and the SAME draft carries the edits.
        $this->assertSame($before, Property::where('agent_id', $agent->id)->count());
        $this->assertSame('Updated Headline', $draft->fresh()->title);
        $this->assertEquals(2_750_000, $draft->fresh()->price);
    }

    public function test_step1_without_property_id_still_mints_a_fresh_draft(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $before = Property::where('agent_id', $agent->id)->count();

        $this->actingAs($agent)
            ->postJson(route('corex.properties.wizard.draft'), $this->step1Payload($suburbId))
            ->assertOk();

        $this->assertSame($before + 1, Property::where('agent_id', $agent->id)->count());
    }

    public function test_step1_property_id_never_mutates_a_published_or_foreign_listing(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $published = $this->makeDraft($agent, $suburbId);
        $published->update(['status' => 'active', 'published_at' => now(), 'title' => 'LIVE LISTING']);
        $before = Property::where('agent_id', $agent->id)->count();

        // A tampered property_id pointing at a published (non-draft) listing must
        // never be mutated — the wizard falls back to minting a new draft.
        $this->actingAs($agent)->postJson(
            route('corex.properties.wizard.draft'),
            $this->step1Payload($suburbId, ['property_id' => $published->id, 'title' => 'HIJACKED'])
        )->assertOk();

        $this->assertSame('LIVE LISTING', $published->fresh()->title);
        $this->assertSame($before + 1, Property::where('agent_id', $agent->id)->count());
    }

    public function test_step1_property_id_of_another_agents_draft_mints_own_new_draft(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        [$other] = $this->seedAgencyAgent();
        $foreign     = $this->makeDraft($other, $suburbId);
        $foreignName = $foreign->title;
        $mineBefore  = Property::where('agent_id', $agent->id)->count();

        // A tampered id belonging to ANOTHER agent's draft must never be mutated;
        // the caller gets their own fresh draft instead.
        $this->actingAs($agent)->postJson(
            route('corex.properties.wizard.draft'),
            $this->step1Payload($suburbId, ['property_id' => $foreign->id, 'title' => 'HIJACK'])
        )->assertOk();

        $this->assertSame($foreignName, $foreign->fresh()->title);
        $this->assertSame($mineBefore + 1, Property::where('agent_id', $agent->id)->count());
    }

    public function test_resume_preloads_the_draft_fields_for_in_place_editing(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();
        $draft = $this->makeDraft($agent, $suburbId);

        $this->actingAs($agent)
            ->get(route('corex.properties.wizard', ['resume' => $draft->id]))
            ->assertOk()
            ->assertSee('draftPrefill', false)
            ->assertSee($draft->title, false);
    }

    /** Valid step-1 payload keyed to the seeded P24 chain. */
    private function step1Payload(int $suburbId, array $over = []): array
    {
        return array_merge([
            'listing_type'    => 'sale',
            'property_type'   => 'House',
            'title'           => 'Fresh ' . Str::random(4),
            'price'           => 2_000_000,
            'beds'            => 3,
            'baths'           => 2,
            'garages'         => 1,
            'p24_province_id' => $this->provinceId,
            'p24_city_id'     => $this->cityId,
            'p24_suburb_id'   => $suburbId,
        ], $over);
    }

    private function makeDraft(User $agent, int $suburbId): Property
    {
        return Property::create([
            'agency_id'       => $agent->agency_id,
            'branch_id'       => $agent->branch_id,
            'agent_id'        => $agent->id,
            'listing_type'    => 'sale',
            'property_type'   => 'House',
            'title'           => 'Draft ' . Str::random(4),
            'price'           => 1_500_000,
            'beds'            => 3,
            'baths'           => 2,
            'garages'         => 1,
            'suburb'          => 'Uvongo',
            'p24_province_id' => $this->provinceId,
            'p24_city_id'     => $this->cityId,
            'p24_suburb_id'   => $suburbId,
            'status'          => 'draft',
            'published_at'    => null,
        ]);
    }

    private int $provinceId = 0;
    private int $cityId = 0;
    private int $suburbId = 0;

    /**
     * Seed the shared P24 location chain exactly once per test — the reference
     * rows carry fixed p24_id values, so re-inserting them collides on the
     * unique index. Every agent created afterwards reuses the same suburb.
     */
    private function seedP24Once(): void
    {
        if ($this->suburbId !== 0) {
            return;
        }

        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 90000, 'name' => 'South Africa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90002, 'p24_province_id' => $this->provinceId, 'name' => 'Hibiscus Coast',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(6),
            'p24_id' => 90003, 'p24_city_id' => $this->cityId, 'confirmed' => 1,
            'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:User,1:int,2:int} [agent, p24SuburbId, agencyId] */
    private function seedAgencyAgent(): array
    {
        $this->seedP24Once();

        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        return [$agent, $this->suburbId, $agencyId];
    }
}
