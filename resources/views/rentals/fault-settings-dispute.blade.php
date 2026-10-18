<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>How you want faults handled</title>
    <style>
        body { margin: 0; background: #f3f5f9; font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.55; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #dfe3ea; border-radius: 10px; padding: 20px; margin-top: 12px; }
        h1 { font-size: 20px; margin: 0 0 6px; color: #1a365d; }
        .muted { color: #667085; font-size: 14px; }
        textarea { width: 100%; box-sizing: border-box; min-height: 90px; border: 1px solid #c7ced9; border-radius: 6px; padding: 10px; font: inherit; }
        .btns { display: flex; gap: 10px; margin-top: 16px; }
        .btn { flex: 1 1 0; display: block; box-sizing: border-box; text-align: center; padding: 12px 8px; border-radius: 6px; border: 2px solid #1a365d; font: inherit; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-primary { background: #1a365d; color: #fff; }
        .btn-outline { background: #fff; color: #1a365d; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" data-dispute-page="{{ $state }}">
        <p class="muted" style="margin:0 0 4px;">{{ $agencyName }}</p>
        <h1>How you want faults handled</h1>
        <p class="muted" style="margin-top:0;">{{ $address }}</p>

        @if($state === 'ask')
            <p>Dear {{ $ownerName }}, this is what is recorded for this property: <strong>{{ $lines }}</strong>.</p>
            <p>If you do not agree, say so here. Your agent will then ask you before every decision about a fault, until you confirm your own settings.</p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <label for="reason" class="muted">Anything you would like us to know (optional)</label>
                <textarea id="reason" name="reason" maxlength="1000"></textarea>
                <div class="btns">
                    <button type="submit" class="btn btn-primary" data-confirm-dispute>Yes, I do not agree</button>
                    <a href="{{ $portalUrl }}" class="btn btn-outline" data-open-my-settings>Open my settings instead</a>
                </div>
            </form>
        @elseif($state === 'recorded')
            <p><strong>Thank you - we have recorded that you do not agree.</strong></p>
            <p>Your agent has been told. Until you confirm your own settings, every decision about a fault at this property will be put to you first.</p>
            <div class="btns"><a href="{{ $portalUrl }}" class="btn btn-primary" data-open-my-settings>Open my settings</a></div>
        @elseif($state === 'already')
            <p><strong>We already have it recorded that you do not agree.</strong></p>
            <p>Your agent will ask you before every decision until you confirm your own settings.</p>
            <div class="btns"><a href="{{ $portalUrl }}" class="btn btn-primary" data-open-my-settings>Open my settings</a></div>
        @elseif($state === 'stale')
            <p><strong>These settings have changed since that email was sent.</strong></p>
            <p>Please open your settings to see what is recorded now, and confirm it or change it there.</p>
            <div class="btns"><a href="{{ $portalUrl }}" class="btn btn-primary" data-open-my-settings>Open my settings</a></div>
        @else
            <p>There is nothing recorded for this property yet.</p>
        @endif
    </div>
</div>
</body>
</html>
