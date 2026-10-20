<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports;

use App\Models\MarketReports\MarketReport;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\MarketReports\LayoutFingerprint;
use App\Services\MarketReports\LearnedLayoutReader;
use Tests\TestCase;

/**
 * Report recogniser — the pure pieces: value gates, fingerprint maths, cell/figure parsing.
 * Spec: .ai/specs/mic-complete-spec.md §8.9.
 */
final class ReportRecogniserUnitTest extends TestCase
{
    public function test_sanitiser_drops_implausible_values_and_never_corrects_them(): void
    {
        $mapper = new CanonicalReadMapper();
        $out = $mapper->sanitise([
            'publisher' => '  Some   Publisher ',
            'comps' => [
                ['kind' => 'comp', 'address' => '1 A Road', 'sale_price' => 'R 1 250 000', 'sale_date' => '2026-03-01', 'extent_m2' => 600],
                ['kind' => 'comp', 'address' => 'bleed',    'sale_price' => 81000018,       'sale_date' => '2026-03-01'],   // column bleed: out of range -> row dropped
                ['kind' => 'comp', 'address' => 'future',   'sale_price' => 900000,         'sale_date' => '2999-01-01'],   // future date -> value dropped, row kept
                ['kind' => 'comp', 'address' => 'no price'],
                ['kind' => 'listing', 'address' => 'for sale', 'list_price' => 1800000],
            ],
            'suburb_stats' => [
                ['metric' => 'median_price', 'year' => 2024, 'value' => 1700000],
                ['metric' => 'median_price', 'year' => 1850, 'value' => 1700000],   // year out of range
                ['metric' => 'made_up_metric', 'year' => 2024, 'value' => 5],
                ['metric' => 'annual_change_pct', 'year' => 2024, 'value' => '-3.4%'],
                ['metric' => 'total_sales_12m', 'value' => 140],
            ],
            'valuation' => ['lower' => 1000000, 'middle' => '2', 'upper' => 1900000],
        ]);
        $c = $out['clean'];

        $this->assertSame('Some Publisher', $c['publisher']);
        $this->assertSame([1250000, 900000, 1800000], array_map(fn ($r) => $r['sale_price'] ?? $r['list_price'], $c['comps']));
        $this->assertArrayNotHasKey('sale_date', $c['comps'][1], 'a future date is dropped, not "fixed"');
        $this->assertSame(['listing'], [$c['comps'][2]['kind']]);
        $this->assertCount(3, $c['suburb_stats']);
        $this->assertSame(-3.4, $c['suburb_stats'][1]['value']);
        $this->assertSame(['lower' => 1000000, 'upper' => 1900000], $c['valuation']);
        $this->assertNotEmpty($out['dropped']);
        $this->assertFalse($mapper->isEmpty($c));
        $this->assertTrue($mapper->isEmpty($mapper->sanitise(['comps' => [], 'suburb_stats' => []])['clean']));
    }

    public function test_canonical_read_becomes_the_same_rows_the_cma_parsers_write(): void
    {
        $mapper = new CanonicalReadMapper();
        $clean = $mapper->sanitise([
            'suburb' => 'Southport', 'town' => 'Margate',
            'subject' => ['address' => '5 Subject Street', 'extent_m2' => 700],
            'valuation' => ['lower' => 1000000, 'middle' => 1500000, 'upper' => 2000000, 'average_r_per_m2' => 2500],
            'comps' => [['kind' => 'comp', 'address' => '12 Marine Drive', 'sale_date' => '2026-08-14', 'sale_price' => 1850000, 'extent_m2' => 620, 'r_per_m2' => 2984]],
            'suburb_stats' => [['metric' => 'sales_count', 'year' => 2025, 'value' => 126], ['metric' => 'total_sales_12m', 'value' => 140]],
        ])['clean'];

        $r = $mapper->toParseResult($clean, new MarketReport(['agency_id' => 1]));

        $byKey = collect($r->dataPoints)->groupBy('metric_key');
        foreach (['cma_value_lower', 'cma_value_middle', 'cma_value_upper', 'cma_value_average_r_per_m2', 'comparable_sale_price', 'suburb_sales_count_year', 'suburb_total_sales_12m'] as $key) {
            $this->assertTrue($byKey->has($key), "missing metric $key");
        }
        $this->assertSame('2025-12-31', $byKey['suburb_sales_count_year'][0]['metric_date']);
        $this->assertSame('southport', $byKey['comparable_sale_price'][0]['suburb_normalised']);
        $this->assertSame('comp', $r->compRows[0]['row_type']);
        $this->assertSame(1850000, $r->compRows[0]['sale_price']);
        $this->assertSame('5 Subject Street', $r->subjectMeta['subject_address']);
        $this->assertSame('Southport', $r->subjectMeta['source_suburb']);
        $this->assertSame('Margate', $r->subjectMeta['source_town']);
        $this->assertNotEmpty($r->extractedAddresses);
        // every data point satisfies MarketDataPoint's "exactly one value" rule
        foreach ($r->dataPoints as $p) {
            $this->assertNotNull($p['metric_value_numeric']);
        }
    }

