@extends('attorney-portal.layout')
@section('title', 'Open your deals')
@section('content')
<div style="max-width:520px;margin:24px auto;">
    <div class="card">
        <h1>Hello {{ $personName }}</h1>
        <p class="sub">{{ $agencyName }} has shared your deals with you. To make sure it is really you, we email you a short code.</p>

        @if(session('status')) <div class="ok">{{ session('status') }}</div> @endif
        @if($errors->any()) <div class="error">{{ $errors->first() }}</div> @endif

        @if(!$codeSent)
            <p>We will send a code to <strong>{{ $maskedMail }}</strong>.</p>
            <form method="POST" action="{{ route('attorney.code', $token) }}">
                @csrf
                <button type="submit" class="btn">Email me a code</button>
            </form>
        @else
            <form method="POST" action="{{ route('attorney.verify', $token) }}">
                @csrf
                <label for="code" style="display:block;margin-bottom:6px;">Enter the 6-digit code we emailed to {{ $maskedMail }}</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus
                       style="font-size:1.6rem;letter-spacing:.35em;padding:10px 14px;width:100%;border:1px solid var(--line);border-radius:10px;text-align:center;">
                <p style="margin:14px 0 0;"><button type="submit" class="btn">Open my deals</button></p>
            </form>
            <form method="POST" action="{{ route('attorney.code', $token) }}" style="margin-top:14px;">
                @csrf
                <button type="submit" class="btn ghost">Send me a new code</button>
            </form>
        @endif
        <p class="muted" style="margin-top:18px;font-size:.85rem;">The code works for 10 minutes. Nobody else can open your deals with this link.</p>
    </div>
</div>
@endsection
