{{--
    AT-49 — public self-service marketing OPT-IN / re-consent confirm page.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — uses var(--token, #fallback)
    cascade, no naked hex (mirrors seller-outreach/opt-out.blade.php).

    Props: $agencyName, $token, $alreadyOptedIn (bool), $done (bool)
    - GET render: $done=false. Shows the confirm button UNLESS already opted in.
    - POST render: $done=true. Shows the success message.
    PREVIEW-SAFE: the confirm button POSTs; merely loading this page never opts in.
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

    @if($done || $alreadyOptedIn)
        {{-- Success / already-opted-in state (identical wording — idempotent) --}}
        <div class="text-center">
            <div class="text-2xl mb-2" aria-hidden="true">✓</div>
            <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary, #111827);">
                You're set to receive marketing updates
            </h2>
            <p class="text-sm" style="color: var(--text-secondary, #4b5563);">
                {{ $agencyName }} will send you marketing and buyer-demand updates again.
                You can opt out at any time from any message.
            </p>
        </div>
    @else
        {{-- Confirm state --}}
        <h2 class="text-lg font-semibold mb-3 text-center" style="color: var(--text-primary, #111827);">
            Get marketing updates from {{ $agencyName }}?
        </h2>
        <p class="text-sm mb-5 text-center" style="color: var(--text-secondary, #4b5563);">
            You'll receive marketing and buyer-demand updates. You can opt out at any time.
        </p>

        <form method="POST" action="{{ route('seller-outreach.public.opt-in.confirm', $token) }}">
            @csrf
            <button type="submit"
                    class="w-full px-4 py-3 text-sm font-semibold rounded"
                    style="background: var(--brand-default, #0b2a4a); color: #ffffff; border: none; cursor: pointer;">
                Yes, send me marketing updates
            </button>
        </form>
    @endif
</div>

@endsection
