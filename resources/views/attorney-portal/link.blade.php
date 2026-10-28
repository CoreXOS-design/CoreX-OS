@extends('attorney-portal.layout')
@section('title', 'Open your deals')
@section('content')
<div style="max-width:520px;margin:24px auto;">
    <div class="card">
        <h1>Hello {{ $personName }}</h1>
        <p class="sub">{{ $agencyName }} has shared your deals with you. To make sure it is really you, we email you a short code.</p>

        @if(session('status')) <div class="ok">{{ session('status') }}</div> @endif
        @if($errors->any()) <div class="error" data-ap-error>{{ $errors->first() }}</div> @endif

        @if(($throttled ?? null) === 'hourly')
            {{-- Blocked: nothing was sent, so say nothing about an email - only the wait. --}}
        @elseif(!$codeSent)
            <p>We will send a code to <strong>{{ $maskedMail }}</strong>.</p>
            <form method="POST" action="{{ ($codeUrl ?? route('attorney.code', $token)) }}">
                @csrf
                <button type="submit" class="btn">Email me a code</button>
            </form>
        @else
            <p data-ap-sent style="margin:0 0 10px;">We emailed a 6-digit code to <strong>{{ $maskedMail }}</strong>@if($codeSentAt) at <strong>{{ $codeSentAt->format('H:i') }}</strong>@endif.</p>
            <form method="POST" action="{{ ($verifyUrl ?? route('attorney.verify', $token)) }}">
                @csrf
                <label for="code" style="display:block;margin-bottom:6px;">Type the code here</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus
                       style="font-size:1.6rem;letter-spacing:.35em;padding:10px 14px;width:100%;border:1px solid var(--line);border-radius:10px;text-align:center;">
                <p style="margin:14px 0 0;"><button type="submit" class="btn">Open my deals</button></p>
            </form>
            <form method="POST" action="{{ ($codeUrl ?? route('attorney.code', $token)) }}" style="margin-top:14px;">
                @csrf
                <button type="submit" class="btn ghost">Send me a new code</button>
            </form>
        @endif
        @if(($throttled ?? null) !== 'hourly')
        <p class="muted" style="margin-top:18px;font-size:.85rem;">The code works for 10 minutes. Nobody else can open your deals with this link.</p>
        @endif
    </div>
</div>
@endsection
