<?php

namespace App\Services\CommercialEvaluation;

use App\Models\CommercialEvaluation;

/**
 * Generates a self-contained HTML document for a CommercialEvaluation.
 * Users open in browser then Ctrl+P → Save as PDF.
 *
 * Same approach as PresentationPdfService — no external PDF library.
 */
class CommercialEvaluationPdfService
{
    /**
     * Generate the full HTML string for the evaluation report.
     */
    public function generate(CommercialEvaluation $evaluation): string
    {
        $evaluation->load([
            'creator', 'branch', 'financials', 'comparables',
            'assets', 'units', 'crops', 'livestock',
        ]);

        $ej       = $evaluation->evaluation_json ?? [];
        $rec      = $ej['recommended'] ?? [];
        $methods  = $ej['methods'] ?? [];
        $used     = $ej['methods_used'] ?? [];
        $skipped  = $ej['methods_skipped'] ?? [];

        // Formatting helpers
        $zar = function (?int $cents): string {
            if ($cents === null || $cents === 0) return '—';
            return 'R ' . number_format($cents / 100, 0, '.', ' ');
        };
        $esc = function (?string $val): string {
            return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
        };
        $pct = function (?float $val): string {
            if ($val === null) return '—';
            return number_format($val, 1) . '%';
        };

        $agentName  = $esc($evaluation->creator->name ?? 'Agent');
        $agentEmail = $esc($evaluation->creator->email ?? '');
        $branchName = $esc($evaluation->branch->name ?? 'Home Finders Coastal');
        $date       = now()->format('d F Y');
        $propName   = $esc($evaluation->property_name ?? 'Property');
        $address    = $esc($evaluation->address ?? '');
        $typeLabel  = $esc(CommercialEvaluation::propertyTypeLabel($evaluation->property_type));
        $condition  = ucfirst($evaluation->condition ?? 'Unknown');

        $methodLabels = [
            'income_capitalisation' => 'Income Capitalisation',
            'comparable_sales'      => 'Comparable Sales',
            'revenue_multiple'      => 'Revenue / Income Multiple',
            'asset_based'           => 'Asset-Based / Cost Approach',
            'productive_value'      => 'Agricultural Productive Value',
            'gross_rent_multiplier' => 'Gross Rent Multiplier',
        ];

        // Start building HTML
        $html = $this->htmlHead($propName, $date);

        // Cover page
        $html .= $this->coverPage($propName, $address, $typeLabel, $date, $agentName, $agentEmail, $branchName);

        // Property overview
        $html .= $this->propertyOverview($evaluation, $zar, $esc, $typeLabel, $condition);

        // Financial summary (if financials exist)
        if ($evaluation->financials->isNotEmpty()) {
            $html .= $this->financialSummary($evaluation, $zar);
        }

        // Rental schedule (commercial/industrial with units)
        if (in_array($evaluation->property_type, ['commercial', 'industrial']) && $evaluation->units->isNotEmpty()) {
            $html .= $this->rentalSchedule($evaluation, $zar);
        }

        // Asset register (if assets exist)
        if ($evaluation->assets->isNotEmpty()) {
            $html .= $this->assetRegister($evaluation, $zar);
        }

        // Crops & Livestock (agricultural)
        if ($evaluation->property_type === 'agricultural') {
            if ($evaluation->crops->isNotEmpty()) {
                $html .= $this->cropSchedule($evaluation, $zar);
            }
            if ($evaluation->livestock->isNotEmpty()) {
                $html .= $this->livestockSchedule($evaluation, $zar);
            }
        }

        // Property Intelligence — Seller Responses (agricultural)
        if ($evaluation->property_type === 'agricultural') {
            $sellerSection = $this->sellerResponses($evaluation);
            if ($sellerSection) {
                $html .= $sellerSection;
            }
        }

        // Comparable sales
        if ($evaluation->comparables->isNotEmpty()) {
            $html .= $this->comparableSalesSection($evaluation, $zar);
        }

        // Evaluation methods
        if (!empty($methods)) {
            $html .= $this->evaluationMethods($methods, $used, $skipped, $methodLabels, $zar, $rec);
        }

        // Recommended market evaluation
        if (!empty($rec)) {
            $html .= $this->recommendedEvaluation($rec, $methodLabels, $zar);
        }

        // Disclaimer
        $html .= $this->disclaimer();

        $html .= '</body></html>';

        return $html;
    }

    // ══════════════════════════════════════════════════════════════════
    //  HTML Sections
    // ══════════════════════════════════════════════════════════════════

