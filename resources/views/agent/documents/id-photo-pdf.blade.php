<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
        .page {
            width: 100%;
            height: 1000px;
            text-align: center;
            padding: 32px;
            box-sizing: border-box;
            page-break-after: always;
        }
        .page.last { page-break-after: auto; }
        .label {
            font-size: 13px;
            color: #444;
            margin-bottom: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        img {
            max-width: 100%;
            max-height: 880px;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="label">ID Document &mdash; Front</div>
        <img src="{{ $front }}" alt="ID front">
    </div>
    <div class="page last">
        <div class="label">ID Document &mdash; Back</div>
        <img src="{{ $back }}" alt="ID back">
    </div>
</body>
</html>
