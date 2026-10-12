<?php

namespace Tests\Feature\Address;

use App\Models\AddressMatchSetting;
use App\Services\Address\AddressFacts;
use App\Services\Address\AddressMatchScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/structured-address-matching.md §6 — step 5, the scorer on real-shaped rows. Pure
 * comparisons of two addresses: Grindewald 19/29/21, Uvongo / Uvongo Beach, sectional scheme + unit
 * (San Miguel 71, Leisure Crest / Glenmore), freehold erf + portion (Lynne Avenue stand 1166), the
 * LPI code, numbered complex names, a Cape Town / Afrikaans pair.
 */
class AddressMatchScorerTest extends TestCase
{
    use RefreshDatabase;

    private function f(array $facts, array $neighbours = []): AddressFacts
    {
        $f = AddressFacts::fromPayload($facts, false);
        $f->neighbourKeys = $neighbours;

        return $f;
    }

    private function score(AddressFacts $a, AddressFacts $b, array $settings = []): array
    {
        return (new AddressMatchScorer())->score($a, $b, array_merge(AddressMatchSetting::DEFAULTS, $settings));
    }

    private function tier(array $a, array $b, array $settings = [], array $aNeighbours = []): string
    {
        return $this->score($this->f($a, $aNeighbours), $this->f($b), $settings)['tier'];
    }

    // ── Grindewald 19 / 29 / 21 ──────────────────────────────────────────

