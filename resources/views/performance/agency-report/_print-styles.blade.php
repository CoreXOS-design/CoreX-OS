{{-- AT-366 (cc1 #8) — shared print stylesheet + company header for the report printables. --}}
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#111; background:#fff; margin:0; padding:24px; font-size:12px; }
    .print-header { display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:2px solid #111; padding-bottom:12px; margin-bottom:16px; }
    .print-header .agency { font-size:18px; font-weight:700; }
    .print-header .meta { text-align:right; font-size:11px; color:#555; }
    .print-header .logo { max-height:48px; max-width:180px; object-fit:contain; }
    h1.report-title { font-size:15px; margin:0 0 2px; }
    h2.section { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#666; margin:18px 0 6px; }
    table { width:100%; border-collapse:collapse; font-size:11px; }
    th, td { padding:5px 8px; border-bottom:1px solid #ddd; }
    th { text-align:right; color:#555; border-bottom:1px solid #111; white-space:nowrap; }
    th.l, td.l { text-align:left; }
    .cards { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:8px; }
    .card { border:1px solid #ccc; border-radius:6px; padding:10px; }
    .card .v { font-size:18px; font-weight:700; }
    .card .k { font-size:10px; color:#666; }
    .trend-up { color:#15803d; } .trend-down { color:#b91c1c; } .trend-flat { color:#666; }
    .foot { margin-top:16px; font-size:9px; color:#888; }
    .print-actions { margin-bottom:14px; }
    .print-actions button { font-size:12px; padding:6px 12px; border:1px solid #111; background:#111; color:#fff; border-radius:5px; cursor:pointer; }
    @media print {
        body { padding:0; }
        .print-actions { display:none; }
        thead { display:table-header-group; }
        tr { break-inside:avoid; }
        .card { break-inside:avoid; }
    }
</style>
@php
    $logoData = null;
    try {
        $logoPath = $agency?->logo_path ?? $agency?->logo ?? null;
        if ($logoPath && \Illuminate\Support\Facades\Storage::exists($logoPath)) {
            $raw = \Illuminate\Support\Facades\Storage::get($logoPath);
            $mime = \Illuminate\Support\Facades\Storage::mimeType($logoPath) ?: 'image/png';
            $logoData = 'data:' . $mime . ';base64,' . base64_encode($raw);
        }
    } catch (\Throwable $e) { $logoData = null; }
@endphp
<div class="print-header">
    <div>
        @if($logoData)
            <img src="{{ $logoData }}" alt="{{ $agency?->name }}" class="logo">
        @else
            <div class="agency">{{ $agency?->name ?? 'Agency' }}</div>
        @endif
        <h1 class="report-title">{{ $printTitle ?? 'Agency Performance & ROI' }}</h1>
    </div>
    <div class="meta">
        <div><strong>{{ $agency?->name }}</strong></div>
        <div>{{ $periodLabel ?? '' }}</div>
        <div>Generated {{ now()->format('d M Y, H:i') }}</div>
    </div>
</div>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save PDF</button>
</div>
