<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Helvetica, Arial, sans-serif; }

        .page-container {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
            page-break-after: always;
        }
        .page-container:last-child {
            page-break-after: auto;
        }

        .page-image {
            width: 210mm;
            height: 297mm;
            display: block;
        }
    </style>
</head>
<body>
    @foreach($pages as $i => $pageBase64)
        <div class="page-container">
            <img class="page-image" src="{{ $pageBase64 }}" alt="Page {{ $i + 1 }}">
        </div>
    @endforeach
</body>
</html>
