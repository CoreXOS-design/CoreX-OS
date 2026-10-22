<?php

/**
 * Regenerates the "Sales (Days on Market)" fixtures used by the recogniser tests (mic-complete-spec §8.9, 22 Oct 2026).
 * The publisher is invented ("Tidewater Market Data"); the layout is the shape that broke the first learner: ONE wide
 * table of twelve columns (Property, Address, Type, Usage, Extent, Sale Date, Sale Price, List Date, List Price, Days on
 * Market, Change in Price, R/m²) where a sale that was never listed leaves List Date / List Price / Days on Market /
 * Change in Price BLANK, so rows do not all split into the same number of cells, plus a footer of totals.
 *
 *   php tests/Fixtures/market_reports/make-days-on-market-reports.php
 *
 *   days_on_market_a.pdf        26 sales, Uvongo   (the report the AI "reads")
 *   days_on_market_b.pdf        14 sales, Margate  (same layout, other figures: must be read free after A)
 *   days_on_market_wrapped.pdf  10 sales whose long addresses wrap onto a second line
 *   days_on_market_*.read.json  the canonical read an AI would return for each (truth for the tests)
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;

const PUBLISHER = 'Tidewater Market Data';
const HEADINGS = ['Property', 'Address', 'Type', 'Usage', 'Extent', 'Sale Date', 'Sale Price', 'List Date', 'List Price', 'Days on Market', 'Change in Price', 'R/m²'];

function money(int $v): string
{
    return 'R ' . number_format($v, 0, '.', ' ');
}

function median(array $v): int
{
    sort($v);

    return (int) $v[intdiv(count($v), 2)];
}

/** @return array{rows: array<int, array<string, mixed>>, read: array<string, mixed>} */
function build(string $suburb, int $count, int $seed, array $streets, bool $long): array
{
    mt_srand($seed);
    $types = ['Freehold', 'Sectional', 'Freehold', 'Estate'];
    $rows = [];
    $comps = [];
    $total = 0;
    for ($i = 1; $i <= $count; $i++) {
        $price = mt_rand(8, 42) * 100000 + mt_rand(0, 19) * 5000;
        $ext = mt_rand(300, 1400);
        $listed = mt_rand(0, 2) > 0;
        $street = $streets[mt_rand(0, count($streets) - 1)];
        $addr = $long ? (mt_rand(1, 90) . " $street, $suburb Beach, KwaZulu-Natal") : (mt_rand(1, 90) . " $street, $suburb");
        $sale = sprintf('2026-%02d-%02d', mt_rand(1, 9), mt_rand(1, 28));
        $row = ['address' => $addr, 'type' => $types[mt_rand(0, 3)], 'extent' => $ext, 'sale' => $sale, 'price' => $price, 'rm2' => intdiv($price, $ext),
            'erf' => 'ERF ' . mt_rand(1000, 9999), 'listed' => $listed];
        $comp = ['kind' => 'comp', 'address' => $addr, 'property_type' => $row['type'], 'extent_m2' => $ext, 'sale_date' => $sale, 'sale_price' => $price, 'r_per_m2' => $row['rm2']];
        if ($listed) {
            $row['listDate'] = sprintf('2026-%02d-%02d', 1, mt_rand(1, 28));
            $row['listPrice'] = $price + mt_rand(1, 12) * 10000;
            $row['dom'] = mt_rand(5, 190);
            $row['chg'] = '-' . mt_rand(1, 9) . '%';
            $comp['list_price'] = $row['listPrice'];
            $comp['days_on_market'] = $row['dom'];
        }
        $rows[] = $row;
        $comps[] = $comp;
        $total += $price;
    }
    $avg = intdiv($total, $count);
    $avgRm2 = intdiv(array_sum(array_column($rows, 'rm2')), $count);

    return ['rows' => $rows, 'read' => [
        'publisher' => PUBLISHER, 'report_title' => 'Sales (Days on Market)', 'suburb' => $suburb, 'town' => null,
        'subject' => [], 'valuation' => ['average' => $avg, 'average_r_per_m2' => $avgRm2],
        'comps' => $comps, 'suburb_stats' => [],
        'layout' => [
            'publisher_signature' => PUBLISHER,
            'column_headings' => HEADINGS,
            'header_phrases' => ['Sales (Days on Market)', 'No of sales', 'Average price', 'Total spend'],
        ],
        'confidence' => 0.91, 'notes' => null,
    ], 'total' => $total, 'avg' => $avg, 'avgRm2' => $avgRm2, 'median' => median(array_column($comps, 'sale_price'))];
}

function pdfOf(array $b, int $count, string $path, bool $wrap): void
{
    $addrW = $wrap ? 'width:110px;' : '';
    $tr = '';
    foreach ($b['rows'] as $r) {
        $cells = [$r['erf'], $r['address'], $r['type'], 'Residential', $r['extent'] . ' m²', $r['sale'], money($r['price']),
            $r['listed'] ? $r['listDate'] : '', $r['listed'] ? money($r['listPrice']) : '', $r['listed'] ? (string) $r['dom'] : '', $r['listed'] ? $r['chg'] : '', money($r['rm2'])];
        $tr .= '<tr>' . implode('', array_map(fn ($c, $i) => '<td style="vertical-align:top;' . ($i === 1 ? $addrW : '') . '">' . htmlspecialchars((string) $c) . '</td>', $cells, array_keys($cells))) . '</tr>';
    }
    $head = implode('', array_map(fn ($h) => "<th align=left>$h</th>", HEADINGS));
    $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 9px;">'
        . '<h3>' . PUBLISHER . '</h3><h4>Sales (Days on Market)</h4>'
        . '<table cellpadding="4" cellspacing="0"><tr>' . $head . '</tr>' . $tr . '</table>'
        . "<p>No of sales $count</p><p>Average price " . money($b['avg']) . '</p><p>Total spend ' . money($b['total']) . '</p><p>Median ' . money($b['median']) . '</p><p>Average R/m² ' . money($b['avgRm2']) . '</p>'
        . '</body></html>';
    $d = new Dompdf();
    $d->loadHtml($html);
    $d->setPaper('A4', 'landscape');
    $d->render();
    file_put_contents($path, $d->output());
    file_put_contents(preg_replace('/\.pdf$/', '.read.json', $path), json_encode($b['read'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "wrote $path\n";
}

$dir = __DIR__;
$streets = ['Marine Drive', 'Beach Road', 'Edward Avenue', 'Tucker Avenue', 'Pine Street', 'Ocean View Road', 'Hibiscus Lane', 'Albatross Close'];
pdfOf(build('Uvongo', 26, 11, $streets, false), 26, "$dir/days_on_market_a.pdf", false);
pdfOf(build('Margate', 14, 23, array_reverse($streets), false), 14, "$dir/days_on_market_b.pdf", false);
pdfOf(build('Ramsgate', 10, 37, $streets, true), 10, "$dir/days_on_market_wrapped.pdf", true);
