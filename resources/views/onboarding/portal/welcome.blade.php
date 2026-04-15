@extends('layouts.onboarding-portal')

@section('portal-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="rounded-md bg-surface border border-subtle/30 p-8 shadow-sm">
        <h1 class="text-2xl font-bold mb-3">Welcome, {{ $agency->name }}</h1>
        <p class="text-sm text-muted mb-4">
            Home Finders Coastal has imported your Property24 stock into CoreX OS.
            Please review each listing and confirm, exclude, or reassign the responsible agent.
            Your changes go live only after you click <strong>Finish review</strong>.
        </p>

        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 my-6">
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Pending</div>
                <div class="text-xl font-semibold">{{ $counts['pending'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">In progress</div>
                <div class="text-xl font-semibold">{{ $counts['processing'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Confirmed</div>
                <div class="text-xl font-semibold">{{ $counts['confirmed'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Excluded</div>
                <div class="text-xl font-semibold">{{ $counts['excluded'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Errors</div>
                <div class="text-xl font-semibold">{{ $counts['error'] }}</div>
            </div>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <a href="{{ route('onboarding.portal.review', $portal->token) }}"
               class="portal-cta rounded-md px-5 py-2.5 text-sm font-semibold">
                Start review
            </a>
            @if ($portal->expires_at)
                <span class="text-xs text-muted">Link expires {{ $portal->expires_at->diffForHumans() }}</span>
            @endif
        </div>
    </div>
</div>
@endsection
