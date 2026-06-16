{{--
    AT-49 — generic public marketing unsubscribe page.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — uses var(--token, #fallback)
    cascade, no naked hex (mirrors seller-outreach/opt-out.blade.php).

    Props: $agencyId, $agencyName, $done (bool), $invalid (bool)
    - GET render: $done=false. Shows the email/phone entry form.
    - POST render: $done=true. Shows the (match-agnostic) success message.
    PREVIEW-SAFE: submitting POSTs; merely loading this page never suppresses.
--}}
@extends('layouts.public')

@section('title', 'Unsubscribe — ' . $agencyName)

@section('public-content')

@php($brand = $brand ?? [])
@php($agencyLogoUrl = $agencyLogoUrl ?? null)

{{-- BUG A — present as the sending agency, not CoreX (theme + logo + link preview). --}}
@push('head')
<meta property="og:title" content="Unsubscribe — {{ $agencyName }}">
<meta property="og:description" content="Stop receiving marketing messages from {{ $agencyName }}.">
@if($agencyLogoUrl)<meta property="og:image" content="{{ $agencyLogoUrl }}">@endif
<style>
    :root {
        --brand-sidebar: {{ $brand['sidebar'] ?? '#0b2a4a' }};
        --brand-icon:    {{ $brand['icon'] ?? '#33c4e0' }};
        --brand-default: {{ $brand['default'] ?? '#0b2a4a' }};
        --brand-button:  {{ $brand['button'] ?? '#00b4d8' }};
    }
</style>
@endpush

<div class="text-center mb-6">
    @if($agencyLogoUrl)
        <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="max-height:56px;width:auto;margin:0 auto 10px;display:block;">
    @endif
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary, #111827);">
        {{ $agencyName }}
    </h1>
</div>

<div class="p-5 rounded-md"
     style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb);">

    @if($done)
        {{-- Success state. Match-agnostic by default; when the resolved contact is
             in a live sale (AT-50) we add that transactional comms continue. --}}
        <div class="text-center">
            <div class="text-2xl mb-2" aria-hidden="true">✓</div>
            <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary, #111827);">
                Your request has been processed
            </h2>
            <p class="text-sm" style="color: var(--text-secondary, #4b5563);">
                If that email address or number is on file with {{ $agencyName }}, it will no
                longer receive marketing messages.
            </p>
            @if($inLiveTransaction)
                <div class="mt-4 p-3 rounded text-sm text-left"
                     style="background: color-mix(in srgb, var(--brand-default, #0b2a4a) 8%, transparent); border: 1px solid color-mix(in srgb, var(--brand-default, #0b2a4a) 25%, transparent); color: var(--text-secondary, #4b5563);">
                    Because you have an active sale with us, we're required to keep sending you
                    essential updates about that sale until it concludes. You can opt out of those
                    once it's finalised. Marketing messages have been stopped.
                </div>
            @else
                <p class="text-sm mt-2" style="color: var(--text-secondary, #4b5563);">
                    This does not affect messages about your own transaction.
                </p>
            @endif
        </div>
    @else
        {{-- Entry state --}}
        <h2 class="text-lg font-semibold mb-3 text-center" style="color: var(--text-primary, #111827);">
            Stop receiving marketing messages from {{ $agencyName }}
        </h2>
        <p class="text-sm mb-5 text-center" style="color: var(--text-secondary, #4b5563);">
            Enter the email address or phone number you were contacted on.
        </p>

        @if($invalid)
            <p class="text-sm mb-3 text-center" style="color: var(--ds-crimson, #dc2626);">
                Please enter an email address or phone number.
            </p>
        @endif

        <form method="POST" action="{{ route('seller-outreach.public.unsubscribe.submit', $agencyId) }}">
            @csrf
            <input type="text" name="identifier" autocomplete="off"
                   value="{{ old('identifier') }}"
                   placeholder="you@example.com or 082 123 4567"
                   class="w-full px-3 py-2 mb-4 text-sm rounded"
                   style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb); color: var(--text-primary, #111827);">
            <button type="submit"
                    class="w-full px-4 py-3 text-sm font-semibold rounded"
                    style="background: var(--ds-crimson, #dc2626); color: #ffffff; border: none; cursor: pointer;">
                Unsubscribe me
            </button>
        </form>
    @endif
</div>

@endsection
