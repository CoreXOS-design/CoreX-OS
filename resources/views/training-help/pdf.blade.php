<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $doc->title }}</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #1a1a2e; margin: 40px; }
        h1 { font-size: 22px; color: #0b2a4a; border-bottom: 2px solid #0ea5e9; padding-bottom: 8px; margin-bottom: 16px; }
        h2 { font-size: 16px; color: #0b2a4a; margin-top: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        h3 { font-size: 14px; color: #374151; margin-top: 16px; }
        table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 11px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: 600; }
        code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: 11px; }
        pre { background: #f3f4f6; padding: 12px; border-radius: 6px; overflow: auto; font-size: 11px; }
        blockquote { border-left: 3px solid #0ea5e9; margin: 12px 0; padding: 8px 16px; background: #f0f9ff; color: #374151; }
        .header { text-align: center; margin-bottom: 30px; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb; }
        .header img { height: 40px; margin-bottom: 8px; }
        .header .meta { font-size: 11px; color: #6b7280; }
        .footer { position: fixed; bottom: 20px; left: 40px; right: 40px; text-align: center; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
        .screenshot-placeholder { padding: 12px 16px; border-radius: 6px; margin: 12px 0; font-size: 11px; font-style: italic; background: #f0f9ff; border: 1px dashed #93c5fd; color: #6b7280; }
        p { margin: 8px 0; }
        ul, ol { margin: 8px 0; padding-left: 24px; }
        li { margin: 4px 0; }
        @page { margin: 40px; }
    </style>
</head>
<body>
    <div class="header">
        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 2px; color: #0ea5e9; font-weight: 600;">CoreX OS Training Centre</div>
        <h1 style="border: none; margin: 8px 0; padding: 0;">{{ $doc->title }}</h1>
        <div class="meta">
            Version {{ $doc->version }} &middot;
            {{ $doc->word_count }} words &middot;
            ~{{ $doc->reading_time }} min read &middot;
            Generated {{ now()->format('j M Y') }}
        </div>
    </div>

    {!! $content !!}

    <div class="footer">
        Generated from CoreX OS Training Centre &middot; {{ config('app.url') }}
    </div>
</body>
</html>
