@extends('layouts.public')
@section('title', 'Contract')
@section('public-content')
@php
    $msg = match($state) {
        'signed_now'   => ['Thank you — it is signed', 'A copy of the signed agreement is on its way to your email.'],
        'declined_now' => ['Your response has been recorded', 'We have let CoreX know you declined to sign. They will be in touch.'],
        'signed'       => ['This agreement has already been signed', 'No further action is needed.'],
        'declined'     => ['This agreement was declined', 'Please contact CoreX if this was a mistake.'],
        'voided'       => ['This link is no longer valid', 'The agreement was withdrawn. Please contact CoreX.'],
        'expired'      => ['This link has expired', 'Please ask CoreX to send you a new link.'],
        default        => ['This link is not active', 'Please ask CoreX to send you a new link.'],
    };
@endphp
<div class="rounded-md p-8 text-center" style="background:#fff; border:1px solid #e5e7eb;">
    <div class="text-xl font-bold mb-2" style="color:#0b2a4a;">CoreX <span style="color:#00b4d8;">Os</span></div>
    <h1 class="text-base font-semibold mb-1">{{ $msg[0] }}</h1>
    <p class="text-sm" style="color:#6b7280;">{{ $msg[1] }}</p>
</div>
@endsection
