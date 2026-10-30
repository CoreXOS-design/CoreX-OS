@extends('attorney-portal.layout')
@section('title', 'Sign-in & password')
@section('content')
<p><a href="{{ route('attorney.deals') }}">&larr; Back to my deals</a></p>
<h1>Sign-in &amp; password</h1>
<p class="sub">Choose how you open your deals. Your choice is saved for you and you can change it here at any time.</p>
@if(session('saved'))<div class="card" style="border-color:#16a34a;">{{ session('saved') }}</div>@endif
<div class="card">
    <h2>Right now: {{ $choice === 'password' ? 'email and password' : ($choice === 'pin' ? 'an emailed PIN each time' : 'not chosen yet') }}</h2>
    @if($choice !== 'pin')
        <form method="POST" action="{{ route('attorney.account.pin') }}">@csrf<button type="submit" class="btn ghost">Keep using an emailed PIN each time</button></form>
        <p class="muted" style="font-size:.85rem;">Best if you only open your deals now and then. Any password you set is removed.</p>
    @else
        <p class="muted">You are using an emailed PIN each time. Set a password below if you sign in often.</p>
    @endif
</div>
<div class="card">
    <h2>{{ $hasPassword ? 'Change password' : 'Set up a password' }}</h2>
    <form method="POST" action="{{ route('attorney.account.password') }}" style="max-width:420px;">
        @csrf
        @if($hasPassword && ! $resetOk)
            <label for="current_password" style="display:block;font-weight:600;margin-bottom:4px;">Current password</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
            @error('current_password')<p style="color:#b91c1c;margin:0 0 12px;">{{ $message }}</p>@enderror
        @endif
        <label for="password" style="display:block;font-weight:600;margin-bottom:4px;">New password (at least 8 characters)</label>
        <input id="password" name="password" type="password" required autocomplete="new-password" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
        @error('password')<p style="color:#b91c1c;margin:0 0 12px;">{{ $message }}</p>@enderror
        <label for="password_confirmation" style="display:block;font-weight:600;margin-bottom:4px;">Type it again</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
        <button type="submit" class="btn">{{ $hasPassword ? 'Change password' : 'Save password' }}</button>
    </form>
</div>
@endsection