    private function htmlHead(string $propName, string $date): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commercial Market Evaluation — {$propName} — {$date}</title>
<style>
    @page { size: A4; margin: 15mm 18mm 20mm 18mm; }
    @media print { .page-break { page-break-before: always; } .no-print { display: none; } }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.5; background: #fff; }
    .container { max-width: 210mm; margin: 0 auto; padding: 0 10mm; }
    h1 { font-size: 22px; color: #0b2a4a; margin-bottom: 4px; }
    h2 { font-size: 15px; color: #0b2a4a; border-bottom: 2px solid #0b2a4a; padding-bottom: 4px; margin: 20px 0 10px; }
    h3 { font-size: 12px; color: #334155; margin: 12px 0 6px; }
    .cover { text-align: center; padding: 60px 0 40px; }
    .cover h1 { font-size: 28px; margin-bottom: 8px; }
    .cover .subtitle { font-size: 16px; color: #64748b; margin-bottom: 24px; }
    .cover .property { font-size: 20px; font-weight: 700; color: #0b2a4a; margin-bottom: 4px; }
    .cover .address { font-size: 13px; color: #64748b; margin-bottom: 30px; }
    .cover .meta { font-size: 11px; color: #94a3b8; }
    .brand { font-size: 14px; font-weight: 700; color: #0b2a4a; letter-spacing: 0.5px; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 11px; }
    th { background: #f1f5f9; padding: 6px 8px; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; }
    td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .mono { font-family: 'Consolas', 'Courier New', monospace; }
    .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin: 8px 0; }
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin: 12px 0; }
    .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center; }
    .summary-box.highlight { background: #ecfdf5; border-color: #86efac; }
    .summary-box .label { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
    .summary-box .value { font-size: 16px; font-weight: 700; color: #0b2a4a; font-family: 'Consolas', monospace; }
    .summary-box.highlight .value { color: #059669; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: 600; }
    .badge-primary { background: #dbeafe; color: #1d4ed8; }
    .badge-confidence-high { background: #dcfce7; color: #166534; }
    .badge-confidence-moderate { background: #fef3c7; color: #92400e; }
    .badge-confidence-low { background: #fecaca; color: #991b1b; }
    .method-card { background: #fff; border: 1px solid #e2e8f0; border-left: 4px solid #94a3b8; border-radius: 6px; padding: 12px; margin: 10px 0; }
    .method-card.primary { border-left-color: #3b82f6; }
    .method-title { font-size: 12px; font-weight: 700; color: #0b2a4a; margin-bottom: 4px; }
    .method-desc { font-size: 10px; color: #94a3b8; margin-bottom: 8px; }
    .disclaimer { background: #fefce8; border: 1px solid #fde68a; border-radius: 6px; padding: 14px; margin: 24px 0; font-size: 10px; color: #713f12; line-height: 1.6; }
    .disclaimer strong { color: #92400e; }
    .print-btn { position: fixed; top: 12px; right: 16px; background: #0b2a4a; color: #fff; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; z-index: 999; }
    .print-btn:hover { background: #1e3a5f; }
    .text-muted { color: #94a3b8; }
    .text-red { color: #dc2626; }
    .text-green { color: #059669; }
    .fw-bold { font-weight: 700; }
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Print / Save as PDF</button>
<div class="container">
HTML;
    }

    private function coverPage(string $propName, string $address, string $typeLabel, string $date, string $agentName, string $agentEmail, string $branchName): string
    {
        return <<<HTML

<div class="cover">
    <div class="brand">HOME FINDERS COASTAL</div>
    <p style="font-size:10px;color:#94a3b8;margin-bottom:40px;">KZN South Coast</p>

    <h1>Commercial Market Evaluation</h1>
    <p class="subtitle">Confidential Report</p>

    <div class="property">{$propName}</div>
    <div class="address">{$address}</div>
    <p class="meta" style="margin-bottom:4px;">{$typeLabel}</p>

    <div style="margin-top:40px;padding-top:20px;border-top:1px solid #e2e8f0;">
        <p class="meta">Prepared by: <strong>{$agentName}</strong></p>
        <p class="meta">{$agentEmail}</p>
        <p class="meta" style="margin-top:8px;">{$branchName}</p>
        <p class="meta">{$date}</p>
    </div>
</div>
<div class="page-break"></div>
HTML;
    }

    private function propertyOverview(CommercialEvaluation $e, callable $zar, callable $esc, string $typeLabel, string $condition): string
    {
        $suburb = $esc($e->suburb ?? '');
        $town   = $esc($e->town ?? '');
        $loc    = implode(', ', array_filter([$suburb, $town, $esc($e->province ?? '')]));

        $landDisplay = '';
        if ($e->total_land_size_ha) {
            $landDisplay = number_format($e->total_land_size_ha, 2) . ' ha';
        } elseif ($e->total_land_size_m2) {
            $landDisplay = number_format($e->total_land_size_m2) . ' m²';
        }

        $buildDisplay = $e->total_building_size_m2 ? number_format($e->total_building_size_m2) . ' m²' : '—';

        return <<<HTML

<h2>Property Overview</h2>
<table>
    <tr><td style="width:35%;color:#64748b;font-weight:600;">Property Type</td><td>{$typeLabel}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Location</td><td>{$loc}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Erf Number</td><td>{$esc($e->erf_number ?? '—')}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Zoning</td><td>{$esc($e->zoning ?? '—')}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Land Size</td><td>{$landDisplay}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Building Size</td><td>{$buildDisplay}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Year Built</td><td>{$esc((string)($e->year_built ?? '—'))}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Condition</td><td>{$condition}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Asking Price</td><td class="mono fw-bold">{$zar($e->asking_price)}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Municipal Evaluation</td><td class="mono">{$zar($e->municipal_evaluation)}</td></tr>
    <tr><td style="color:#64748b;font-weight:600;">Seller</td><td>{$esc($e->seller_name ?? '—')}</td></tr>
</table>
HTML;
    }

    private function financialSummary(CommercialEvaluation $e, callable $zar): string
    {
        $html = '<div class="page-break"></div><h2>Financial Summary</h2>';

        foreach ($e->financials->sortByDesc('financial_year') as $f) {
            $year = htmlspecialchars($f->financial_year ?? '?');
            $html .= "<h3>Financial Year: {$year} ({$f->period_months} months)</h3>";
            $html .= '<table>';
            $html .= '<tr><th colspan="2">Income</th></tr>';
            if ($f->gross_revenue)         $html .= "<tr><td>Gross Revenue</td><td class='text-right mono'>{$zar($f->gross_revenue)}</td></tr>";
            if ($f->rental_income)         $html .= "<tr><td>Rental Income</td><td class='text-right mono'>{$zar($f->rental_income)}</td></tr>";
            if ($f->room_revenue)          $html .= "<tr><td>Room Revenue</td><td class='text-right mono'>{$zar($f->room_revenue)}</td></tr>";
            if ($f->food_beverage_revenue) $html .= "<tr><td>Food & Beverage Revenue</td><td class='text-right mono'>{$zar($f->food_beverage_revenue)}</td></tr>";
            if ($f->other_income)          $html .= "<tr><td>Other Income</td><td class='text-right mono'>{$zar($f->other_income)}</td></tr>";
            if ($f->vacancy_rate)          $html .= "<tr><td>Vacancy Rate</td><td class='text-right'>" . number_format($f->vacancy_rate, 1) . "%</td></tr>";

            $html .= '<tr><th colspan="2">Expenses</th></tr>';
            if ($f->rates_taxes)           $html .= "<tr><td>Rates & Taxes</td><td class='text-right mono text-red'>{$zar($f->rates_taxes)}</td></tr>";
            if ($f->insurance)             $html .= "<tr><td>Insurance</td><td class='text-right mono text-red'>{$zar($f->insurance)}</td></tr>";
            if ($f->utilities)             $html .= "<tr><td>Utilities</td><td class='text-right mono text-red'>{$zar($f->utilities)}</td></tr>";
            if ($f->maintenance)           $html .= "<tr><td>Maintenance</td><td class='text-right mono text-red'>{$zar($f->maintenance)}</td></tr>";
            if ($f->management_fees)       $html .= "<tr><td>Management Fees</td><td class='text-right mono text-red'>{$zar($f->management_fees)}</td></tr>";
            if ($f->salaries_wages)        $html .= "<tr><td>Salaries & Wages</td><td class='text-right mono text-red'>{$zar($f->salaries_wages)}</td></tr>";
            if ($f->security)              $html .= "<tr><td>Security</td><td class='text-right mono text-red'>{$zar($f->security)}</td></tr>";
            if ($f->marketing)             $html .= "<tr><td>Marketing</td><td class='text-right mono text-red'>{$zar($f->marketing)}</td></tr>";
            if ($f->food_beverage_cost)    $html .= "<tr><td>Food & Beverage Cost</td><td class='text-right mono text-red'>{$zar($f->food_beverage_cost)}</td></tr>";
            if ($f->farm_operating_costs)  $html .= "<tr><td>Farm Operating Costs</td><td class='text-right mono text-red'>{$zar($f->farm_operating_costs)}</td></tr>";
            if ($f->other_expenses)        $html .= "<tr><td>Other Expenses</td><td class='text-right mono text-red'>{$zar($f->other_expenses)}</td></tr>";

            $html .= '<tr style="border-top:2px solid #0b2a4a;"><td class="fw-bold">Total Expenses</td><td class="text-right mono fw-bold text-red">' . $zar($f->total_expenses) . '</td></tr>';
            $html .= '<tr style="background:#ecfdf5;"><td class="fw-bold">Net Operating Income</td><td class="text-right mono fw-bold text-green">' . $zar($f->net_operating_income) . '</td></tr>';
            if ($f->ebitda) {
                $html .= '<tr style="background:#f0f9ff;"><td class="fw-bold">EBITDA</td><td class="text-right mono fw-bold">' . $zar($f->ebitda) . '</td></tr>';
            }
            $html .= '</table>';
        }

        return $html;
    }

    private function rentalSchedule(CommercialEvaluation $e, callable $zar): string
    {
        $html = '<h2>Rental Schedule</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Unit</th><th>Tenant</th><th class="text-right">Size (m²)</th><th class="text-right">Monthly Rental</th><th>Lease Period</th><th class="text-center">Vacant</th><th class="text-right">Escalation</th></tr>';

        $totalRent = 0;
        foreach ($e->units as $u) {
            $eName  = htmlspecialchars($u->unit_name ?? '');
            $tenant = htmlspecialchars($u->tenant_name ?? '—');
            $size   = $u->size_m2 ? number_format($u->size_m2) : '—';
            $rent   = $zar($u->monthly_rental);
            $lease  = ($u->lease_start ? $u->lease_start->format('Y-m-d') : '?') . ' — ' . ($u->lease_end ? $u->lease_end->format('Y-m-d') : '?');
            $vacant = $u->is_vacant ? 'Yes' : 'No';
            $esc    = $u->escalation_rate ? number_format($u->escalation_rate, 1) . '%' : '—';
            $totalRent += $u->monthly_rental ?? 0;
            $html .= "<tr><td>{$eName}</td><td>{$tenant}</td><td class='text-right'>{$size}</td><td class='text-right mono'>{$rent}</td><td>{$lease}</td><td class='text-center'>{$vacant}</td><td class='text-right'>{$esc}</td></tr>";
        }

        $html .= '<tr style="border-top:2px solid #0b2a4a;font-weight:700;"><td colspan="3">Total Monthly Rental</td><td class="text-right mono">' . $zar($totalRent) . '</td><td colspan="3"></td></tr>';
        $html .= '<tr><td colspan="3" class="fw-bold">Annualised</td><td class="text-right mono fw-bold">' . $zar($totalRent * 12) . '</td><td colspan="3"></td></tr>';
        $html .= '</table>';

        return $html;
    }

    private function assetRegister(CommercialEvaluation $e, callable $zar): string
    {
        $html = '<h2>Asset Register</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Category</th><th>Description</th><th class="text-right">Qty</th><th class="text-right">Estimated Value</th></tr>';

        $total = 0;
        foreach ($e->assets as $a) {
            $cat  = htmlspecialchars($a->category ?? '');
            $desc = htmlspecialchars($a->description ?? '');
            $qty  = $a->quantity ?? 1;
            $val  = ($a->estimated_value ?? 0) * $qty;
            $total += $val;
            $html .= "<tr><td>{$cat}</td><td>{$desc}</td><td class='text-right'>{$qty}</td><td class='text-right mono'>{$zar($a->estimated_value)}</td></tr>";
        }

        $html .= '<tr style="border-top:2px solid #0b2a4a;font-weight:700;"><td colspan="3">Total Assets</td><td class="text-right mono">' . $zar($total) . '</td></tr>';
        $html .= '</table>';

        return $html;
    }

    private function cropSchedule(CommercialEvaluation $e, callable $zar): string
    {
        $html = '<h2>Crop Schedule</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Crop</th><th>Variety</th><th class="text-right">Hectares</th><th class="text-right">Age (yrs)</th><th class="text-right">Yield t/ha</th><th class="text-right">Annual Revenue</th></tr>';

        $totalRev = 0;
        foreach ($e->crops as $c) {
            $type  = htmlspecialchars(ucfirst($c->crop_type ?? ''));
            $var   = htmlspecialchars($c->variety ?? '—');
            $ha    = number_format($c->hectares ?? 0, 1);
            $age   = $c->age_years ?? '—';
            $yield = $c->current_yield_tons_per_ha ? number_format($c->current_yield_tons_per_ha, 2) : '—';
            $rev   = $zar($c->annual_revenue);
            $totalRev += $c->annual_revenue ?? 0;
            $html .= "<tr><td>{$type}</td><td>{$var}</td><td class='text-right'>{$ha}</td><td class='text-right'>{$age}</td><td class='text-right'>{$yield}</td><td class='text-right mono'>{$rev}</td></tr>";
        }
        $html .= '<tr style="border-top:2px solid #0b2a4a;font-weight:700;"><td colspan="5">Total Annual Crop Revenue</td><td class="text-right mono">' . $zar($totalRev) . '</td></tr>';
        $html .= '</table>';

        return $html;
    }

    private function livestockSchedule(CommercialEvaluation $e, callable $zar): string
    {
        $html = '<h2>Livestock Schedule</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Type</th><th>Breed</th><th class="text-right">Head</th><th class="text-right">Value/Head</th><th class="text-right">Total Value</th><th class="text-right">Annual Revenue</th></tr>';

        foreach ($e->livestock as $l) {
            $type  = htmlspecialchars(ucfirst($l->livestock_type ?? ''));
            $breed = htmlspecialchars($l->breed ?? '—');
            $count = $l->head_count ?? 0;
            $vph   = $zar($l->value_per_head);
            $tv    = $zar($l->total_value);
            $rev   = $zar($l->annual_revenue);
            $html .= "<tr><td>{$type}</td><td>{$breed}</td><td class='text-right'>{$count}</td><td class='text-right mono'>{$vph}</td><td class='text-right mono'>{$tv}</td><td class='text-right mono'>{$rev}</td></tr>";
        }
        $html .= '</table>';

        return $html;
    }

    private function comparableSalesSection(CommercialEvaluation $e, callable $zar): string
    {
        $html = '<div class="page-break"></div><h2>Comparable Sales Analysis</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Address</th><th>Suburb</th><th>Type</th><th class="text-right">Size</th><th class="text-right">Sale Price</th><th class="text-right">R/m²</th><th>Date</th><th>Source</th></tr>';

        foreach ($e->comparables as $c) {
            $addr    = htmlspecialchars($c->address ?? '');
            $suburb  = htmlspecialchars($c->suburb ?? '');
            $type    = htmlspecialchars($c->property_type ?? '');
            $size    = $c->size_ha ? number_format($c->size_ha, 2) . ' ha' : ($c->size_m2 ? number_format($c->size_m2) . ' m²' : '—');
            $price   = $zar($c->sale_price);
            $ppm2    = $zar($c->price_per_m2);
            $date    = $c->sale_date ? $c->sale_date->format('Y-m-d') : '—';
            $source  = htmlspecialchars($c->source ?? '—');
            $html .= "<tr><td>{$addr}</td><td>{$suburb}</td><td>{$type}</td><td class='text-right'>{$size}</td><td class='text-right mono'>{$price}</td><td class='text-right mono'>{$ppm2}</td><td>{$date}</td><td>{$source}</td></tr>";
        }
        $html .= '</table>';

        // Summary statistics
        $avgM2  = $e->comparables->whereNotNull('price_per_m2')->avg('price_per_m2');
        $avgHa  = $e->comparables->whereNotNull('price_per_ha')->avg('price_per_ha');
        $avgPr  = $e->comparables->avg('sale_price');

        $html .= '<div class="card">';
        if ($avgPr) $html .= '<p style="margin-bottom:4px;"><strong>Average Sale Price:</strong> <span class="mono">' . $zar((int)$avgPr) . '</span></p>';
        if ($avgM2) $html .= '<p style="margin-bottom:4px;"><strong>Average R/m²:</strong> <span class="mono">' . $zar((int)$avgM2) . '</span></p>';
        if ($avgHa) $html .= '<p style="margin-bottom:4px;"><strong>Average R/ha:</strong> <span class="mono">' . $zar((int)$avgHa) . '</span></p>';
        $html .= '<p><strong>Number of Comparables:</strong> ' . $e->comparables->count() . '</p>';
        $html .= '</div>';

        return $html;
    }

    private function evaluationMethods(array $methods, array $used, array $skipped, array $labels, callable $zar, array $rec): string
    {
        $html = '<div class="page-break"></div><h2>Evaluation Methods</h2>';
        $primary = $rec['primary_method'] ?? '';

        // Income Capitalisation
        if (($methods['income_capitalisation']['applicable'] ?? false)) {
            $ic = $methods['income_capitalisation'];
            $cls = 'income_capitalisation' === $primary ? ' primary' : '';
            $html .= "<div class='method-card{$cls}'>";
            $html .= "<div class='method-title'>{$labels['income_capitalisation']}</div>";
            if ('income_capitalisation' === $primary) $html .= ' <span class="badge badge-primary">Primary Method</span>';
            $html .= "<div class='method-desc'>NOI ÷ Capitalisation Rate — the standard method for income-producing properties.</div>";
            $html .= '<table>';
            $html .= "<tr><td>Gross Income</td><td class='text-right mono'>{$zar($ic['breakdown']['gross_income'] ?? null)}</td></tr>";
            $html .= "<tr><td>Less: Vacancy ({$ic['breakdown']['vacancy_rate']}%)</td><td class='text-right mono text-red'>-{$zar($ic['breakdown']['vacancy_allowance'] ?? null)}</td></tr>";
            $html .= "<tr><td>Less: Operating Expenses</td><td class='text-right mono text-red'>-{$zar($ic['breakdown']['operating_expenses'] ?? null)}</td></tr>";
            $html .= "<tr style='background:#ecfdf5;'><td class='fw-bold'>Net Operating Income (NOI)</td><td class='text-right mono fw-bold text-green'>{$zar($ic['noi'] ?? null)}</td></tr>";
            $html .= '</table>';
            $html .= '<div class="summary-grid">';
            $html .= "<div class='summary-box'><div class='label'>@ {$ic['cap_rate_high']}% (Conservative)</div><div class='value'>{$zar($ic['evaluation_low'] ?? null)}</div></div>";
            $html .= "<div class='summary-box highlight'><div class='label'>@ {$ic['cap_rate_mid']}% (Mid)</div><div class='value'>{$zar($ic['evaluation_mid'] ?? null)}</div></div>";
            $html .= "<div class='summary-box'><div class='label'>@ {$ic['cap_rate_low']}% (Optimistic)</div><div class='value'>{$zar($ic['evaluation_high'] ?? null)}</div></div>";
            $html .= '</div>';
            $html .= '</div>';
        }

        // Comparable Sales
        if (($methods['comparable_sales']['applicable'] ?? false)) {
            $cs = $methods['comparable_sales'];
            $cls = 'comparable_sales' === $primary ? ' primary' : '';
            $html .= "<div class='method-card{$cls}'>";
            $html .= "<div class='method-title'>{$labels['comparable_sales']}</div>";
            if ('comparable_sales' === $primary) $html .= ' <span class="badge badge-primary">Primary Method</span>';
            $html .= "<div class='method-desc'>Based on recent sales of similar properties — {$cs['comp_count']} comparable(s) analysed.</div>";
            $html .= '<div class="summary-grid">';
            $html .= "<div class='summary-box'><div class='label'>Low</div><div class='value'>{$zar($cs['evaluation_low'] ?? null)}</div></div>";
            $html .= "<div class='summary-box highlight'><div class='label'>Average</div><div class='value'>{$zar($cs['evaluation_mid'] ?? null)}</div></div>";
            $html .= "<div class='summary-box'><div class='label'>High</div><div class='value'>{$zar($cs['evaluation_high'] ?? null)}</div></div>";
            $html .= '</div>';
            if ($cs['note'] ?? false) {
                $html .= '<p style="color:#92400e;font-size:10px;">' . htmlspecialchars($cs['note']) . '</p>';
            }
            $html .= '</div>';
        }

        // Revenue Multiple
        if (($methods['revenue_multiple']['applicable'] ?? false)) {
            $rm = $methods['revenue_multiple'];
            $cls = 'revenue_multiple' === $primary ? ' primary' : '';
            $html .= "<div class='method-card{$cls}'>";
            $html .= "<div class='method-title'>{$labels['revenue_multiple']}</div>";
            if ('revenue_multiple' === $primary) $html .= ' <span class="badge badge-primary">Primary Method</span>';
            $html .= "<div class='method-desc'>Applies industry multiples to revenue and EBITDA figures.</div>";

            if (isset($rm['evaluation_revenue'])) {
                $html .= '<h3>Revenue Multiple (Gross Revenue: ' . $zar($rm['gross_revenue']) . ')</h3>';
                $range = implode('× / ', $rm['revenue_multiple_range'] ?? []) . '×';
                $html .= '<div class="summary-grid">';
                $html .= "<div class='summary-box'><div class='label'>{$rm['revenue_multiple_range'][0]}×</div><div class='value'>{$zar($rm['evaluation_revenue'][0] ?? null)}</div></div>";
                $html .= "<div class='summary-box'><div class='label'>{$rm['revenue_multiple_range'][1]}×</div><div class='value'>{$zar($rm['evaluation_revenue'][1] ?? null)}</div></div>";
                $html .= "<div class='summary-box'><div class='label'>{$rm['revenue_multiple_range'][2]}×</div><div class='value'>{$zar($rm['evaluation_revenue'][2] ?? null)}</div></div>";
                $html .= '</div>';
            }

            if (isset($rm['evaluation_ebitda'])) {
                $html .= '<h3>EBITDA Multiple (EBITDA: ' . $zar($rm['ebitda']) . ')</h3>';
                $html .= '<div class="summary-grid">';
                $html .= "<div class='summary-box'><div class='label'>{$rm['ebitda_multiple_range'][0]}×</div><div class='value'>{$zar($rm['evaluation_ebitda'][0] ?? null)}</div></div>";
                $html .= "<div class='summary-box highlight'><div class='label'>{$rm['ebitda_multiple_range'][1]}×</div><div class='value'>{$zar($rm['evaluation_ebitda'][1] ?? null)}</div></div>";
                $html .= "<div class='summary-box'><div class='label'>{$rm['ebitda_multiple_range'][2]}×</div><div class='value'>{$zar($rm['evaluation_ebitda'][2] ?? null)}</div></div>";
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // Asset-Based
        if (($methods['asset_based']['applicable'] ?? false)) {
            $ab = $methods['asset_based'];
            $cls = 'asset_based' === $primary ? ' primary' : '';
            $html .= "<div class='method-card{$cls}'>";
            $html .= "<div class='method-title'>{$labels['asset_based']}</div>";
            if ('asset_based' === $primary) $html .= ' <span class="badge badge-primary">Primary Method</span>';
            $html .= "<div class='method-desc'>Replacement cost of land, buildings, and assets, less depreciation.</div>";
            $html .= '<table>';
            $html .= "<tr><td>Land Value</td><td class='text-right mono'>{$zar($ab['land_value'] ?? null)}</td></tr>";
            $bm2 = number_format($ab['building_m2'] ?? 0);
            $cpm = number_format($ab['building_cost_per_m2'] ?? 0);
            $html .= "<tr><td>Building Replacement ({$bm2} m² @ R {$cpm}/m²)</td><td class='text-right mono'>{$zar($ab['building_replacement'] ?? null)}</td></tr>";
            $depPct = round(($ab['depreciation_rate'] ?? 0) * 100);
            $html .= "<tr><td>Less: Depreciation ({$depPct}% — " . ucfirst($ab['condition'] ?? '') . ")</td><td class='text-right mono text-red'>-{$zar($ab['depreciation'] ?? null)}</td></tr>";
            $html .= "<tr><td>Movable Assets</td><td class='text-right mono'>{$zar($ab['movable_assets'] ?? null)}</td></tr>";
            if (($ab['goodwill'] ?? 0) > 0) {
                $html .= "<tr><td>Goodwill (2 years net profit)</td><td class='text-right mono'>{$zar($ab['goodwill'] ?? null)}</td></tr>";
            }
            $html .= "<tr style='background:#ecfdf5;border-top:2px solid #0b2a4a;'><td class='fw-bold'>Total Asset-Based Evaluation</td><td class='text-right mono fw-bold text-green'>{$zar($ab['total'] ?? null)}</td></tr>";
            $html .= '</table>';
            $html .= '</div>';
        }

        // Productive Value
        if (($methods['productive_value']['applicable'] ?? false)) {
            $pv = $methods['productive_value'];
            $cls = 'productive_value' === $primary ? ' primary' : '';
            $html .= "<div class='method-card{$cls}'>";
            $html .= "<div class='method-title'>{$labels['productive_value']}</div>";
            if ('productive_value' === $primary) $html .= ' <span class="badge badge-primary">Primary Method</span>';
            $html .= "<div class='method-desc'>Values the farm based on productive capacity of crops and livestock.</div>";
            $html .= '<table>';
            $html .= "<tr><td>Crop Revenue</td><td class='text-right mono'>{$zar($pv['crop_revenue'] ?? null)}</td></tr>";
            $html .= "<tr><td>Crop Costs</td><td class='text-right mono text-red'>-{$zar($pv['crop_cost'] ?? null)}</td></tr>";
            $html .= "<tr><td>Livestock Revenue</td><td class='text-right mono'>{$zar($pv['livestock_revenue'] ?? null)}</td></tr>";
            $html .= "<tr><td>Livestock Costs</td><td class='text-right mono text-red'>-{$zar($pv['livestock_cost'] ?? null)}</td></tr>";
            $html .= "<tr style='background:#ecfdf5;'><td class='fw-bold'>Total Net Farm Income</td><td class='text-right mono fw-bold text-green'>{$zar($pv['total_net_farm_income'] ?? null)}</td></tr>";
            $html .= '</table>';
            $html .= '<div class="summary-grid">';
            $html .= "<div class='summary-box'><div class='label'>Low</div><div class='value'>{$zar($pv['evaluation_low'] ?? null)}</div></div>";
            $html .= "<div class='summary-box highlight'><div class='label'>Mid</div><div class='value'>{$zar($pv['evaluation_mid'] ?? null)}</div></div>";
            $html .= "<div class='summary-box'><div class='label'>High</div><div class='value'>{$zar($pv['evaluation_high'] ?? null)}</div></div>";
            $html .= '</div>';
            $html .= '</div>';
        }

        // GRM
        if (($methods['gross_rent_multiplier']['applicable'] ?? false)) {
            $grm = $methods['gross_rent_multiplier'];
            $cls = 'gross_rent_multiplier' === $primary ? ' primary' : '';
            $html .= "<div class='method-card{$cls}'>";
            $html .= "<div class='method-title'>{$labels['gross_rent_multiplier']}</div>";
            if ('gross_rent_multiplier' === $primary) $html .= ' <span class="badge badge-primary">Primary Method</span>';
            $html .= "<div class='method-desc'>Annual Rental × GRM factor — a quick-check method.</div>";
            $html .= "<p style='margin-bottom:8px;'>Annual Rental Income: <span class='mono fw-bold'>{$zar($grm['annual_rent'] ?? null)}</span></p>";
            $html .= '<div class="summary-grid">';
            $html .= "<div class='summary-box'><div class='label'>{$grm['grm_range'][0]}×</div><div class='value'>{$zar($grm['evaluation'][0] ?? null)}</div></div>";
            $html .= "<div class='summary-box highlight'><div class='label'>{$grm['grm_range'][1]}×</div><div class='value'>{$zar($grm['evaluation'][1] ?? null)}</div></div>";
            $html .= "<div class='summary-box'><div class='label'>{$grm['grm_range'][2]}×</div><div class='value'>{$zar($grm['evaluation'][2] ?? null)}</div></div>";
            $html .= '</div>';
            $html .= '</div>';
        }

        // Skipped methods
        if (!empty($skipped)) {
            $html .= '<h3>Methods Not Applied</h3>';
            $html .= '<table><tr><th>Method</th><th>Reason</th></tr>';
            foreach ($skipped as $sk => $reason) {
                $label = htmlspecialchars($labels[$sk] ?? $sk);
                $html .= "<tr><td>{$label}</td><td class='text-muted'>" . htmlspecialchars($reason) . "</td></tr>";
            }
            $html .= '</table>';
        }

        return $html;
    }

    private function recommendedEvaluation(array $rec, array $labels, callable $zar): string
    {
        $primary = $labels[$rec['primary_method'] ?? ''] ?? ($rec['primary_method'] ?? '—');
        $confClass = match ($rec['confidence'] ?? 'low') {
            'high'     => 'badge-confidence-high',
            'moderate' => 'badge-confidence-moderate',
            default    => 'badge-confidence-low',
        };

        $html = '<div class="page-break"></div>';
        $html .= '<h2>Recommended Market Evaluation</h2>';
        $html .= '<div class="summary-grid">';
        $html .= "<div class='summary-box'><div class='label'>Conservative</div><div class='value'>{$zar($rec['low'] ?? null)}</div></div>";
        $html .= "<div class='summary-box highlight'><div class='label'>Market Evaluation</div><div class='value'>{$zar($rec['mid'] ?? null)}</div></div>";
        $html .= "<div class='summary-box'><div class='label'>Optimistic</div><div class='value'>{$zar($rec['high'] ?? null)}</div></div>";
        $html .= '</div>';

        $html .= '<div class="card">';
        $html .= "<p style='margin-bottom:6px;'><strong>Primary Method:</strong> " . htmlspecialchars($primary) . "</p>";
        $html .= "<p style='margin-bottom:6px;color:#64748b;font-size:10px;'>" . htmlspecialchars($rec['primary_reason'] ?? '') . "</p>";
        $html .= "<p style='margin-bottom:4px;'><strong>Confidence:</strong> <span class='badge {$confClass}'>" . ucfirst($rec['confidence'] ?? 'unknown') . "</span></p>";
        $html .= "<p style='color:#94a3b8;font-size:10px;'>" . htmlspecialchars($rec['confidence_reason'] ?? '') . "</p>";
        $html .= '</div>';

        return $html;
    }

    private function sellerResponses(CommercialEvaluation $e): ?string
    {
        $cropConfig = config('agricultural_crops.crops', []);
        $livestockConfig = config('agricultural_crops.livestock', []);

        $hasAny = false;

        // Check if there are any answers at all
        foreach ($e->crops as $c) {
            if (!empty($c->guidance_answers)) { $hasAny = true; break; }
        }
        if (!$hasAny) {
            foreach ($e->livestock as $l) {
                if (!empty($l->guidance_answers)) { $hasAny = true; break; }
            }
        }

        if (!$hasAny) {
            return null;
        }

        $html = '<div class="page-break"></div>';
        $html .= '<h2>Property Intelligence — Seller Responses</h2>';
        $html .= '<p style="color:#64748b;font-size:10px;margin-bottom:12px;">The following responses were captured during the seller interview and provide context for the evaluation assumptions.</p>';

        // Crop responses
        foreach ($e->crops as $c) {
            if (empty($c->guidance_answers)) continue;

            $questions = $cropConfig[$c->crop_type]['questions'] ?? [];
            $qMap = collect($questions)->keyBy('id');
            $typeLabel = htmlspecialchars($cropConfig[$c->crop_type]['label'] ?? ucfirst($c->crop_type));
            $variety = $c->variety ? ' — ' . htmlspecialchars($c->variety) : '';

            $html .= "<h3>{$typeLabel}{$variety}</h3>";
            $html .= '<table>';
            $html .= '<tr><th style="width:45%;">Question</th><th>Seller Response</th></tr>';

            foreach ($c->guidance_answers as $qId => $answer) {
                if ($answer === '' || $answer === null) continue;
                $qDef = $qMap[$qId] ?? null;
                $qText = htmlspecialchars($qDef['question'] ?? $qId);
                $unit = $qDef['unit'] ?? '';
                $ansText = htmlspecialchars((string) $answer) . ($unit ? " {$unit}" : '');
                $html .= "<tr><td style='color:#64748b;'>{$qText}</td><td>{$ansText}</td></tr>";
            }
            $html .= '</table>';
        }

        // Livestock responses
        foreach ($e->livestock as $l) {
            if (empty($l->guidance_answers)) continue;

            $questions = $livestockConfig[$l->livestock_type]['questions'] ?? [];
            $qMap = collect($questions)->keyBy('id');
            $typeLabel = htmlspecialchars($livestockConfig[$l->livestock_type]['label'] ?? ucfirst($l->livestock_type));
            $breed = $l->breed ? ' — ' . htmlspecialchars($l->breed) : '';

            $html .= "<h3>{$typeLabel}{$breed}</h3>";
            $html .= '<table>';
            $html .= '<tr><th style="width:45%;">Question</th><th>Seller Response</th></tr>';

            foreach ($l->guidance_answers as $qId => $answer) {
                if ($answer === '' || $answer === null) continue;
                $qDef = $qMap[$qId] ?? null;
                $qText = htmlspecialchars($qDef['question'] ?? $qId);
                $unit = $qDef['unit'] ?? '';
                $ansText = htmlspecialchars((string) $answer) . ($unit ? " {$unit}" : '');
                $html .= "<tr><td style='color:#64748b;'>{$qText}</td><td>{$ansText}</td></tr>";
            }
            $html .= '</table>';
        }

        return $html;
    }

    private function disclaimer(): string
    {
        return <<<'HTML'

<div class="disclaimer">
    <strong>Important Disclaimer</strong><br><br>
    This market evaluation is prepared for guidance purposes only and does not constitute a formal
    property valuation as defined by the Property Valuers Profession Act (Act No. 47 of 2000).
    The figures presented are based on available data, comparable sales, financial information provided,
    and industry-standard evaluation methodologies. They should be used as indicative guidelines only.<br><br>
    For a formal valuation for mortgage bond, tax, insurance, expropriation, or legal purposes,
    please consult a registered property valuer accredited by the South African Council for the
    Property Valuers Profession (SACPVP).<br><br>
    Home Finders Coastal and its agents accept no liability for decisions made based on this evaluation report.
</div>

<div style="text-align:center;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;">
    <p style="font-size:10px;color:#94a3b8;">Home Finders Coastal — KZN South Coast</p>
    <p style="font-size:9px;color:#cbd5e1;">This report was generated by HF Coastal Nexus OS</p>
</div>
HTML;
    }
}
