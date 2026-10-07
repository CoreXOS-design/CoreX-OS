<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\User;
use App\Services\Prospecting\PropertyDuplicateMatchEvidence;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matcher fixes of 2026-10-07 (.ai/specs/deeds-capture.md §9.11), the "not fixed" list
 * of the 3.8.1 pre-check change:
 *   a. promoting a capture to stock links to the existing property even when the street
 *      TYPE (or the number's place in the text) differs — "19 Grindewald Drive" → "19 Grindewald";
 *   b. suburb spelling twins ("Saint Michaels On Sea" / "St Michaels On Sea") are one suburb;
 *      Property24's neighbouring suburbs (Uvongo / Uvongo Beach) are only ever "possible";
 *   c. tracked property 373 — four different streets merged into one record because the
 *      pollution words of old CMA report rows ("Cadastral Extent … M²") counted as an address match.
 */
final class MatchingPromoteLinkAndMergeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private TrackedPropertyMatchOrCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
        $this->service = new TrackedPropertyMatchOrCreateService();
    }

    private function property(string $number, string $street, string $suburb, array $over = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agency->id,
            'branch_id'     => $this->branch->id,
            'agent_id'      => $this->user->id,
            'street_number' => $number,
            'street_name'   => $street,
            'address'       => trim($number . ' ' . $street),
            'suburb'        => $suburb,
            'property_type' => 'house',
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000,
            'title' => 'Test', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    private function capture(array $facts, string $ref): TrackedProperty
    {
        return $this->service->matchOrCreate($this->agency->id, $facts, ['type' => 'deeds_capture', 'ref' => $ref]);
    }

    private function stockCount(): int
    {
        return Property::queryWithoutAgencyScope()->where('agency_id', $this->agency->id)->count();
    }

    // ── a. promote links across street type / number-in-text ────────────────

    public function test_promote_links_a_capture_with_street_type_to_the_property_without_one(): void
    {
        $existing = $this->property('19', 'Grindewald', 'Uvongo');
        $tp = $this->capture(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], 'cmainfo:a1');

        $linked = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame($existing->id, $linked->id, 'must link to the property CoreX already holds');
        $this->assertSame(1, $this->stockCount(), 'no second property may be created');
    }

    public function test_promote_links_when_the_capture_has_the_number_inside_the_street_text(): void
    {
        $existing = $this->property('19', 'Grindewald Drive', 'Uvongo');
        $tp = TrackedProperty::create([
            'agency_id' => $this->agency->id, 'capture_kind' => 'deeds_capture',
            'street_number' => null, 'street_name' => '19 Grindewald Drive', 'suburb' => 'UVONGO', 'source_chain' => [],
        ]);

        $linked = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame($existing->id, $linked->id);
        $this->assertSame(1, $this->stockCount());
    }

    public function test_promote_never_links_a_different_street_number(): void
    {
        $this->property('29', 'Grindewald Drive', 'Uvongo');
        $tp = $this->capture(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], 'cmainfo:a3');

        $created = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame(2, $this->stockCount(), '19 is a new property, not 29');
        $this->assertNotSame('29', (string) $created->street_number);
    }

    public function test_promote_never_links_a_different_street_type_on_both_sides_or_a_different_unit(): void
    {
        $this->property('19', 'Grindewald Road', 'Uvongo');
        $tp = $this->capture(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], 'cmainfo:a4');

        $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame(2, $this->stockCount(), 'Road vs Drive is only a possible match — Promote must not auto-link it');
    }

    public function test_deeds_screen_evidence_lists_the_same_candidate_promote_will_link(): void
    {
        $existing = $this->property('19', 'Grindewald', 'Uvongo');
        $tp = $this->capture(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], 'cmainfo:a5');

        $evidence = app(PropertyDuplicateMatchEvidence::class);
        $list = $evidence->candidates($tp, $evidence->strategyFor($tp), $this->agency->id);

        $this->assertSame([$existing->id], $list->pluck('id')->all());
        $this->assertSame('confident', $evidence->verdict($tp, $this->agency->id));
    }

    // ── b. suburb spelling and neighbouring suburbs ─────────────────────────

    public function test_saint_and_st_are_the_same_suburb(): void
    {
        $this->assertContains('st michaels on sea', TrackedPropertyAddress::suburbSpellingKeys('Saint Michaels On Sea'));
        $this->assertContains('saint michaels on sea', TrackedPropertyAddress::suburbSpellingKeys('ST MICHAEL\'S ON SEA'));
        $this->assertNotContains('uvongo beach', TrackedPropertyAddress::suburbSpellingKeys('Uvongo'), 'neighbours are not spelling twins');
    }

    public function test_promote_links_across_the_saint_st_spelling(): void
    {
        $existing = $this->property('12', 'Marine Drive', 'St Michaels On Sea');
        $tp = $this->capture(['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Saint Michaels On Sea'], 'cmainfo:b2');

        $linked = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame($existing->id, $linked->id);
        $this->assertSame(1, $this->stockCount());
    }

    private function seedUvongoNeighbours(): void
    {
        P24Suburb::create(['name' => 'Uvongo', 'slug' => 'uvongo', 'p24_id' => 6359, 'surrounding_ids' => [33106, 6361], 'confirmed' => true]);
        P24Suburb::create(['name' => 'Uvongo Beach', 'slug' => 'uvongo-beach', 'p24_id' => 33106, 'surrounding_ids' => [6359], 'confirmed' => true]);
    }

    public function test_neighbouring_suburb_is_a_possible_match_in_the_precheck_never_a_duplicate(): void
    {
        $this->seedUvongoNeighbours();
        $this->property('19', 'Grindewald Drive', 'Uvongo Beach');
        Sanctum::actingAs($this->user);

        $json = $this->postJson(route('v1.deeds-capture.check-duplicate'), ['property' => [
            'address' => '19 Grindewald Drive', 'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo',
        ]])->assertOk()->json();

        $this->assertSame('possible_match', $json['status']);
        $this->assertFalse($json['matches'][0]['confident']);
        $this->assertStringContainsString('neighbouring suburb', $json['matches'][0]['reason']);
    }

    public function test_neighbouring_suburb_is_not_auto_linked_on_promote(): void
    {
        $this->seedUvongoNeighbours();
        $this->property('19', 'Grindewald Drive', 'Uvongo Beach');
        $tp = $this->capture(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], 'cmainfo:b4');

        $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame(2, $this->stockCount(), 'a neighbouring suburb is left for a human, never silently merged');
    }

    // ── c. tracked property 373 — four streets merged into one ──────────────

    private function reportRow(string $line, int $reportId): TrackedProperty
    {
        // the exact shape the CMA report parsers store: whole line in street_name, no number column
        return $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => $line . str_repeat(' ', 30) . 'Cadastral Extent     1 225 M²', 'suburb' => 'UVONGO'],
            ['type' => 'cmainfo', 'ref' => 'report:' . $reportId],
        );
    }

    public function test_report_rows_with_the_same_pollution_words_stay_separate_properties(): void
    {
        $a = $this->reportRow('1 Como Drive', 35);
        $b = $this->reportRow('4 Garden Place', 42);
        $c = $this->reportRow('15 Meriel Road', 54);
        $d = $this->reportRow('36 Grindewald Drive', 157);

        $this->assertCount(4, array_unique([$a->id, $b->id, $c->id, $d->id]));
    }

    public function test_same_number_different_street_report_rows_stay_separate(): void
    {
        // number "1" on both sides — no number veto can save this; only the street can
        $a = $this->reportRow('1 Como Drive', 35);
        $b = $this->reportRow('1 Garden Place', 36);

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_the_same_report_row_seen_again_still_matches_itself(): void
    {
        $a = $this->reportRow('4 Garden Place', 42);
        $again = $this->reportRow('4 Garden Place', 99);

        $this->assertSame($a->id, $again->id, 'the guard must not break a genuine re-sighting');
    }

    public function test_a_stored_legacy_row_with_the_spaced_pollution_is_not_swallowed_by_another_street(): void
    {
        // tracked property 373's real stored text — the double-space separators survive in old rows
        $legacy = TrackedProperty::create([
            'agency_id' => $this->agency->id, 'capture_kind' => 'cmainfo', 'source_chain' => [],
            'street_number' => null, 'suburb' => 'UVONGO',
            'street_name' => '1 Como Drive                                   Cadastral Extent     1 225 M²',
        ]);

        $incoming = $this->reportRow('1 Garden Place', 42);

        $this->assertNotSame($legacy->id, $incoming->id);
    }
}
