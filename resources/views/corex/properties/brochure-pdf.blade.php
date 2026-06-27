<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
    {{-- dompdf A4 wrapper for the Printable Brochure. The brochure div is 794px
         wide (= A4 @ 96dpi); @page margin 0 lets it fill the sheet edge-to-edge.
         Inter (the CoreX UI font) is embedded so the PDF matches the app. --}}
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; background: #ffffff; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; }
        p { margin: 0; }
        table { page-break-inside: auto; }
    </style>
</head>
<body>
    @include('corex.properties._brochure', ['b' => $b])
</body>
</html>