    public function test_fingerprint_normalises_and_measures_presence(): void
    {
        $this->assertSame('price per m', LayoutFingerprint::normalise('Price per m²'));
        $this->assertSame('page # of #', LayoutFingerprint::normalise('Page 3 of 12'));

        $text = "Coastline Property Data\n   Suburb Sales Report\nProperty Address     Registered   Purchase Price";
        $anchors = ['coastline property data', 'suburb sales report', 'purchase price'];
        $this->assertSame(1.0, LayoutFingerprint::presentShare($anchors, $text));
        $this->assertEqualsWithDelta(2 / 3, LayoutFingerprint::presentShare($anchors, 'Coastline Property Data  Suburb Sales Report'), 0.0001);
        $this->assertSame(0.0, LayoutFingerprint::presentShare($anchors, 'a recipe for scones'));
        $this->assertSame(LayoutFingerprint::hash(['b', 'a']), LayoutFingerprint::hash(['a', 'b']), 'order does not matter');

        // weak or made-up hints never produce a layout
        $this->assertSame([], LayoutFingerprint::anchorsFromHints(['column_headings' => ['Price', 'No']], $text));
        $this->assertSame([], LayoutFingerprint::anchorsFromHints(['publisher_signature' => 'Not In The Text', 'column_headings' => ['Imaginary Column', 'Other Imaginary']], $text));
        $this->assertCount(3, LayoutFingerprint::anchorsFromHints([
            'publisher_signature' => 'Coastline Property Data', 'header_phrases' => ['Suburb Sales Report', '12 Marine Drive 2026-08-14'], 'column_headings' => ['Purchase Price'],
        ], $text));
    }

    public function test_reader_parses_figures_the_way_reports_print_them(): void
    {
        $this->assertSame(1250000, LearnedLayoutReader::parseMoney('R 1 250 000'));
        $this->assertSame(1250000, LearnedLayoutReader::parseMoney('R1,250,000.00'));
        $this->assertNull(LearnedLayoutReader::parseMoney('1 605 m²'));
        $this->assertNull(LearnedLayoutReader::parseMoney('Purchase Price'));
        $this->assertSame(1605, LearnedLayoutReader::parseSize('1 605 m²'));
        $this->assertSame(2984, LearnedLayoutReader::parseSize('R 2 984'));
        $this->assertSame(-3.4, LearnedLayoutReader::parseFloat('-3.4%'));
        $this->assertSame('2026-03-05', LearnedLayoutReader::parseDate('05/03/2026', 'd/m/Y'));
        $this->assertSame('2026-03-05', LearnedLayoutReader::parseDate('5 Mar 2026'));
        $this->assertNull(LearnedLayoutReader::parseDate('not a date'));
        $this->assertSame('d/m/Y', LearnedLayoutReader::dateFormatFor('05/03/2026', '2026-03-05'));
        $this->assertSame(['12 Marine Drive', '2026-08-14', 'R 1 850 000'], LearnedLayoutReader::cells('  12 Marine Drive     2026-08-14   R 1 850 000 '));
    }

    public function test_reader_applies_a_mapping_and_skips_everything_that_is_not_a_data_row(): void
    {
        $mapping = [
            'tables' => [
                ['type' => 'comps', 'kind' => 'comp', 'cells' => 3, 'columns' => [['i' => 0, 'field' => 'address'], ['i' => 1, 'field' => 'sale_date', 'format' => 'd/m/Y'], ['i' => 2, 'field' => 'sale_price']]],
                ['type' => 'stats', 'cells' => 3, 'year_col' => 0, 'metrics' => [['i' => 1, 'metric' => 'median_price'], ['i' => 2, 'metric' => 'sales_count']]],
            ],
            'scalars' => [['field' => 'valuation.lower', 'label' => 'Lower Band', 'type' => 'money'], ['field' => 'suburb', 'label' => 'Suburb:', 'type' => 'text']],
        ];
        $text = implode("\n", [
            'Suburb Sales Report',
            'Suburb: Southport',
            'Address          Sold         Price',
            '12 Marine Drive  14/08/2026   R 1 850 000',
            '7 Albatross Cl   02/07/2026   R 2 275 000',
            'Page 1 of 1      footer       text',
            'Year    Median          Sales',
            '2024    R 1 745 000     131',
            'Lower Band     R 1 450 000',
        ]);

        $read = (new LearnedLayoutReader())->read($mapping, $text);

        $this->assertCount(2, $read['comps']);
        $this->assertSame('2026-08-14', $read['comps'][0]['sale_date']);
        $this->assertSame(2275000, $read['comps'][1]['sale_price']);
        $this->assertSame([['metric' => 'median_price', 'year' => 2024, 'value' => 1745000], ['metric' => 'sales_count', 'year' => 2024, 'value' => 131.0]], $read['suburb_stats']);
        $this->assertSame(1450000, $read['valuation']['lower']);
        $this->assertSame('Southport', $read['suburb']);
        $none = (new LearnedLayoutReader())->read($mapping, "nothing\nlike it");
        $this->assertSame([], array_merge($none['comps'], $none['suburb_stats'], $none['valuation'], $none['subject']));
    }
}
