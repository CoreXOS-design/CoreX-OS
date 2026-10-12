<?php

namespace Tests\Feature\Address;

use App\Models\AddressMatchSetting;
use App\Services\Address\AddressFacts;
use App\Services\Address\AddressMatchScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CMA pre-check ranking fix (.ai/specs/deeds-capture.md §9.12, Johan 2026-10-12) — the scorer rules it rests on:
 * partial scheme names (guarded), either unit number, a missing street number, and the "no unit" wording.
 * The CMA case: Unit/Section 6, Du Bantry [Holiday Flats], 77 Colin Street, Uvongo Beach — CoreX holds
 * "Unit 6, Du Bantry, Colin Street, Uvongo Beach" with NO street number.
 */
class AddressMatchScorerPreCheckRulesTest extends TestCase
{
    use RefreshDatabase;

    private function f(array $facts): AddressFacts
    {
        return AddressFacts::fromPayload($facts, false);
    }

    private function score(array $capture, array $record): array
    {
        return (new AddressMatchScorer())->score($this->f($capture), $this->f($record), AddressMatchSetting::DEFAULTS);
    }

    /** the CMA page for Johan's case */
    private function cma(array $over = []): array
    {
        return array_merge([
            'scheme_name' => 'DU BANTRY HOLIDAY FLATS', 'complex_name' => 'DU BANTRY HOLIDAY FLATS',
            'scheme_number' => '47/1978', 'section_number' => '6', 'unit_number' => '6',
            'street_number' => '77', 'street_name' => 'COLIN STREET', 'address' => '77 COLIN STREET', 'suburb' => 'Uvongo Beach',
        ], $over);
    }

    /** CoreX property 21729 — no street number */
    private function p21729(array $over = []): array
    {
        return array_merge([
            'complex_name' => 'Du Bantry', 'unit_number' => '6', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach',
            'address' => 'Unit 6, Du Bantry, Colin Street',
        ], $over);
    }

    // ── 1. partial scheme names ─────────────────────────────────────────

    public function test_du_bantry_is_the_same_scheme_as_du_bantry_holiday_flats_even_with_no_street_number_on_the_record(): void
    {
        $r = $this->score($this->cma(), $this->p21729());

        $this->assertSame('exact', $r['tier']);
        $this->assertSame('scheme', $r['rule']);
        $this->assertSame('agree', $r['columns']['scheme']);
        $this->assertSame('agree', $r['columns']['unit']);
        $this->assertSame('missing', $r['columns']['number']);
        $this->assertSame([], $r['veto']);
    }

    public function test_the_scheme_name_matches_whichever_side_is_the_longer_one(): void
    {
        $r = $this->score($this->cma(['scheme_name' => 'DU BANTRY', 'complex_name' => 'DU BANTRY']), $this->p21729(['complex_name' => 'Du Bantry Holiday Flats']));

        $this->assertSame('exact', $r['tier']);
    }

    public function test_a_trivially_short_or_generic_name_does_not_match_every_scheme(): void
    {
        foreach ([['Sea', 'Sea View Gardens'], ['The Villas', 'The Villas Of Margate'], ['SS 12', 'SS 12 Beach Court'], ['Villa', 'Villa Del Sol'], ['Flats', 'Ocean Flats']] as [$short, $long]) {
            $r = $this->score($this->cma(['scheme_name' => $short, 'complex_name' => $short, 'scheme_number' => null]), $this->p21729(['complex_name' => $long]));
            $this->assertNotSame('agree', $r['columns']['scheme'], "$short must not match $long");
            $this->assertNotSame('exact', $r['tier'], "$short must not match $long");
        }
    }

    public function test_only_whole_words_count_so_bantry_is_not_inside_bantryside(): void
    {
        $r = $this->score($this->cma(['scheme_name' => 'Du Bantry', 'complex_name' => 'Du Bantry']), $this->p21729(['complex_name' => 'Du Bantryside Lodge']));

        $this->assertNotSame('agree', $r['columns']['scheme']);
    }

    // ── 2. either unit number, however it is written ────────────────────

    public function test_unit_numbers_are_the_same_however_they_are_written(): void
    {
        foreach (['Unit 6', '6', '06', 'No 6', 'No. 6', 'Flat 6', 'Door 6', '#6'] as $written) {
            $r = $this->score($this->cma(['section_number' => $written, 'unit_number' => $written]), $this->p21729());
            $this->assertSame('agree', $r['columns']['unit'], "'$written' is unit 6");
            $this->assertSame('exact', $r['tier'], "'$written' is unit 6");
        }
        $this->assertSame('6a', AddressFacts::unitKey('Unit 6A'));
        $this->assertNull(AddressFacts::unitKey('-'));
    }

