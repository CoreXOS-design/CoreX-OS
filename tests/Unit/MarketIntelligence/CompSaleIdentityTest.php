<?php

declare(strict_types=1);

namespace Tests\Unit\MarketIntelligence;

use App\Services\MarketIntelligence\Comps\CompSaleIdentity;
use PHPUnit\Framework\TestCase;

/**
 * MIC 5.3 (AT-524): which CMA rows with the same date and price are the same sale. Fixtures are real QA1 rows (31 Oct 2026).
 */
final class CompSaleIdentityTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge(['section_number' => null, 'scheme_name' => null, 'ss_number' => null, 'address' => null,
            'suburb_normalised' => null, 'p24_suburb_id' => null, 'extent_m2' => null, 'erf' => null], $o);
    }

    public function test_the_same_sectional_sale_with_scheme_or_ss_missing_in_one_report_is_one_sale(): void
    {
        $full = $this->row(['scheme_name' => 'CHESAPEAKE BAY', 'section_number' => '29', 'ss_number' => '672', 'address' => '54 PANORAMA', 'extent_m2' => 90, 'p24_suburb_id' => 5]);
        $thin = $this->row(['scheme_name' => 'CHESAPEAKE BAY', 'section_number' => '29', 'extent_m2' => 90, 'p24_suburb_id' => 5]);
        $this->assertTrue(CompSaleIdentity::same($full, $thin));
    }

    public function test_the_same_scheme_filed_under_two_suburb_names_is_one_sale(): void
    {
        $a = $this->row(['scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'ss_number' => '137', 'address' => '16 FAUX STREET', 'extent_m2' => 145, 'p24_suburb_id' => 10]);
        $b = $this->row(['scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'ss_number' => '137', 'address' => '16 FAUX STREET MARGATE', 'extent_m2' => 145, 'p24_suburb_id' => 5]);
        $this->assertTrue(CompSaleIdentity::same($a, $b));
    }

    public function test_a_scheme_named_with_an_extra_word_in_one_report_is_the_same_scheme(): void
    {
        $a = $this->row(['scheme_name' => 'CASA-UVONGO', 'section_number' => '14', 'ss_number' => '66', 'extent_m2' => 118]);
        $b = $this->row(['scheme_name' => 'UVONGO', 'section_number' => '14', 'ss_number' => '66', 'extent_m2' => 118]);
        $this->assertTrue(CompSaleIdentity::same($a, $b));
        $this->assertSame(['scheme', 'ss', 'extent'], CompSaleIdentity::evidence($a, $b));
    }

    public function test_two_different_sections_sold_for_the_same_price_on_the_same_day_are_two_sales(): void
    {
        $a = $this->row(['scheme_name' => 'LAGUNA LA CRETE', 'section_number' => '236', 'extent_m2' => 69, 'p24_suburb_id' => 15548]);
        $b = $this->row(['scheme_name' => 'LAGUNA LA CRETE', 'section_number' => '38', 'extent_m2' => 69, 'p24_suburb_id' => 15548]);
        $this->assertFalse(CompSaleIdentity::same($a, $b));
    }

    public function test_leading_zero_section_numbers_match(): void
    {
        $a = $this->row(['section_number' => '02', 'p24_suburb_id' => 2]);
        $b = $this->row(['section_number' => '2', 'p24_suburb_id' => 2]);
        $this->assertTrue(CompSaleIdentity::same($a, $b), 'sections with nothing else but the suburb match inside one suburb');
        $this->assertFalse(CompSaleIdentity::same($a, $this->row(['section_number' => '2', 'p24_suburb_id' => 6])), 'a different suburb is not enough');
    }

    public function test_a_disagreeing_identity_field_means_two_sales(): void
    {
        $base = ['scheme_name' => 'SEESTER', 'section_number' => '1', 'ss_number' => '97', 'extent_m2' => 120];
        $this->assertFalse(CompSaleIdentity::same($this->row($base), $this->row(array_merge($base, ['scheme_name' => 'OTHERSCHEME']))));
        $this->assertFalse(CompSaleIdentity::same($this->row($base), $this->row(array_merge($base, ['ss_number' => '98']))));
        $this->assertFalse(CompSaleIdentity::same($this->row($base), $this->row(array_merge($base, ['extent_m2' => 121]))));
    }

    public function test_a_sectional_row_never_matches_a_freehold_row(): void
    {
        $this->assertFalse(CompSaleIdentity::same(
            $this->row(['section_number' => '4', 'address' => '16 FAUX STREET', 'extent_m2' => 145]),
            $this->row(['address' => '16 FAUX STREET', 'extent_m2' => 145]),
        ));
    }

    public function test_freehold_rows_match_on_erf_extent_or_address_with_or_without_the_town(): void
    {
        $a = $this->row(['address' => '50 KING HAARKON ROAD', 'extent_m2' => 1270, 'erf' => '208']);
        $b = $this->row(['address' => '50 KING HAARKON ROAD, OSLO BEACH', 'extent_m2' => 1270, 'erf' => '208']);
        $this->assertTrue(CompSaleIdentity::same($a, $b));
        $this->assertTrue(CompSaleIdentity::same($this->row(['erf' => '1619', 'extent_m2' => 525]), $this->row(['erf' => '1619', 'extent_m2' => 525])));
    }

    public function test_freehold_rows_with_different_addresses_or_erfs_are_two_sales_and_with_no_identity_they_do_not_merge(): void
    {
        $this->assertFalse(CompSaleIdentity::same($this->row(['address' => '16 SETTLERS ROAD']), $this->row(['address' => '18 SETTLERS ROAD'])));
        $this->assertFalse(CompSaleIdentity::same($this->row(['erf' => '1618', 'extent_m2' => 515]), $this->row(['erf' => '1619', 'extent_m2' => 515])));
        $this->assertFalse(CompSaleIdentity::same($this->row(['p24_suburb_id' => 5]), $this->row(['p24_suburb_id' => 5])), 'a suburb alone never makes two freehold rows one sale');
    }

    public function test_completeness_ranks_the_richer_row_higher(): void
    {
        $rich = $this->row(['scheme_name' => 'X', 'ss_number' => '1', 'address' => 'A', 'section_number' => '1', 'extent_m2' => 10, 'p24_suburb_id' => 1]);
        $this->assertGreaterThan(CompSaleIdentity::completeness($this->row(['section_number' => '1'])), CompSaleIdentity::completeness($rich));
    }
}