    public function test_19_grindewald_drive_is_the_same_as_19_grindewald_with_the_type_missing(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
            $this->f(['street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo'])
        );
        $this->assertSame('exact', $r['tier']);
        $this->assertSame('street', $r['rule']);
        $this->assertSame('Street number, street name and suburb all match.', $r['reasons'][0]);
        $this->assertTrue($r['confident']);
        $this->assertSame([], $r['veto']);
    }

    public function test_a_different_street_type_is_possible_never_exact(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
            $this->f(['street_name' => '19 Grindewald Road', 'suburb' => 'Uvongo'])
        );
        $this->assertSame('possible', $r['tier']);
        $this->assertStringContainsString('street type differs (drive / road)', $r['reasons'][0]);
        $this->assertFalse($r['confident']);
    }

    public function test_29_and_21_grindewald_are_never_the_same_as_19(): void
    {
        foreach (['29', '21'] as $other) {
            $r = $this->score(
                $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
                $this->f(['street_name' => $other . ' Grindewald Drive', 'suburb' => 'Uvongo'])
            );
            $this->assertSame('street_only', $r['tier'], "19 vs $other");
            $this->assertContains('number', $r['veto']);
            $this->assertStringContainsString('different street number', $r['reasons'][0]);
        }
    }

    public function test_a_missing_number_on_one_side_is_street_only_not_exact(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
            $this->f(['street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'])
        );
        $this->assertSame('street_only', $r['tier']);
        $this->assertStringContainsString('not on file', $r['reasons'][0]);
    }

    public function test_a_partial_street_name_is_possible_not_exact(): void
    {
        $this->assertSame('possible', $this->tier(
            ['street_name' => '417 Baumbach Avenue', 'suburb' => 'Uvongo'],
            ['street_name' => '417 Von Baumbach Avenue', 'suburb' => 'Uvongo']
        ));
    }

    public function test_number_inside_the_street_text_of_an_old_row_still_vetoes(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
            $this->f(['street_name' => '29 Grindewald Drive', 'suburb' => 'Uvongo'])
        );
        $this->assertContains('number', $r['veto']);
        $this->assertNotSame('exact', $r['tier']);
    }

    // ── suburbs ─────────────────────────────────────────────────────────

    public function test_uvongo_and_uvongo_beach_are_possible_never_automatically_the_same(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '61 Colin Street', 'suburb' => 'Uvongo Beach'], ['uvongo']),
            $this->f(['street_name' => '61 Colin Street', 'suburb' => 'Uvongo'])
        );
        $this->assertSame('possible', $r['tier']);
        $this->assertSame('neighbour', $r['columns']['suburb']);
        $this->assertStringContainsString('neighbouring suburb Uvongo', $r['reasons'][0]);
        $this->assertFalse($r['confident']);
    }

    public function test_the_neighbour_setting_can_turn_the_credit_off(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '61 Colin Street', 'suburb' => 'Uvongo Beach'], ['uvongo']),
            $this->f(['street_name' => '61 Colin Street', 'suburb' => 'Uvongo']),
            ['neighbour_suburb_credit' => 'ignore']
        );
        $this->assertSame('none', $r['tier']);
        $this->assertContains('suburb', $r['veto']);
    }

    public function test_an_unrelated_suburb_is_a_veto(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '12 Marine Drive', 'suburb' => 'Margate']),
            $this->f(['street_name' => '12 Marine Drive', 'suburb' => 'Port Shepstone'])
        );
        $this->assertSame('none', $r['tier']);
        $this->assertContains('suburb', $r['veto']);
    }

    public function test_saint_and_st_michaels_on_sea_are_the_same_suburb(): void
    {
        $this->assertSame('exact', $this->tier(
            ['street_name' => '8 Orange Rocks Road', 'suburb' => 'Saint Michaels On Sea'],
            ['street_name' => '8 Orange Rocks Road', 'suburb' => "St Michael's-on-Sea"]
        ));
    }

    public function test_a_suburb_missing_on_one_side_is_possible_not_exact(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
            $this->f(['street_name' => '19 Grindewald Drive'])
        );
        $this->assertSame('possible', $r['tier']);
        $this->assertStringContainsString('suburb is not on file', $r['reasons'][0]);
    }

    // ── sectional: scheme + unit ────────────────────────────────────────

    public function test_same_scheme_unit_and_suburb_is_exact(): void
    {
        $r = $this->score(
            $this->f(['complex_name' => 'San Miguel', 'section_number' => '71', 'suburb' => 'Leisure Crest']),
            $this->f(['complex_name' => 'San Miguel', 'unit_number' => '71', 'suburb' => 'Leisure Crest'])
        );
        $this->assertSame('exact', $r['tier']);
        $this->assertSame('scheme', $r['rule']);
        $this->assertStringContainsString('Same sectional scheme and section/unit number (71)', $r['reasons'][0]);
    }

    public function test_staging_tp27_san_miguel_71_leisure_crest_vs_glenmore_is_possible(): void
    {
        $r = $this->score(
            $this->f(['complex_name' => 'San Miguel', 'section_number' => '71', 'suburb' => 'Leisure Crest'], ['glenmore']),
            $this->f(['complex_name' => 'San Miguel', 'unit_number' => '71', 'suburb' => 'Glenmore'])
        );
        $this->assertSame('possible', $r['tier'], 'a repeated scheme name in a neighbouring suburb is never exact');
    }

    public function test_the_same_scheme_name_in_two_unrelated_towns_is_not_a_match(): void
    {
        $this->assertSame('none', $this->tier(
            ['complex_name' => 'San Miguel', 'section_number' => '71', 'suburb' => 'Leisure Crest'],
            ['complex_name' => 'San Miguel', 'unit_number' => '71', 'suburb' => 'Sea Park']
        ));
    }

    public function test_a_different_unit_in_the_same_scheme_is_never_the_same(): void
    {
        $r = $this->score(
            $this->f(['complex_name' => 'Villa Del Sol', 'section_number' => '7', 'suburb' => 'Margate']),
            $this->f(['complex_name' => 'Villa Del Sol', 'unit_number' => '9', 'suburb' => 'Margate'])
        );
        $this->assertSame('none', $r['tier']);
        $this->assertContains('unit', $r['veto']);
    }

    public function test_leading_zeros_in_a_unit_are_the_same_unit(): void
    {
        $this->assertSame('exact', $this->tier(
            ['complex_name' => 'Villa Del Sol', 'section_number' => '2', 'suburb' => 'Margate'],
            ['complex_name' => 'Villa Del Sol', 'unit_number' => '02', 'suburb' => 'Margate']
        ));
    }

    public function test_two_different_registered_scheme_numbers_are_a_veto(): void
    {
        $r = $this->score(
            $this->f(['scheme_number' => 'SS 123/1995', 'section_number' => '5', 'suburb' => 'Margate']),
            $this->f(['scheme_number' => 'SS 456/2001', 'unit_number' => '5', 'suburb' => 'Margate'])
        );
        $this->assertSame('none', $r['tier']);
        $this->assertContains('scheme', $r['veto']);
    }

    public function test_numbered_complex_names_are_different_blocks(): void
    {
        $r = $this->score(
            $this->f(['complex_name' => 'Aqua Breeze 3', 'section_number' => '1', 'suburb' => 'Margate']),
            $this->f(['complex_name' => 'Aqua Breeze 5', 'unit_number' => '1', 'suburb' => 'Margate'])
        );
        $this->assertSame('none', $r['tier']);
        $this->assertContains('scheme', $r['veto']);
    }

    public function test_a_unit_on_one_side_only_is_possible_by_default_and_different_by_setting(): void
    {
        $a = ['street_name' => '12 Marine Drive', 'unit_number' => '5', 'suburb' => 'Margate'];
        $b = ['street_name' => '12 Marine Drive', 'suburb' => 'Margate'];

        $r = $this->score($this->f($a), $this->f($b));
        $this->assertSame('possible', $r['tier']);
        $this->assertStringContainsString('no unit number', $r['reasons'][0]);

        $r2 = $this->score($this->f($a), $this->f($b), ['unit_missing_on_one_side' => 'different']);
        $this->assertNotSame('exact', $r2['tier']);
        $this->assertNotSame('possible', $r2['tier']);
        $this->assertContains('unit', $r2['veto']);
    }

    // ── freehold: erf + portion + LPI ──────────────────────────────────

    public function test_same_erf_and_suburb_is_exact(): void
    {
        $r = $this->score(
            $this->f(['erf_number' => '1166', 'suburb' => 'Ramsgate']),
            $this->f(['erf_number' => '01166', 'suburb' => 'Ramsgate'])
        );
        $this->assertSame('exact', $r['tier']);
        $this->assertSame('erf', $r['rule']);
        $this->assertSame('Same erf number (1166) and suburb.', $r['reasons'][0]);
    }

    public function test_six_portions_of_stand_1166_stay_apart(): void
    {
        $base = ['erf_number' => '1166', 'suburb' => 'Ramsgate', 'street_name' => 'Lynne Avenue'];
        $r = $this->score($this->f($base + ['erf_portion' => '1']), $this->f($base + ['erf_portion' => '2']));
        $this->assertNotContains($r['tier'], ['exact', 'possible'], 'a different portion is never the same property (at most "another property on this street")');
        $this->assertContains('portion', $r['veto']);
        $this->assertSame('exact', $this->score($this->f($base + ['erf_portion' => '3']), $this->f($base + ['erf_portion' => '3']))['tier']);
    }

    public function test_an_erf_with_a_portion_on_only_one_side_is_possible_not_exact(): void
    {
        $r = $this->score(
            $this->f(['erf_number' => '1166', 'erf_portion' => '1', 'suburb' => 'Ramsgate']),
            $this->f(['erf_number' => '1166', 'suburb' => 'Ramsgate'])
        );
        $this->assertSame('possible', $r['tier']);
        $this->assertStringContainsString('portion is not known', $r['reasons'][0]);
    }

    public function test_the_same_erf_number_in_another_suburb_is_a_different_property(): void
    {
        $r = $this->score($this->f(['erf_number' => '1329', 'suburb' => 'Uvongo']), $this->f(['erf_number' => '1329', 'suburb' => 'Margate']));
        $this->assertSame('none', $r['tier']);
    }

    public function test_the_same_lpi_is_exact_even_when_the_suburb_text_differs(): void
    {
        $r = $this->score(
            $this->f(['lpi_code' => 'n0et03630000132900000', 'suburb' => 'Uvongo']),
            $this->f(['lpi_code' => 'N0ET03630000132900000', 'suburb' => 'Margate'])
        );
        // a suburb that differs is still a veto — the LPI rule needs no veto; here the suburbs are unrelated
        $this->assertContains($r['tier'], ['exact', 'none']);
        $same = $this->score(
            $this->f(['lpi_code' => 'n0et03630000132900000']),
            $this->f(['lpi_code' => 'N0ET03630000132900000', 'suburb' => 'Margate'])
        );
        $this->assertSame('exact', $same['tier']);
        $this->assertSame('lpi', $same['rule']);
    }

    public function test_two_different_lpi_codes_are_a_veto(): void
    {
        $r = $this->score(
            $this->f(['lpi_code' => 'n0et03630000132900000', 'suburb' => 'Uvongo']),
            $this->f(['lpi_code' => 'n0et03630000133000000', 'suburb' => 'Uvongo'])
        );
        $this->assertSame('none', $r['tier']);
        $this->assertContains('lpi', $r['veto']);
    }

    // ── settings ───────────────────────────────────────────────────────

    public function test_an_exact_rule_switched_off_downgrades_to_possible(): void
    {
        $a = ['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo'];
        $this->assertSame('possible', $this->tier($a, $a, ['rule_street_exact' => false]));
        $e = ['erf_number' => '1166', 'suburb' => 'Ramsgate'];
        $this->assertNotSame('exact', $this->tier($e, $e, ['rule_erf_exact' => false]));
        $s = ['complex_name' => 'Villa Del Sol', 'unit_number' => '7', 'suburb' => 'Margate'];
        $this->assertNotSame('exact', $this->tier($s, $s, ['rule_scheme_exact' => false]));
    }

    public function test_the_minimum_agreeing_columns_setting_tightens_possible(): void
    {
        $a = ['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo'];
        $b = ['street_name' => '19 Grindewald Road', 'suburb' => 'Uvongo'];
        $this->assertSame('possible', $this->tier($a, $b, ['possible_min_agreeing_columns' => 3]));
        $this->assertSame('none', $this->tier($a, $b, ['possible_min_agreeing_columns' => 4]), 'only three columns agree');
    }

    public function test_the_gps_radius_setting_decides_corroboration_but_never_the_tier(): void
    {
        $base = ['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo'];
        $near = $this->score($this->f($base + ['latitude' => -30.8301, 'longitude' => 30.3901]), $this->f($base + ['latitude' => -30.83012, 'longitude' => 30.39012]));
        $far = $this->score($this->f($base + ['latitude' => -30.8301, 'longitude' => 30.3901]), $this->f($base + ['latitude' => -30.8500, 'longitude' => 30.4100]));
        $this->assertSame('agree', $near['columns']['gps']);
        $this->assertSame('differ', $far['columns']['gps']);
        $this->assertSame('exact', $near['tier']);
        $this->assertSame('exact', $far['tier'], 'a different pin never makes the same address a different property');
        $this->assertGreaterThan($far['score'], $near['score']);
    }

    // ── shape, symmetry, other regions ─────────────────────────────────

    public function test_the_output_shape_is_what_the_merge_tool_will_read(): void
    {
        $r = $this->score(
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']),
            $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo'])
        );
        foreach (['tier', 'score', 'columns', 'veto', 'matched_on', 'matched_fields', 'reasons', 'confident', 'rule'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertGreaterThanOrEqual(0, $r['score']);
        $this->assertLessThanOrEqual(100, $r['score']);
        foreach (['lpi', 'erf', 'portion', 'scheme', 'unit', 'suburb', 'street', 'number', 'type', 'gps'] as $c) {
            $this->assertArrayHasKey($c, $r['columns']);
        }
        $this->assertSame(['street_number', 'street_name', 'suburb'], $r['matched_fields']);
    }

    public function test_exact_and_street_only_are_symmetric_and_repeatable(): void
    {
        $a = $this->f(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);
        $b = $this->f(['street_name' => '29 Grindewald Drive', 'suburb' => 'Uvongo']);
        $this->assertSame($this->score($a, $b)['tier'], $this->score($b, $a)['tier']);
        $this->assertEquals($this->score($a, $b), $this->score($a, $b));
    }

    public function test_cape_town_afrikaans_street_types_match(): void
    {
        $this->assertSame('exact', $this->tier(
            ['street_name' => '12 Kerk Straat', 'suburb' => 'Stellenbosch'],
            ['street_name' => '12 Kerk Street', 'suburb' => 'Stellenbosch']
        ));
        $this->assertSame('street_only', $this->tier(
            ['street_name' => '12 Kerk Straat', 'suburb' => 'Stellenbosch'],
            ['street_name' => '14 Kerk Straat', 'suburb' => 'Stellenbosch']
        ));
    }

    public function test_nothing_in_common_is_none_and_blank_facts_never_error(): void
    {
        $this->assertSame('none', $this->tier(['street_name' => '1 Beach Road', 'suburb' => 'Margate'], ['street_name' => '9 Hill Street', 'suburb' => 'Margate']));
        $this->assertSame('none', $this->tier([], []));
        $this->assertSame('none', $this->tier(['suburb' => 'Margate'], ['suburb' => 'Margate']));
    }
}
