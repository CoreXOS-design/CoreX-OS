<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Is the work finished? — {{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; background:#f4f6fb; color:#141821; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; -webkit-text-size-adjust: 100%; }
        header.top { background: {{ $agency['color'] }}; color:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        header.top img { height:34px; width:auto; max-width:120px; object-fit:contain; background:#fff; border-radius:6px; padding:3px; }
        header.top .name { font-size:15px; font-weight:700; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 14px 14px 40px; }
        .card { background:#fff; border:1px solid rgba(0,0,0,.09); border-radius:14px; padding:16px; margin-bottom:14px; }
        .title { font-size:19px; font-weight:800; margin:0 0 6px; line-height:1.25; }
        .muted { color:#5a6472; font-size:14px; line-height:1.45; }
        h2 { font-size:15px; margin:0 0 10px; font-weight:700; }
        .alert-success { background:#eafaf0; border:1px solid #1f8a4c; color:#1f8a4c; padding:12px; border-radius:12px; margin-bottom:14px; font-size:15px; font-weight:600; }
        .alert-error { background:#fdecea; border:1px solid #c0392b; color:#c0392b; padding:12px; border-radius:12px; margin-bottom:14px; font-size:15px; }
        .btn { display:block; width:100%; min-height:54px; padding:14px; border:0; border-radius:12px; font:inherit; font-size:17px; font-weight:800; text-align:center; cursor:pointer; margin-top:12px; }
        .btn.ok { background:#1f8a4c; color:#fff; }
        .btn.warn { background:#c0392b; color:#fff; }
        .btn.ghost { background:#eef3f8; color:#26435f; }
        textarea { width:100%; min-height:110px; padding:12px; border:1px solid rgba(0,0,0,.18); border-radius:10px; font:inherit; font-size:16px; }
        label.lbl { display:block; font-size:13px; font-weight:700; margin:12px 0 5px; }
        .photos { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:12px; }
        .photos a { display:block; aspect-ratio:1; border-radius:10px; overflow:hidden; background:#e8edf2; }
        .photos img { width:100%; height:100%; object-fit:cover; display:block; }
        details > summary { list-style:none; cursor:pointer; }
        details > summary::-webkit-details-marker { display:none; }
    </style>
</head>
<body>
    <header class="top">
        @if($agency['logo_url'])<img src="{{ $agency['logo_url'] }}" alt="">@endif
        <span class="name">{{ $agency['name'] }}</span>
    </header>
    <div class="wrap">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <div class="card">
            <div class="title">{{ $title }}</div>
            @if($address !== '')<div style="font-size:15px;">{{ $address }}</div>@endif
            <p class="muted" style="margin-top:10px;">
                {{ $reportedBy }} says this work is complete @if($reportedOn)({{ $reportedOn }})@endif.
                Please check it and tell us if it is done, or still wrong.
                @if($answerBy)If we don't hear from you by <strong>{{ $answerBy }}</strong> we will treat it as accepted.@endif
            </p>
            @if(count($photos))
                <div class="photos">
                    @foreach($photos as $p)
                        <a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="" loading="lazy"></a>
                    @endforeach
                </div>
            @endif
        </div>

        <form method="POST" action="{{ $actionUrl }}" enctype="multipart/form-data" id="completion-form">
            @csrf
            <div class="card">
                <button type="submit" name="answer" value="fixed" class="btn ok" id="answer-fixed">All done, thanks</button>
            </div>

            <div class="card">
                <details {{ $errors->any() || old('answer') === 'not_fixed' ? 'open' : '' }}>
                    <summary><span class="btn warn" style="margin-top:0;" id="answer-not-fixed-open">Not complete / still wrong</span></summary>
                    <label class="lbl" for="note">What is still wrong? (required)</label>
                    <textarea id="note" name="note" maxlength="2000" placeholder="For example: the tap is fixed but it still drips.">{{ old('note') }}</textarea>
                    <label class="lbl" for="photos">Photos (optional — up to {{ $maxPhotos }})</label>
                    <input id="photos" type="file" name="photos[]" accept="image/*" multiple style="width:100%; min-height:48px; font-size:15px;">
                    <button type="submit" name="answer" value="not_fixed" class="btn warn" id="answer-not-fixed">Send — it is not complete</button>
                </details>
            </div>
        </form>
        <script>
            (function () {
                var form = document.getElementById('completion-form');
                form.addEventListener('submit', function (e) {
                    var clicked = e.submitter ? e.submitter.value : null;
                    var note = document.getElementById('note').value.trim();
                    if (clicked === 'not_fixed' && note.length < 5) {
                        e.preventDefault();
                        document.getElementById('note').focus();
                        window.corexNotice('Please tell us what is still wrong (at least 5 characters).');
                        return;
                    }
                    form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
                    if (e.submitter) {
                        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'answer'; h.value = e.submitter.value; form.appendChild(h);
                    }
                });
            })();
        </script>
    </div>
@include('partials.corex-confirm')
</body>
</html>
