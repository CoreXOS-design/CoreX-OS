<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- AT-445 — .ai/specs/rental-portal-access.md §8. Same dompdf A4
         convention as every other rentals PDF (RentalDocumentPdfService).
         AT-462 — the header is the agency's letterhead, read from the agency record
         (logo, name, address, registration, FFC, VAT, contact), so every agency's notices
         carry their own; a detail the agency has not filled in is simply left off. --}}
    @php
        $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter'));
        $line = fn (array $parts) => implode('  |  ', array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        $phones = $line([(string) ($agency?->phone ?? ''), (string) ($agency?->phone_secondary ?? '')]);
        $contactLine = $line([$phones, (string) ($agency?->email ?? '')]);
        $registrationLine = $line([
            trim((string) ($agency?->reg_no ?? '')) !== '' ? 'Reg. no. ' . trim((string) $agency->reg_no) : '',
            trim((string) ($agency?->ffc_no ?? '')) !== '' ? 'FFC ' . trim((string) $agency->ffc_no) : '',
            ($agency?->vat_registered && trim((string) ($agency?->vat_no ?? '')) !== '') ? 'VAT ' . trim((string) $agency->vat_no) : '',
        ]);
    @endphp
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 24px 32px; }
        /* §14.23 — NO html/body margin reset: dompdf lets the html box win over @page, so a
           "margin:0" here removed the page margins and text ran to the paper edge. */
        html, body { background: #fff; color: #0b2a4a; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 11px; line-height: 1.5; }
        .letterhead { display: table; width: 100%; padding-bottom: 10px; margin-bottom: 18px; border-bottom: 2px solid #0b2a4a; }
        .letterhead .logo-cell { display: table-cell; vertical-align: middle; width: 30%; }
        .letterhead .details-cell { display: table-cell; vertical-align: middle; text-align: right; font-size: 9px; color: #334e68; line-height: 1.55; }
        .letterhead .agency-name { font-size: 14px; font-weight: 700; color: #0b2a4a; }
        .body-content { margin-top: 20px; word-wrap: break-word; }
        .body-content p, .body-content td, .body-content th, .body-content li, .body-content div { word-wrap: break-word; }
        .body-content p { margin: 0 0 9px; }
        .body-content h1, .body-content h2, .body-content h3 { margin: 14px 0 8px; }
        .body-content img { max-width: 100%; }
        /* an auto-layout table grows to its longest unbroken word and runs past the margin; fixed layout keeps it inside the page */
        .body-content table { width: 100%; table-layout: fixed; }
    </style>
</head>
<body>
    <div class="letterhead" data-letterhead>
        <div class="logo-cell">
            @if($logo)<img src="{{ $logo }}" style="max-height:56px; max-width:180px;">@else<span class="agency-name">{{ $agencyName }}</span>@endif
        </div>
        <div class="details-cell">
            @if($logo)<div class="agency-name">{{ $agencyName }}</div>@endif
            @if(trim((string) ($agency?->address ?? '')) !== '')<div>{{ trim((string) $agency->address) }}</div>@endif
            @if($contactLine !== '')<div>{{ $contactLine }}</div>@endif
            @if($registrationLine !== '')<div>{{ $registrationLine }}</div>@endif
        </div>
    </div>
    <div class="body-content">
        {!! $bodyHtml !!}
    </div>
</body>
</html>
