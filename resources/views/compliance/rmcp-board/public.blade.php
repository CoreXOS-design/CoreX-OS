<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Board approval of the RMCP - {{ $agencyName }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #eef2f7; color: #1a2733; margin: 0; }
        .card { max-width: 720px; margin: 32px auto; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,.08); overflow: hidden; }
        .head { background: #0b2a4a; color: #fff; padding: 20px 26px; }
        .head h1 { margin: 0; font-size: 18px; }
        .head p { margin: 6px 0 0; font-size: 13px; opacity: .85; }
        .body { padding: 26px; }
        .msg { padding: 11px 13px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        label { display: block; font-size: 12px; color: #64748b; margin-bottom: 6px; }
        input.code { width: 100%; box-sizing: border-box; padding: 11px 12px; font-size: 20px; letter-spacing: 4px; text-align: center; border: 1px solid #cbd5e1; border-radius: 8px; }
        button.main { width: 100%; padding: 13px; font-size: 15px; font-weight: bold; color: #fff; background: #0b2a4a; border: 0; border-radius: 8px; cursor: pointer; margin-top: 14px; }
        button.alt { width: 100%; padding: 12px; font-size: 14px; font-weight: bold; color: #0b2a4a; background: #e2e8f0; border: 0; border-radius: 8px; cursor: pointer; margin-top: 10px; }
        .letter { border: 1px solid #e2e8f0; border-radius: 8px; padding: 22px 24px; background: #fff; line-height: 1.6; }
        .letter h1 { font-size: 19px; color: #0b2a4a; margin: 0 0 10px; }
        .letter .kicker { text-transform: uppercase; letter-spacing: 1.5px; font-size: 11px; color: #64748b; margin: 0 0 4px; }
        .letter .fingerprint { font-size: 11px; color: #64748b; }
        .who { margin: 16px 0; font-size: 13px; color: #475569; }
        a.dl { display: block; text-align: center; text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
    <div class="head">
        <h1>Board approval of the RMCP</h1>
        <p>{{ $agencyName }} &middot; version {{ $version?->version_number }}</p>
    </div>
    <div class="body">
        @if(session('status'))<div class="msg ok">{{ session('status') }}</div>@endif
        @if(session('code_error'))<div class="msg err">{{ session('code_error') }}</div>@endif
        @if(session('sign_error'))<div class="msg err">{{ session('sign_error') }}</div>@endif
        @if($errors->any())<div class="msg err">{{ $errors->first() }}</div>@endif

        @if(! $verified)
            <p>Hello {{ $sig->member_name }}. Before we show you the letter, we need to check it is you.</p>
            @if($codeSent)
                <p>We emailed a six-digit code to <strong>{{ $maskedEmail }}</strong>. Type it here.</p>
                <form method="POST" action="{{ route('rmcp-board.verify', $token) }}">
                    @csrf
                    <label for="code">Six-digit code</label>
                    <input id="code" class="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
                    <button class="main" type="submit">Continue</button>
                </form>
                <form method="POST" action="{{ route('rmcp-board.code', $token) }}">
                    @csrf
                    <button class="alt" type="submit">Send me a new code</button>
                </form>
            @else
                <form method="POST" action="{{ route('rmcp-board.code', $token) }}">
                    @csrf
                    <button class="main" type="submit">Email me a code</button>
                </form>
                <p style="font-size:12px; color:#94a3b8; margin-top:14px;">The code goes to {{ $maskedEmail }}.</p>
            @endif
        @elseif($sig->isSigned())
            <div class="msg ok">You signed this letter on {{ $sig->signed_at->timezone(config('app.timezone'))->format('d F Y \a\t H:i') }}. Nothing more is needed from you.</div>
            @if($letter->isComplete() && $letter->signed_pdf_path)
                <p>Everyone has now signed. You can keep a copy of the signed letter.</p>
                <a class="dl" href="{{ route('rmcp-board.pdf', $token) }}"><button class="main" type="button">Download the signed letter</button></a>
            @else
                <p>We will let {{ $agencyName }} know when everyone has signed.</p>
            @endif
            <div class="letter" style="margin-top:18px;">{!! $letter->letter_html !!}</div>
        @else
            <p class="who">You are signing as <strong>{{ $sig->member_name }}</strong>, {{ $sig->member_title }}.</p>
            <div class="letter">{!! $letter->letter_html !!}</div>
            @include('compliance.rmcp-board._signature-capture', ['action' => route('rmcp-board.sign', $token), 'agencyName' => $agencyName])
        @endif
    </div>
</div>
</body>
</html>
