<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- AT-442 req #5 — same dompdf A4 convention as
         corex.rental-work-orders.pdf (font-face embed, @page margin). --}}
    @php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 24px 32px; }
        /* §14.22 — NO margin/padding reset here: dompdf lets the html/body box win over
           @page, so "margin:0" silently removed the 24px/32px page margins and the header
           and wide tables ran to / past the paper edge. */
        html, body { background: #ffffff; color: #0b2a4a; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
        p { margin: 0 0 4px; }
        p, td, th { word-wrap: break-word; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.label { width: 160px; color: #4b5563; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .logo-cell { display: table-cell; vertical-align: middle; }
        .header .title-cell { display: table-cell; vertical-align: middle; text-align: right; }
        .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; }
        .note { white-space: pre-wrap; }
        .muted { color: #6b7280; }
        .lines td, .lines th { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
        .lines th { color: #4b5563; font-weight: 600; }
        .total-row td { font-weight: 700; border-bottom: none; }
        /* §14.23 — the grand-totals box is never split across a page break (some rows on one page, the grand total on the next); a line is never split mid-row; the lines header repeats on page 2+ (thead). */
        .totals-box { page-break-inside: avoid; }
        .lines tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-cell">
            @if($logo)
                <img src="{{ $logo }}" alt="" style="height:48px;max-width:280px;">
            @else
                <span style="font-weight:700;font-size:18px;">{{ $agencyName }}</span>
            @endif
        </div>
        <div class="title-cell">
            <h1>Quote</h1>
            <p class="muted">Job card #{{ $jobCard->id }} &middot; {{ now()->format('Y-m-d') }}@if(($revision ?? null) > 1) &middot; Rev {{ $revision }} (replaces Rev {{ $revision - 1 }})@endif</p>
        </div>
    </div>

    {{-- BUILD 2 (.ai/specs/rental-work-orders.md §17.8.3) — emergency-approved work orders say so on every quote document. --}}
    @if(!empty($emergencyBanner))
        <div style="border:1px solid #fdba74;background:#fff7ed;color:#9a3412;border-radius:4px;padding:8px 12px;margin-bottom:10px;font-weight:600;">{{ $emergencyBanner }}</div>
    @endif

    <div class="box">
        <table>
            <tr><td class="label">Property</td><td>{{ $jobCard->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Tenancy</td><td>{{ $jobCard->lease?->tenantNames() ?? '—' }}</td></tr>
            <tr><td class="label">Crew</td><td>{{ $jobCard->crew?->name ?? ($jobCard->assigned_user_id ? 'Previously assigned: ' . ($jobCard->assignedUser?->name ?? '—') : '—') }}</td></tr>
            @if($jobCard->crew && $jobCard->crew->members->isNotEmpty())
            <tr><td class="label">Crew members</td><td>{{ $jobCard->crew->members->pluck('name')->implode(', ') }}</td></tr>
            @endif
            @if($vat['registered'] && $vatNumber)
            <tr><td class="label">VAT No</td><td>{{ $vatNumber }}</td></tr>
            @endif
        </table>
    </div>

    <h2>Job</h2>
    <div class="box">
        <p><strong>{{ $jobCard->title }}</strong></p>
    </div>

    @include('corex.rental-job-cards._pdf-source')

    {{-- 2026-10-05 rebuild (req #6) — same task → lines layout as the show
         screen and the printed job card, not a separate flat list. --}}
    <h2>Our maintenance team's quote</h2>
    @forelse($jobCard->tasks as $task)
        <div class="box">
            <p><strong>{{ $loop->iteration }} - {{ $task->description }}</strong></p>
            @include('corex.rental-job-cards._pdf-lines-table', ['lines' => $task->lines, 'pricesOn' => $pricesOn, 'vat' => $vat])
        </div>
    @empty
        <div class="box"><p class="muted">No tasks.</p></div>
    @endforelse

    @php $generalLines = $jobCard->lines->whereNull('rental_job_card_task_id'); @endphp
    @if($generalLines->isNotEmpty())
        <h2>General</h2>
        <div class="box">
            @include('corex.rental-job-cards._pdf-lines-table', ['lines' => $generalLines, 'pricesOn' => $pricesOn, 'vat' => $vat])
        </div>
    @endif

    @if($pricesOn)
    <div class="box totals-box">
        <table>
            @if($vat['registered'])
                <tr><td colspan="2">Subtotal (excl VAT)</td><td>R{{ number_format((float) $vat['subtotalExcl'], 2) }}</td></tr>
                @foreach($vat['groups'] as $group)
                    <tr><td colspan="2">{{ $group['label'] }}</td><td>R{{ number_format((float) $group['amount'], 2) }}</td></tr>
                @endforeach
                {{-- §14.21 — the VAT total in one figure whenever the per-rate lines above are not already exactly that (several rates, or none). --}}
                @if(count($vat['groups']) !== 1)
                    <tr><td colspan="2">Total VAT</td><td>R{{ number_format((float) $vat['totalVat'], 2) }}</td></tr>
                @endif
            @endif
            <tr class="total-row">
                <td colspan="2">{{ $vat['registered'] ? 'Total (incl VAT)' : 'Total' }}</td>
                <td>R{{ number_format($vat['registered'] ? (float) $vat['totalIncl'] : (float) ($jobCard->total_amount ?? 0), 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    <h2>Agency contact</h2>
    <div class="box">
        <p>{{ $agencyName }}</p>
    </div>
</body>
</html>
