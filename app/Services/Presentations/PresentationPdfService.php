<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a printable HTML pack from a PresentationVersion snapshot (P18).
 *
 * No external PDF library is required. The output is a self-contained HTML
 * document that browsers can print to PDF (Ctrl+P → Save as PDF).
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
     * Build the full HTML document from the presentation + analysis data.
     */
    public function buildHtml(PresentationVersion $version): string
    {
        // Load the presentation with all relations
        $presentation = Presentation::with([
            'fields', 'soldComps', 'activeListings', 'links',
        ])->findOrFail($version->presentation_id);

        // Get the agent who created this presentation
        $agent = \App\Models\User::find($presentation->created_by_user_id);
        $agentName = $agent->name ?? 'Agent';
        $agentEmail = $agent->email ?? '';

        // Compile analysis data from AnalysisDataService (real extracted data)
        $data = (new AnalysisDataService())->compile($presentation);

        $subject     = $data['subject_property']   ?? [];
        $suburb      = $data['suburb_overview']     ?? [];
        $comps       = $data['comparable_sales']    ?? [];
        $cma         = $data['cma_valuation']       ?? [];
        $competition = $data['active_competition']  ?? [];
        $stock       = $data['stock_absorption']    ?? [];
        $holding     = $data['holding_cost']        ?? [];
        $insights    = $data['key_insights']        ?? [];

        $compiledAt = $version->compiled_at?->format('d F Y') ?? now()->format('d F Y');

        // ── Formatting helpers ──────────────────────────────────────────────
        $zar = function (?int $val): string {
            if ($val === null || $val === 0) return '—';
            return 'R ' . number_format($val, 0, '.', ' ');
        };
        $zarFloat = function (?float $val): string {
            if ($val === null || $val == 0) return '—';
            return 'R ' . number_format((int) round($val), 0, '.', ' ');
        };
        $pct = function (?float $val): string {
            if ($val === null) return '—';
            $sign = $val > 0 ? '+' : '';
            return $sign . number_format($val, 1) . '%';
        };
        $esc = function (?string $val): string {
            return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
        };

        // ── Build data for each page ────────────────────────────────────────
        $address     = $esc($subject['address'] ?? $presentation->property_address ?? '');
        $suburbName  = $esc($subject['suburb'] ?? $presentation->suburb ?? '');
        $sellerName  = $esc($presentation->seller_name ?? '');
        $propType    = $esc(ucfirst($presentation->property_type ?? ''));
        $bedrooms    = $presentation->bedrooms;
        $bathrooms   = null; // not on model currently
        $erfSize     = $subject['extent_m2'] ?? $presentation->floor_area_m2;
        $askingPrice = $subject['asking_price'] ?? $presentation->asking_price_inc;

        // CMA values
        $cmaLower  = $cma['cma_lower'] ?? null;
        $cmaMiddle = $cma['cma_middle'] ?? null;
        $cmaUpper  = $cma['cma_upper'] ?? null;
        $askVsCmaPct = $cma['asking_vs_cma_pct'] ?? null;

        // Suburb overview
        $suburbMedian    = $suburb['median_price'] ?? null;
        $suburbSales     = $suburb['sales_count'] ?? null;
        $suburbYear      = $suburb['latest_year'] ?? date('Y');
        $suburbLow       = $suburb['low_range'] ?? null;
        $suburbHigh      = $suburb['high_range'] ?? null;
        $suburbMax       = $suburb['max_price'] ?? null;

        // Competition
        $activeCount   = $competition['count'] ?? 0;
        $avgAskPrice   = $competition['avg_asking_price'] ?? null;

        // Holding cost
        $monthlyTotal  = $holding['monthly_total'] ?? 0;
        $projected6m   = $holding['projected_6m'] ?? 0;
        $projected12m  = $holding['projected_12m'] ?? 0;
        $breakdown     = $holding['breakdown'] ?? [];

        // Comparable sales
        $vicinitySales = $comps['vicinity']['rows'] ?? [];
        $vicAvgPrice   = $comps['vicinity']['avg_price'] ?? null;
        $vicAvgPpm2    = $comps['vicinity']['avg_price_per_m2'] ?? null;
        $cmaComps      = $comps['cma_comps']['rows'] ?? [];
        $streetSales   = $comps['street_sales']['rows'] ?? [];

        // Active listings
        $activeRows    = $competition['rows'] ?? [];

        // Stock absorption from AnalysisDataService (uses portal search total_count)
        $totalActiveStock  = $stock['total_active_stock'] ?? $activeCount;
        $absorptionRate    = $stock['monthly_sales'] ?? null;
        $monthsOfSupply    = $stock['months_of_supply'] ?? null;
        $yearsOfSupply     = $stock['years_of_supply'] ?? null;
        $absorptionLabel   = $stock['absorption_label'] ?? null;
        $absorptionColor   = $stock['absorption_color'] ?? null;

        // Links for references
        $p24Links = $presentation->links->where('type', 'property24')->values();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Market Analysis — <?= $address ?></title>
<style>
/* ── RESET & BASE ────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --brand: #0b2a4a;
    --brand-light: #1a4a73;
    --brand-accent: #4f46e5;
    --text: #1e293b;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --bg: #ffffff;
    --bg-alt: #f8fafc;
    --border: #e2e8f0;
    --border-light: #f1f5f9;
    --success: #059669;
    --success-bg: #ecfdf5;
    --warning: #d97706;
    --warning-bg: #fffbeb;
    --danger: #dc2626;
    --danger-bg: #fef2f2;
}

@page {
    size: A4 portrait;
    margin: 15mm 18mm 20mm 18mm;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    font-size: 11px;
    line-height: 1.55;
    color: var(--text);
    background: var(--bg);
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ── PAGE BREAK HELPERS ──────────────────────────────────────────────── */
.page-break { page-break-before: always; }
.avoid-break { page-break-inside: avoid; }

/* ── TYPOGRAPHY ──────────────────────────────────────────────────────── */
h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; }
h2 { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; }
h3 { font-size: 14px; font-weight: 600; }

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--brand);
}
.section-header h2 { color: var(--brand); }
.section-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--brand);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

