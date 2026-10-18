<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- .ai/specs/leases.md §12.2 — "Print tenancy report": parties, terms,
         lifecycle, full tenancy log with dates. Same dompdf A4 convention as
         the fault-report/work-order PDFs alongside it
         (RentalDocumentPdfService::applyOptions()). --}}
    @php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 24px 32px; }
        /* §14.23 — NO html/body margin reset: dompdf lets the html box win over @page, so a
           "margin:0" here removed the page margins and text ran to the paper edge. */
        html, body { background: #ffffff; color: #0b2a4a; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
        p { margin: 0 0 4px; }
        p, td, th, li { word-wrap: break-word; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { padding: 3px 0; vertical-align: top; }
        td.label { width: 160px; color: #4b5563; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .logo-cell { display: table-cell; vertical-align: middle; }
        .header .title-cell { display: table-cell; vertical-align: middle; text-align: right; }
        .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; }
        .muted { color: #6b7280; }
        .pill { display: inline-block; border: 1px solid #d1d5db; border-radius: 10px; padding: 1px 6px; font-size: 9px; margin-right: 4px; }
        .log-row td { border-bottom: 1px solid #e5e7eb; padding: 4px 2px; }
        .log-date { width: 80px; color: #4b5563; }
        .log-type { width: 90px; }
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
            <h1>Tenancy Report</h1>
            <p class="muted">Lease #{{ $lease->id }} &middot; {{ now()->format('Y-m-d') }}</p>
        </div>
    </div>

    <div class="box">
        <table>
            <tr><td class="label">Property</td><td>{{ $lease->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Status</td><td>{{ ucfirst($lease->status) }}</td></tr>
            <tr><td class="label">Tenant(s)</td><td>{{ $lease->tenantNames() }}</td></tr>
            <tr><td class="label">Landlord(s)</td><td>{{ $landlords->isEmpty() ? '—' : $landlords->map(fn ($c) => $c->full_name)->implode(', ') }}</td></tr>
            <tr><td class="label">Monthly rental</td><td>R{{ number_format((float) $lease->rental_amount, 2) }}</td></tr>
            <tr><td class="label">Deposit</td><td>{{ $lease->deposit_amount !== null ? 'R' . number_format((float) $lease->deposit_amount, 2) : '—' }}</td></tr>
            <tr><td class="label">Term</td><td>{{ $lease->start_date?->format('Y-m-d') }} &ndash; {{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</td></tr>
        </table>
    </div>

    <h2>Lifecycle</h2>
    <div class="box">
        @foreach($lifecycle as $step)
            <span class="pill">{{ $step['label'] }} ({{ $step['state'] }})</span>
        @endforeach
    </div>

    <h2>Tenancy log</h2>
    <div class="box">
        @forelse($timeline as $entry)
            <table class="log-row">
                <tr>
                    <td class="log-date">{{ \Illuminate\Support\Carbon::parse($entry['occurred_at'])->format('Y-m-d') }}</td>
                    <td class="log-type">{{ ucfirst(str_replace('_', ' ', $entry['type'])) }}</td>
                    <td>{{ $entry['description'] }}{{ $entry['actor'] ? ' — ' . $entry['actor'] : '' }}</td>
                </tr>
            </table>
        @empty
            <p class="muted">Nothing recorded yet on this tenancy.</p>
        @endforelse
    </div>
</body>
</html>
