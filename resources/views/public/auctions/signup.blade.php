@extends('public.auctions._layout')
@section('title', 'Create your account')
@section('content')
<div class="narrow">
    <div class="panel">
        <h1 style="margin-top:0;">Create your account</h1>
        @if($step === 'email')
            <p class="muted small">A free account lets you see all auction documents, register for an auction and bid. We start by sending a code to your email.</p>
            <form method="POST" action="{{ route('public.auctions.signup.send', $agency->slug) }}" class="stack">
                @csrf
                <label for="email">Your email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                <p style="margin-top:16px;"><button class="btn" type="submit">Send me a code</button></p>
            </form>
            <p class="small muted">Already have an account? <a href="{{ route('public.auctions.login', $agency->slug) }}">Log in</a>.</p>
        @else
            <p class="muted small">We sent a 6-digit code to <strong>{{ $email }}</strong>. It is valid for 10 minutes. <a href="{{ route('public.auctions.signup', $agency->slug) }}">Use another email</a></p>
            <form method="POST" action="{{ route('public.auctions.signup.complete', $agency->slug) }}" class="stack">
                @csrf
                <label for="code">Code</label>
                <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" value="{{ old('code') }}" required autofocus autocomplete="one-time-code">
                <label for="name">Full name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="190" autocomplete="name">
                <label for="phone">Phone</label>
                <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="40" autocomplete="tel">
                <label for="password">Choose a password (at least 8 characters)</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                <label for="password_confirmation">Repeat the password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin-top:14px;">
                    <input type="checkbox" name="popia_consent" value="1" required style="margin-top:4px;">
                    <span class="small">I agree that {{ $branding['name'] }} may store my details and contact me about its auctions.</span>
                </label>
                <p style="margin-top:16px;"><button class="btn" type="submit">Create account</button></p>
            </form>
        @endif
    </div>
</div>
@endsection
