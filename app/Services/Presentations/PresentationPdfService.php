<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Support\Presentations\CompLabel;
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
            'fields', 'soldComps', 'activeListings', 'links', 'articles',
        ])->findOrFail($version->presentation_id);

        // Get the agent who created this presentation
        $agent = \App\Models\User::find($presentation->created_by_user_id);
        $agentName = $agent->name ?? 'Agent';
        $agentEmail = $agent->email ?? '';
        $agentPhone = $agent->cell ?? $agent->phone ?? '';
        $agentDesignation = $agent->designation ?? 'Property Practitioner';
        $agentPhotoPath = null;
        if ($agent && $agent->agent_photo_path && file_exists(storage_path('app/public/' . $agent->agent_photo_path))) {
            $agentPhotoPath = 'data:image/' . pathinfo($agent->agent_photo_path, PATHINFO_EXTENSION) . ';base64,'
                . base64_encode(file_get_contents(storage_path('app/public/' . $agent->agent_photo_path)));
        }

        $logoBase64 = null;
        $agency = $agent ? ($agent->agency ?? \App\Models\Agency::first()) : \App\Models\Agency::first();
        if ($agency && $agency->logo_path) {
            $logoFile = storage_path('app/public/' . $agency->logo_path);
            if (file_exists($logoFile)) {
                $ext = pathinfo($logoFile, PATHINFO_EXTENSION);
                $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/' . $ext;
                $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
            }
        }

        // Compile analysis data from AnalysisDataService (real extracted data).
        // Build 3 — pass the LATEST PUBLISHED version so the condition snapshot
        // travels with the PDF. If there's no published version yet (rare; the
        // public/show flow uses the same path before publish), the live
        // resolution falls back to property condition.
        $latestPublished = $presentation->versions()
            ->where('review_status', \App\Models\PresentationVersion::REVIEW_PUBLISHED)
            ->orderByDesc('published_at')
            ->first();
        $data = (new AnalysisDataService())->compile($presentation, $latestPublished);

        // Build 4 — section toggles. Each PAGE block is wrapped with
        // a $sectionEnabled('key') guard below; floor sections always
        // return true so the report never renders without a cover or
        // subject facts. Reading off the version (not a fresh lookup)
        // is intentional — published versions honour their snapshot.
        $sectionEnabled = function (string $key) use ($version): bool {
            return $version->isSectionEnabled($key);
        };

        $subject     = $data['subject_property']   ?? [];
        $suburb      = $data['suburb_overview']     ?? [];
        $comps       = $data['comparable_sales']    ?? [];
        $cma         = $data['cma_valuation']       ?? [];
        $competition = $data['active_competition']  ?? [];
        $stock       = $data['stock_absorption']    ?? [];
        $inflow      = $data['inflow_absorption']   ?? [];
        $propcon     = $data['propcon_insights']    ?? [];
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
        $propType    = $esc(\Illuminate\Support\Str::humanType($presentation->property_type ?? ''));
        // Build 7 — sectional check reads title_type (keystone single
        // source of truth). The compile()'s is_sectional flag also now
        // reads title_type, so the two sources are consistent.
        $isSectional = ($presentation->property?->title_type === 'sectional_title')
            || ($data['is_sectional'] ?? false);
        $sizeLabel   = $isSectional ? 'Unit m²' : 'Erf m²';
        $bedrooms    = $presentation->bedrooms;
        $bathrooms   = null; // not on model currently
        // Build 7 — sectional subjects don't have an erf size, they have
        // a floor area. Read the right column per title_type so the
        // "Subject Property Context" table is honest.
        if ($isSectional) {
            $erfSize     = $presentation->floor_area_m2 ?? $subject['extent_m2'] ?? null;
            $sizeRowLbl  = 'Floor Area';
        } else {
            $erfSize     = $subject['extent_m2'] ?? $presentation->erf_size_m2;
            $sizeRowLbl  = 'Erf Extent';
        }
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

        // Price position & brackets
        $pricePosition = $data['price_position'] ?? [];
        $priceBrackets = $data['price_brackets'] ?? [];

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
    display: flex;
    align-items: center;
    letter-spacing: 0.12em;
    color: var(--brand);
    margin-bottom: 28px;
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
    margin-top: auto;
    padding-top: 20px;
}
.cover-agent-row {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    border-top: 2px solid var(--brand);
    padding-top: 20px;
}
.cover-agent-info {
    flex: 1;
}
.cover-agent-info .agent-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--brand);
    margin-bottom: 4px;
}
.cover-agent-info .agent-company {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 2px;
}
.cover-agent-info .agent-contact {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.7;
}
.cover-agent-photo {
    width: 140px;
    height: 170px;
    object-fit: cover;
    border-radius: 8px;
    border: 3px solid var(--brand);
    margin-left: 24px;
    flex-shrink: 0;
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

/* ── CHART: PRICE POSITION NUMBER LINE ───────────────────────────────── */
.price-line { position: relative; height: 50px; margin: 16px 0 30px; }
.price-line-track { position: absolute; top: 18px; left: 0; right: 0; height: 14px; border-radius: 7px; overflow: hidden; }
.price-line-zone { position: absolute; top: 0; height: 100%; }
.price-line-marker {
    position: absolute; top: 0; transform: translateX(-50%);
    display: flex; flex-direction: column; align-items: center;
}
.price-line-marker .dot {
    width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3); z-index: 2;
}
.price-line-marker .marker-label {
    font-size: 8px; font-weight: 700; white-space: nowrap; margin-top: 2px;
    padding: 1px 4px; border-radius: 3px; background: #fff;
}
.price-line-marker .marker-value {
    font-size: 7.5px; color: var(--text-muted); white-space: nowrap;
}

/* ── CHART: ABSORPTION GAUGE ──────────────────────────────────────────── */
.gauge-container { position: relative; width: 240px; margin: 12px auto; }
.gauge-bar { height: 22px; border-radius: 11px; overflow: hidden; display: flex; }
.gauge-seg { height: 100%; }
.gauge-pointer {
    position: absolute; top: -4px; transform: translateX(-50%);
    width: 4px; height: 30px; background: var(--brand); border-radius: 2px;
    box-shadow: 0 0 4px rgba(0,0,0,0.3);
}
.gauge-labels { display: flex; justify-content: space-between; font-size: 8px; color: var(--text-muted); margin-top: 4px; }

