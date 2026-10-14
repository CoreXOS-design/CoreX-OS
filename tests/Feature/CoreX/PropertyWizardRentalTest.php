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
 * Cape walk, 14 Oct 2026 - the property wizard for a RENTAL: the rent typed in step 1 is the rent (not a sale price, so the
 * header never reads "R 0"), the deposit and lease start typed in step 3 are kept, and one wizard session is one draft however
 * many times "Continue" is pressed.
 */
final class PropertyWizardRentalTest extends TestCase
{
    use RefreshDatabase;

    private int $provinceId = 0;
    private int $cityId = 0;
    private int $suburbId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_rent_typed_in_step_one_is_saved_as_the_rent_and_the_sale_price_stays_empty(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->assertOk();

        $p = Property::where('agent_id', $agent->id)->firstOrFail();
        $this->assertEquals(12500, $p->rental_amount);
        $this->assertEquals(0, $p->price);
        $this->assertSame(12500.0, $p->effectivePrice());
        $this->assertSame('R 12 500', $p->formattedPrice(), 'the header shows the rent, not R 0');
    }

    public function test_a_sale_still_saves_the_asking_price_as_the_price(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['listing_type' => 'sale', 'price' => 1_850_000]))->assertOk();

        $p = Property::where('agent_id', $agent->id)->firstOrFail();
        $this->assertEquals(1_850_000, $p->price);
        $this->assertNull($p->rental_amount);
    }

    public function test_editing_step_one_again_updates_the_rent_and_a_resumed_draft_shows_it_in_the_price_box(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->json('property.id');

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 13000, 'property_id' => $id]))->assertOk();

        $this->assertEquals(13000, Property::findOrFail($id)->rental_amount);
        $this->assertSame(1, Property::where('agent_id', $agent->id)->count());
        $this->actingAs($agent)->get(route('corex.properties.wizard', ['resume' => $id]))->assertOk()->assertSee('&quot;price&quot;:13000', false);
    }

    public function test_switching_a_resumed_draft_from_rental_to_sale_moves_the_figure_back_to_the_price(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->json('property.id');

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['listing_type' => 'sale', 'price' => 900_000, 'property_id' => $id]))->assertOk();

        $p = Property::findOrFail($id);
        $this->assertEquals(900_000, $p->price);
        $this->assertNull($p->rental_amount);
    }

    public function test_a_rental_draft_with_a_rent_passes_the_publish_readiness_check(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->json('property.id');
        $p = Property::findOrFail($id);
        $p->update(['gallery_images_json' => [['path' => 'x.jpg']]]);

        $this->assertNotEmpty($p->fresh()->effectivePrice(), 'finalize(publish) refuses a rental whose effective price is empty');
    }

    public function test_the_deposit_and_lease_start_typed_in_step_three_are_saved(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->json('property.id');

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.step', $id), [
            'description' => 'A lovely two bedroom flat close to the beach with a sea view and secure parking.',
            'deposit_amount' => 25000,
            'lease_start_date' => '2026-12-01',
            'mandate_type' => 'Sole',
        ])->assertOk();

        $p = Property::findOrFail($id);
        $this->assertEquals(25000, $p->deposit_amount);
        $this->assertSame('2026-12-01', $p->lease_start_date->format('Y-m-d'));
        $this->assertEquals(12500, $p->rental_amount, 'step 3 does not disturb the rent');
    }

    public function test_step_three_posted_like_the_browser_form_saves_the_deposit_and_lease_start(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->json('property.id');

        // The browser posts multipart FormData with only the non-empty s3 fields, and an Accept: application/json header.
        $this->actingAs($agent)->post(route('corex.properties.wizard.step', $id), [
            'description' => '', 'mandate_type' => '', 'branch_id' => (string) $agent->branch_id, 'agent_id' => (string) $agent->id,
            'deposit_amount' => '25000', 'lease_start_date' => '2026-12-01',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $p = Property::findOrFail($id);
        $this->assertEquals(25000, $p->deposit_amount);
        $this->assertSame('2026-12-01', $p->lease_start_date->format('Y-m-d'));
    }

    public function test_a_failed_step_three_says_why(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500]))->json('property.id');

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.step', $id), ['deposit_amount' => 'lots'])
            ->assertStatus(422)->assertJsonValidationErrors('deposit_amount');
    }

    public function test_the_same_wizard_session_pressed_twice_makes_one_draft(): void
    {
        $agent = $this->agent();
        $payload = $this->step1(['price' => 12500, 'wizard_token' => 'session-aaa']);

        $a = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $payload)->assertOk()->json('property.id');
        $b = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $payload)->assertOk()->json('property.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Property::where('agent_id', $agent->id)->count());
    }

    public function test_the_second_press_carries_the_edits_into_the_one_draft(): void
    {
        $agent = $this->agent();

        $a = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 12500, 'wizard_token' => 'session-bbb', 'title' => 'First']))->json('property.id');
        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['price' => 14000, 'wizard_token' => 'session-bbb', 'title' => 'Second']))->assertOk();

        $p = Property::findOrFail($a);
        $this->assertSame('Second', $p->title);
        $this->assertEquals(14000, $p->rental_amount);
        $this->assertSame(1, Property::where('agent_id', $agent->id)->count());
    }

    public function test_different_wizard_sessions_are_different_drafts(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['wizard_token' => 'one']))->assertOk();
        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['wizard_token' => 'two']))->assertOk();

        $this->assertSame(2, Property::where('agent_id', $agent->id)->count());
    }

    public function test_a_token_never_reaches_another_agents_draft(): void
    {
        $mine = $this->agent();
        $theirs = $this->agent();
        $theirId = $this->actingAs($theirs)->postJson(route('corex.properties.wizard.draft'), $this->step1(['wizard_token' => 'shared-guess', 'title' => 'THEIRS']))->json('property.id');

        $myId = $this->actingAs($mine)->postJson(route('corex.properties.wizard.draft'), $this->step1(['wizard_token' => 'shared-guess', 'title' => 'MINE']))->json('property.id');

        $this->assertNotSame($theirId, $myId);
        $this->assertSame('THEIRS', Property::withoutGlobalScopes()->findOrFail($theirId)->title);
    }

    public function test_a_token_does_not_reopen_a_published_listing(): void
    {
        $agent = $this->agent();
        $id = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['wizard_token' => 'pub', 'title' => 'LIVE']))->json('property.id');
        Property::findOrFail($id)->update(['status' => 'active', 'published_at' => now()]);

        $again = $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1(['wizard_token' => 'pub', 'title' => 'NEW']))->json('property.id');

        $this->assertNotSame($id, $again);
        $this->assertSame('LIVE', Property::findOrFail($id)->title);
    }

    public function test_without_a_token_a_post_still_makes_a_fresh_draft(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1())->assertOk();
        $this->actingAs($agent)->postJson(route('corex.properties.wizard.draft'), $this->step1())->assertOk();

        $this->assertSame(2, Property::where('agent_id', $agent->id)->count());
    }

    public function test_the_wizard_page_stamps_every_save_with_a_token_and_blocks_a_second_press(): void
    {
        $agent = $this->agent();

        $html = $this->actingAs($agent)->get(route('corex.properties.wizard'))->assertOk()->getContent();

        $this->assertStringContainsString("body.append('wizard_token', this.wizardToken)", $html);
        $this->assertStringContainsString('if (this.loading) return;', $html);
        $this->assertStringContainsString('data-qa="step3-error"', $html);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function step1(array $over = []): array
    {
        return array_merge([
            'listing_type' => 'rental', 'property_type' => 'Apartment / Flat', 'title' => 'Sea view flat ' . Str::random(4),
            'price' => 12500, 'beds' => 2, 'baths' => 1, 'garages' => 1,
            'p24_province_id' => $this->provinceId, 'p24_city_id' => $this->cityId, 'p24_suburb_id' => $this->suburbId,
        ], $over);
    }

    private function agent(): User
    {
        if ($this->suburbId === 0) {
            $countryId = (int) DB::table('p24_countries')->insertGetId(['p24_id' => 90000, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now()]);
            $this->provinceId = (int) DB::table('p24_provinces')->insertGetId(['p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'Western Cape', 'created_at' => now(), 'updated_at' => now()]);
            $this->cityId = (int) DB::table('p24_cities')->insertGetId(['p24_id' => 90002, 'p24_province_id' => $this->provinceId, 'name' => 'Cape Town', 'created_at' => now(), 'updated_at' => now()]);
            $this->suburbId = (int) DB::table('p24_suburbs')->insertGetId([
                'name' => 'Sea Point', 'slug' => 'sea-point-' . Str::random(6), 'p24_id' => 90003, 'p24_city_id' => $this->cityId,
                'confirmed' => 1, 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Wiz ' . Str::random(6), 'slug' => 'wiz-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
    }
}
