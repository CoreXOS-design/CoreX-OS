<?php

namespace App\Services\Presentations;

use App\Models\PresentationVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a printable HTML pack from a PresentationVersion snapshot (P18).
 *
 * No external PDF library is required. The output is a self-contained HTML
 * document that browsers can print to PDF (Ctrl+P → Save as PDF).
 *
 * To upgrade to a proper PDF binary (dompdf, wkhtmltopdf, etc.) in the future,
 * replace generate() internals only — the public interface and storage path
 * contract remain stable.
 *
 * Storage path: presentations/{presentationId}/versions/{versionId}.html
 */
class PresentationPdfService
{
    public const STORAGE_DISK = 'local';

    /**
     * Generate the pack HTML, persist it to storage, and return the stored path.
     *
     * @return string Storage path relative to the disk root.
     */
    public function generate(PresentationVersion $version): string
    {
        $html = $this->buildHtml($version);

        $path = $this->storagePath($version);

        Storage::disk(self::STORAGE_DISK)->put($path, $html);

        return $path;
    }

    /**
     * Return the canonical storage path for a version's pack file.
     */
    public function storagePath(PresentationVersion $version): string
    {
        return sprintf(
            'presentations/%d/versions/%d.html',
            $version->presentation_id,
            $version->id,
        );
    }