/* ── CHART: SALE PRICE TIMELINE ──────────────────────────────────────── */
.sale-timeline { position: relative; height: 140px; margin: 14px 0; border-left: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.sale-timeline-dot {
    position: absolute; width: 8px; height: 8px; border-radius: 50%;
    background: var(--brand); border: 1.5px solid #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2); transform: translate(-50%, 50%);
}
.sale-timeline-line { position: absolute; left: 0; right: 0; border-top: 2px dashed var(--danger); opacity: 0.5; }
.sale-timeline-axis { display: flex; justify-content: space-between; font-size: 8px; color: var(--text-muted); margin-top: 4px; padding-left: 1px; }
.sale-timeline-yaxis { position: absolute; left: -2px; font-size: 7.5px; color: var(--text-light); transform: translateY(50%); text-align: right; }

/* ── CHART: VERTICAL BAR CHART ───────────────────────────────────────── */
.bar-chart { display: flex; align-items: flex-end; gap: 3px; height: 100px; margin: 12px 0; padding: 0 4px; }
.bar-col {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
    min-width: 20px;
}
.bar-col .bar {
    width: 100%; border-radius: 3px 3px 0 0; min-height: 2px;
    transition: height 0.2s;
}
.bar-col .bar-label { font-size: 7px; color: var(--text-muted); margin-top: 3px; text-align: center; white-space: nowrap; }
.bar-col .bar-count { font-size: 8px; font-weight: 700; color: var(--text); margin-bottom: 2px; }

/* ── CHART: HORIZONTAL BRACKET BARS ──────────────────────────────────── */
.hbar-row { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.hbar-label { width: 100px; text-align: right; font-size: 9px; color: var(--text-muted); flex-shrink: 0; }
.hbar-track { flex: 1; background: #f1f5f9; border-radius: 999px; height: 18px; overflow: hidden; position: relative; }
.hbar-fill { height: 100%; border-radius: 999px; display: flex; align-items: center; padding: 0 6px; }
.hbar-fill span { font-size: 8px; color: #fff; font-weight: 700; }
.hbar-count { width: 28px; text-align: right; font-size: 10px; font-weight: 600; flex-shrink: 0; }
</style>
</head>
<body>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 1 — COVER
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="cover">
    <?php if ($logoBase64): ?>
    <div class="cover-brand"><img src="<?= $logoBase64 ?>" alt="Home Finders Coastal" style="max-height:120px;width:auto;"></div>
    <?php else: ?>
    <div class="cover-brand">Home Finders Coastal</div>
    <?php endif ?>
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
        <div class="cover-agent-row">
            <div class="cover-agent-info">
                <div class="agent-name"><?= $esc($agentName) ?></div>
                <div class="agent-company">Home Finders Coastal — Shelly Beach, KZN South Coast</div>
                <div class="agent-contact">
                    <?php if ($agentEmail): ?><?= $esc($agentEmail) ?><br><?php endif ?>
                    <?php if (!empty($agentPhone)): ?><?= $esc($agentPhone) ?><br><?php endif ?>
                    <?= $esc($agentDesignation) ?><br>
                    <?= $compiledAt ?>
                </div>
            </div>
            <?php if ($agentPhotoPath): ?>
            <img class="cover-agent-photo" src="<?= $agentPhotoPath ?>" alt="<?= $esc($agentName) ?>">
            <?php endif ?>
        </div>
    </div>
    <div style="position:absolute;bottom:40px;left:0;right:0;text-align:center;font-size:9px;color:#888;">Registered with the PPRA</div>
</div>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 1b — SUBJECT FACTS CARD (Build 1 / BUG-5)
      // Data path: AnalysisDataService::compileSubjectProperty produces the
      // dict consumed below. Card is positioned BEFORE the PAGE 2 page-break
      // so it sits on page 1 immediately after the cover hero.
      // POPIA: owner is intentionally omitted at the model level — this
      // builder does not include any owner-PII surface today; if a future
      // version introduces an owner facet it must replicate the §95c05b09
      // map-side gate (server-side suppress, never CSS hide).
      // ══════════════════════════════════════════════════════════════════════ ?>
<div style="margin-top:18px;padding:18px;border:1px solid #0b2a4a;border-radius:3px;background:#f8fafc;page-break-inside:avoid;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
        <div style="width:4px;height:18px;background:#00d4aa;"></div>
        <h3 style="margin:0;font-size:13px;font-weight:700;color:#0b2a4a;letter-spacing:0.04em;text-transform:uppercase;">Subject Property — At a Glance</h3>
    </div>
    <?php
        $_facts = [];
        $_addLine = function (string $label, $value) use (&$_facts) {
            if ($value === null || $value === '' || $value === '—') return;
            $_facts[] = ['label' => $label, 'value' => $value];
        };

        // Address — full line, separate from suburb.
        $_addLine('Address',         $subject['address']         ?? null);
        $_addLine('Suburb',          $subject['suburb']          ?? null);
        $_addLine('Erf number',      $subject['erf']             ?? null);

        // Extent / type / category — humanType'd display.
        if (!empty($subject['extent_m2'])) {
            $_addLine('Extent', number_format((int) $subject['extent_m2']) . ' m²');
        }
        if (!empty($subject['property_type'])) {
            $_addLine('Type', \Illuminate\Support\Str::humanType($subject['property_type']));
        }
        if (!empty($presentation->property?->category)) {
            // Build 7 — render the agency's configured label (proper-
            // case) instead of the raw lowercase column.
            $_categoryLabel = app(\App\Services\TitleTypeClassifier::class)
                ->displayCategoryLabel((int) $presentation->agency_id, $presentation->property->category)
                ?? $presentation->property->category;
            $_addLine('Category', $_categoryLabel);
        }

        // GPS (only render when both lat and lng are present).
        if (!empty($subject['gps'])) {
            $_addLine('GPS', $subject['gps']);
        }

        // Municipal valuation + year.
        if (!empty($subject['municipal_value'])) {
            $munLabel = 'Municipal valuation';
            $_munText = 'R ' . number_format((int) $subject['municipal_value'], 0, '.', ' ');
            if (!empty($subject['municipal_year'])) {
                $_munText .= ' (' . $subject['municipal_year'] . ')';
            }
            $_addLine($munLabel, $_munText);
        }

        // Last sale (purchase_date + purchase_price together when both present).
        if (!empty($subject['purchase_date']) && !empty($subject['purchase_price'])) {
            $_addLine('Last sale',
                'R ' . number_format((int) $subject['purchase_price'], 0, '.', ' ')
                . ' (' . $subject['purchase_date'] . ')');
        } elseif (!empty($subject['purchase_price'])) {
            $_addLine('Last sale', 'R ' . number_format((int) $subject['purchase_price'], 0, '.', ' '));
        }

        // Title deed (if the property carries one) and mandate / listing type.
        if (!empty($presentation->property?->deed_number)) {
            $_addLine('Title deed', $presentation->property->deed_number);
        }
        if (!empty($presentation->property?->listing_type)) {
            $_addLine('Sale type', \Illuminate\Support\Str::humanType($presentation->property->listing_type));
        }
    ?>
    <?php if (empty($_facts)): ?>
        <p style="margin:0;font-size:11px;color:#64748b;">Source property record carries no enriched fact data yet. Populate the property record to surface this card.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:11.5px;color:#0f172a;">
            <?php $_n = count($_facts); foreach (array_chunk($_facts, 2) as $_row): ?>
                <tr>
                    <?php foreach ($_row as $_f): ?>
                        <td style="padding:5px 12px 5px 0;width:18%;color:#64748b;font-weight:600;vertical-align:top;white-space:nowrap;"><?= $esc($_f['label']) ?></td>
                        <td style="padding:5px 24px 5px 0;width:32%;color:#0f172a;vertical-align:top;"><?= $esc($_f['value']) ?></td>
                    <?php endforeach ?>
                    <?php if (count($_row) === 1): ?>
                        <td style="padding:5px 12px 5px 0;width:18%;"></td>
                        <td style="padding:5px 24px 5px 0;width:32%;"></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        </table>
    <?php endif ?>
</div>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 2 — EXECUTIVE SUMMARY
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">1</span>
    <h2>Executive Summary</h2>
</div>

<?php // Phase 3 — AI-generated executive summary lives on the version snapshot.
      // Falls back to the static blurb when no AI summary present (legacy versions). ?>
<?php if (!empty($version->ai_summary_text)): ?>
    <div style="font-size:12px;line-height:1.6;color:var(--text-primary);margin-bottom:16px;white-space:pre-wrap;"><?= e($version->ai_summary_text) ?></div>
<?php else: ?>
<p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
    A data-driven overview of your property's market position. This summary draws on
    <?= count($vicinitySales) + count($cmaComps) + count($streetSales) ?> comparable sales,
    <?= $activeCount ?> active listing<?= $activeCount !== 1 ? 's' : '' ?>,
    and current suburb statistics.
</p>
<?php endif ?>

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

<?php // CHART 1: Price Position Number Line ?>
<?php if ($cmaLower && $askingPrice && $askingPrice > 0): ?>
<?php
    // Build markers for the number line
    $lineMarkers = [];
    if ($cmaLower)    $lineMarkers[] = ['val' => $cmaLower,    'label' => 'CMA Low',    'color' => '#64748b'];
    if ($cmaMiddle)   $lineMarkers[] = ['val' => $cmaMiddle,   'label' => 'CMA Mid',    'color' => 'var(--brand)'];
    if ($cmaUpper)    $lineMarkers[] = ['val' => $cmaUpper,    'label' => 'CMA High',   'color' => 'var(--brand-light)'];
    if ($suburbMedian) $lineMarkers[] = ['val' => $suburbMedian, 'label' => 'Suburb Med', 'color' => '#6366f1'];
    $lineMarkers[] = ['val' => $askingPrice, 'label' => 'Asking', 'color' => ($askVsCmaPct !== null && $askVsCmaPct > 10) ? 'var(--danger)' : 'var(--success)'];

    $lineMin = min(array_column($lineMarkers, 'val')) * 0.9;
    $lineMax = max(array_column($lineMarkers, 'val')) * 1.05;
    $lineRange = $lineMax - $lineMin;
    if ($lineRange <= 0) $lineRange = 1;
    $pctOf = function($v) use ($lineMin, $lineRange) { return max(2, min(98, round(($v - $lineMin) / $lineRange * 100))); };

    // CMA green zone
    $cmaZoneLeft = $pctOf($cmaLower);
    $cmaZoneWidth = $pctOf($cmaUpper ?? $cmaLower) - $cmaZoneLeft;
?>
<div class="avoid-break" style="margin-bottom:14px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Price Position Overview</p>
    <div class="price-line">
        <div class="price-line-track" style="background:#f1f5f9;">
            <div class="price-line-zone" style="left:<?= $cmaZoneLeft ?>%;width:<?= max(1,$cmaZoneWidth) ?>%;background:rgba(5,150,105,0.15);"></div>
            <?php if ($cmaUpper && $askingPrice > $cmaUpper): ?>
            <div class="price-line-zone" style="left:<?= $pctOf($cmaUpper) ?>%;width:<?= $pctOf($askingPrice) - $pctOf($cmaUpper) ?>%;background:rgba(220,38,38,0.1);"></div>
            <?php endif ?>
        </div>
        <?php foreach ($lineMarkers as $mk): ?>
        <div class="price-line-marker" style="left:<?= $pctOf($mk['val']) ?>%;">
            <div class="dot" style="background:<?= $mk['color'] ?>;"></div>
            <div class="marker-label" style="color:<?= $mk['color'] ?>;"><?= $mk['label'] ?></div>
            <div class="marker-value"><?= $zar($mk['val']) ?></div>
        </div>
        <?php endforeach ?>
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
        <div class="sub"><?= $pct($askVsCmaPct) ?> vs CMA evaluation</div>
        <?php endif ?>
    </div>
    <div class="metric-card highlight">
        <div class="label">CMA Evaluation (<?= $esc(ucfirst($cma['selected_range'] ?? 'middle')) ?>)</div>
        <div class="value"><?= $zar($cma['selected_value'] ?? $cmaMiddle) ?></div>
        <?php if (!empty($cma['condition_applied'])): ?>
            <?php // Build 3 — defensibility footer. The seller sees that the
                 // headline number is condition-adjusted, not opaque. ?>
            <div class="sub">
                Reflects <?= $esc((string) ($cma['condition_label'] ?? '')) ?> condition
                (<?= ((float)$cma['condition_pct'] >= 0 ? '+' : '') . (float) $cma['condition_pct'] ?>%).
                Baseline: <?= $zar($cma['cma_middle_baseline'] ?? null) ?>.
            </div>
        <?php else: ?>
            <div class="sub">Independent market assessment</div>
        <?php endif ?>
    </div>
</div>
<?php endif ?>

<?php // KEY METRICS ROW ?>
<div class="metric-grid" style="margin-top:14px;grid-template-columns:repeat(4,1fr);">
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
    <?php if (!empty($pricePosition['has_data'])): ?>
    <div class="metric-card <?= ($pricePosition['position_color'] ?? '') === 'red' ? 'danger' : (($pricePosition['position_color'] ?? '') === 'orange' ? 'warning' : '') ?>">
        <div class="label">Price Rank</div>
        <div class="value">#<?= $pricePosition['price_rank'] ?> <span style="font-size:13px;font-weight:400;">of <?= $pricePosition['total_listings'] ?></span></div>
        <div class="sub"><?= $pricePosition['price_percentile'] ?>th percentile</div>
    </div>
    <?php else: ?>
    <div class="metric-card">
        <div class="label">Price Rank</div>
        <div class="value">—</div>
        <div class="sub">Needs price data</div>
    </div>
    <?php endif ?>
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
      // PAGE 3 — MARKET OVERVIEW  (Build 4 toggleable)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('market_overview')): ?>
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

<?php // CHART 2: Absorption Rate Gauge ?>
<?php if ($monthsOfSupply !== null): ?>
<?php
    $gaugeMax = 24; // cap gauge at 24 months
    $gaugeVal = min($monthsOfSupply, $gaugeMax);
    $gaugePct = round($gaugeVal / $gaugeMax * 100);
?>
<div class="avoid-break" style="margin-top:16px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:8px;">Market Absorption Gauge</p>
    <div class="gauge-container" style="width:100%;">
        <div class="gauge-bar">
            <div class="gauge-seg" style="width:12.5%;background:#059669;"></div>
            <div class="gauge-seg" style="width:12.5%;background:#16a34a;"></div>
            <div class="gauge-seg" style="width:12.5%;background:#d97706;"></div>
            <div class="gauge-seg" style="width:12.5%;background:#ea580c;"></div>
            <div class="gauge-seg" style="width:50%;background:#dc2626;"></div>
        </div>
        <div class="gauge-pointer" style="left:<?= $gaugePct ?>%;"></div>
        <div class="gauge-labels">
            <span>0</span><span>3 mo</span><span>6 mo</span><span>12 mo</span><span>24+ mo</span>
        </div>
        <div style="text-align:center;margin-top:8px;">
            <span style="font-size:16px;font-weight:800;color:var(--brand);"><?= number_format($monthsOfSupply, 1) ?></span>
            <span style="font-size:10px;color:var(--text-muted);"> months of supply</span>
        </div>
    </div>
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
        <?php if ($erfSize): ?><tr><td><?= $esc($sizeRowLbl) ?></td><td class="num"><?= number_format((int) $erfSize) ?> m²</td></tr><?php endif ?>
        <?php if ($subject['purchase_date']): ?><tr><td>Purchase Date</td><td class="num"><?= $esc($subject['purchase_date']) ?></td></tr><?php endif ?>
        <?php if ($subject['purchase_price']): ?><tr><td>Purchase Price</td><td class="num"><?= $zar($subject['purchase_price']) ?></td></tr><?php endif ?>
        <?php if ($subject['indexed_value']): ?><tr><td>Indexed Value</td><td class="num"><?= $zar($subject['indexed_value']) ?></td></tr><?php endif ?>
        <?php if ($subject['cagr']): ?><tr><td>CAGR</td><td class="num"><?= number_format($subject['cagr'], 2) ?>%</td></tr><?php endif ?>
        <?php if ($subject['municipal_value']): ?><tr><td>Municipal Evaluation<?php if ($subject['municipal_year']): ?> (<?= $esc($subject['municipal_year']) ?>)<?php endif ?></td><td class="num"><?= $zar($subject['municipal_value']) ?></td></tr><?php endif ?>
    </tbody>
</table>
</div>
<?php endif ?>

<?php endif // /market_overview ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 4 — RECENT SALES NEAR YOUR PROPERTY  (Build 4 toggleable)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('recent_sales')): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">3</span>
    <h2>Recent Sales Near Your Property</h2>
</div>

<?php
    // Combine vicinity + street sales, dedup, exclude subject property, sort by date desc
    $allSales = array_merge($vicinitySales, $streetSales);

    // 1. Exclude subject property (case-insensitive address match)
    $subjectAddr = strtolower(trim($address ?? ''));
    if ($subjectAddr !== '' && $subjectAddr !== '—') {
        $allSales = array_filter($allSales, function ($sale) use ($subjectAddr) {
            $saleAddr = strtolower(trim($sale['address'] ?? ''));
            return $saleAddr === '' || !str_contains($saleAddr, $subjectAddr);
        });
    }

    // 2. Dedup: same address + sale_date + sale_price = same row (keep most data-rich)
    $seen = [];
    $dedupSales = [];
    foreach ($allSales as $sale) {
        $addr = strtolower(trim($sale['address'] ?? ''));
        $dedupKey = $addr . '|' . ($sale['sale_date'] ?? '') . '|' . (int) ($sale['sale_price'] ?? 0);
        if ($addr !== '' && isset($seen[$dedupKey])) {
            continue; // skip duplicate
        }
        if ($addr !== '') {
            $seen[$dedupKey] = true;
        }
        $dedupSales[] = $sale;
    }
    $allSales = $dedupSales;

    // 3. Sort by date desc, take top 15
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
            <th class="num"><?= $esc($sizeLabel) ?></th>
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

<?php // CHART 3: Sale Price Timeline ?>
<?php
    $chartSales = array_filter($topSales, fn($s) => !empty($s['sale_date']) && !empty($s['sale_price']) && $s['sale_price'] > 0);
    if (count($chartSales) >= 3):
        usort($chartSales, fn($a, $b) => strcmp($a['sale_date'], $b['sale_date']));
        $cPrices = array_column($chartSales, 'sale_price');
        $cMinP = min($cPrices) * 0.85;
        $cMaxP = max(max($cPrices), $askingPrice ?? 0) * 1.05;
        $cRangeP = $cMaxP - $cMinP;
        if ($cRangeP <= 0) $cRangeP = 1;
        $cDates = array_column($chartSales, 'sale_date');
        $cMinD = strtotime(min($cDates));
        $cMaxD = strtotime(max($cDates));
        $cRangeD = $cMaxD - $cMinD;
        if ($cRangeD <= 0) $cRangeD = 1;
?>
<div class="avoid-break" style="margin-top:16px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Sale Prices Over Time</p>
    <div class="sale-timeline">
        <?php // Y-axis labels ?>
        <div class="sale-timeline-yaxis" style="bottom:0;left:-4px;"><?= $zar((int) $cMinP) ?></div>
        <div class="sale-timeline-yaxis" style="top:0;left:-4px;"><?= $zar((int) $cMaxP) ?></div>
        <?php // Asking price reference line ?>
        <?php if ($askingPrice && $askingPrice > 0): ?>
        <?php $askY = 100 - round(($askingPrice - $cMinP) / $cRangeP * 100); ?>
        <div class="sale-timeline-line" style="top:<?= max(2, min(98, $askY)) ?>%;"></div>
        <div style="position:absolute;right:2px;top:<?= max(2, min(92, $askY)) ?>%;font-size:8px;color:var(--danger);font-weight:600;">Asking</div>
        <?php endif ?>
        <?php // Data dots ?>
        <?php foreach ($chartSales as $cs): ?>
        <?php
            $dotX = round((strtotime($cs['sale_date']) - $cMinD) / $cRangeD * 96) + 2;
            $dotY = 100 - round(($cs['sale_price'] - $cMinP) / $cRangeP * 90) - 5;
        ?>
        <div class="sale-timeline-dot" style="left:<?= $dotX ?>%;bottom:<?= max(2, min(92, 100 - $dotY)) ?>%;" title="<?= $esc($cs['sale_date'] ?? '') ?>: <?= $zar($cs['sale_price']) ?>"></div>
        <?php endforeach ?>
    </div>
    <div class="sale-timeline-axis">
        <span><?= $esc(min($cDates)) ?></span>
        <span><?= $esc(max($cDates)) ?></span>
    </div>
</div>
<?php endif ?>

<?php else: ?>
<div class="callout callout-info">No vicinity sales data available for this property.</div>
<?php endif ?>
<?php endif // /recent_sales ?>

<?php // Phase 3g V2 Part D4/D5 — Spatial View SVG. Only renders when the
      // subject property has resolved GPS + at least one comp with GPS.
      // Build 4 — also gated by section toggle. ?>
<?php if ($sectionEnabled('spatial_view')): ?>
<?php
    $_propertyForMap = $presentation->property_id ? \App\Models\Property::withoutGlobalScopes()->find($presentation->property_id) : null;
    $_subjLat = $_propertyForMap?->latitude;
    $_subjLng = $_propertyForMap?->longitude;
    $_svgComps = [];
    if ($_subjLat !== null && $_subjLng !== null) {
        foreach ($presentation->soldComps as $_sc) {
            $_raw = is_string($_sc->raw_row_json) ? (json_decode($_sc->raw_row_json, true) ?: []) : ((array) $_sc->raw_row_json ?: []);
            $_lat = $_raw['latitude'] ?? null;
            $_lng = $_raw['longitude'] ?? null;
            $_compRowId = $_raw['mic_comp_row_id'] ?? null;
            $_schemeName = $_raw['scheme_name'] ?? null;
            if (($_lat === null || $_lng === null) && $_compRowId) {
                $_gps = \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
                    ->where('id', $_compRowId)
                    ->first(['latitude', 'longitude', 'scheme_name']);
                if ($_gps) {
                    if ($_gps->latitude !== null && $_gps->longitude !== null) {
                        $_lat = (float) $_gps->latitude; $_lng = (float) $_gps->longitude;
                    }
                    $_schemeName = $_schemeName ?: $_gps->scheme_name;
                }
            }
            // Scheme-name fallback — inherit from any matching subject report.
            if (($_lat === null || $_lng === null) && $_schemeName) {
                $_mr = \Illuminate\Support\Facades\DB::table('market_reports')
                    ->whereRaw('LOWER(subject_scheme_name) = ?', [mb_strtolower($_schemeName)])
                    ->whereNotNull('subject_latitude')->whereNotNull('subject_longitude')
                    ->orderByDesc('id')
                    ->first(['subject_latitude', 'subject_longitude']);
                if ($_mr) { $_lat = (float) $_mr->subject_latitude; $_lng = (float) $_mr->subject_longitude; }
            }
            if ($_lat === null || $_lng === null) continue;
            $_svgComps[] = [
                'lat'       => (float) $_lat,
                'lng'       => (float) $_lng,
                // Never-blank label — same chain as the review screen
                // / PDF table render. Sectional comps without street
                // address resolve to "Scheme, Section N" instead of
                // empty tooltips.
                'title'     => CompLabel::build($_raw, $_sc->suburb ?? null, $_sc->id ?? null),
                'layer'     => 'sold_comps',
                'price'     => $_sc->sold_price_inc ? (int) $_sc->sold_price_inc : null,
                'sale_date' => $_sc->sold_date ? $_sc->sold_date->toDateString() : null,
            ];
        }
        foreach ($presentation->activeListings as $_al) {
            $_raw = is_string($_al->raw_row_json) ? (json_decode($_al->raw_row_json, true) ?: []) : ((array) $_al->raw_row_json ?: []);
            $_lat = $_raw['latitude'] ?? null;
            $_lng = $_raw['longitude'] ?? null;
            $_compRowId = $_raw['mic_comp_row_id'] ?? null;
            $_schemeName = $_raw['scheme_name'] ?? null;
            if (($_lat === null || $_lng === null) && $_compRowId) {
                $_gps = \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
                    ->where('id', $_compRowId)
                    ->first(['latitude', 'longitude', 'scheme_name']);
                if ($_gps) {
                    if ($_gps->latitude !== null && $_gps->longitude !== null) {
                        $_lat = (float) $_gps->latitude; $_lng = (float) $_gps->longitude;
                    }
                    $_schemeName = $_schemeName ?: $_gps->scheme_name;
                }
            }
            // Scheme-name fallback — inherit from any matching subject report.
            if (($_lat === null || $_lng === null) && $_schemeName) {
                $_mr = \Illuminate\Support\Facades\DB::table('market_reports')
                    ->whereRaw('LOWER(subject_scheme_name) = ?', [mb_strtolower($_schemeName)])
                    ->whereNotNull('subject_latitude')->whereNotNull('subject_longitude')
                    ->orderByDesc('id')
                    ->first(['subject_latitude', 'subject_longitude']);
                if ($_mr) { $_lat = (float) $_mr->subject_latitude; $_lng = (float) $_mr->subject_longitude; }
            }
            if ($_lat === null || $_lng === null) continue;
            $_svgComps[] = [
                'lat'       => (float) $_lat,
                'lng'       => (float) $_lng,
                'title'     => CompLabel::build($_raw, $_al->suburb ?? null, $_al->id ?? null),
                'layer'     => 'active_listings',
                'price'     => $_al->list_price_inc ? (int) $_al->list_price_inc : null,
                'sale_date' => null,
            ];
        }
    }
?>
<?php if ($_subjLat !== null && $_subjLng !== null && !empty($_svgComps)): ?>
<div style="margin-top:18px;">
    <h3 style="margin-bottom:8px;">Spatial View — Comps Around Subject</h3>
    <?= (new \App\Services\Presentations\Pdf\SpatialViewSvgRenderer())->render(
        ['lat' => (float) $_subjLat, 'lng' => (float) $_subjLng, 'title' => $address],
        $_svgComps,
        540, 360,
    ) ?>
    <p style="font-size:10px;color:#64748b;margin-top:4px;">
        Subject at centre · <?= count($_svgComps) ?> data point<?= count($_svgComps) === 1 ? '' : 's' ?> within view ·
        Distances Haversine-corrected · Compass: north up
    </p>
</div>
<?php elseif ($_subjLat === null || $_subjLng === null): ?>
<?php // Empty state — subject property has no resolved GPS. Surface this
      // in the PDF so the agent sees the spatial view is missing because
      // of stale geocoding, not because no comps were found. ?>
<div class="callout callout-info" style="margin-top:14px;">
    Spatial View unavailable — subject property has no resolved GPS pin.
    Open the property and use the Map strip to set the pin, then regenerate this PDF.
</div>
<?php endif ?>

<?php endif // /spatial_view ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 5 — COMPARATIVE MARKET ANALYSIS  (Build 4 toggleable as cma_analysis)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('cma_analysis')): ?>
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
            <th class="num"><?= $esc($sizeLabel) ?></th>
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

<?php // CHART 4: Comp Price Distribution Bar Chart ?>
<?php
    $allCompPrices = array_merge(
        array_filter(array_column($vicinitySales, 'sale_price'), fn($v) => $v > 0),
        array_filter(array_column($cmaComps, 'sale_price'), fn($v) => $v > 0)
    );
    if (count($allCompPrices) >= 3):
        // Build brackets
        $cpMin = min($allCompPrices);
        $cpMax = max($allCompPrices);
        $cpRange = $cpMax - $cpMin;
        $cpBktSize = max(50000, (int) (ceil($cpRange / 6 / 50000) * 50000));
        $cpStart = (int) (floor($cpMin / $cpBktSize) * $cpBktSize);
        $cpBuckets = [];
        foreach ($allCompPrices as $cp) {
            $idx = (int) floor(($cp - $cpStart) / $cpBktSize);
            $cpBuckets[$idx] = ($cpBuckets[$idx] ?? 0) + 1;
        }
        $cpMaxBkt = max(1, max($cpBuckets));
        $askBktIdx = ($askingPrice && $askingPrice > 0) ? (int) floor(($askingPrice - $cpStart) / $cpBktSize) : null;
        $cpNumBkts = max(array_keys($cpBuckets)) + 1;
?>
<div class="avoid-break" style="margin-top:18px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Comparable Sales Price Distribution (<?= count($allCompPrices) ?> sales)</p>
    <div class="bar-chart">
        <?php for ($bi = 0; $bi < $cpNumBkts; $bi++): ?>
        <?php $bCount = $cpBuckets[$bi] ?? 0; $bPct = round($bCount / $cpMaxBkt * 100); ?>
        <div class="bar-col">
            <?php if ($bCount > 0): ?><div class="bar-count"><?= $bCount ?></div><?php endif ?>
            <div class="bar" style="height:<?= max(2, $bPct) ?>%;background:<?= $bi === $askBktIdx ? '#2563eb' : 'var(--brand)' ?>;<?= $bi === $askBktIdx ? 'box-shadow:0 0 0 2px #2563eb33;' : '' ?>"></div>
            <div class="bar-label"><?= 'R' . number_format(($cpStart + $bi * $cpBktSize) / 1000, 0) . 'k' ?></div>
        </div>
        <?php endfor ?>
    </div>
    <?php if ($askBktIdx !== null): ?>
    <p style="font-size:8px;text-align:center;color:#2563eb;font-weight:600;margin-top:2px;">Blue bar = your asking price bracket</p>
    <?php endif ?>
</div>
<?php endif ?>

<?php endif // /cma_analysis ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 6 — ACTIVE COMPETITION  (Build 4 toggleable)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('active_competition')): ?>
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
            <th class="num"><?= $esc($sizeLabel) ?></th>
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

<?php // CHART 5: Competition Price Bracket Bars ?>
<?php if (!empty($priceBrackets['brackets']) && count($priceBrackets['brackets']) >= 2): ?>
<div class="avoid-break" style="margin-top:18px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:8px;">Your Competition at Each Price Level</p>
    <?php foreach ($priceBrackets['brackets'] as $bkt): ?>
    <div class="hbar-row">
        <div class="hbar-label"><?= 'R' . number_format($bkt['lower'] / 1000, 0) . 'k–R' . number_format($bkt['upper'] / 1000, 0) . 'k' ?></div>
        <div class="hbar-track">
            <div class="hbar-fill" style="width:<?= max(4, $bkt['bar_pct']) ?>%;background:<?= $bkt['contains_asking'] ? '#2563eb' : 'var(--brand)' ?>;">
                <?php if ($bkt['count'] > 0): ?><span><?= $bkt['count'] ?></span><?php endif ?>
            </div>
        </div>
        <div class="hbar-count" style="<?= $bkt['contains_asking'] ? 'color:#2563eb;' : '' ?>"><?= $bkt['count'] ?></div>
    </div>
    <?php endforeach ?>
    <?php if ($askingPrice): ?>
    <p style="font-size:8px;text-align:center;color:#2563eb;font-weight:600;margin-top:4px;">Blue bar = your asking price bracket</p>
    <?php endif ?>
</div>
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

<?php // Price Position callout ?>
<?php if (!empty($pricePosition['has_data'])): ?>
<?php
    $posCalloutClass = match($pricePosition['position_color'] ?? '') {
        'green' => 'callout-success', 'amber' => 'callout-warning',
        'orange' => 'callout-warning', 'red' => 'callout-danger',
        default => 'callout-info',
    };
?>
<div class="callout <?= $posCalloutClass ?>" style="margin-top:10px;">
    <strong>Your Price Position:</strong>
    Ranked #<?= $pricePosition['price_rank'] ?> of <?= $pricePosition['total_listings'] ?> listings
    (<?= $pricePosition['price_percentile'] ?>th percentile)
    — <?= $pricePosition['listings_more_expensive'] ?> priced higher, <?= $pricePosition['listings_cheaper'] ?> priced lower.
    <?= $pricePosition['position_label'] ?>.
</div>
<?php endif ?>

<?php // Price Bracket Distribution ?>
<?php if (!empty($priceBrackets['has_data']) && !empty($priceBrackets['brackets'])): ?>
<div class="avoid-break" style="margin-top:14px;">
<h3 style="margin-bottom:8px;font-size:11px;color:var(--text-muted);">Price Distribution (<?= $priceBrackets['total_priced'] ?> listings)</h3>
<?php foreach ($priceBrackets['brackets'] as $bracket): ?>
<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;<?= $bracket['contains_asking'] ? 'background:#eef2ff;border:1px solid #c7d2fe;border-radius:4px;padding:3px 6px;margin-left:-6px;margin-right:-6px;' : '' ?>">
    <div style="width:140px;text-align:right;font-size:9.5px;color:var(--text-muted);flex-shrink:0;font-family:monospace;"><?= $bracket['label'] ?></div>
    <div style="flex:1;background:#f3f4f6;border-radius:999px;height:14px;overflow:hidden;">
        <?php if ($bracket['bar_pct'] > 0): ?>
        <div style="width:<?= max($bracket['bar_pct'], 4) ?>%;height:100%;background:<?= $bracket['contains_asking'] ? 'var(--brand-accent)' : '#94a3b8' ?>;border-radius:999px;"></div>
        <?php endif ?>
    </div>
    <div style="width:24px;text-align:right;font-size:10px;font-weight:600;color:var(--text);"><?= $bracket['count'] ?></div>
    <?php if ($bracket['contains_asking']): ?>
    <div style="font-size:9px;color:var(--brand-accent);font-weight:600;flex-shrink:0;width:50px;">Your price</div>
    <?php else: ?>
    <div style="width:50px;"></div>
    <?php endif ?>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<?php
// ── Competitor Stock — scored Active Competition, Core Matches engine.
//    Renders ONLY the ticked competitors (included_competitor_ids_json
//    on the version, or all when null = first paint). Each card shows
//    match % + tier + per-card link to the source portal (P24/PP).
//    HFC-owned stock gets a DOM/views badge from the PropCon join.
$competitorStock = $data['competitor_stock'] ?? ['visible' => []];
$visibleCompetitors = $competitorStock['visible'] ?? [];
if (!empty($visibleCompetitors)):
?>
<h3 style="margin-top:18px;margin-bottom:8px;">Scored Competitor Stock (<?= count($visibleCompetitors) ?>)</h3>
<p style="margin:0 0 10px 0;font-size:11px;color:#64748b;">
    Active listings the property competes against, scored by Core Matches.
    Match % reflects proximity by price, suburb, type, and bedrooms.
</p>

<!-- Photo-card grid — same visual as the review-screen Active Competition
     cards. DomPDF renders local file paths natively for the thumbnails
     (no remote fetch). Cards without a cached thumbnail render the
     placeholder icon — matches the CAPTURED PROPERTIES card behaviour. -->
<table style="width:100%;border-collapse:separate;border-spacing:8px;">
<?php
$visibleCount = count($visibleCompetitors);
$columns      = 2;
for ($rowStart = 0; $rowStart < $visibleCount; $rowStart += $columns):
?>
    <tr>
    <?php for ($col = 0; $col < $columns; $col++):
        $idx = $rowStart + $col;
        if (!isset($visibleCompetitors[$idx])):
            ?><td style="width:50%;"></td><?php
            continue;
        endif;
        $c = $visibleCompetitors[$idx];
        $tierBg = match ($c['tier'] ?? '') {
            'perfect'     => '#ecfdf5',
            'strong'      => '#eff6ff',
            'approximate' => '#fefce8',
            default       => '#f8fafc',
        };
        $tierColor = match ($c['tier'] ?? '') {
            'perfect'     => '#10b981',
            'strong'      => '#0ea5e9',
            'approximate' => '#a16207',
            default       => '#475569',
        };
        $tierLabel = ucfirst((string) ($c['tier'] ?? 'Match'));
        $title     = $c['address'] ?? ('Listing #' . $c['listing_id']);
        $thumbAbs  = $c['thumbnail_abs_path'] ?? null;
        $stats     = [];
        if (!empty($c['bedrooms']))         $stats[] = (int) $c['bedrooms']  . ' bed';
        if (!empty($c['bathrooms']))        $stats[] = (int) $c['bathrooms'] . ' bath';
        if (!empty($c['garages']))          $stats[] = (int) $c['garages']   . ' garage';
        if (!empty($c['erf_size_m2']))      $stats[] = (int) $c['erf_size_m2']      . ' m² erf';
        if (!empty($c['property_size_m2'])) $stats[] = (int) $c['property_size_m2'] . ' m² floor';
    ?>
        <td style="width:50%;vertical-align:top;padding:0;">
            <div style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;background:#fff;">
                <!-- Image / placeholder + price banner + match% top-right -->
                <div style="position:relative;height:96px;background:#f1f5f9;overflow:hidden;">
                    <?php if ($thumbAbs): ?>
                        <img src="<?= $esc($thumbAbs) ?>" style="width:100%;height:100%;object-fit:cover;display:block;" />
                    <?php else: ?>
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;">No photo</div>
                    <?php endif; ?>
                    <span style="position:absolute;top:6px;right:6px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#ffffff;color:<?= $tierColor ?>;">
                        <?= (int) ($c['score'] ?? 0) ?>%
                    </span>
                    <?php if (!empty($c['price'])): ?>
                        <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.55);color:#fff;padding:4px 8px;">
                            <span style="font-weight:700;font-size:12px;"><?= $zar($c['price']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Body -->
                <div style="padding:6px 8px;">
                    <div style="font-size:11px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $esc($title) ?></div>
                    <?php if (!empty($c['suburb']) && $c['suburb'] !== $title): ?>
                        <div style="font-size:10px;color:#94a3b8;"><?= $esc($c['suburb']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($stats)): ?>
                        <div style="font-size:10px;color:#64748b;margin-top:2px;"><?= $esc(implode(' · ', $stats)) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($c['agent_name']) || !empty($c['agency_name'])): ?>
                        <div style="font-size:9px;color:#94a3b8;margin-top:2px;"><?= $esc($c['agent_name'] ?? $c['agency_name']) ?></div>
                    <?php endif; ?>
                    <!-- Footer: ref + tier badge + HFC/DOM/views -->
                    <div style="border-top:1px solid #f1f5f9;margin-top:4px;padding-top:4px;font-size:9px;color:#94a3b8;">
                        <?php if (!empty($c['portal_ref'])): ?><span style="font-family:monospace;"><?= $esc($c['portal_ref']) ?></span> · <?php endif; ?>
                        <span style="background:<?= $tierBg ?>;color:<?= $tierColor ?>;padding:1px 5px;border-radius:6px;font-weight:600;"><?= $esc($tierLabel) ?></span>
                        <?php if (!empty($c['is_hfc_owned'])): ?>
                            · <span style="color:#10b981;font-weight:600;">HFC</span>
                            <?php if (isset($c['days_on_market']) && $c['days_on_market'] !== null): ?> · <?= (int) $c['days_on_market'] ?>d<?php endif; ?>
                            <?php if (isset($c['views']) && $c['views'] !== null): ?> · <?= number_format((int) $c['views']) ?> views<?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </td>
    <?php endfor; ?>
    </tr>
<?php endfor; ?>
</table>
<?php endif; ?>

<?php endif // /active_competition ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 7 — NEW LISTING INFLOW & ABSORPTION  (Build 4 toggleable)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('inflow_absorption')): ?>
<?php if (!empty($inflow['has_data'])): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">6</span>
    <h2>New Listing Inflow &amp; Absorption</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">
    New competing listings entering the market directly impact your selling probability.
    This analysis uses P24 alert data to measure the rate of new stock inflow.
</p>

<?php // Period cards: 7d / 30d / 90d ?>
<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Last 7 Days</div>
        <div class="value"><?= (int) $inflow['count_7d'] ?></div>
        <div class="sub">new listings</div>
    </div>
    <div class="metric-card">
        <div class="label">Last 30 Days</div>
        <div class="value"><?= (int) $inflow['count_30d'] ?></div>
        <div class="sub">new listings</div>
    </div>
    <div class="metric-card highlight">
        <div class="label">Last 90 Days</div>
        <div class="value"><?= (int) $inflow['count_90d'] ?></div>
        <div class="sub">new listings</div>
    </div>
</div>

<?php // Inflow rate callout ?>
<?php if ($inflow['new_listing_rate'] > 0): ?>
<div class="callout callout-info" style="margin-top:14px;">
    <strong>Inflow Rate:</strong> <?= $inflow['new_listing_rate'] ?> new similar listings per month
    (<?= number_format($inflow['new_listing_rate'] * 12, 0) ?>/year).
    Based on <?= (int) $inflow['count_90d'] ?> matching listings over the past 90 days.
    <?php if (!empty($inflow['target_suburbs'])): ?>
    <br><span style="font-size:10px;color:var(--text-light);">
        Matching: <?= $esc(implode(', ', $inflow['target_suburbs'])) ?>
        <?php if (!empty($inflow['target_types'])): ?> &middot; <?= $esc(implode('/', $inflow['target_types'])) ?><?php endif ?>
        <?php if (!empty($inflow['price_range'])): ?> &middot; R <?= number_format($inflow['price_range']['low']) ?> – R <?= number_format($inflow['price_range']['high']) ?><?php endif ?>
    </span>
    <?php endif ?>
</div>
<?php endif ?>

<?php // Adjusted absorption & selling probability ?>
<?php if ($inflow['net_absorption'] !== null): ?>
<?php
    $inflowTrendColor = match($inflow['stock_trend'] ?? '') {
        'growing'   => 'danger',
        'depleting' => 'success',
        default     => 'warning',
    };
    $inflowTrendLabel = match($inflow['stock_trend'] ?? '') {
        'growing'   => 'Stock Growing',
        'depleting' => 'Stock Depleting',
        default     => 'Stock Stable',
    };
    $inflowCalloutClass = match($inflow['stock_trend'] ?? '') {
        'growing'   => 'callout-danger',
        'depleting' => 'callout-success',
        default     => 'callout-warning',
    };
?>
<div class="avoid-break" style="margin-top:18px;">
<div class="two-col">
    <?php // Left: Standard vs Adjusted absorption ?>
    <div>
        <h3 style="margin-bottom:10px;">Adjusted Absorption</h3>
        <table>
            <thead><tr><th>Metric</th><th class="num">Value</th></tr></thead>
            <tbody>
                <tr>
                    <td>Standard supply</td>
                    <td class="num">
                        <?= (int) $inflow['active_listings'] ?> listings &divide; <?= $inflow['monthly_sales'] ?>/mo
                        <?php if ($monthsOfSupply !== null): ?>= <?= number_format($monthsOfSupply, 1) ?> months<?php endif ?>
                    </td>
                </tr>
                <tr>
                    <td>Net absorption</td>
                    <td class="num" style="color:var(--<?= $inflowTrendColor ?>);">
                        <?= $inflow['monthly_sales'] ?> sold &minus; <?= $inflow['new_listing_rate'] ?> new
                        = <?= $inflow['net_absorption'] > 0 ? '+' : '' ?><?= $inflow['net_absorption'] ?>/mo
                    </td>
                </tr>
                <tr>
                    <td>Stock trend</td>
                    <td class="num"><span class="cmp-badge cmp-<?= $inflowTrendColor ?>"><?= $inflowTrendLabel ?></span></td>
                </tr>
                <?php if ($inflow['adjusted_months_supply'] !== null): ?>
                <tr style="font-weight:700;">
                    <td>Adjusted supply</td>
                    <td class="num" style="color:var(--<?= $inflowTrendColor ?>);"><?= $inflow['adjusted_months_supply'] ?> months</td>
                </tr>
                <?php endif ?>
                <?php if ($inflow['pool_after_3_months'] !== null): ?>
                <tr>
                    <td>Pool after 3 months</td>
                    <td class="num">~<?= $inflow['pool_after_3_months'] ?> properties</td>
                </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php // Right: Selling probability ?>
    <div>
        <h3 style="margin-bottom:10px;">Selling Probability</h3>
        <?php if ($inflow['monthly_probability'] !== null): ?>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
                <span style="color:var(--text-muted);">Monthly chance</span>
                <span style="font-weight:700;"><?= $inflow['monthly_probability'] ?>%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;">
                <div style="width:<?= min($inflow['monthly_probability'], 100) ?>%;height:100%;background:var(--brand-light);border-radius:999px;"></div>
            </div>
        </div>
        <?php endif ?>
        <?php if ($inflow['prob_3_months'] !== null): ?>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
                <span style="color:var(--text-muted);">3-month chance</span>
                <span style="font-weight:700;"><?= $inflow['prob_3_months'] ?>%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;">
                <div style="width:<?= min($inflow['prob_3_months'], 100) ?>%;height:100%;background:var(--brand);border-radius:999px;"></div>
            </div>
        </div>
        <?php endif ?>
        <?php if ($inflow['adjusted_prob_3_months'] !== null && $inflow['adjusted_prob_3_months'] != $inflow['prob_3_months']): ?>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
                <span style="color:var(--text-muted);">Adjusted 3-month <span style="font-size:8px;color:var(--text-light);">(with inflow)</span></span>
                <span style="font-weight:700;color:var(--<?= $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'danger' : 'success' ?>);"><?= $inflow['adjusted_prob_3_months'] ?>%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;">
                <div style="width:<?= min($inflow['adjusted_prob_3_months'], 100) ?>%;height:100%;background:<?= $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'var(--danger)' : 'var(--success)' ?>;border-radius:999px;"></div>
            </div>
        </div>
        <?php endif ?>
    </div>
</div>
</div>
<?php endif ?>

<?php // Narrative insight ?>
<?php if (!empty($inflow['narrative'])): ?>
<div class="callout <?= $inflowCalloutClass ?? 'callout-info' ?>" style="margin-top:14px;">
    <strong>Key Insight:</strong> <?= $esc($inflow['narrative']) ?>
</div>
<?php endif ?>

<p style="font-size:8.5px;color:var(--text-light);margin-top:10px;">
    Source: P24 alert email imports (<?= number_format($inflow['total_p24_listings'] ?? 0) ?> total listings in database)
</p>
<?php else: ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">6</span>
    <h2>New Listing Inflow &amp; Absorption</h2>
</div>
<div class="callout callout-info">
    No P24 alert data available for this suburb in the selected period.
    The inflow story will populate once alerts arrive.
</div>
<?php endif // end inflow has_data ?>
<?php endif // /inflow_absorption ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 7.5 — PROPCON LISTING PERFORMANCE INSIGHTS
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if (!empty($propcon['has_data'])): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number"><?= !empty($inflow['has_data']) ? '7' : '6' ?></span>
    <h2>Listing Performance &mdash; Similar Properties</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">
    How similar properties currently on the market are performing &mdash; portal views, buyer matches, and time on market.
    <?php if (!empty($propcon['criteria'])): ?>
    <br><strong>Matching:</strong> <?= $esc($propcon['criteria']) ?>
    &middot; <?= (int) $propcon['similar_count'] ?> similar <?= $propcon['similar_count'] === 1 ? 'listing' : 'listings' ?>
    <?php endif ?>
</p>

<!-- Benchmark stat cards -->
<div class="metric-grid" style="grid-template-columns: repeat(4, 1fr);">
    <div class="metric-card">
        <div class="label">Avg Views</div>
        <div class="value"><?= $propcon['avg_views'] !== null ? number_format($propcon['avg_views']) : '—' ?></div>
        <?php if ($propcon['min_views'] !== null && $propcon['max_views'] !== null): ?>
        <div class="sub"><?= number_format($propcon['min_views']) ?> – <?= number_format($propcon['max_views']) ?></div>
        <?php endif ?>
    </div>
    <div class="metric-card">
        <div class="label">Avg Buyer Matches</div>
        <div class="value"><?= $propcon['avg_matches'] !== null ? number_format($propcon['avg_matches']) : '—' ?></div>
        <?php if ($propcon['min_matches'] !== null && $propcon['max_matches'] !== null): ?>
        <div class="sub"><?= $propcon['min_matches'] ?> – <?= $propcon['max_matches'] ?></div>
        <?php endif ?>
    </div>
    <div class="metric-card">
        <div class="label">Avg Days on Market</div>
        <div class="value"><?= $propcon['avg_days_on_market'] !== null ? $propcon['avg_days_on_market'] : '—' ?></div>
        <?php if ($propcon['min_days'] !== null && $propcon['max_days'] !== null): ?>
        <div class="sub"><?= $propcon['min_days'] ?> – <?= $propcon['max_days'] ?> days</div>
        <?php endif ?>
    </div>
    <div class="metric-card highlight">
        <div class="label">Avg Views/Day</div>
        <div class="value"><?= $propcon['avg_views_per_day'] !== null ? $propcon['avg_views_per_day'] : '—' ?></div>
    </div>
</div>

<!-- Similar listings table -->
<?php if (!empty($propcon['listings'])): ?>
<div class="avoid-break" style="margin-top:16px;">
    <h3 style="margin-bottom:8px;">Similar Active Listings</h3>
    <table>
        <thead>
            <tr>
                <th style="text-align:left;">Address</th>
                <th style="text-align:left;">Type</th>
                <th class="num">Price</th>
                <th class="num">Beds</th>
                <th class="num">Views</th>
                <th class="num">Matches</th>
                <th class="num">Days</th>
                <th class="num">Views/Day</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($propcon['listings'] as $pcRow): ?>
            <tr<?= !empty($pcRow['is_subject']) ? ' style="background:var(--bg-alt);font-weight:600;"' : '' ?>>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $esc($pcRow['address'] ?? '—') ?>
                    <?php if (!empty($pcRow['is_subject'])): ?>
                    <span style="font-size:8px;background:var(--brand-light);color:var(--brand);padding:1px 5px;border-radius:3px;margin-left:4px;">YOUR LISTING</span>
                    <?php endif ?>
                </td>
                <td><?= $esc($pcRow['type'] ?? '—') ?></td>
                <td class="num"><?= $pcRow['price'] ? $zar($pcRow['price']) : '—' ?></td>
                <td class="num"><?= $pcRow['beds'] ?? '—' ?></td>
                <td class="num"><?= $pcRow['views'] !== null ? number_format($pcRow['views']) : '—' ?></td>
                <td class="num"><?= $pcRow['matches'] ?? '—' ?></td>
                <td class="num"><?= $pcRow['days_on_market'] ?? '—' ?></td>
                <td class="num"><?= $pcRow['views_per_day'] ?? '—' ?></td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<!-- Subject property highlight -->
<?php if (!empty($propcon['subject_found']) && !empty($propcon['subject_stats'])): ?>
<?php $ss = $propcon['subject_stats']; ?>
<div class="callout callout-info" style="margin-top:14px;">
    <strong>Your Listing Performance:</strong>
    <?= $ss['views'] !== null ? number_format($ss['views']) . ' views' : '' ?>
    <?= $ss['matches'] !== null ? ' · ' . $ss['matches'] . ' matches' : '' ?>
    <?= $ss['days_on_market'] !== null ? ' · ' . $ss['days_on_market'] . ' days on market' : '' ?>
    <?= $ss['views_per_day'] !== null ? ' · ' . $ss['views_per_day'] . ' views/day' : '' ?>
    <?php if ($ss['rank_views']): ?>
    <br>Ranked <?= $esc($ss['rank_views']) ?> by views<?= $ss['rank_matches'] ? ', ' . $esc($ss['rank_matches']) . ' by matches' : '' ?> among similar listings.
    <?php endif ?>
</div>
<?php endif ?>

<!-- Market signal narrative -->
<?php if (!empty($propcon['market_signal_text'])): ?>
<?php
    $pcSignalClass = match($propcon['market_signal'] ?? '') {
        'price_issue'      => 'callout-danger',
        'visibility_issue' => 'callout-warning',
        'healthy'          => 'callout-success',
        'new_listing'      => 'callout-info',
        default            => 'callout-info',
    };
?>
<div class="callout <?= $pcSignalClass ?>" style="margin-top:14px;">
    <strong>Market Signal:</strong> <?= $esc($propcon['market_signal_text']) ?>
</div>
<?php endif ?>

<p style="font-size:8.5px;color:var(--text-light);margin-top:10px;">
    Source: PropCon agency data &middot; <?= number_format($propcon['total_propcon_listings'] ?? 0) ?> listings in database &middot; Updated weekly
</p>
<?php endif // end propcon section ?>

<?php
    // Compute dynamic section number offset for remaining sections
    $sectionAfterInflow = 6;
    if (!empty($inflow['has_data'])) $sectionAfterInflow++;
    if (!empty($propcon['has_data'])) $sectionAfterInflow++;
?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 8 — HOLDING COST ANALYSIS  (Build 4 toggleable)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('holding_cost')): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number"><?= $sectionAfterInflow ?></span>
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

<?php endif // /holding_cost ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 9 — PRICING STRATEGY & RECOMMENDATION  (Build 4 toggleable;
      // dependency-gated to cma_analysis at the compiler + review layers)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('pricing_strategy')): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number"><?= $sectionAfterInflow + 1 ?></span>
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
<?php endif // /pricing_strategy ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 10 — PRICING SCENARIOS (conditional — only if simulator saved with include_in_pdf)
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
    <span class="section-number"><?= $sectionAfterInflow + 2 ?></span>
    <h2>Pricing Scenarios</h2>
</div>

<div class="avoid-break" style="margin-bottom:14px;">
<p style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">
    Commission: <?= number_format($simCfg['commission_pct'] ?? 7.5, 1) ?>% (excl. VAT)
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

<?php // ══════════════════════════════════════════════════════════════════════
      // SELLER PRICING CONFIRMATION (conditional — only if seller live capture exists)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php
    $sellerCapture = $presentation->seller_live_capture_json;
    if ($sellerCapture && !empty($sellerCapture['price'])):
        $capPrice = (int) $sellerCapture['price'];
        $capProb  = $sellerCapture['probability'] ?? '';
        $capNet   = (int) ($sellerCapture['net_proceeds'] ?? 0);
        $capProbStyle = match(true) {
            str_contains(strtolower($capProb), 'very likely') => 'background:#d1fae5;color:#059669',
            str_contains(strtolower($capProb), 'likely')      => 'background:#dcfce7;color:#16a34a',
            str_contains(strtolower($capProb), 'possible')    => 'background:#fef3c7;color:#d97706',
            str_contains(strtolower($capProb), 'unlikely')    => 'background:#fed7aa;color:#ea580c',
            default                                           => 'background:#fecaca;color:#dc2626',
        };
?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">&bull;</span>
    <h2>Seller Pricing Confirmation</h2>
</div>

<div class="avoid-break" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:18px;">
    <p style="font-size:11px;color:var(--text-muted);margin-bottom:16px;text-transform:uppercase;letter-spacing:0.05em;">
        Price point confirmed during listing appointment
    </p>
    <table>
        <tbody>
            <tr>
                <td style="font-weight:600;width:180px;">Confirmed Price</td>
                <td style="font-size:16px;font-weight:700;"><?= $zar($capPrice) ?></td>
            </tr>
            <tr>
                <td style="font-weight:600;">Probability of Sale</td>
                <td><span class="cmp-badge" style="<?= $capProbStyle ?>"><?= $esc($capProb) ?></span></td>
            </tr>
            <tr>
                <td style="font-weight:600;">Estimated Net Proceeds</td>
                <td style="font-weight:700;"><?= $zar($capNet) ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif // end seller live capture ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // MARKET NEWS & ARTICLES (conditional — only if articles attached)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php
    $pdfArticles = $presentation->articles;
    if ($pdfArticles->isNotEmpty()):
?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">&bull;</span>
    <h2>Market Context</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:16px;">
    Relevant market news and commentary supporting this analysis.
</p>

<?php foreach ($pdfArticles as $pdfArticle):
    $artTags   = $pdfArticle->tags_json ?? [];
    $artTitle  = $esc($artTags['title'] ?? '');
    $artSource = $esc($artTags['source'] ?? 'Unknown source');
    $artDate   = '';
    if (!empty($artTags['published_at'])) {
        try { $artDate = (new \DateTimeImmutable($artTags['published_at']))->format('d M Y'); } catch (\Throwable) {}
    }
    $artSummary = $pdfArticle->ai_summary_text ?? $pdfArticle->snapshot_text ?? '';
    $artUrl     = $pdfArticle->url ?? '';
?>
<div class="avoid-break" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:12px;">
    <?php if ($artTitle): ?>
    <h3 style="font-size:12px;font-weight:700;color:var(--brand);margin-bottom:4px;line-height:1.4;"><?= $artTitle ?></h3>
    <?php endif ?>
    <p style="font-size:9px;color:var(--text-light);margin-bottom:8px;">
        <?= $artSource ?><?= $artDate ? ' &middot; ' . $artDate : '' ?>
    </p>
    <?php if ($artSummary): ?>
    <p style="font-size:11px;color:var(--text);line-height:1.6;margin-bottom:6px;">
        <?= $esc(mb_substr($artSummary, 0, 500)) ?>
    </p>
    <?php endif ?>
    <?php if ($artUrl): ?>
    <p style="font-size:8.5px;color:var(--text-light);word-break:break-all;">
        <a href="<?= $esc($artUrl) ?>" style="color:var(--brand-light);text-decoration:none;"><?= $esc($artUrl) ?></a>
    </p>
    <?php endif ?>
</div>
<?php endforeach ?>

<?php endif // end articles ?>

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
