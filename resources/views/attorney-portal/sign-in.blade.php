@extends('attorney-portal.layout')
@section('title', 'Sign in')
@section('content')
<div style="max-width:460px;margin:24px auto;">
    <div class="card">
        <h1>Sign in</h1>
        <p class="sub">For attorneys who set up a password. If you did not, open the link in the email your agent sent you: it asks for an emailed PIN.</p>
        <form method="POST" action="{{ route('attorney.sign-in.post') }}">
            @csrf
            <label for="email" style="display:block;font-weight:600;margin-bottom:4px;">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
            <label for="password" style="display:block;font-weight:600;margin-bottom:4px;">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;margin-bottom:12px;">
            @error('password')<p style="color:#b91c1c;margin:0 0 12px;">{{ $message }}</p>@enderror
            @error('email')<p style="color:#b91c1c;margin:0 0 12px;">{{ $message }}</p>@enderror
            <button type="submit" class="btn">Sign in</button>
            <a href="{{ route('attorney.forgot') }}" style="margin-left:14px;font-size:.9rem;">Forgot password?</a>
        </form>
    </div>
</div>
@endsection
