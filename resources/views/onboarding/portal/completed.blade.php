@extends('layouts.onboarding-portal')

@section('portal-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="rounded-md bg-surface border border-subtle/30 p-8 text-center shadow-sm">
        <div class="w-14 h-14 mx-auto mb-4 rounded-full flex items-center justify-center portal-cta">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold mb-1">Review already submitted</h1>
        <p class="text-sm text-muted mb-6">
            This onboarding review has been completed and CoreX has been notified — there's nothing more to do here.
        </p>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 my-6 text-left">
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Confirmed</div>
                <div class="text-xl font-semibold">{{ $counts['confirmed'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Excluded</div>
                <div class="text-xl font-semibold">{{ $counts['excluded'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Still pending</div>
                <div class="text-xl font-semibold {{ $counts['pending'] > 0 ? 'text-amber-600' : '' }}">{{ $counts['pending'] }}</div>
            </div>
            <div class="rounded-md bg-surface-2 p-3 text-center">
                <div class="text-xs text-muted">Errors</div>
                <div class="text-xl font-semibold {{ $counts['error'] > 0 ? 'text-red-600' : '' }}">{{ $counts['error'] }}</div>
            </div>
        </div>

        <p class="text-xs text-muted">
            Need to make further changes? Contact your CoreX administrator and they'll reopen the link for you.
        </p>
    </div>
</div>
@endsection
