<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- .ai/specs/rental-work-orders.md §"Printing" — a fault report handed
         to a landlord. Same dompdf A4 convention as
         corex.properties.brochure-pdf and the work-order PDF alongside it.
         Character-for-character the record's own data (.ai/STANDARDS.md:915). --}}
    @php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 24px 32px; }
        html, body { margin: 0; padding: 0; background: #ffffff; color: #0b2a4a; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
        p { margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.label { width: 160px; color: #4b5563; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .logo-cell { display: table-cell; vertical-align: middle; }
        .header .title-cell { display: table-cell; vertical-align: middle; text-align: right; }
        .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; }
        .note { white-space: pre-wrap; }
        .muted { color: #6b7280; }
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
            <h1>Fault Report</h1>
            <p class="muted">#{{ $faultReport->id }} &middot; {{ $faultReport->reported_at?->format('Y-m-d') }}</p>
        </div>
    </div>

    <div class="box">
        <table>
            <tr><td class="label">Property</td><td>{{ $faultReport->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Tenancy</td><td>{{ $faultReport->lease?->tenantNames() ?? '—' }}</td></tr>
            <tr><td class="label">Reported by</td><td>{{ ucfirst(str_replace('_', ' ', $faultReport->reported_by_type)) }}</td></tr>
            <tr><td class="label">Channel</td><td>{{ ucfirst(str_replace('_', ' ', $faultReport->reported_channel)) }}</td></tr>
        </table>
    </div>

    <h2>What was reported</h2>
    <div class="box">
        <p><strong>{{ $faultReport->title }}</strong></p>
        <p class="note">{{ $faultReport->description }}</p>
    </div>

    @if($faultReport->outcome)
    <h2>Outcome</h2>
    <div class="box">
        <table>
            <tr><td class="label">Outcome</td><td>{{ ucfirst(str_replace('_', ' ', $faultReport->outcome)) }}</td></tr>
            @if($faultReport->repaired_at)
            <tr><td class="label">Repaired</td><td>{{ $faultReport->repaired_at->format('Y-m-d') }}</td></tr>
            @endif
        </table>
        @if($faultReport->outcome_note)
            <p class="note">{{ $faultReport->outcome_note }}</p>
        @endif
    </div>
    @endif

    <h2>Agency contact</h2>
    <div class="box">
        <p>{{ $agencyName }}</p>
    </div>
</body>
</html>
