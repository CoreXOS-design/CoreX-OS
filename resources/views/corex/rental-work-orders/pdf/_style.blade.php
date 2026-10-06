{{-- .ai/specs/rental-work-orders.md §17 — shared dompdf A4 look for the maintenance-flow documents (same convention as
     corex.rental-work-orders.pdf). Included inside <head>. --}}
@php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
<style>
    @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
    @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
    @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
    @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
    @page { margin: 24px 32px; }
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
    td.r, th.r { text-align: right; }
    .header { display: table; width: 100%; margin-bottom: 18px; }
    .header .logo-cell { display: table-cell; vertical-align: middle; }
    .header .title-cell { display: table-cell; vertical-align: middle; text-align: right; }
    .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; }
    .banner { border: 1px solid #fdba74; background: #fff7ed; color: #9a3412; border-radius: 4px; padding: 8px 12px; margin-bottom: 10px; font-weight: 600; }
    .note { white-space: pre-wrap; }
    .muted { color: #6b7280; }
    .lines td, .lines th { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
    .lines th { color: #4b5563; font-weight: 600; }
    .lines td.r, .lines th.r { text-align: right; }
    .lines tr { page-break-inside: avoid; }
    .total-row td { font-weight: 700; border-bottom: none; }
    .photos img { width: 150px; height: 112px; object-fit: cover; margin: 0 6px 6px 0; border: 1px solid #d1d5db; }
    .term { color: #4b5563; font-size: 10px; margin-top: 14px; padding-top: 8px; border-top: 1px solid #e5e7eb; }
</style>
