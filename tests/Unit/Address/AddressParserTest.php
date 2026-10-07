<?php

namespace Tests\Unit\Address;

use App\Services\Address\AddressParser;
use App\Services\Address\LpiCode;
use App\Support\Address\StreetTypes;
use PHPUnit\Framework\TestCase;

/**
 * .ai/specs/structured-address-matching.md §4 — the one address reader. Real-shaped rows from
 * QA1 / Staging (Grindewald 19/29/21, the 373 pollution line, Villa-Del-Mei, 61 Colin Street,
 * P24's "42 Springwood, Umhlali Golf Estate") plus a Cape Town / Afrikaans set. Pure — no database.
 */
class AddressParserTest extends TestCase
{
    private function p(array $in): array
    {
        return (new AddressParser())->parse($in);
    }

    private function line(array $r): string
    {
        return trim(($r['street_number'] ?? '') . ' | ' . ($r['street_core'] ?? '') . ' | ' . ($r['street_type'] ?? ''));
    }

    // ── number + street ─────────────────────────────────────────────────

    public function test_number_in_the_street_text_is_lifted_out(): void
    {
        $r = $this->p(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);
        $this->assertSame('19 | grindewald | drive', $this->line($r));
        $this->assertSame('Grindewald Drive', $r['street_name_clean']);
        $this->assertSame('parsed', $r['status']);
    }

    public function test_number_in_its_own_column_and_name_without_number(): void
    {
        $r = $this->p(['street_number' => '29', 'street_name' => 'Grindewald Drive']);
        $this->assertSame('29 | grindewald | drive', $this->line($r));
    }

    public function test_the_same_number_in_both_places_is_not_a_conflict(): void
    {
        $r = $this->p(['street_number' => '19', 'street_name' => '19 Grindewald Drive']);
        $this->assertSame('19 | grindewald | drive', $this->line($r));
        $this->assertSame([], $r['conflicts']);
    }

    public function test_street_type_missing_on_one_side_gives_the_same_core(): void
    {
        $a = $this->p(['street_name' => '19 Grindewald Drive']);
        $b = $this->p(['street_number' => '19', 'street_name' => 'Grindewald']);
        $this->assertSame($a['street_core'], $b['street_core']);
        $this->assertSame('drive', $a['street_type']);
        $this->assertNull($b['street_type']);
    }

    public function test_grindewald_19_29_21_are_three_numbers_on_one_street(): void
    {
        $rows = array_map(fn ($n) => $this->p(['street_name' => $n . ' Grindewald Drive']), [19, 29, 21]);
        $this->assertSame(['19', '29', '21'], array_column($rows, 'street_number'));
        $this->assertSame(['grindewald'], array_values(array_unique(array_column($rows, 'street_core'))));
    }

    public function test_abbreviations_and_case(): void
    {
        $this->assertSame('mitchell | street', $this->core($this->p(['street_name' => 'MITCHELL ST'])));
        $this->assertSame('von baumbach | avenue', $this->core($this->p(['street_name' => 'Von Baumbach Ave'])));
        $this->assertSame('beach | road', $this->core($this->p(['street_name' => '123 beach rd.'])));
        $this->assertSame('riviera | crescent', $this->core($this->p(['street_name' => '45 Riviera Cres'])));
    }

    private function core(array $r): string
    {
        return ($r['street_core'] ?? '') . ' | ' . ($r['street_type'] ?? '');
    }

    public function test_st_at_the_start_is_saint_not_a_street_type(): void
    {
        $r = $this->p(['street_name' => 'St Michaels Manor']);
        $this->assertSame('st michaels manor', $r['street_core']);
        $this->assertNull($r['street_type']);
        $this->assertSame('st andrews', $this->p(['street_name' => 'Saint Andrews Drive'])['street_core']);
        $this->assertSame('st andrews', $this->p(['street_name' => 'St Andrews Drive'])['street_core']);
    }

    public function test_apostrophes_and_ordinals(): void
    {
        $this->assertSame('johns', $this->p(['street_name' => "John's Road"])['street_core']);
        $this->assertSame('1st', $this->p(['street_name' => 'First Avenue'])['street_core']);
        $this->assertSame('2nd', $this->p(['street_name' => '2nd Avenue'])['street_core']);
    }

    public function test_house_number_shapes(): void
    {
        $this->assertSame('12A', $this->p(['street_name' => '12A Marine Drive'])['street_number']);
        $this->assertSame('1/3', $this->p(['street_name' => '1/3 Main Road'])['street_number']);
        $this->assertSame('12-14', $this->p(['street_name' => '12 - 14 Main Road'])['street_number']);
    }

    public function test_no_number_means_no_number_never_a_guess(): void
    {
        $r = $this->p(['street_name' => 'Marine Drive']);
        $this->assertNull($r['street_number']);
        $this->assertSame('marine', $r['street_core']);
        $this->assertSame('parsed', $r['status'], 'a street with no number is real (vacant land, estates)');
    }

    // ── pollution ───────────────────────────────────────────────────────

