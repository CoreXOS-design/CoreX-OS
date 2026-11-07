@extends('public.auctions._layout')
@section('title', 'Reset your password')
@section('content')
<div class="narrow">
    <div class="panel">
        <h1 style="margin-top:0;">Reset your password</h1>
        @if($step === 'email')
            <form method="POST" action="{{ route('public.auctions.forgot.send', $agency->slug) }}" class="stack">
                @csrf
                <label for="email">Your email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                <p style="margin-top:16px;"><button class="btn" type="submit">Send me a code</button></p>
            </form>
        @else
            <p class="muted small">Enter the 6-digit code we sent to <strong>{{ $email }}</strong> and choose a new password.</p>
            <form method="POST" action="{{ route('public.auctions.forgot.complete', $agency->slug) }}" class="stack">
                @csrf
                <label for="code">Code</label>
                <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" value="{{ old('code') }}" required autofocus autocomplete="one-time-code">
                <label for="password">New password (at least 8 characters)</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                <label for="password_confirmation">Repeat the password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                <p style="margin-top:16px;"><button class="btn" type="submit">Change password</button></p>
            </form>
        @endif
        <p class="small"><a href="{{ route('public.auctions.login', $agency->slug) }}">Back to log in</a></p>
    </div>
</div>
@endsection