    public function test_the_section_number_alone_or_the_flat_number_alone_is_enough(): void
    {
        $sectionOnly = $this->score($this->cma(['unit_number' => null]), $this->p21729());
        $flatOnly = $this->score($this->cma(['section_number' => null]), $this->p21729());

        $this->assertSame('exact', $sectionOnly['tier']);
        $this->assertSame('exact', $flatOnly['tier']);
    }

    public function test_either_the_section_or_the_flat_number_may_equal_the_corex_unit(): void
    {
        // the CMA page says section 6 / flat 7 and CoreX holds unit 7: it agrees, but only on the secondary number — never exact
        $r = $this->score($this->cma(['section_number' => '6', 'unit_number' => '7']), $this->p21729(['unit_number' => '7']));

        $this->assertSame('agree', $r['columns']['unit']);
        $this->assertNotSame('exact', $r['tier'], 'a match on the secondary unit number alone is never exact');
        $this->assertSame('possible', $r['tier']);
        $this->assertStringContainsString('flat/unit number but not the section number', implode(' ', $r['reasons']));
    }

    public function test_a_unit_stored_in_the_street_field_is_still_the_unit(): void
    {
        // CoreX property 2872: unit_number empty, "1 DU BANTRY\n77 Colin Street" in the street field
        $record = ['street_name' => "1 DU BANTRY\n77 Colin Street", 'suburb' => 'Uvongo Beach', 'address' => "Unit 1, 1 DU BANTRY\n77 Colin Street"];
        $r = (new AddressMatchScorer())->score($this->f($this->cma(['section_number' => '1', 'unit_number' => '1'])), $this->f($record), AddressMatchSetting::DEFAULTS);

        $this->assertSame('agree', $r['columns']['unit']);
        $this->assertSame('exact', $r['tier']);
    }

    public function test_a_different_unit_in_the_same_scheme_never_matches(): void
    {
        $r = $this->score($this->cma(['section_number' => '7', 'unit_number' => '7']), $this->p21729());

        $this->assertSame('differ', $r['columns']['unit']);
        $this->assertContains('unit', $r['veto']);
        $this->assertNotContains($r['tier'], ['exact', 'possible']);
    }

    // ── 3. a missing street number must not exclude the record ──────────

    public function test_scheme_street_and_suburb_keep_a_record_with_no_number_and_no_unit_in_the_list(): void
    {
        $record = ['complex_name' => 'Du Bantry', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach'];
        $r = $this->score($this->cma(), $record);

        $this->assertSame('possible', $r['tier']);
        $this->assertSame('Same address, no unit number - may be a different unit', $r['reasons'][0]);
        $this->assertTrue($r['record_lacks_unit']);
    }

    public function test_unit_street_and_suburb_keep_a_record_when_the_scheme_name_was_not_read(): void
    {
        $r = $this->score($this->cma(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null]), $this->p21729(['complex_name' => null]));

        $this->assertSame('possible', $r['tier']);
        $this->assertSame('agree', $r['columns']['unit']);
        $this->assertStringContainsString('street number is not on file', $r['reasons'][0]);
    }

    public function test_a_different_street_number_is_still_a_veto(): void
    {
        foreach (['19' => '29', '29' => '19'] as $captureNo => $recordNo) {
            $r = $this->score(
                ['street_number' => $captureNo, 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'],
                ['street_number' => $recordNo, 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']
            );
            $this->assertContains('number', $r['veto']);
            // never the same property: at most "another property on this street"
            $this->assertSame('street_only', $r['tier']);
            $this->assertFalse($r['confident']);
        }
    }

    // ── the weaker "no unit" record — the exact words ───────────────────

    public function test_the_same_address_with_no_unit_on_the_record_says_exactly_why_it_is_weaker(): void
    {
        $r = $this->score($this->cma(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null]), ['street_number' => '77', 'street_name' => 'Colin Street', 'address' => '77 Colin Street', 'suburb' => 'UVONGO BEACH']);

        $this->assertSame('possible', $r['tier']);
        $this->assertSame('Same address, no unit number - may be a different unit', $r['reasons'][0]);
        $this->assertTrue($r['record_lacks_unit']);
    }
}
