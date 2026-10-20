<?php

/**
 * Regenerates the two fake suburb-report fixtures used by the report-recogniser tests
 * (mic-complete-spec §8.9). The publisher is invented ("Coastline Property Data") and
 * deliberately NOT a CMA Info layout: no CMA wordmark, none of the CMA section headings,
 * so every built-in parser scores zero and only a learned layout or the AI can read it.
 *
 *   php tests/Fixtures/market_reports/make-fake-suburb-reports.php
 *
 * A and B share one layout (same publisher, same headings, same columns) with different
 * suburbs and figures, which is exactly what "learn once, read the next one for free"
 * needs. C is a one-page document that is not a market report at all.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;

function report(string $suburb, string $date, array $sales, array $stats, array $range): string
{
    $rows = '';
    foreach ($sales as [$addr, $d, $price, $ext, $rm2]) {
        $rows .= "<tr><td style='width:230px'>$addr</td><td style='width:110px'>$d</td><td style='width:120px'>R " . number_format($price, 0, '.', ' ') . "</td><td style='width:90px'>$ext m²</td><td style='width:80px'>R " . number_format($rm2, 0, '.', ' ') . "</td></tr>";
    }
    $statRows = '';
    foreach ($stats as [$y, $median, $count, $chg]) {
        $statRows .= "<tr><td style='width:100px'>$y</td><td style='width:160px'>R " . number_format($median, 0, '.', ' ') . "</td><td style='width:100px'>$count</td><td style='width:120px'>$chg</td></tr>";
    }

    return <<<HTML
<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px;">
<h2>Coastline Property Data</h2>
<h3>Suburb Sales Report</h3>
<p>Suburb: $suburb</p>
<p>Report date: $date</p>
<h4>Sold Properties</h4>
<table cellpadding="3"><tr><th style='width:230px;text-align:left'>Property Address</th><th style='width:110px;text-align:left'>Registered</th><th style='width:120px;text-align:left'>Purchase Price</th><th style='width:90px;text-align:left'>Erf Size</th><th style='width:80px;text-align:left'>Price per m²</th></tr>
$rows</table>
<h4>Suburb Price Trends</h4>
<table cellpadding="3"><tr><th style='width:100px;text-align:left'>Year</th><th style='width:160px;text-align:left'>Median Price</th><th style='width:100px;text-align:left'>Transfers</th><th style='width:120px;text-align:left'>Yearly Change</th></tr>
$statRows</table>
<h4>Indicative Value Range</h4>
<p>Lower Band &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;R {$range[0]}</p>
<p>Middle Band &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;R {$range[1]}</p>
<p>Upper Band &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;R {$range[2]}</p>
<p style="margin-top:40px">Coastline Property Data compiles this report from registered transfer records.</p>
</body></html>
HTML;
}

function pdf(string $html, string $path): void
{
    $d = new Dompdf();
    $d->loadHtml($html);
    $d->setPaper('A4');
    $d->render();
    file_put_contents($path, $d->output());
    echo "wrote $path\n";
}

$dir = __DIR__;

pdf(report('Southport', '2026-10-12', [
    ['12 Marine Drive',    '2026-08-14', 1850000, 620, 2984],
    ['7 Albatross Close',  '2026-07-02', 2275000, 810, 2809],
    ['41 Dolphin Street',  '2026-05-29', 1495000, 540, 2769],
    ['3 Kingfisher Road',  '2026-04-17', 2950000, 1020, 2892],
    ['88 Lagoon Avenue',   '2026-03-06', 1720000, 600, 2867],
    ['19 Pelican Way',     '2026-01-23', 2140000, 735, 2912],
], [
    [2023, 1620000, 118, '4.2%'],
    [2024, 1745000, 131, '7.7%'],
    [2025, 1890000, 126, '8.3%'],
], ['1 450 000', '1 950 000', '2 600 000']), "$dir/fake_suburb_report_a.pdf");

pdf(report('Margate Beach', '2026-09-30', [
    ['5 Sandpiper Lane',   '2026-08-21', 1325000, 480, 2760],
    ['22 Tern Crescent',   '2026-06-11', 1980000, 705, 2809],
    ['14 Gull Street',     '2026-05-02', 1610000, 590, 2729],
    ['60 Ocean View Road', '2026-02-19', 2480000, 905, 2740],
], [
    [2023, 1380000, 96, '3.1%'],
    [2024, 1475000, 104, '6.9%'],
    [2025, 1560000, 99, '5.8%'],
], ['1 150 000', '1 600 000', '2 200 000']), "$dir/fake_suburb_report_b.pdf");

pdf('<html><body><h2>Annual Tea Party Invitation</h2><p>Join us on Saturday for tea and scones.</p></body></html>', "$dir/not_a_market_report.pdf");

// A three-page document, for the "too many pages for the AI" rule (test sets the limit to 2).
pdf('<html><body><h2>Coastline Property Data</h2><p>Page one.</p><div style="page-break-after:always"></div><p>Page two.</p><div style="page-break-after:always"></div><p>Page three.</p></body></html>', "$dir/three_page_document.pdf");