    /**
     * Build the full HTML document from the snapshot data.
     */
    private function buildHtml(PresentationVersion $version): string
    {
        $snapshot     = $version->getSnapshotArray();
        $presentation = $snapshot['presentation']  ?? [];
        $analytics    = $snapshot['analytics']     ?? [];
        $evidence     = $snapshot['evidence']      ?? [];
        $holdingCost  = $snapshot['holding_cost']  ?? null;
        $confidence   = $snapshot['confidence']    ?? null;
        $ppi          = $snapshot['ppi']           ?? null;
        $articles     = $snapshot['articles']      ?? [];
        $compiledAt   = $version->compiled_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i');

        $title        = htmlspecialchars($presentation['title'] ?? 'Untitled Presentation', ENT_QUOTES);
        $address      = htmlspecialchars($presentation['property_address'] ?? '', ENT_QUOTES);
        $suburb       = htmlspecialchars($presentation['suburb'] ?? '', ENT_QUOTES);
        $propertyType = htmlspecialchars(ucfirst($presentation['property_type'] ?? ''), ENT_QUOTES);
        $sellerName   = htmlspecialchars($presentation['seller_name'] ?? '', ENT_QUOTES);
        $currency     = htmlspecialchars($presentation['currency'] ?? 'ZAR', ENT_QUOTES);

        // ── Key metric formatting helpers ─────────────────────────────────
        $fmt = fn ($val, $suffix = '') => $val !== null ? (is_float($val) ? number_format($val, 2) : $val) . $suffix : '—';
        $pct = fn ($val) => $val !== null ? number_format($val * 100, 0) . '%' : '—';

        $p60 = $analytics['p60'] ?? null;
        $p30 = $analytics['p30'] ?? null;
        $p90 = $analytics['p90'] ?? null;
        $expectedDays = $analytics['expected_days'] ?? null;
        $monthsInv    = $analytics['months_of_inventory'] ?? null;
        $ppiScore     = $ppi['ppi_score'] ?? null;
        $ppiLabel     = $ppi['ppi_label'] ?? null;
        $confScore    = $confidence['confidence_score'] ?? null;

        $hcMonthly    = $holdingCost['monthly_total'] ?? null;
        $hc6mo        = $holdingCost['six_month_total'] ?? null;
        $hc12mo       = $holdingCost['twelve_month_total'] ?? null;

        $soldCount   = $evidence['sold_comps_count']      ?? 0;
        $activeCount = $evidence['active_listings_count'] ?? 0;
        $uploadCount = $evidence['upload_count']          ?? 0;
        $linkCount   = $evidence['links_count']           ?? 0;

        // ── Sections list for internal TOC ────────────────────────────────
        $sections = $snapshot['sections'] ?? [];

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $title ?> — Presentation Pack</title>
<style>
  body { font-family: Georgia, serif; margin: 40px; color: #1a1a1a; font-size: 13px; line-height: 1.6; }
  h1 { font-size: 22px; margin-bottom: 4px; }
  h2 { font-size: 16px; border-bottom: 1px solid #ccc; padding-bottom: 4px; margin-top: 36px; }
  h3 { font-size: 14px; margin-top: 20px; }
  .meta { color: #555; font-size: 11px; margin-bottom: 24px; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th { background: #f5f5f5; text-align: left; padding: 6px 10px; font-size: 11px; text-transform: uppercase; color: #555; }
  td { padding: 6px 10px; border-bottom: 1px solid #eee; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
  .badge-strong { background: #d1fae5; color: #065f46; }
  .badge-balanced { background: #fef3c7; color: #92400e; }
  .badge-risky { background: #fee2e2; color: #991b1b; }
  .section-toc { margin: 12px 0; padding: 12px; background: #f9f9f9; border-radius: 4px; }
  .section-toc li { margin: 4px 0; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>

<!-- ══ COVER ══════════════════════════════════════════════════════════════ -->
<h1><?= $title ?></h1>
<div class="meta">
  <?php if ($sellerName): ?>Prepared for: <strong><?= $sellerName ?></strong> &nbsp;·&nbsp; <?php endif ?>
  <?= $address ?> <?php if ($suburb): ?>· <?= $suburb ?><?php endif ?>
  <br>
  Blueprint: <?= htmlspecialchars($version->blueprint_version ?? 'v1') ?> &nbsp;·&nbsp;
  Compiled: <?= $compiledAt ?>
</div>

<!-- ══ EXECUTIVE SUMMARY ══════════════════════════════════════════════════ -->
<h2 id="executive-summary">Executive Summary</h2>
<table>
  <tr><th>Metric</th><th>Value</th></tr>
  <tr><td>Property</td><td><?= $address ?> · <?= $propertyType ?></td></tr>
  <tr><td>30-day sale probability</td><td><?= $pct($p30) ?></td></tr>
  <tr><td>60-day sale probability</td><td><?= $pct($p60) ?></td></tr>
  <tr><td>90-day sale probability</td><td><?= $pct($p90) ?></td></tr>
  <tr><td>Expected days on market</td><td><?= $fmt($expectedDays, ' days') ?></td></tr>
  <tr><td>Months of inventory</td><td><?= $fmt($monthsInv, ' mo') ?></td></tr>
  <?php if ($ppiScore !== null): ?>
  <tr><td>Presentation Performance Index</td>
      <td><?= $ppiScore ?>
        <span class="badge badge-<?= strtolower((string) $ppiLabel) ?>"><?= htmlspecialchars((string) $ppiLabel) ?></span>
      </td>
  </tr>
  <?php endif ?>
  <?php if ($confScore !== null): ?>
  <tr><td>Confidence score</td><td><?= $confScore ?>/100</td></tr>
  <?php endif ?>
</table>

<!-- ══ HOLDING COST ════════════════════════════════════════════════════════ -->
<?php if ($holdingCost): ?>
<h2 id="holding-cost">Holding Cost</h2>
<table>
  <tr><th>Period</th><th>Total (<?= $currency ?>)</th></tr>
  <tr><td>Monthly</td><td><?= number_format((float) $hcMonthly, 2) ?></td></tr>
  <tr><td>6 months</td><td><?= number_format((float) $hc6mo, 2) ?></td></tr>
  <tr><td>12 months</td><td><?= number_format((float) $hc12mo, 2) ?></td></tr>
</table>
<?php endif ?>

<!-- ══ MARKET ANALYTICS ════════════════════════════════════════════════════ -->
<?php if (!empty($analytics)): ?>
<h2 id="market-analytics">Market Analytics</h2>
<table>
  <tr><th>Indicator</th><th>Value</th></tr>
  <tr><td>Months of inventory</td><td><?= $fmt($analytics['months_of_inventory'] ?? null) ?></td></tr>
  <tr><td>Demand / supply ratio</td><td><?= $fmt($analytics['demand_supply_ratio'] ?? null) ?></td></tr>
  <tr><td>Price/sqm deviation</td><td><?= $fmt($analytics['price_per_sqm_deviation_pct'] ?? null, '%') ?></td></tr>
  <tr><td>DOM P25 / P50 / P75</td>
      <td><?= $fmt($analytics['dom_p25'] ?? null) ?> / <?= $fmt($analytics['dom_p50'] ?? null) ?> / <?= $fmt($analytics['dom_p75'] ?? null) ?> days</td>
  </tr>
</table>
<?php endif ?>

<!-- ══ EVIDENCE APPENDIX ═══════════════════════════════════════════════════ -->
<h2 id="evidence-appendix">Appendix — Evidence Sources</h2>
<table>
  <tr><th>Source type</th><th>Count</th></tr>
  <tr><td>Sold comparables</td><td><?= (int) $soldCount ?></td></tr>
  <tr><td>Active listings</td><td><?= (int) $activeCount ?></td></tr>
  <tr><td>Uploaded documents</td><td><?= (int) $uploadCount ?></td></tr>
  <tr><td>Property links</td><td><?= (int) $linkCount ?></td></tr>
  <?php if (!empty($articles)): ?>
  <tr><td>Market articles</td><td><?= count($articles) ?></td></tr>
  <?php endif ?>
</table>

<?php if (!empty($articles)): ?>
<h3>Article Sources</h3>
<table>
  <tr><th>URL</th><th>Fetched</th><th>Content hash</th></tr>
  <?php foreach ($articles as $article): ?>
  <tr>
    <td><a href="<?= htmlspecialchars($article['url'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($article['url'] ?? '', ENT_QUOTES) ?></a></td>
    <td><?= htmlspecialchars($article['fetched_at'] ?? '—', ENT_QUOTES) ?></td>
    <td style="font-family:monospace;font-size:10px"><?= htmlspecialchars(substr($article['snapshot_hash'] ?? '', 0, 16), ENT_QUOTES) ?>…</td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<!-- ══ SECTIONS ════════════════════════════════════════════════════════════ -->
<?php if (!empty($sections)): ?>
<h2 id="sections">Pack Sections</h2>
<div class="section-toc">
<ol>
<?php foreach ($sections as $section): ?>
  <li><?= htmlspecialchars($section['title'] ?? $section['key'] ?? '', ENT_QUOTES) ?></li>
<?php endforeach ?>
</ol>
</div>
<?php endif ?>

<div class="meta" style="margin-top:40px;border-top:1px solid #eee;padding-top:12px;">
  Generated by HF Coastal Nexus &nbsp;·&nbsp; Version ID: <?= $version->id ?> &nbsp;·&nbsp; <?= $compiledAt ?>
</div>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