    public function test_the_373_report_line_is_cleaned(): void
    {
        $r = $this->p(['street_name' => "4 Garden Place   Cadastral Extent  1 605 M²", 'suburb' => 'Uvongo']);
        $this->assertSame('4 | garden | place', $this->line($r));
    }

    public function test_extent_pollution_on_the_same_line_and_on_the_next(): void
    {
        $this->assertSame('1 | como | drive', $this->line($this->p(['street_name' => '1 Como Drive Cadastral Extent 1 225 M'])));
        $this->assertSame('29 | grindewald | drive', $this->line($this->p(['street_name' => "29 Grindewald Drive\nCadastral Extent 1 375 M²"])));
    }

    // ── segments: unit, complex, suburb tail ────────────────────────────

    public function test_unit_and_complex_lines_around_the_street(): void
    {
        $r = $this->p(['address' => 'Unit 5, 12 Marine Drive']);
        $this->assertSame('12 | marine | drive', $this->line($r));
        $this->assertSame('5', $r['unit_number']);

        $r = $this->p(['address' => 'Sea View Court, 12 Marine Drive']);
        $this->assertSame('12 | marine | drive', $this->line($r));
        $this->assertSame('Sea View Court', $r['complex_name']);
    }

    public function test_p24_street_line_with_the_suburb_repeated_drops_the_suburb(): void
    {
        $r = $this->p(['address' => '42 Springwood, Umhlali Golf Estate, Ballito', 'suburb' => 'Umhlali Golf Estate', 'town' => 'Ballito']);
        $this->assertSame('42 | springwood |', $this->line($r));
        $this->assertNull($r['complex_name']);

        $r = $this->p(['address' => '12 Marine Drive, Margate', 'suburb' => 'Margate']);
        $this->assertSame('12 | marine | drive', $this->line($r));
    }

    public function test_villa_del_mei_old_multi_line_row_lifts_a_unit_and_a_complex(): void
    {
        $r = $this->p(['street_number' => '35', 'street_name' => "4 Villa-Del-Mei\n35 Grindewald Drive"]);
        $this->assertSame('35 | grindewald | drive', $this->line($r));
        $this->assertSame('Villa-Del-Mei', $r['complex_name']);
        $this->assertSame('4', $r['unit_number']);
        $this->assertSame([], $r['conflicts']);
    }

    public function test_explicit_columns_win_over_text_for_unit_and_complex(): void
    {
        $r = $this->p(['street_name' => 'Unit 9, 12 Marine Drive', 'unit_number' => '5', 'complex_name' => 'Golden Moon']);
        $this->assertSame('5', $r['unit_number']);
        $this->assertSame('Golden Moon', $r['complex_name']);
    }

    // ── conflicts => review, never a pick ───────────────────────────────

    public function test_two_different_numbers_is_review_and_the_explicit_one_is_kept(): void
    {
        $r = $this->p(['street_number' => '29', 'street_name' => '19 Grindewald Drive']);
        $this->assertSame('review', $r['status']);
        $this->assertContains('number_sources_disagree', $r['conflicts']);
        $this->assertSame('29', $r['street_number']);
    }

    public function test_two_different_streets_in_one_address_is_review(): void
    {
        $r = $this->p(['street_name' => "12 Marine Drive\n7 Beach Road"]);
        $this->assertSame('review', $r['status']);
        $this->assertContains('multiple_streets', $r['conflicts']);
    }

    public function test_nothing_readable_is_unparseable(): void
    {
        $this->assertSame('unparseable', $this->p([])['status']);
        $this->assertSame('unparseable', $this->p(['street_name' => '-', 'address' => 'N/A'])['status']);
        $this->assertSame('unparseable', $this->p(['address' => 'Umhlali Golf Estate', 'suburb' => 'Umhlali Golf Estate'])['status']);
    }

    public function test_a_scheme_or_an_erf_alone_is_parseable(): void
    {
        $this->assertSame('parsed', $this->p(['scheme_number' => 'SS 123', 'section_number' => '7'])['status']);
        $this->assertSame('parsed', $this->p(['erf_number' => '1166'])['status']);
        $this->assertSame('parsed', $this->p(['complex_name' => 'Villa Del Sol'])['status']);
    }

    public function test_a_street_number_column_that_is_not_a_house_number_is_ignored(): void
    {
        $r = $this->p(['street_number' => 'Erf 123', 'street_name' => '19 Grindewald Drive']);
        $this->assertSame('19', $r['street_number']);
        $this->assertSame('parsed', $r['status']);
    }

    // ── erf + LPI ───────────────────────────────────────────────────────

    public function test_lpi_gives_erf_portion_and_township(): void
    {
        $r = $this->p(['lpi_code' => 'n0et03630000132900000', 'street_name' => '1 Como Drive']);
        $this->assertSame('1329', $r['erf_number']);
        $this->assertSame('0', $r['erf_portion']);
        $this->assertSame('0363', $r['township']);
        $this->assertSame('n0et03630000132900000', $r['lpi_code']);
    }

