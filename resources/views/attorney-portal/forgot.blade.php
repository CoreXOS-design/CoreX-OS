@extends('attorney-portal.layout')
@section('title', 'Forgot password')
@section('content')
<div style="max-width:460px;margin:24px auto;">
    <div class="card">
        <h1>Forgot your password?</h1>
        @if($sent)
            <p class="sub">If that email has a password set up, we have emailed it a PIN. Type it here, then choose a new password.</p>
            <form method="POST" action="{{ route('attorney.forgot.verify') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <label for="code" style="display:block;font-weight:600;margin-bottom:4px;">PIN from the email</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
                @error('code')<p style="color:#b91c1c;margin:0 0 12px;">{{ $message }}</p>@enderror
                <button type="submit" class="btn">Continue</button>
            </form>
        @else
            <p class="sub">Type your email. We will send you a PIN so you can choose a new password.</p>
            <form method="POST" action="{{ route('attorney.forgot.post') }}">
                @csrf
                <label for="email" style="display:block;font-weight:600;margin-bottom:4px;">Email</label>
                <input id="email" name="email" type="email" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
                @error('email')<p style="color:#b91c1c;margin:0 0 12px;">{{ $message }}</p>@enderror
                <button type="submit" class="btn">Email me a PIN</button>
            </form>
        @endif
        <p style="margin-top:16px;"><a href="{{ route('attorney.sign-in') }}">Back to sign in</a></p>
    </div>
</div>
@endsection
