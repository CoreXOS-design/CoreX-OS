<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- dompdf A4 wrapper for the Printable Brochure. The brochure div is 794px
         wide (= A4 @ 96dpi); @page margin 0 lets it fill the sheet edge-to-edge,
         and the brochure's own 30px padding provides the print margin. --}}
    <style>
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; background: #ffffff; }
        * { box-sizing: border-box; }
        p { margin: 0; }
        table { page-break-inside: auto; }
    </style>
</head>
<body>
    @include('corex.properties._brochure', ['b' => $b])
</body>
</html>
