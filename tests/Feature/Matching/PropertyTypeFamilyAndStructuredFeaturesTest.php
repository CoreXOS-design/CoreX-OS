<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan's 2026-08-04 matching-model ruling:
 *
 *   1. property_type becomes a HARD gate at the FAMILY level (built/land/farm/
 *      commercial) — a built-property buyer must never see vacant land and
 *      vice versa, but a House buyer still sees Townhouses. Reads the
 *      multi-select property_types array (propertyTypeList()), not the
 *      legacy singular column. Beds/baths/price stay soft %.
 *   2. Feature matching (must-have / nice-to-have) uses STRUCTURED features
 *      (features_json) only — the description/headline text-scan fallback
 *      is removed. This is the corrected fix for the "4 Alomsee" case: a
 *      property whose prose happens to mention the ocean must NOT pass a
 *      "sea view" must-have it was never marked with.
 *
 * MIC-path cases (1-4) mirror MicCanonicalScoringTest's harness so both
 * surfaces of the shared canonical engine are covered. Case 5 hits
 * MatchingService::score() directly (feature text-fallback removal). Case 6
 * hits the property→buyer direction (matchesForProperty/applyHardFilters)
 * directly, since that's the actual new code the suburb-gate move touches.
 */
final class PropertyTypeFamilyAndStructuredFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
    }

    public function test_apartment_wishlist_excludes_vacant_land_listing(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000, 'beds_min' => 2, 'baths_min' => 2,
            'property_types' => ['Apartment / Flat'],
        ]);

        $land = $this->listing($agencyId, $agent->id, [
            'price' => 1_020_000, 'bedrooms' => null, 'bathrooms' => null, 'property_type' => 'Vacant Land / Plot',
        ]);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $this->assertFalse(
            DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $land)->exists(),
            'a vacant-land listing must never cache a match for an apartment-only buyer, even on a perfect price hit'
        );
    }

    public function test_apartment_wishlist_still_shows_townhouse_same_family(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000, 'beds_min' => 2, 'baths_min' => 2,
            'property_types' => ['Apartment / Flat'],
        ]);

        $townhouse = $this->listing($agencyId, $agent->id, [
            'price' => 1_020_000, 'bedrooms' => 2, 'bathrooms' => 2, 'property_type' => 'Townhouse',
        ]);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $row = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $townhouse)->first();
        $this->assertNotNull($row, 'a same-family (built) property must still surface — the gate is family-level, not exact-type');
        $this->assertGreaterThanOrEqual(80, (int) $row->score, 'strong fit on every soft criterion within the same family');
    }

    public function test_multiselect_wishlist_including_vacant_land_now_shows_vacant_land(): void
    {
        // This is the false-negative this fix corrects: a buyer who selected
        // House + Townhouse + Vacant Land was previously penalised on vacant
        // land because the OLD code only ever read the legacy singular
        // property_type column, which multi-select truncates to the FIRST
        // selection ("House"). propertyTypeList() reads the full array.
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000,
            'property_type' => 'House', // legacy singular column truncated to the first selection
            'property_types' => ['House', 'Townhouse', 'Vacant Land / Plot'],
        ]);

        $land = $this->listing($agencyId, $agent->id, [
            'price' => 1_020_000, 'bedrooms' => null, 'bathrooms' => null, 'property_type' => 'Vacant Land / Plot',
        ]);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $this->assertTrue(
            DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $land)->exists(),
            'a buyer who explicitly included Vacant Land in their multi-select must still see vacant land'
        );
    }

    public function test_open_type_wishlist_unaffected_by_family_gate(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000,
            // No property_type / property_types set at all — open to any type.
        ]);

        $land = $this->listing($agencyId, $agent->id, [
            'price' => 1_020_000, 'bedrooms' => null, 'bathrooms' => null, 'property_type' => 'Vacant Land / Plot',
        ]);
        $house = $this->listing($agencyId, $agent->id, [
            'price' => 1_020_000, 'bedrooms' => 3, 'bathrooms' => 2, 'property_type' => 'House',
        ]);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $this->assertTrue(
            DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $land)->exists(),
            'a buyer with no type preference at all must still see vacant land'
        );
        $this->assertTrue(
            DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $house)->exists(),
            'and must still see built stock too — the gate never fires when the wishlist is open'
        );
    }

    public function test_must_have_feature_ignores_description_text_uses_structured_features_only(): void
    {
        // Recreates the exact "4 Alomsee" mechanism: the property's PROSE
        // mentions the feature but features_json does not carry it.
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);

        $match = $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000,
            'must_have_features' => ['sea_view', 'security'],
        ]);

        $property = Property::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $agent->id,
            'title' => '4 Test Lane', 'listing_type' => 'sale', 'status' => 'active', 'category' => 'Residential',
            'property_type' => 'Apartment / Flat', 'address' => '4 Test Lane', 'suburb' => 'Uvongo',
            'price' => 1_000_000, 'beds' => 2, 'baths' => 2,
            'headline' => 'Upmarket house in secure complex',
            'description' => 'Enjoy romantic evenings looking out over the ocean from this secure complex.',
            // Structured features carry NEITHER "sea_view" nor "security" —
            // only the prose mentions them (ocean / secure).
            'features_json' => ['Tiled Floors', 'Built-in Cupboards'],
        ]);

        $matcher = app(MatchingService::class);
        $this->assertSame(
            0,
            $matcher->score($property, $match),
            'the property must NOT pass a must-have that only appears in prose, not in structured features_json'
        );

        // Confirm it is a genuinely strong fit on every OTHER axis, isolating
        // that the must-have gate — not something else — is what zeroes it.
        $matchNoMustHaves = clone $match;
        $matchNoMustHaves->must_have_features = [];
        $this->assertSame(100, $matcher->score($property, $matchNoMustHaves));

        // And confirm a property that DOES carry the feature structurally
        // still passes — this isn't "must-haves are broken", just "text no
        // longer rescues an unmarked feature".
        $property->features_json = ['Tiled Floors', 'Built-in Cupboards', 'Sea View', 'Security'];
        $property->save();
        $this->assertSame(100, $matcher->score($property, $match->fresh()));
    }

    public function test_property_page_core_matches_respects_suburb_hard_gate(): void
    {
        // Decision 1b — the property→buyer direction (matchesForProperty via
        // applyHardFilters) previously had NO suburb hard filter at all; only
        // the wishlist→property direction did. This is the actual new code.
        [$agencyId, $agent] = $this->fixture();

        $ramsgateName = 'Ramsgate ' . Str::random(4);
        $ramsgate = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => $ramsgateName, 'slug' => Str::slug($ramsgateName), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $southbroomName = 'Southbroom ' . Str::random(4);
        $southbroom = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => $southbroomName, 'slug' => Str::slug($southbroomName), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $property = Property::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $agent->id,
            'title' => '1 Ramsgate Road', 'listing_type' => 'sale', 'status' => 'active', 'category' => 'Residential',
            'property_type' => 'Apartment / Flat', 'address' => '1 Ramsgate Road', 'suburb' => 'Ramsgate',
            'p24_suburb_id' => $ramsgate, 'price' => 1_000_000, 'beds' => 2, 'baths' => 2,
        ]);

        $inArea = $this->buyer($agencyId, $agent->id, 'InArea');
        $this->match($agencyId, $inArea->id, ['created_by_user_id' => $agent->id, 'suburbs' => ['Ramsgate'], 'p24_suburb_ids' => [$ramsgate]]);

        $outOfArea = $this->buyer($agencyId, $agent->id, 'OutOfArea');
        $this->match($agencyId, $outOfArea->id, ['created_by_user_id' => $agent->id, 'suburbs' => ['Southbroom'], 'p24_suburb_ids' => [$southbroom]]);

        $matcher = app(MatchingService::class);
        $matches = $matcher->matchesForProperty($property)->pluck('contact_id')->all();

        $this->assertContains($inArea->id, $matches, 'the buyer whose wishlist is IN this suburb must appear');
        $this->assertNotContains($outOfArea->id, $matches, 'a buyer who explicitly wants a DIFFERENT suburb must never appear here');
    }

    public function test_mixed_family_multiselect_wishlist_shows_both_families(): void
    {
        // A buyer explicitly open to House OR Vacant Land — two DIFFERENT
        // families in one wishlist. The gate must let BOTH through; it only
        // excludes a family the buyer never selected at all.
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000,
            'property_types' => ['House', 'Vacant Land / Plot'],
        ]);

        $house = $this->listing($agencyId, $agent->id, ['price' => 1_020_000, 'bedrooms' => 3, 'property_type' => 'House']);
        $land  = $this->listing($agencyId, $agent->id, ['price' => 1_020_000, 'bedrooms' => null, 'property_type' => 'Vacant Land / Plot']);
        $farm  = $this->listing($agencyId, $agent->id, ['price' => 1_020_000, 'bedrooms' => null, 'property_type' => 'Farm']);
        $office = $this->listing($agencyId, $agent->id, ['price' => 1_020_000, 'bedrooms' => null, 'property_type' => 'Office']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $matchedIds = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->pluck('prospecting_listing_id')->all();
        $this->assertContains($house, $matchedIds, 'a family the buyer explicitly selected (Built) must show');
        $this->assertContains($land, $matchedIds, 'a family the buyer explicitly selected (Land) must show');
        $this->assertNotContains($farm, $matchedIds, 'a family NOT selected (Farm) must not show');
        $this->assertNotContains($office, $matchedIds, 'a family NOT selected (Commercial) must not show');
    }

    public function test_farm_wishlist_excludes_commercial_and_built(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 2_000_000, 'price_max' => 6_000_000,
            'property_types' => ['Farm'],
        ]);

        $farm      = $this->listing($agencyId, $agent->id, ['price' => 4_000_000, 'bedrooms' => null, 'property_type' => 'Farm']);
        $house     = $this->listing($agencyId, $agent->id, ['price' => 4_000_000, 'bedrooms' => 4, 'property_type' => 'House']);
        $commercial = $this->listing($agencyId, $agent->id, ['price' => 4_000_000, 'bedrooms' => null, 'property_type' => 'Commercial Property']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $matchedIds = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->pluck('prospecting_listing_id')->all();
        $this->assertContains($farm, $matchedIds, 'a Farm buyer must still see farms');
        $this->assertNotContains($house, $matchedIds, 'a Farm-only buyer must never see a house');
        $this->assertNotContains($commercial, $matchedIds, 'a Farm-only buyer must never see commercial stock');
    }

    public function test_commercial_wishlist_excludes_farm_and_built(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 1_500_000, 'price_max' => 5_000_000,
            'property_types' => ['Commercial Property', 'Industrial Property'],
        ]);

        $commercial = $this->listing($agencyId, $agent->id, ['price' => 3_000_000, 'bedrooms' => null, 'property_type' => 'Commercial Property']);
        $industrial = $this->listing($agencyId, $agent->id, ['price' => 3_000_000, 'bedrooms' => null, 'property_type' => 'Industrial Property']);
        $farm       = $this->listing($agencyId, $agent->id, ['price' => 3_000_000, 'bedrooms' => null, 'property_type' => 'Farm']);
        $apartment  = $this->listing($agencyId, $agent->id, ['price' => 3_000_000, 'bedrooms' => 2, 'property_type' => 'Apartment / Flat']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $matchedIds = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->pluck('prospecting_listing_id')->all();
        $this->assertContains($commercial, $matchedIds, 'a commercial buyer must see commercial stock');
        $this->assertContains($industrial, $matchedIds, 'a commercial buyer who also selected Industrial must see it');
        $this->assertNotContains($farm, $matchedIds, 'a commercial-only buyer must never see a farm');
        $this->assertNotContains($apartment, $matchedIds, 'a commercial-only buyer must never see a residential apartment');
    }

    public function test_unrecognised_custom_type_never_hard_excludes(): void
    {
        // Guardrail: an agency-custom property_type this session's classifier
        // doesn't recognise must NEVER silently exclude a buyer — unmapped
        // families are treated as "unknown", not "different", on both sides.
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 900_000, 'price_max' => 1_100_000,
            'property_types' => ['Houseboat'], // not in PROPERTY_TYPE_FAMILIES
        ]);

        $houseboat = $this->listing($agencyId, $agent->id, ['price' => 1_020_000, 'bedrooms' => 2, 'property_type' => 'Houseboat']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $this->assertTrue(
            DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $houseboat)->exists(),
            'an unrecognised type on BOTH sides must never be treated as a family mismatch'
        );
    }

    // ── Harness — mirrors MicCanonicalScoringTest ──────────────────────────

    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        return [$agencyId, $agent];
    }

    private function buyer(int $agencyId, int $agentId, string $first = 'Bea'): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => $first, 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999), 'email' => strtolower($first) . '-' . Str::random(5) . '@e.co.za',
        ]);
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }

    private function listing(int $agencyId, int $agentId, array $extra): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agencyId, 'captured_by_user_id' => $agentId,
            'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6),
            'address' => Str::random(6) . ' Test Road', 'suburb' => 'Uvongo',
            'price' => 800_000, 'bedrooms' => 2, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }
}
