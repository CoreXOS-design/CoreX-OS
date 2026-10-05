<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- AT-445 — .ai/specs/rental-portal-access.md §8. Same dompdf A4
         convention as every other rentals PDF (RentalDocumentPdfService). --}}
    @php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 24px 32px; }
        html, body { margin: 0; padding: 0; background: #fff; color: #0b2a4a; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 11px; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .logo-cell { display: table-cell; vertical-align: middle; }
        .header .title-cell { display: table-cell; vertical-align: middle; text-align: right; }
        h1 { font-size: 16px; margin: 0; text-transform: uppercase; }
        .body-content { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-cell">
            @if($logo)<img src="{{ $logo }}" style="max-height:40px;">@else{{ $agencyName }}@endif
        </div>
        <div class="title-cell">
            <h1>{{ str_replace('_', ' ', $notice->notice_type) }} notice</h1>
        </div>
    </div>
    <div class="body-content">
        {!! $bodyHtml !!}
    </div>
</body>
</html>