    public function test_an_explicit_erf_stands_and_a_disagreeing_lpi_is_review(): void
    {
        $r = $this->p(['erf_number' => '01329', 'lpi_code' => 'N0ET03630000132900000']);
        $this->assertSame([], $r['conflicts'], 'leading zeros are the same erf');
        $r = $this->p(['erf_number' => '1330', 'lpi_code' => 'n0et03630000132900000']);
        $this->assertSame('review', $r['status']);
        $this->assertContains('erf_lpi_disagree', $r['conflicts']);
        $this->assertSame('1330', $r['erf_number']);
    }

    public function test_portion_comes_from_the_lpi_and_six_portions_of_stand_1166_stay_apart(): void
    {
        $codes = array_map(fn ($p) => $this->p(['lpi_code' => 'n0et0123' . '00001166' . str_pad((string) $p, 5, '0', STR_PAD_LEFT)])['erf_portion'], [0, 1, 2, 3, 4, 5]);
        $this->assertSame(['0', '1', '2', '3', '4', '5'], $codes);
    }

    public function test_lpi_code_parser(): void
    {
        $this->assertSame(['code' => 'n0et03630000132900000', 'division' => 'n0et', 'township' => '0363', 'erf' => '1329', 'portion' => '0'], LpiCode::parse('N0ET03630000132900000'));
        $this->assertSame('1329', LpiCode::parse(' n0et 0363 00001329 00000 ')['erf']);
        $this->assertSame('1329', LpiCode::parse('cmainfo:n0et03630000132900000')['erf']);
        $this->assertNull(LpiCode::parse('n0et0363'));
        $this->assertNull(LpiCode::parse(''));
        $this->assertNull(LpiCode::parse(null));
        $this->assertNull(LpiCode::parse('not a code at all 1234567'));
    }

    // ── Cape Town / Afrikaans (parser and types are data, not KZN code) ──

    public function test_afrikaans_street_types(): void
    {
        $this->assertSame('kerk | street', $this->core($this->p(['street_name' => 'Kerk Straat'])));
        $this->assertSame('protea | road', $this->core($this->p(['street_name' => '12 Protea Weg'])));
        $this->assertSame('blou | avenue', $this->core($this->p(['street_name' => 'Blou Laan'])));
        $this->assertSame('12 | protea | road', $this->line($this->p(['street_name' => '12 Protea Weg'])));
    }

    public function test_street_types_table(): void
    {
        $this->assertSame('street', StreetTypes::canonical('St'));
        $this->assertSame('street', StreetTypes::canonical('straat'));
        $this->assertSame('crescent', StreetTypes::canonical('Cres.'));
        $this->assertNull(StreetTypes::canonical('Marine'));
        $this->assertSame([['marine'], 'drive'], StreetTypes::splitTrailing(['marine', 'drive']));
        $this->assertSame([['drive'], null], StreetTypes::splitTrailing(['drive']), 'a one-word street is never split');
    }

    // ── idempotence, absence ────────────────────────────────────────────

    public function test_reparsing_the_output_gives_the_same_columns(): void
    {
        foreach ([
            ['street_name' => '19 Grindewald Drive'],
            ['street_name' => "4 Garden Place   Cadastral Extent  1 605 M²"],
            ['address' => 'Unit 5, 12 Marine Drive'],
            ['street_number' => '35', 'street_name' => "4 Villa-Del-Mei\n35 Grindewald Drive"],
            ['lpi_code' => 'n0et03630000132900000', 'street_name' => 'Kerk Straat'],
        ] as $in) {
            $first = $this->p($in);
            $again = $this->p([
                'street_number' => $first['street_number'], 'street_name' => $first['street_name_clean'],
                'unit_number' => $first['unit_number'], 'complex_name' => $first['complex_name'],
                'erf_number' => $first['erf_number'], 'erf_portion' => $first['erf_portion'], 'lpi_code' => $first['lpi_code'],
            ]);
            foreach (['street_number', 'street_core', 'street_type', 'unit_number', 'complex_name', 'erf_number', 'erf_portion', 'township', 'lpi_code', 'status'] as $k) {
                $this->assertSame($first[$k], $again[$k], "idempotent: $k for " . json_encode($in));
            }
        }
    }

    public function test_each_input_missing_individually_never_errors(): void
    {
        $full = ['address' => '12 Marine Drive', 'street_number' => '12', 'street_name' => 'Marine Drive', 'unit_number' => '5', 'section_number' => '5',
            'complex_name' => 'Sea View', 'scheme_name' => 'Sea View', 'scheme_number' => 'SS 1', 'suburb' => 'Margate', 'town' => 'Margate',
            'province' => 'KwaZulu-Natal', 'lpi_code' => 'n0et03630000132900000', 'erf_number' => '1329', 'erf_portion' => '0'];
        foreach (array_keys($full) as $drop) {
            $in = $full;
            unset($in[$drop]);
            $this->assertIsArray($this->p($in), "without $drop");
        }
        $this->assertIsArray($this->p(array_map(fn () => null, $full)));
        $this->assertIsArray($this->p(array_map(fn () => '   ', $full)));
    }
}
