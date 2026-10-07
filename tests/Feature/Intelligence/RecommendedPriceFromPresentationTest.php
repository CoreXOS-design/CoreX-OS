<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Presentations\PresentationRecommendedPrice;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan 2026-10-07 (F): the recommended price is the PRESENTATION's price — one figure on the
 * presentation left panel, the Intelligence tab and the client API; no presentation, no price.
 * The figure is the frozen evaluated value (cma_middle) — what the seller PDF prints as
 * "your home fits best at …". Draft presentations count; latest presentation wins.
 */
final class RecommendedPriceFromPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
    }

    // ── Resolver ──────────────────────────────────────────────────────────

    public function test_no_presentation_means_state_none_and_no_price(): void
    {
        $property = $this->makeProperty();

        $r = app(PresentationRecommendedPrice::class)->forProperty($property);

        $this->assertSame('none', $r['state']);
        $this->assertNull($r['price']);
        $this->assertNull($r['presentation_id']);
    }

    public function test_presentation_without_a_calculated_valuation_is_no_price_not_none(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property);
        $this->makeVersion($presentation, null);                       // no frozen payload
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => null]]); // valuation block, no comps

        $r = app(PresentationRecommendedPrice::class)->forProperty($property);

        $this->assertSame('no_price', $r['state']);
        $this->assertNull($r['price']);
        $this->assertSame($presentation->id, $r['presentation_id']);
    }

    public function test_draft_presentation_price_counts_and_is_the_evaluated_value(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property, ['status' => 'draft']);
        $this->makeVersion($presentation, ['cma_valuation' => [
            'cma_lower' => 882000, 'cma_middle' => 980000, 'cma_upper' => 1107400,
        ]]);

        $r = app(PresentationRecommendedPrice::class)->forProperty($property);

        $this->assertSame('price', $r['state']);
        $this->assertSame(980000, $r['price'], 'the middle (evaluated value), not lower/upper');
    }

    public function test_latest_presentation_wins_even_when_an_older_one_had_a_price(): void
    {
        $property = $this->makeProperty();
        $old = $this->makePresentation($property);
        $this->makeVersion($old, ['cma_valuation' => ['cma_middle' => 900000]]);
        $new = $this->makePresentation($property);
        $this->makeVersion($new, ['cma_valuation' => ['cma_middle' => 1010000]]);

        $this->assertSame(1010000, app(PresentationRecommendedPrice::class)->forProperty($property)['price']);

        // A newer presentation with NO price must not fall back to the older figure
        // (seller and presentation would disagree).
        $newest = $this->makePresentation($property);
        $r = app(PresentationRecommendedPrice::class)->forProperty($property);
        $this->assertSame($newest->id, $r['presentation_id']);
        $this->assertNull($r['price']);
    }

    public function test_latest_version_with_a_valuation_wins_within_a_presentation(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property);
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => 900000]]);
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => 950000]]);
        $this->makeVersion($presentation, null); // newer, but unfrozen — must not shadow the real one

        $this->assertSame(950000, app(PresentationRecommendedPrice::class)->forPresentationId($presentation->id)['price']);
    }

    public function test_getLatestMarketPosition_uses_the_presentation_price_not_its_own_calculation(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property);
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => 1234000]]);

        $pos = app(PropertyIntelligenceService::class)->getLatestMarketPosition($property->id);

        $this->assertNotNull($pos);
        $this->assertSame(1234000, $pos['recommended_price']);
    }

    public function test_the_tabs_presentation_list_finds_presentations_by_property(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property);

        $this->assertSame([$presentation->id], app(PropertyIntelligenceService::class)->getPresentations($property->id)->pluck('id')->all());
    }

    // ── Intelligence tab ──────────────────────────────────────────────────

    public function test_tab_with_no_presentation_says_so_offers_generate_and_shows_no_price(): void
    {
        $property = $this->makeProperty();

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('No presentation done yet', $html);
        $this->assertStringContainsString('data-generate-presentation', $html);
        $this->assertStringNotContainsString('data-recommended-price>', $html);
        // The existing generator widget listens for the button's event — no second generate flow.
        $this->assertStringContainsString('corex:generate-presentation', $html);
    }

    public function test_tab_shows_the_presentation_price_and_no_generate_button(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property);
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => 980000]]);

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-recommended-price>\s*R 980,000/', $html);
        $this->assertStringNotContainsString('No presentation done yet', $html);
        $this->assertStringNotContainsString('data-generate-presentation', $html);
    }

    public function test_tab_with_presentation_but_no_price_shows_a_dash_not_a_made_up_figure(): void
    {
        $property = $this->makeProperty();
        $this->makePresentation($property);

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('data-no-presentation-price', $html);
        $this->assertStringNotContainsString('data-recommended-price>', $html);
        $this->assertStringNotContainsString('No presentation done yet', $html);
    }

    public function test_rental_has_no_recommended_price_card(): void
    {
        $property = $this->makeProperty(['listing_type' => 'rental']);

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-recommended-price-card', $html);
    }

    // ── Presentation left panel ───────────────────────────────────────────

    public function test_left_panel_shows_the_recommended_price_under_the_asking_price(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property, ['asking_price_inc' => 1195000]);
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => 980000]]);

        $html = $this->actingAs($this->agent)->get(route('presentations.show', $presentation))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Asking price.*?R 1 195 000.*?data-recommended-price-panel.*?Recommended price.*?R 980 000/s', $html);
    }

    public function test_left_panel_without_a_valuation_shows_a_dash_and_says_why(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property, ['asking_price_inc' => 1195000]);

        $html = $this->actingAs($this->agent)->get(route('presentations.show', $presentation))->assertOk()->getContent();

        $this->assertStringContainsString('data-recommended-price-panel', $html);
        $this->assertStringContainsString('Not calculated yet', $html);
    }

    // ── Seller live link: omits it entirely ───────────────────────────────

    public function test_seller_live_link_never_shows_a_recommended_price_or_a_generate_button(): void
    {
        $property = $this->makeProperty();
        $presentation = $this->makePresentation($property);
        $this->makeVersion($presentation, ['cma_valuation' => ['cma_middle' => 980000]]);
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sam', 'last_name' => 'Seller' . Str::random(3),
            'phone' => '083' . random_int(1000000, 9999999), 'email' => 'sam-' . Str::random(5) . '@example.co.za',
        ]);
        $link = PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'contact_id' => $seller->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->agent->id, 'generated_at' => now(),
        ]);

        $html = $this->get('/property/live/' . $link->token)->assertOk()->getContent();

        $this->assertStringNotContainsString('Recommended Price', $html);
        $this->assertStringNotContainsString('Generate presentation', $html);
        $this->assertStringNotContainsString('No presentation done yet', $html);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeProperty(array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_250_000,
        ], $extra));
    }

    private function makePresentation(Property $property, array $extra = []): Presentation
    {
        return Presentation::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'created_by_user_id' => $this->agent->id, 'title' => 'Pres ' . Str::random(4),
            'property_address' => '1 Test St', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'status' => 'draft', 'currency' => 'ZAR',
        ], $extra));
    }

    private function makeVersion(Presentation $presentation, ?array $payload): PresentationVersion
    {
        $v = PresentationVersion::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'presentation_id' => $presentation->id, 'compiled_by' => $this->agent->id,
            'blueprint_version' => 'v1', 'data_snapshot_json' => '{}', 'compiled_at' => now(),
        ]);
        if ($payload !== null) {
            DB::table('presentation_versions')->where('id', $v->id)->update([
                'snapshot_payload' => json_encode($payload), 'snapshot_taken_at' => now(),
            ]);
        }

        return $v;
    }
}
