{{--
    AT-49 — public self-service marketing opt-out confirm page.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — uses var(--token, #fallback)
    cascade, no naked hex (mirrors seller-outreach/landing.blade.php).

    Props: $agencyName, $token, $alreadyOptedOut (bool), $done (bool)
    - GET render: $done=false. Shows the confirm button UNLESS already opted out.
    - POST render: $done=true. Shows the success message.
    PREVIEW-SAFE: the confirm button POSTs; merely loading this page never opts out.
--}}
@extends('layouts.public')

@section('title', 'Marketing preferences — ' . $agencyName)

@section('public-content')

<div class="text-center mb-6">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary, #111827);">
        {{ $agencyName }}
    </h1>
</div>

<div class="p-5 rounded-md"
     style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb);">

    @if($done || $alreadyOptedOut)
        {{-- Success / already-opted-out state (identical wording — idempotent) --}}
        <div class="text-center">
            <div class="text-2xl mb-2" aria-hidden="true">✓</div>
            <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary, #111827);">
                You won't receive further marketing messages
            </h2>
            <p class="text-sm" style="color: var(--text-secondary, #4b5563);">
                {{ $agencyName }} has stopped sending you marketing and buyer-demand updates.
                This does not affect messages about your own transaction.
            </p>
        </div>
    @else
        {{-- Confirm state --}}
        <h2 class="text-lg font-semibold mb-3 text-center" style="color: var(--text-primary, #111827);">
            Stop receiving marketing messages from {{ $agencyName }}?
        </h2>
        <p class="text-sm mb-5 text-center" style="color: var(--text-secondary, #4b5563);">
            This does not affect messages about your own transaction.
        </p>

        <form method="POST" action="{{ route('seller-outreach.public.opt-out.confirm', $token) }}">
            @csrf
            <button type="submit"
                    class="w-full px-4 py-3 text-sm font-semibold rounded"
                    style="background: var(--ds-crimson, #dc2626); color: #ffffff; border: none; cursor: pointer;">
                Yes, stop marketing messages
            </button>
        </form>
    @endif
</div>

@endsection
