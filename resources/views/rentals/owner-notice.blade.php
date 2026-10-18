<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $action === 'query' ? 'Ask your agent' : 'Stop this' }}</title>
    <style>
        body { margin: 0; background: #f3f5f9; font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.55; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #dfe3ea; border-radius: 10px; padding: 20px; margin-top: 12px; }
        h1 { font-size: 20px; margin: 0 0 6px; color: #1a365d; }
        .muted { color: #667085; font-size: 14px; }
        .error { color: #9b1c1c; font-size: 14px; }
        .banner { background: #fdecec; border: 1px solid #9b1c1c; color: #7a1313; border-radius: 8px; padding: 10px 12px; font-weight: bold; }
        textarea { width: 100%; box-sizing: border-box; min-height: 90px; border: 1px solid #c7ced9; border-radius: 6px; padding: 10px; font: inherit; }
        .btns { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
        .btn { display: block; width: 100%; min-width: 0; box-sizing: border-box; text-align: center; padding: 12px 8px; border-radius: 6px; border: 2px solid #1a365d; font: inherit; font-weight: bold; text-decoration: none; cursor: pointer; background: #fff; color: #1a365d; }
        .btn-primary { background: #1a365d; color: #fff; }
        .btn-stop { background: #9b1c1c; border-color: #9b1c1c; color: #fff; }
        form.inline { display: block; margin: 0; }
        form.inline .btn { width: 100%; }
        .q { border-top: 1px solid #e6e9ef; padding-top: 8px; margin-top: 8px; font-size: 14px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" data-owner-notice-page="{{ $state }}">
        <p class="muted" style="margin:0 0 4px;">{{ $agencyName }}</p>
        <h1>{{ $jobTitle }}</h1>
        <p class="muted" style="margin-top:0;">{{ $address }}</p>
        @if($error)<p class="error" data-owner-notice-error>{{ $error }}</p>@endif

        @if($state === 'ask_stop')
            <p>Dear {{ $ownerName }}, stopping this puts the job on hold. Nothing more will be sent, approved or started until you say go ahead. You can only stop it until the work has started.</p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <input type="hidden" name="do" value="stop">
                <label for="reason" class="muted">Why do you want to stop it? (required)</label>
                <textarea id="reason" name="reason" maxlength="500" required data-stop-reason>{{ old('reason', request()->input('reason')) }}</textarea>
                <div class="btns">
                    <button type="submit" class="btn btn-stop" data-confirm-stop>Yes, stop this</button>
                    <a href="{{ $portalUrl }}" class="btn" data-open-portal>Not now</a>
                </div>
            </form>
        @elseif($state === 'stopped')
            <p class="banner" data-paused-banner>On hold &mdash; you stopped this.</p>
            <p>Your agents have been told, and the contractor has been asked not to attend. Nothing more will happen until you say go ahead.</p>
            <div class="btns">
                <form class="inline" method="POST" action="{{ $stopUrl }}">@csrf<input type="hidden" name="do" value="resume"><button type="submit" class="btn btn-primary" data-go-ahead>Go ahead after all</button></form>
                <a href="{{ $queryUrl }}" class="btn" data-open-query>Query this</a>
            </div>
        @elseif($state === 'held')
            <p class="banner" data-paused-banner>On hold &mdash; stopped by the owner.</p>
            <p data-hold-line>{{ $holdLine }}</p>
            <div class="btns">
                <form class="inline" method="POST" action="{{ $stopUrl }}">@csrf<input type="hidden" name="do" value="resume"><button type="submit" class="btn btn-primary" data-go-ahead>Go ahead after all</button></form>
                <a href="{{ $queryUrl }}" class="btn" data-open-query>Query this</a>
            </div>
        @elseif($state === 'resumed')
            <p><strong>Thank you &mdash; the hold is lifted.</strong></p>
            <p>Your agents have been told that you said go ahead.</p>
            <div class="btns"><a href="{{ $portalUrl }}" class="btn btn-primary" data-open-portal>Open my portal</a></div>
        @elseif($state === 'started')
            <p data-work-started><strong>Work has started &mdash; contact your agent.</strong></p>
            <p class="muted">It is too late to stop it from here. You can still ask your agent a question.</p>
            <div class="btns"><a href="{{ $queryUrl }}" class="btn btn-primary" data-open-query>Query this</a><span class="btn" style="visibility:hidden" aria-hidden="true"></span></div>
        @elseif($state === 'closed')
            <p>This job is closed, so there is nothing to stop.</p>
            <div class="btns"><a href="{{ $queryUrl }}" class="btn btn-primary" data-open-query>Query this</a><span class="btn" style="visibility:hidden" aria-hidden="true"></span></div>
        @elseif($state === 'asked')
            <p><strong>Thank you &mdash; your question has been sent.</strong></p>
            <p>Your agents have been told. Nothing has been stopped. You will be emailed the answer.</p>
            <div class="btns"><a href="{{ $portalUrl }}" class="btn btn-primary" data-open-portal>Open my portal</a><a href="{{ $queryUrl }}" class="btn">Ask another question</a></div>
        @else {{-- ask_query --}}
            <p>Dear {{ $ownerName }}, ask your agent anything about this. <strong>Asking does not stop anything.</strong></p>
            @foreach($queries as $q)
                <div class="q" data-owner-notice-q>
                    <div>You asked {{ $q->asked_at->copy()->setTimezone($tz)->format('j M Y H:i') }}: {{ $q->question }}</div>
                    @if($q->answer)<div><strong>{{ $q->answeredBy?->name ?? 'Your agent' }} answered:</strong> {{ $q->answer }}</div>@endif
                </div>
            @endforeach
            <form method="POST" action="{{ request()->fullUrl() }}" style="margin-top:12px;">
                @csrf
                <input type="hidden" name="do" value="ask">
                <label for="question" class="muted">Your question</label>
                <textarea id="question" name="question" maxlength="1000" required data-query-text></textarea>
                <div class="btns">
                    <button type="submit" class="btn btn-primary" data-send-query>Send my question</button>
                    <a href="{{ $stopUrl }}" class="btn" data-open-stop>Stop this</a>
                </div>
            </form>
        @endif
    </div>
</div>
</body>
</html>
