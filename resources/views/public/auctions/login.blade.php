@extends('public.auctions._layout')
@section('title', 'Log in')
@section('content')
<div class="narrow">
    <div class="panel">
        <h1 style="margin-top:0;">Log in</h1>
        <form method="POST" action="{{ route('public.auctions.login.submit', $agency->slug) }}" class="stack">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            <p style="margin-top:16px;"><button class="btn" type="submit">Log in</button></p>
        </form>
        <p class="small"><a href="{{ route('public.auctions.forgot', $agency->slug) }}">Forgot your password?</a></p>
        <p class="small muted">New here? <a href="{{ route('public.auctions.signup', $agency->slug) }}">Create a free account</a>.</p>
    </div>
</div>
@endsection
