{{-- AT-534 — public unsubscribe page for CoreX campaign emails (.ai/specs/campaigns.md §6.4). GET never writes; the button POSTs.
     Receives: $state (confirm | unsubscribed | done | missing), $email (masked), $action (signed URL to POST to). --}}
@extends('layouts.public')

@section('title', 'Unsubscribe — CoreX OS')

@section('public-content')
<div class="max-w-md mx-auto px-4 py-16 text-center" data-unsubscribe-state="{{ $state }}">
    <h1 class="text-lg font-bold mb-3" style="color: var(--text-primary, #0f172a);">CoreX OS</h1>
    @if($state === 'confirm')
        <p class="text-sm mb-6" style="color: var(--text-secondary, #475569);">Stop all CoreX emails to {{ $email }}?</p>
        <form method="POST" action="{{ $action }}">
            <button type="submit" class="corex-btn-primary">Unsubscribe</button>
        </form>
    @elseif($state === 'unsubscribed')
        <p class="text-sm" style="color: var(--text-secondary, #475569);">Done. {{ $email }} will not receive any more emails from us.</p>
    @elseif($state === 'done')
        <p class="text-sm" style="color: var(--text-secondary, #475569);">You're already unsubscribed. {{ $email }} will not receive any more emails from us.</p>
    @else
        <p class="text-sm" style="color: var(--text-secondary, #475569);">This link is no longer valid.</p>
    @endif
</div>
@endsection
