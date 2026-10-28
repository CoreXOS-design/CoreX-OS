@extends('attorney-portal.layout')
@section('title', 'Signed out')
@section('content')
<div style="max-width:520px;margin:24px auto;">
    <div class="card">
        <h1>You are signed out</h1>
        <p class="sub">To see your deals again, open the link in the email {{ isset($agencyName) ? $agencyName : 'your agent' }} sent you. It will ask for a new code.</p>
        <p class="muted" style="font-size:.9rem;">If you cannot find the email, ask your agent to send the link again.</p>
    </div>
</div>
@endsection
