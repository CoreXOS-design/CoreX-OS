<!DOCTYPE html>
{{-- AT-534 spec 9.4 - stand-alone, always-light preview of a campaign email as the recipient sees it. Owner-only (the controller guards it).
     Receives: from, to, subject, html (null = plain text), text, picture, back, backLabel, note. Nothing here is sent or saved. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Email preview</title>
    <style>
        body { margin: 0; background: #eef0f3; color: #1f2933; font-family: Arial, Helvetica, sans-serif; }
        .bar { background: #1f2933; color: #fff; padding: 10px 16px; font-size: 13px; display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; }
        .bar a { color: #fff; }
        .wrap { max-width: 760px; margin: 18px auto; padding: 0 16px 40px; }
        .meta { background: #fff; border: 1px solid #d5d9de; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 14px 18px; font-size: 13px; }
        .meta div { margin: 2px 0; }
        .meta b { display: inline-block; min-width: 62px; color: #5f6b7a; font-weight: 600; }
        .subject { font-size: 17px; font-weight: 700; margin-bottom: 6px; }
        .mail { background: #fff; border: 1px solid #d5d9de; border-radius: 0 0 8px 8px; padding: 22px 18px; overflow-wrap: anywhere; }
        .mail pre { margin: 0; white-space: pre-wrap; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; background: #e6f4ea; color: #1e6b34; }
        .tag.plain { background: #fff4d6; color: #7a5b00; }
        .note { font-size: 12px; color: #5f6b7a; margin-top: 12px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="bar">
        <span><b>Preview only</b> - nothing is sent from this page.</span>
        <a href="{{ $back }}">&larr; {{ $backLabel }}</a>
    </div>
    <div class="wrap">
        <div class="meta">
            <div class="subject" data-preview-subject>{{ $subject }}</div>
            <div><b>From</b> {{ $from }}</div>
            <div><b>To</b> {{ $to }}</div>
            <div><b>Sent as</b>
                @if($picture)<span class="tag">Formatted email with your signature picture (plus a plain-text copy)</span>
                @else<span class="tag plain">Plain text - no signature picture set on this sending address</span>@endif
            </div>
        </div>
        <div class="mail" data-preview-body>
            @if($html !== null){!! $html !!}@else<pre>{{ $text }}</pre>@endif
        </div>
        <div class="note">{{ $note }} The unsubscribe line at the bottom is added to every email; each person gets their own link.</div>
    </div>
</body>
</html>