/* ── TABLES ──────────────────────────────────────────────────────────── */
table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    font-size: 10.5px;
}
th {
    background: var(--brand);
    color: #fff;
    text-align: left;
    padding: 7px 10px;
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
td {
    padding: 6px 10px;
    border-bottom: 1px solid var(--border-light);
    vertical-align: top;
}
tr:nth-child(even) td { background: var(--bg-alt); }
.table-summary td {
    background: var(--brand) !important;
    color: #fff;
    font-weight: 700;
    font-size: 11px;
}
td.num, th.num { text-align: right; }

/* ── METRIC CARDS ────────────────────────────────────────────────────── */
.metric-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 14px 0;
}
.metric-card {
    background: var(--bg-alt);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px 16px;
    text-align: center;
}
.metric-card .label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    font-weight: 600;
    margin-bottom: 6px;
}
.metric-card .value {
    font-size: 20px;
    font-weight: 800;
    color: var(--brand);
    letter-spacing: -0.02em;
}
.metric-card .sub {
    font-size: 9px;
    color: var(--text-light);
    margin-top: 3px;
}
.metric-card.highlight {
    background: var(--brand);
    border-color: var(--brand);
}
.metric-card.highlight .label { color: rgba(255,255,255,0.7); }
.metric-card.highlight .value { color: #fff; }
.metric-card.highlight .sub { color: rgba(255,255,255,0.6); }

.metric-card.danger { border-color: var(--danger); }
.metric-card.danger .value { color: var(--danger); }
.metric-card.warning { border-color: var(--warning); }
.metric-card.warning .value { color: var(--warning); }
.metric-card.success { border-color: var(--success); }
.metric-card.success .value { color: var(--success); }

/* ── VALUATION BAR ───────────────────────────────────────────────────── */
.val-bar-container { margin: 16px 0; position: relative; }
.val-bar {
    display: flex;
    height: 40px;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 6px;
}
.val-bar .segment {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
}
.val-bar .seg-lower { background: #64748b; flex: 1; }
.val-bar .seg-middle { background: var(--brand); flex: 1; }
.val-bar .seg-upper { background: var(--brand-light); flex: 1; }
.val-bar-labels {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--text-muted);
    font-weight: 600;
}

/* ── CALLOUT ─────────────────────────────────────────────────────────── */
.callout {
    padding: 12px 16px;
    border-radius: 6px;
    border-left: 4px solid;
    margin: 12px 0;
    font-size: 11px;
    line-height: 1.5;
}
.callout-info { background: #eff6ff; border-color: #3b82f6; color: #1e40af; }
.callout-warning { background: var(--warning-bg); border-color: var(--warning); color: #92400e; }
.callout-danger { background: var(--danger-bg); border-color: var(--danger); color: #991b1b; }
.callout-success { background: var(--success-bg); border-color: var(--success); color: #065f46; }

/* ── COVER PAGE ──────────────────────────────────────────────────────── */
.cover {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 90vh;
    text-align: left;
    padding: 40px 0;
}
.cover-brand {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--brand);
    margin-bottom: 12px;
}
.cover-bar {
    width: 80px;
    height: 4px;
    background: var(--brand);
    border-radius: 2px;
    margin: 20px 0 24px;
}
.cover h1 {
    font-size: 32px;
    color: var(--brand);
    margin-bottom: 8px;
    line-height: 1.15;
}
.cover-address {
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
}
.cover-details {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 32px;
    line-height: 1.7;
}
.cover-meta {
    font-size: 11px;
    color: var(--text-light);
    border-top: 1px solid var(--border);
    padding-top: 16px;
    margin-top: auto;
    line-height: 1.8;
}

/* ── FOOTER ──────────────────────────────────────────────────────────── */
@media print {
    .page-footer { display: none; }
    @page { @bottom-center { content: counter(page); } }
}
.page-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8.5px;
    color: var(--text-light);
    padding: 8px 18mm;
    border-top: 1px solid var(--border-light);
}

/* ── COMPARISON INDICATOR ────────────────────────────────────────────── */
.cmp-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
}
.cmp-danger { background: var(--danger-bg); color: var(--danger); }
.cmp-warning { background: var(--warning-bg); color: var(--warning); }
.cmp-success { background: var(--success-bg); color: var(--success); }

/* ── LINKS ───────────────────────────────────────────────────────────── */
a { color: var(--brand-accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── GRID ────────────────────────────────────────────────────────────── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* ── HOLDING COST TIMELINE ───────────────────────────────────────────── */
.timeline-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.timeline-month {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--bg-alt);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
    color: var(--text-muted);
    flex-shrink: 0;
}
.timeline-bar {
    height: 10px;
    background: var(--danger);
    border-radius: 3px;
    opacity: 0.7;
}
.timeline-amount {
    font-size: 10px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
}
</style>
</head>
<body>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 1 — COVER
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="cover">
    <div class="cover-brand">Home Finders Coastal</div>
    <div class="cover-bar"></div>
    <h1>Market Analysis<br>&amp; Pricing Strategy</h1>
    <div style="height:24px"></div>
    <div class="cover-address"><?= $address ?></div>
    <div class="cover-details">
        <?= $suburbName ?>
        <?php if ($erfSize): ?>&nbsp;&middot;&nbsp;<?= number_format((int) $erfSize) ?> m²<?php endif ?>
        <?php if ($propType): ?>&nbsp;&middot;&nbsp;<?= $propType ?><?php endif ?>
        <?php if ($bedrooms): ?>&nbsp;&middot;&nbsp;<?= $bedrooms ?> Bedroom<?= $bedrooms > 1 ? 's' : '' ?><?php endif ?>
    </div>
    <?php if ($sellerName): ?>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">Prepared for <strong style="color:var(--text)"><?= $sellerName ?></strong></p>
    <?php endif ?>
    <div class="cover-meta">
        Prepared by <strong><?= $esc($agentName) ?></strong><br>
        Home Finders Coastal — Shelly Beach, KZN South Coast<br>
        <?php if ($agentEmail): ?><?= $esc($agentEmail) ?><br><?php endif ?>
        <?= $compiledAt ?>
    </div>
</div>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 2 — EXECUTIVE SUMMARY
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">1</span>
    <h2>Executive Summary</h2>
</div>

<p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
    A data-driven overview of your property's market position. This summary draws on
    <?= count($vicinitySales) + count($cmaComps) + count($streetSales) ?> comparable sales,
    <?= $activeCount ?> active listing<?= $activeCount !== 1 ? 's' : '' ?>,
    and current suburb statistics.
</p>

<?php // PROPERTY VALUE — CMA Band ?>
<?php if ($cmaLower || $cmaMiddle || $cmaUpper): ?>
<div class="avoid-break" style="margin-bottom:18px;">
    <h3 style="margin-bottom:8px;color:var(--brand);">Property Valuation (CMA)</h3>
    <div class="val-bar-container">
        <div class="val-bar">
            <div class="segment seg-lower"><?= $zar($cmaLower) ?></div>
            <div class="segment seg-middle"><?= $zar($cmaMiddle) ?></div>
            <div class="segment seg-upper"><?= $zar($cmaUpper) ?></div>
        </div>
        <div class="val-bar-labels">
            <span>Lower Range</span>
            <span>CMA Valuation</span>
            <span>Upper Range</span>
        </div>
    </div>
</div>
<?php endif ?>

<?php // ASKING PRICE vs CMA ?>
<?php if ($askingPrice): ?>
<div class="avoid-break metric-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="metric-card <?= $askVsCmaPct !== null && $askVsCmaPct > 10 ? 'danger' : ($askVsCmaPct !== null && $askVsCmaPct > 5 ? 'warning' : 'success') ?>">
        <div class="label">Your Asking Price</div>
        <div class="value"><?= $zar($askingPrice) ?></div>
        <?php if ($askVsCmaPct !== null): ?>
        <div class="sub"><?= $pct($askVsCmaPct) ?> vs CMA valuation</div>
        <?php endif ?>
    </div>
    <div class="metric-card highlight">
        <div class="label">CMA Valuation (<?= $esc(ucfirst($cma['selected_range'] ?? 'middle')) ?>)</div>
        <div class="value"><?= $zar($cma['selected_value'] ?? $cmaMiddle) ?></div>
        <div class="sub">Independent market assessment</div>
    </div>
</div>
<?php endif ?>

<?php // KEY METRICS ROW ?>
<div class="metric-grid" style="margin-top:14px;">
    <div class="metric-card">
        <div class="label">Suburb Median</div>
        <div class="value"><?= $zar($suburbMedian) ?></div>
        <div class="sub"><?= (int) $suburbSales ?> sales in <?= $esc((string) $suburbYear) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Active Competition</div>
        <div class="value"><?= $totalActiveStock ?></div>
        <div class="sub">listing<?= $totalActiveStock !== 1 ? 's' : '' ?> in area</div>
    </div>
    <div class="metric-card <?= $monthlyTotal > 20000 ? 'danger' : ($monthlyTotal > 10000 ? 'warning' : '') ?>">
        <div class="label">Monthly Holding Cost</div>
        <div class="value"><?= $zarFloat($monthlyTotal) ?></div>
        <div class="sub">12-month: <?= $zarFloat($projected12m) ?></div>
    </div>
</div>

<?php // ABSORPTION RATE ?>
<?php if ($monthsOfSupply !== null): ?>
<?php
    $absCalloutClass = match($absorptionColor) {
        'green' => 'callout-success', 'amber' => 'callout-warning',
        'orange' => 'callout-warning', 'red' => 'callout-danger',
        default => 'callout-info',
    };
?>
<div class="callout <?= $absCalloutClass ?>" style="margin-top:14px;">
    <strong>Market Absorption:</strong>
    <?= $totalActiveStock ?> active listing<?= $totalActiveStock !== 1 ? 's' : '' ?>
    | <?= (int) ($stock['annual_sales'] ?? 0) ?> sales/year (<?= $absorptionRate ?>/month)
    | <strong><?= $monthsOfSupply ?> months of supply</strong>
    — <?= $absorptionLabel ?>
</div>
<?php endif ?>

<?php // RECOMMENDATION PREVIEW ?>
<?php if ($cmaMiddle && $cmaUpper): ?>
<div class="callout callout-info" style="margin-top:10px;">
    <strong>Recommended Price Band:</strong> <?= $zar($cmaMiddle) ?> — <?= $zar($cmaUpper) ?>
    <br>Based on CMA valuation, recent sales data, and current market conditions.
</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 3 — MARKET OVERVIEW
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">2</span>
    <h2>Market Overview — <?= $suburbName ?></h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">
    <?= $suburbName ?> recorded <strong><?= (int) $suburbSales ?> residential sales</strong> in <?= $esc((string) $suburbYear) ?>
    at a median price of <strong><?= $zar($suburbMedian) ?></strong>.
</p>

<div class="avoid-break">
<h3 style="margin-bottom:8px;">Suburb Price Summary (<?= $esc((string) $suburbYear) ?>)</h3>
<table>
    <thead>
        <tr>
            <th>Metric</th>
            <th class="num">Value</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Total Residential Sales</td><td class="num"><strong><?= (int) $suburbSales ?></strong></td></tr>
        <tr><td>Median Sale Price</td><td class="num"><strong><?= $zar($suburbMedian) ?></strong></td></tr>
        <tr><td>Low Range</td><td class="num"><?= $zar($suburbLow) ?></td></tr>
        <tr><td>High Range</td><td class="num"><?= $zar($suburbHigh) ?></td></tr>
        <tr><td>Maximum Sale Price</td><td class="num"><?= $zar($suburbMax) ?></td></tr>
    </tbody>
</table>
</div>

<?php if ($askingPrice && $suburbMedian && $suburbMedian > 0): ?>
<?php $askVsMedianPct = round(($askingPrice - $suburbMedian) / $suburbMedian * 100, 1); ?>
<div class="callout <?= $askVsMedianPct > 50 ? 'callout-danger' : ($askVsMedianPct > 20 ? 'callout-warning' : 'callout-info') ?>" style="margin-top:14px;">
    <strong>Your asking price of <?= $zar($askingPrice) ?> is <?= $pct($askVsMedianPct) ?> <?= $askVsMedianPct > 0 ? 'above' : 'below' ?> the suburb median.</strong>
    <?php if ($askVsMedianPct > 50): ?>
    Properties priced significantly above the suburb median typically experience extended market times.
    <?php endif ?>
</div>
<?php endif ?>

<?php // Subject property context ?>
<?php if ($subject['erf'] || $subject['municipal_value'] || $subject['indexed_value']): ?>
<div class="avoid-break" style="margin-top:18px;">
<h3 style="margin-bottom:8px;">Subject Property Context</h3>
<table>
    <thead><tr><th>Detail</th><th class="num">Value</th></tr></thead>
    <tbody>
        <?php if ($subject['erf']): ?><tr><td>Erf Number</td><td class="num"><?= $esc($subject['erf']) ?></td></tr><?php endif ?>
        <?php if ($erfSize): ?><tr><td>Erf Extent</td><td class="num"><?= number_format((int) $erfSize) ?> m²</td></tr><?php endif ?>
        <?php if ($subject['purchase_date']): ?><tr><td>Purchase Date</td><td class="num"><?= $esc($subject['purchase_date']) ?></td></tr><?php endif ?>
        <?php if ($subject['purchase_price']): ?><tr><td>Purchase Price</td><td class="num"><?= $zar($subject['purchase_price']) ?></td></tr><?php endif ?>
        <?php if ($subject['indexed_value']): ?><tr><td>Indexed Value</td><td class="num"><?= $zar($subject['indexed_value']) ?></td></tr><?php endif ?>
        <?php if ($subject['cagr']): ?><tr><td>CAGR</td><td class="num"><?= number_format($subject['cagr'], 2) ?>%</td></tr><?php endif ?>
        <?php if ($subject['municipal_value']): ?><tr><td>Municipal Valuation<?php if ($subject['municipal_year']): ?> (<?= $esc($subject['municipal_year']) ?>)<?php endif ?></td><td class="num"><?= $zar($subject['municipal_value']) ?></td></tr><?php endif ?>
    </tbody>
</table>
</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 4 — RECENT SALES NEAR YOUR PROPERTY
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">3</span>
    <h2>Recent Sales Near Your Property</h2>
</div>

<?php
    // Combine vicinity + street sales, sort by date desc, take top 15
    $allSales = array_merge($vicinitySales, $streetSales);
    usort($allSales, function ($a, $b) {
        return strcmp($b['sale_date'] ?? '', $a['sale_date'] ?? '');
    });
    $topSales = array_slice($allSales, 0, 15);
?>

<?php if (!empty($topSales)): ?>
<p style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
    The <?= count($topSales) ?> most recent sales within the vicinity of your property,
    sorted by date (most recent first).
</p>

<table>
    <thead>
        <tr>
            <th>Address</th>
            <th>Dist.</th>
            <th class="num">Erf m²</th>
            <th>Sale Date</th>
            <th class="num">Sale Price</th>
            <th class="num">R/m²</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($topSales as $sale): ?>
        <tr>
            <td><?= $esc($sale['address'] ?? '—') ?></td>
            <td><?= $sale['distance_m'] ? $sale['distance_m'] . 'm' : '—' ?></td>
            <td class="num"><?= $sale['extent_m2'] ? number_format((int) $sale['extent_m2']) : '—' ?></td>
            <td><?= $esc($sale['sale_date'] ?? '—') ?></td>
            <td class="num"><?= $zar($sale['sale_price'] ?? null) ?></td>
            <td class="num"><?= $sale['price_per_m2'] ? 'R ' . number_format((int) $sale['price_per_m2']) : '—' ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
    <?php if ($vicAvgPrice || $vicAvgPpm2): ?>
    <tfoot>
        <tr class="table-summary">
            <td colspan="4"><strong>Average</strong></td>
            <td class="num"><?= $zar($vicAvgPrice) ?></td>
            <td class="num"><?= $vicAvgPpm2 ? 'R ' . number_format($vicAvgPpm2) : '—' ?></td>
        </tr>
    </tfoot>
    <?php endif ?>
</table>

<?php if ($vicAvgPrice && $askingPrice && $vicAvgPrice > 0): ?>
<?php $askVsVicPct = round(($askingPrice - $vicAvgPrice) / $vicAvgPrice * 100, 1); ?>
<div class="callout <?= $askVsVicPct > 30 ? 'callout-danger' : ($askVsVicPct > 10 ? 'callout-warning' : 'callout-info') ?>" style="margin-top:12px;">
    The average vicinity sale price is <strong><?= $zar($vicAvgPrice) ?></strong> (R <?= $vicAvgPpm2 ? number_format($vicAvgPpm2) . '/m²' : '—' ?>).
    Your asking price is <strong><?= $pct($askVsVicPct) ?></strong> <?= $askVsVicPct > 0 ? 'above' : 'below' ?> this average.
</div>
<?php endif ?>

<?php else: ?>
<div class="callout callout-info">No vicinity sales data available for this property.</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 5 — COMPARATIVE MARKET ANALYSIS
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">4</span>
    <h2>Comparative Market Analysis</h2>
</div>

<?php if ($cmaLower || $cmaMiddle || $cmaUpper): ?>
<h3 style="margin-bottom:10px;color:var(--brand);">CMA Valuation Range</h3>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Lower Range</div>
        <div class="value"><?= $zar($cmaLower) ?></div>
    </div>
    <div class="metric-card highlight">
        <div class="label">CMA Valuation</div>
        <div class="value"><?= $zar($cmaMiddle) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Upper Range</div>
        <div class="value"><?= $zar($cmaUpper) ?></div>
    </div>
</div>

<?php if ($subject['municipal_value']): ?>
<p style="font-size:11px;color:var(--text-muted);margin:8px 0;">
    Municipal Valuation<?php if ($subject['municipal_year']): ?> (<?= $esc($subject['municipal_year']) ?>)<?php endif ?>:
    <strong><?= $zar($subject['municipal_value']) ?></strong>
</p>
<?php endif ?>

<?php // Vicinity ranges if different from CMA ?>
<?php if ($cma['vicinity_lower'] || $cma['vicinity_middle'] || $cma['vicinity_upper']): ?>
<div class="avoid-break" style="margin-top:14px;">
<h3 style="margin-bottom:8px;">Vicinity Sales Range</h3>
<table>
    <thead><tr><th>Measure</th><th class="num">Value</th></tr></thead>
    <tbody>
        <tr><td>Lower Range</td><td class="num"><?= $zar($cma['vicinity_lower']) ?></td></tr>
        <tr><td>Middle Range</td><td class="num"><?= $zar($cma['vicinity_middle']) ?></td></tr>
        <tr><td>Upper Range</td><td class="num"><?= $zar($cma['vicinity_upper']) ?></td></tr>
        <?php if ($cma['vicinity_ppm2']): ?>
        <tr><td>Average R/m²</td><td class="num">R <?= number_format($cma['vicinity_ppm2']) ?></td></tr>
        <?php endif ?>
    </tbody>
</table>
</div>
<?php endif ?>
<?php endif ?>

<?php // CMA Comps table ?>
<?php if (!empty($cmaComps)): ?>
<div class="avoid-break" style="margin-top:18px;">
<h3 style="margin-bottom:8px;">CMA Comparable Properties (<?= count($cmaComps) ?>)</h3>
<table>
    <thead>
        <tr>
            <th>Address</th>
            <th>Dist.</th>
            <th class="num">Erf m²</th>
            <th>Sale Date</th>
            <th class="num">Sale Price</th>
            <th class="num">R/m²</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cmaComps as $comp): ?>
        <tr>
            <td><?= $esc($comp['address'] ?? '—') ?></td>
            <td><?= $comp['distance_m'] ? $comp['distance_m'] . 'm' : '—' ?></td>
            <td class="num"><?= $comp['extent_m2'] ? number_format((int) $comp['extent_m2']) : '—' ?></td>
            <td><?= $esc($comp['sale_date'] ?? '—') ?></td>
            <td class="num"><?= $zar($comp['sale_price'] ?? null) ?></td>
            <td class="num"><?= isset($comp['price_per_m2']) && $comp['price_per_m2'] ? 'R ' . number_format((int) $comp['price_per_m2']) : '—' ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
    <?php
        $cmaPrices = array_filter(array_column($cmaComps, 'sale_price'), fn($v) => $v > 0);
        $cmaPpm2   = array_filter(array_column($cmaComps, 'price_per_m2'), fn($v) => $v > 0);
        $cmaAvgP   = count($cmaPrices) > 0 ? (int) round(array_sum($cmaPrices) / count($cmaPrices)) : null;
        $cmaAvgPpm2 = count($cmaPpm2) > 0 ? (int) round(array_sum($cmaPpm2) / count($cmaPpm2)) : null;
    ?>
    <?php if ($cmaAvgP): ?>
    <tfoot>
        <tr class="table-summary">
            <td colspan="4"><strong>Average</strong></td>
            <td class="num"><?= $zar($cmaAvgP) ?></td>
            <td class="num"><?= $cmaAvgPpm2 ? 'R ' . number_format($cmaAvgPpm2) : '—' ?></td>
        </tr>
    </tfoot>
    <?php endif ?>
</table>
</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 6 — ACTIVE COMPETITION
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">5</span>
    <h2>Active Competition</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">
    These are the properties buyers are comparing yours against.
    There <?= $totalActiveStock === 1 ? 'is' : 'are' ?> currently <strong><?= $totalActiveStock ?> active listing<?= $totalActiveStock !== 1 ? 's' : '' ?></strong> in the area<?php if ($totalActiveStock > $activeCount && $activeCount > 0): ?> (<?= $activeCount ?> with detailed price data)<?php endif ?>.
    <?php if ($avgAskPrice): ?>Average asking price: <strong><?= $zar($avgAskPrice) ?></strong>.<?php endif ?>
</p>

<?php if (!empty($activeRows)): ?>
<table>
    <thead>
        <tr>
            <th>Address</th>
            <th>Type</th>
            <th class="num">Erf m²</th>
            <th>Listed</th>
            <th class="num">Asking Price</th>
            <th class="num">DOM</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($activeRows as $listing): ?>
        <?php if (!empty($listing['is_excluded'])) continue; ?>
        <tr>
            <td><?= $esc($listing['address'] ?? '—') ?></td>
            <td><?= $esc($listing['property_type'] ?? '—') ?></td>
            <td class="num"><?= isset($listing['extent_m2']) && $listing['extent_m2'] ? number_format((int) $listing['extent_m2']) : '—' ?></td>
            <td><?= $esc($listing['list_date'] ?? '—') ?></td>
            <td class="num"><?= $zar($listing['list_price'] ?? null) ?></td>
            <td class="num"><?= $listing['days_on_market'] ?? '—' ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
    <?php if ($avgAskPrice): ?>
    <tfoot>
        <tr class="table-summary">
            <td colspan="4"><strong>Average</strong></td>
            <td class="num"><?= $zar($avgAskPrice) ?></td>
            <td class="num"></td>
        </tr>
    </tfoot>
    <?php endif ?>
</table>
<?php else: ?>
<div class="callout callout-info">No active listing data available. Add Property24 links or portal captures to populate this section.</div>
<?php endif ?>

<?php // P24 Links ?>
<?php if ($p24Links->isNotEmpty()): ?>
<div class="avoid-break" style="margin-top:14px;">
<h3 style="margin-bottom:6px;font-size:11px;color:var(--text-muted);">Property24 Sources</h3>
<ul style="font-size:10px;list-style:none;padding:0;">
    <?php foreach ($p24Links as $link): ?>
    <li style="margin-bottom:4px;">
        <a href="<?= $esc($link->url) ?>" target="_blank"><?= $esc($link->url) ?></a>
    </li>
    <?php endforeach ?>
</ul>
</div>
<?php endif ?>

<?php if ($monthsOfSupply !== null): ?>
<?php
    $compAbsClass = match($absorptionColor) {
        'green' => 'callout-success', 'amber' => 'callout-warning',
        'orange' => 'callout-warning', 'red' => 'callout-danger',
        default => 'callout-info',
    };
?>
<div class="callout <?= $compAbsClass ?>" style="margin-top:14px;">
    <strong>Stock Absorption:</strong>
    <?= $totalActiveStock ?> active listing<?= $totalActiveStock !== 1 ? 's' : '' ?>
    | <?= (int) ($stock['annual_sales'] ?? 0) ?> sales/year (<?= number_format($absorptionRate ?? 0, 1) ?>/month)
    | <strong><?= number_format($monthsOfSupply, 1) ?> months of supply</strong>
    — <?= $absorptionLabel ?>
</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 7 — HOLDING COST ANALYSIS
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">6</span>
    <h2>Holding Cost Analysis</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">
    Every month on the market at the current asking price costs
    <strong style="color:var(--danger)"><?= $zarFloat($monthlyTotal) ?></strong>.
</p>

<?php if ($monthlyTotal > 0): ?>
<div class="two-col">
    <div class="avoid-break">
        <h3 style="margin-bottom:8px;">Monthly Breakdown</h3>
        <table>
            <thead><tr><th>Expense</th><th class="num">Monthly (ZAR)</th></tr></thead>
            <tbody>
                <?php foreach ($breakdown as $label => $amount): ?>
                <?php if ($amount > 0): ?>
                <tr>
                    <td><?= $esc($label) ?></td>
                    <td class="num"><?= $zarFloat($amount) ?></td>
                </tr>
                <?php endif ?>
                <?php endforeach ?>
            </tbody>
            <tfoot>
                <tr class="table-summary">
                    <td><strong>Monthly Total</strong></td>
                    <td class="num"><?= $zarFloat($monthlyTotal) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="avoid-break">
        <h3 style="margin-bottom:8px;">Cumulative Cost</h3>
        <table>
            <thead><tr><th>Period</th><th class="num">Total Cost (ZAR)</th></tr></thead>
            <tbody>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <tr<?= in_array($m, [6, 12]) ? ' style="font-weight:700"' : '' ?>>
                    <td>Month <?= $m ?></td>
                    <td class="num"><?= $zarFloat($monthlyTotal * $m) ?></td>
                </tr>
                <?php endfor ?>
            </tbody>
        </table>
    </div>
</div>

<div class="metric-grid" style="grid-template-columns: 1fr 1fr 1fr; margin-top:16px;">
    <div class="metric-card warning">
        <div class="label">At 3 Months</div>
        <div class="value"><?= $zarFloat($holding['projected_3m'] ?? $monthlyTotal * 3) ?></div>
    </div>
    <div class="metric-card danger">
        <div class="label">At 6 Months</div>
        <div class="value"><?= $zarFloat($projected6m) ?></div>
    </div>
    <div class="metric-card danger">
        <div class="label">At 12 Months</div>
        <div class="value"><?= $zarFloat($projected12m) ?></div>
    </div>
</div>

<div class="callout callout-danger" style="margin-top:14px;">
    <strong>The cost of waiting:</strong>
    If this property remains on the market for 12 months, the total holding cost
    will be <strong><?= $zarFloat($projected12m) ?></strong>.
    <?php if ($projected12m && $askingPrice && $askingPrice > 0): ?>
    That's <strong><?= number_format($projected12m / $askingPrice * 100, 1) ?>%</strong> of the asking price.
    <?php endif ?>
</div>

<?php else: ?>
<div class="callout callout-info">
    No holding cost data has been entered. Add monthly expenses on the presentation page to populate this section.
</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 8 — PRICING STRATEGY & RECOMMENDATION
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">7</span>
    <h2>Pricing Strategy &amp; Recommendation</h2>
</div>

<?php if ($cmaMiddle && $cmaUpper): ?>
<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:10px;color:var(--brand);">Recommended Price Band</h3>
<div class="metric-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="metric-card highlight">
        <div class="label">Recommended Range</div>
        <div class="value" style="font-size:17px;"><?= $zar($cmaMiddle) ?> — <?= $zar($cmaUpper) ?></div>
        <div class="sub">Based on CMA valuation + market conditions</div>
    </div>
    <?php if ($askingPrice): ?>
    <div class="metric-card <?= $askVsCmaPct !== null && $askVsCmaPct > 10 ? 'danger' : ($askVsCmaPct !== null && $askVsCmaPct > 5 ? 'warning' : 'success') ?>">
        <div class="label">Current Asking Price</div>
        <div class="value"><?= $zar($askingPrice) ?></div>
        <div class="sub"><?php if ($askVsCmaPct !== null): ?><?= $pct($askVsCmaPct) ?> vs CMA middle<?php endif ?></div>
    </div>
    <?php endif ?>
</div>
</div>
<?php endif ?>

<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:10px;">Why This Range?</h3>
<table>
    <thead><tr><th>Evidence Source</th><th class="num">Indicated Value</th><th>Status</th></tr></thead>
    <tbody>
        <?php if ($cmaMiddle): ?>
        <tr>
            <td>CMA Valuation (Middle)</td>
            <td class="num"><?= $zar($cmaMiddle) ?></td>
            <td><span class="cmp-badge cmp-success">Primary</span></td>
        </tr>
        <?php endif ?>
        <?php if ($vicAvgPrice): ?>
        <tr>
            <td>Vicinity Sales Average</td>
            <td class="num"><?= $zar($vicAvgPrice) ?></td>
            <td><span class="cmp-badge cmp-success">Supporting</span></td>
        </tr>
        <?php endif ?>
        <?php if ($suburbMedian): ?>
        <tr>
            <td>Suburb Median (<?= $esc((string) $suburbYear) ?>)</td>
            <td class="num"><?= $zar($suburbMedian) ?></td>
            <td><span class="cmp-badge cmp-success">Context</span></td>
        </tr>
        <?php endif ?>
        <?php if ($subject['municipal_value']): ?>
        <tr>
            <td>Municipal Valuation</td>
            <td class="num"><?= $zar($subject['municipal_value']) ?></td>
            <td><span class="cmp-badge cmp-warning">Reference</span></td>
        </tr>
        <?php endif ?>
        <?php if ($subject['indexed_value']): ?>
        <tr>
            <td>Indexed Value (CAGR <?= $subject['cagr'] ? number_format($subject['cagr'], 2) . '%' : '—' ?>)</td>
            <td class="num"><?= $zar($subject['indexed_value']) ?></td>
            <td><span class="cmp-badge cmp-warning">Reference</span></td>
        </tr>
        <?php endif ?>
    </tbody>
</table>
</div>

<?php // Key Insights from comparisons ?>
<?php if (!empty($insights['comparisons'])): ?>
<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:8px;">Price Position Analysis</h3>
<table>
    <thead><tr><th>Comparison</th><th class="num">Benchmark</th><th class="num">Asking</th><th class="num">Difference</th><th>Status</th></tr></thead>
    <tbody>
        <?php foreach ($insights['comparisons'] as $cmp): ?>
        <tr>
            <td><?= $esc($cmp['label']) ?></td>
            <td class="num"><?= $zar($cmp['benchmark']) ?></td>
            <td class="num"><?= $zar($cmp['asking']) ?></td>
            <td class="num"><?= $pct($cmp['pct_difference']) ?></td>
            <td><span class="cmp-badge cmp-<?= $cmp['status'] ?>"><?= ucfirst($cmp['status']) ?></span></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>

<?php // Source Reports ?>
<?php $allLinks = $presentation->links; ?>
<?php if ($allLinks->isNotEmpty()): ?>
<div class="avoid-break" style="margin-top:18px;">
<h3 style="margin-bottom:6px;">Source Reports &amp; References</h3>
<table>
    <thead><tr><th>Type</th><th>URL</th></tr></thead>
    <tbody>
        <?php foreach ($allLinks as $link): ?>
        <tr>
            <td><span class="cmp-badge" style="background:#eef2ff;color:var(--brand-accent)"><?= $esc(ucfirst(str_replace('_', ' ', $link->type))) ?></span></td>
            <td><a href="<?= $esc($link->url) ?>" target="_blank"><?= $esc($link->url) ?></a></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 9 — PRICING SCENARIOS (conditional — only if simulator saved with include_in_pdf)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php
    $simConfig = $presentation->simulator_config_json;
    if ($simConfig && !empty($simConfig['include_in_pdf']) && !empty($simConfig['scenarios'])):
        $simScenarios = $simConfig['scenarios'];
        $simCfg       = $simConfig['config'] ?? [];
        $simNarrative = $simConfig['narrative'] ?? '';
?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">8</span>
    <h2>Pricing Scenarios</h2>
</div>

<div class="avoid-break" style="margin-bottom:14px;">
<p style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">
    Commission: <?= number_format($simCfg['commission_pct'] ?? 5, 1) ?>%
    &middot; Transfer Cost: <?= number_format($simCfg['transfer_cost_pct'] ?? 4, 1) ?>%
    &middot; Monthly Holding Cost: <?= $zar((int)($simCfg['monthly_holding_cost'] ?? 0)) ?>
</p>

<table>
    <thead>
        <tr>
            <th>Scenario</th>
            <th class="num">Price</th>
            <th class="num">Competing</th>
            <th class="num">Est. Months</th>
            <th class="num">Holding Cost</th>
            <th class="num">Commission</th>
            <th class="num">Net Proceeds</th>
            <th class="num">vs Asking</th>
            <th>Probability</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($simScenarios as $sc): ?>
        <tr>
            <td><?= $esc($sc['label'] ?? '') ?></td>
            <td class="num"><?= $zar($sc['price'] ?? 0) ?></td>
            <td class="num"><?= $sc['competing_count'] ?? '—' ?></td>
            <td class="num"><?= $sc['est_months'] ?? '—' ?></td>
            <td class="num"><?= $zar($sc['holding_cost_total'] ?? 0) ?></td>
            <td class="num"><?= $zar($sc['commission'] ?? 0) ?></td>
            <td class="num" style="font-weight:700;"><?= $zar($sc['net_proceeds'] ?? 0) ?></td>
            <td class="num">
                <?php if (isset($sc['vs_asking_net'])): ?>
                    <?= ($sc['vs_asking_net'] >= 0 ? '+' : '') . $zar($sc['vs_asking_net']) ?>
                <?php else: ?>—<?php endif ?>
            </td>
            <td>
                <?php
                    $probLabel = $sc['probability'] ?? '';
                    $probStyle = match($probLabel) {
                        'Very Likely' => 'background:#d1fae5;color:#059669',
                        'Likely'      => 'background:#dcfce7;color:#16a34a',
                        'Possible'    => 'background:#fef3c7;color:#d97706',
                        'Unlikely'    => 'background:#fed7aa;color:#ea580c',
                        default       => 'background:#fecaca;color:#dc2626',
                    };
                ?>
                <span class="cmp-badge" style="<?= $probStyle ?>"><?= $esc($probLabel) ?></span>
            </td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
</div>

<?php // Bar chart (CSS only) ?>
<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:10px;">Net Proceeds Comparison</h3>
<?php
    $maxNetPdf = max(1, max(array_map(fn($s) => max((int)($s['net_proceeds'] ?? 0), 0), $simScenarios)));
    $barColorMap = [
        'Very Likely' => '#059669', 'Likely' => '#16a34a',
        'Possible'    => '#d97706', 'Unlikely' => '#ea580c', 'Very Unlikely' => '#dc2626',
    ];
?>
<?php foreach ($simScenarios as $sc): ?>
<?php
    $netVal = max((int)($sc['net_proceeds'] ?? 0), 0);
    $barW   = max(2, round($netVal / $maxNetPdf * 100));
    $barC   = $barColorMap[$sc['probability'] ?? ''] ?? '#dc2626';
?>
<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
    <div style="width:100px;text-align:right;font-size:10px;color:var(--text-muted);flex-shrink:0;"><?= $esc($sc['label'] ?? '') ?></div>
    <div style="flex:1;background:#f3f4f6;border-radius:999px;height:20px;overflow:hidden;">
        <div style="width:<?= $barW ?>%;height:100%;background:<?= $barC ?>;border-radius:999px;display:flex;align-items:center;padding:0 6px;">
            <span style="font-size:9px;color:#fff;font-weight:600;white-space:nowrap;"><?= $zar($sc['net_proceeds'] ?? 0) ?></span>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>

<?php if ($simNarrative): ?>
<div class="avoid-break" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:18px;">
    <h3 style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:var(--brand);margin-bottom:6px;">Key Insight</h3>
    <p style="font-size:12px;color:var(--text);line-height:1.6;"><?= $esc($simNarrative) ?></p>
</div>
<?php endif ?>

<?php endif // end simulator page ?>

<div style="margin-top:24px;padding:20px;background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;text-align:center;">
    <p style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:6px;">
        Ready to discuss your pricing strategy?
    </p>
    <p style="font-size:12px;color:var(--text-muted);">
        <strong><?= $esc($agentName) ?></strong> &middot; Home Finders Coastal<br>
        <?php if ($agentEmail): ?><?= $esc($agentEmail) ?><br><?php endif ?>
        Shelly Beach, KZN South Coast
    </p>
</div>

<div style="margin-top:30px;text-align:center;font-size:8.5px;color:var(--text-light);border-top:1px solid var(--border-light);padding-top:12px;">
    Prepared by <?= $esc($agentName) ?> &middot; Home Finders Coastal &middot; <?= $compiledAt ?>
    &middot; Version #<?= $version->id ?>
    <br>
    This report is based on publicly available data and independent CMA valuation.
    All values are in South African Rand (ZAR). Data sources include CMA Info and Property24.
</div>

</body>
</html>
<?php
        return (string) ob_get_clean();
    }
}
