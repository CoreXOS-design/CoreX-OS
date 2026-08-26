<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\User;
use App\Services\Prospecting\PropertyDuplicateMatchEvidence;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Matcher-accuracy baseline (2026-08-22, Johan's priority build: "double stock
 * — massive problem. or no stock — massive problem.").
 *
 * Every fixture's field values were copied verbatim from REAL rows found on
 * this environment (QA1, `corex_qa1`) via a direct database search on
 * 2026-08-22 — not invented. Synthetic data hides real normalisation bugs
 * (yesterday's investigation found several this way); using the real strings
 * is what makes this suite worth anything.
 *
 * Run before AND after the matcher-accuracy changes to measure improvement,
 * not assume it — see the build report for the before/after pass counts.
 *
 * Cases and their real source:
 *   - "10 Abelia Crescent" (TP#695) / "10 Abelia Cresent" (TP#839), Sea Park —
 *     a missing-letter typo that defeats every exact-match strategy.
 *   - "6 Groove Road" (TP#682) / "6 Grove Road" (TP#870), Banners Rest — same
 *     typo class, second independent real example.
 *   - Marine Drive, Margate — 13 distinct real street numbers on one street/
 *     suburb pair; must never collapse into each other.
 *   - Winston Court North, unit 5, 76 Marine Drive, St Michaels On Sea —
 *     TWO real, distinct Property rows (P#1292, P#1442) share this identical
 *     structured identity (114 such genuine collision groups exist in this
 *     environment's real data) — the Villa Del Sol / Lynne Avenue class of
 *     case: a bare identity key that legitimately matches more than one
 *     physical unit.
 *   - "Three Hills" / "Leisure Bay" — Johan's own named example of the
 *     township-vs-marketing-suburb fault; both names are real, populated
 *     suburb strings in this environment (22 and 577 rows respectively).
 *   - A real property (id 1290, "614 Piet Uys Road, Palm Beach") and its real
 *     GPS coordinates — the property-15698 class of gap: nothing structural
 *     matches, but a close physical neighbour exists and must now be found
 *     (never auto-linked, but never invisible either).
 */
final class PropertyMatcherAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private TrackedPropertyMatchOrCreateService $matcher;
    private PropertyDuplicateMatchEvidence $evidence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->matcher = new TrackedPropertyMatchOrCreateService();
        $this->evidence = new PropertyDuplicateMatchEvidence();
    }

    private function property(array $attrs): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->agent->id,
            'address' => 'Test', 'property_type' => 'house',
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1_000_000, 'title' => 'Test',
            'listing_type' => 'sale',
        ], $attrs));
    }

    // ──────────────────────── NORMALISATION — real typo/abbreviation evidence ───

    /** @test */
    public function abelia_crescent_typo_is_recognised_as_close_not_identical(): void
    {
        // Real: TP#695 "10 Abelia Crescent" Sea Park vs TP#839 "10 Abelia Cresent" Sea Park.
        $this->assertTrue(TrackedPropertyAddress::streetNamesAreCloseTypo('Abelia Crescent', 'Abelia Cresent'));
        $this->assertNotEquals(
            TrackedPropertyAddress::normaliseStreet('Abelia Crescent'),
            TrackedPropertyAddress::normaliseStreet('Abelia Cresent'),
            'a genuine one-letter typo is NOT silently treated as byte-identical — it is a POSSIBLE-tier signal, never CONFIDENT'
        );
    }

    /** @test */
    public function groove_road_grove_road_typo_is_recognised_as_close(): void
    {
        // Real: TP#682 "6 Groove Road" Banners Rest vs TP#870 "6 Grove Road" Banners Rest.
        $this->assertTrue(TrackedPropertyAddress::streetNamesAreCloseTypo('Groove Road', 'Grove Road'));
    }

    /** @test */
    public function genuinely_different_streets_are_never_flagged_as_a_typo(): void
    {
        $this->assertFalse(TrackedPropertyAddress::streetNamesAreCloseTypo('Marine Drive', 'Piet Uys Road'));
    }

    /** @test */
    public function crescent_and_cres_abbreviation_are_recognised(): void
    {
        $this->assertSame(
            TrackedPropertyAddress::normaliseStreet('Riviera Crescent'),
            TrackedPropertyAddress::normaliseStreet('Riviera Cres')
        );
    }

    /** @test */
    public function st_michaels_manor_is_not_wrongly_expanded_to_street(): void
    {
        // Real bug, live production data: tracked_properties #38106-38112 (2026-08-21
        // investigation) — "St Michaels Manor" (Saint, a place-name prefix) was blindly
        // expanded to "Street Michaels Manor".
        $this->assertSame('St Michaels Manor', TrackedPropertyAddress::normaliseStreet('St Michaels Manor'));
        // The genuine suffix case must still work.
        $this->assertSame('Mitchell Street', TrackedPropertyAddress::normaliseStreet('Mitchell St'));
    }

    /** @test */
    public function ordinals_are_normalised_consistently(): void
    {
        $this->assertSame(
            TrackedPropertyAddress::normaliseStreet('2nd Avenue'),
            TrackedPropertyAddress::normaliseStreet('Second Avenue')
        );
    }

    /** @test */
    public function apostrophe_suburb_names_normalise_the_same(): void
    {
        $this->assertSame(
            TrackedPropertyAddress::normaliseSuburb("Shaka's Rock"),
            TrackedPropertyAddress::normaliseSuburb('Shakas Rock')
        );
    }

    /** @test */
    public function three_hills_and_leisure_bay_resolve_to_the_same_canonical_suburb(): void
    {
        // Johan's own named example. Both are real, populated suburb strings in this
        // environment (22 and 577 rows respectively, confirmed 2026-08-22).
        $this->assertSame(
            TrackedPropertyAddress::normaliseSuburb('Three Hills'),
            TrackedPropertyAddress::normaliseSuburb('Leisure Bay')
        );
    }

    // ──────────────────────── FALSE-POSITIVE VETOES — must stay separate ────────

    /** @test */
    public function marine_drive_margate_different_numbers_never_collapse(): void
    {
        // Real: Marine Drive, Margate carries 13 distinct real street numbers on this
        // environment. Two of them, created as real TrackedProperty rows via the
        // actual matcher, must resolve to two DIFFERENT records.
        $a = $this->matcher->matchOrCreate($this->agencyId,
            ['street_number' => '217', 'street_name' => 'Marine Drive', 'suburb' => 'Margate'],
            ['type' => 'test', 'ref' => 'a']);
        $b = $this->matcher->matchOrCreate($this->agencyId,
            ['street_number' => '8318', 'street_name' => 'Marine Drive', 'suburb' => 'Margate'],
            ['type' => 'test', 'ref' => 'b']);

        $this->assertNotEquals($a->id, $b->id, 'different street numbers on the same street must never merge into one property');
    }

    /** @test */
    public function winston_court_north_ambiguous_sectional_match_is_never_silently_picked(): void
    {
        // Real: P#1292 and P#1442 — TWO genuinely distinct Property rows, both
        // "Unit 5, Winston Court North, 76 Marine Drive, St Michaels On Sea" (one of
        // 114 real such collision groups found in this environment, 2026-08-22).
        // A bare complex+unit key cannot tell them apart — the matcher must refuse to
        // auto-pick either, not silently merge onto whichever sorts first.
        $unitA = $this->property([
            'complex_name' => 'Winston Court North', 'unit_number' => '5',
            'street_number' => '76', 'street_name' => 'Marine Drive', 'street_name_normalised' => 'Marine Drive',
            'suburb' => 'St Michaels On Sea', 'suburb_normalised' => 'st michaels on sea',
        ]);
        $unitB = $this->property([
            'complex_name' => 'Winston Court North', 'unit_number' => '5',
            'street_number' => '76', 'street_name' => 'Marine Drive', 'street_name_normalised' => 'Marine Drive',
            'suburb' => 'St Michaels On Sea', 'suburb_normalised' => 'st michaels on sea',
        ]);

        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId,
            'complex_name' => 'Winston Court North', 'section_number' => '5',
            'street_number' => '76', 'street_name' => 'Marine Drive', 'suburb' => 'St Michaels On Sea',
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'deeds_captured_by_user_id' => $this->agent->id,
            'source_chain' => [['type' => 'deeds_capture', 'ref' => 'test:' . Str::random(8), 'date' => now()->toIso8601String()]],
        ]);

        $preview = $this->matcher->previewPropertyMatch($tp);
        $this->assertNull($preview, 'an ambiguous match (two real candidates, identical bare key) must NEVER be silently auto-picked');

        $candidates = $this->evidence->candidates($tp, $this->evidence->strategyFor($tp), $this->agencyId);
        $this->assertCount(2, $candidates, 'both real candidates must be surfaced, not silently narrowed to one');
        $this->assertEqualsCanonicalizing([$unitA->id, $unitB->id], $candidates->pluck('id')->all());
        $this->assertSame('possible', $this->evidence->verdict($tp, $this->agencyId));
    }

    /** @test */
    public function a_single_unambiguous_sectional_match_is_still_confidently_linked(): void
    {
        // Regression guard: the ambiguity fix above must not make EVERY sectional
        // match ambiguous — a genuinely unique unit still resolves confidently
        // (this is the property-15698 leading-zero fix's own scenario, cc6's work,
        // must not regress).
        $unit = $this->property([
            'complex_name' => 'Munro Gardens', 'unit_number' => '02',
            'street_number' => null, 'street_name' => 'Munro Avenue', 'suburb' => 'Margate',
        ]);
        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId,
            'complex_name' => 'Munro Gardens', 'section_number' => '2', // no leading zero — must still match '02'
            'suburb' => 'Margate',
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'deeds_captured_by_user_id' => $this->agent->id,
            'source_chain' => [['type' => 'deeds_capture', 'ref' => 'test:' . Str::random(8), 'date' => now()->toIso8601String()]],
        ]);

        $preview = $this->matcher->previewPropertyMatch($tp);
        $this->assertNotNull($preview, 'a single, unambiguous candidate must still resolve confidently — leading-zero fix must not regress');
        $this->assertSame($unit->id, $preview->id);
        $this->assertSame('confident', $this->evidence->verdict($tp, $this->agencyId));
    }

    // ──────────────────────── GPS GAP — the property-15698 class of case ────────

    /** @test */
    public function a_close_gps_neighbour_is_found_but_never_auto_linked(): void
    {
        // Real property id 1290 (as of 2026-08-22): "614 Piet Uys Road, Palm Beach",
        // lat -30.9775312, lng 30.2719175. A TrackedProperty ~10m away with NO
        // structural identity match (no erf, no complex, no matching street) —
        // exactly property 15698's own shape (nearest real candidate 24.6m away,
        // resolvePropertyMatch() never checked distance at all before this fix).
        $existing = $this->property([
            'street_number' => '614', 'street_name' => 'Piet Uys Road', 'suburb' => 'Palm Beach',
            'latitude' => -30.9775312, 'longitude' => 30.2719175,
        ]);

        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId,
            'street_number' => '999', 'street_name' => 'Unrelated Close', 'suburb' => 'Palm Beach',
            'latitude' => -30.97754, 'longitude' => 30.27193, // ~2m away
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'deeds_captured_by_user_id' => $this->agent->id,
            'source_chain' => [['type' => 'deeds_capture', 'ref' => 'test:' . Str::random(8), 'date' => now()->toIso8601String()]],
        ]);

        $preview = $this->matcher->previewPropertyMatch($tp);
        $this->assertNull($preview, 'GPS proximity alone must NEVER auto-link — it is a corroborating signal, not an identity one');

        $gpsCandidates = $this->evidence->candidates($tp, 'gps_proximity', $this->agencyId);
        $this->assertCount(1, $gpsCandidates, 'the close neighbour must now be FOUND — this is exactly the gap that let property 15698 through invisibly');
        $this->assertSame($existing->id, $gpsCandidates->first()->id);
    }

    /** @test */
    public function no_gps_and_no_structural_signal_means_genuinely_no_match(): void
    {
        // Regression guard: the new GPS strategy must not manufacture false
        // candidates when nothing is actually close.
        $this->property([
            'street_number' => '1', 'street_name' => 'Far Away Road', 'suburb' => 'Somewhere Else',
            'latitude' => -29.0, 'longitude' => 31.0,
        ]);

        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId,
            'street_number' => '614', 'street_name' => 'Piet Uys Road', 'suburb' => 'Palm Beach',
            'latitude' => -30.9775312, 'longitude' => 30.2719175,
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'deeds_captured_by_user_id' => $this->agent->id,
            'source_chain' => [['type' => 'deeds_capture', 'ref' => 'test:' . Str::random(8), 'date' => now()->toIso8601String()]],
        ]);

        $this->assertNull($this->matcher->previewPropertyMatch($tp));
        $this->assertSame('none', $this->evidence->verdict($tp, $this->agencyId));
    }
}
