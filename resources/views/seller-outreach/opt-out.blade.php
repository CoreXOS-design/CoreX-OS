{{--
    AT-49/AT-50 — public self-service communication-preferences screen.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — var(--token, #fallback) cascade,
    no naked hex (mirrors seller-outreach/landing.blade.php). Mobile-first.

    Props:
      $agencyName, $token
      $marketingOptedOut (bool)  — current marketing state
      $inLiveTransaction (bool)  — contact is an active party in a live sale
      $liveTransactions (array)  — [{type,label,property,reference}, …] to NAME the sale
      $done (bool)               — a POST just applied a change

    PREVIEW-SAFE: every action is an explicit POST button; loading this page (GET)
    never changes anything. Idempotent.
--}}
@extends('layouts.public')

@section('title', 'Communication preferences — ' . $agencyName)

@section('public-content')

@php
    $lockReason = $liveTransactions[0]['label'] ?? 'you have an active transaction with us';
    $extraSales = max(0, count($liveTransactions) - 1);
    // AT-? branding — agency theme/logo (reuses the per-agency fields the agency
    // public-website page uses; never hardcoded). Defaults to CoreX tokens only
    // when the agency has not set its own.
    $brand = $brand ?? [];
    $agencyLogoUrl = $agencyLogoUrl ?? null;
@endphp

{{-- BUG A — present as the SENDING AGENCY, not CoreX: override the public
     layout's pinned CoreX :root with the agency's own colours, and brand the
     WhatsApp link preview (og) with the agency. --}}
@push('head')
<meta property="og:title" content="{{ $agencyName }} — Communication preferences">
<meta property="og:description" content="Manage how {{ $agencyName }} contacts you.">
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

<div class="text-center mb-5">
    @if($agencyLogoUrl)
        <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="max-height:56px;width:auto;margin:0 auto 10px;display:block;">
    @endif
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary, #111827);">
        {{ $agencyName }}
    </h1>
    <p class="text-sm" style="color: var(--text-secondary, #4b5563);">Your communication preferences</p>
</div>

@if($done)
    <div class="p-3 rounded-md mb-4 text-center text-sm"
         style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 30%, transparent); color: var(--text-primary, #111827);">
        ✓ Your preferences have been updated.
    </div>
@endif

{{-- ── Switch A — Marketing & area updates (always toggleable) ── --}}
<div class="p-4 rounded-md mb-4"
     style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb);">
    <div class="flex items-start justify-between gap-3">
        <div class="flex-1">
            <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary, #111827);">
                Marketing &amp; area updates
            </h2>
            <p class="text-sm" style="color: var(--text-secondary, #4b5563);">
                Area news, buyer demand, and property updates. You can change this anytime.
            </p>
        </div>
        {{-- Visual switch reflecting current state (server-rendered; not interactive) --}}
        @if(!$marketingOptedOut)
            <span aria-label="On" title="On" class="inline-flex items-center shrink-0 rounded-full"
                  style="width:46px;height:26px;padding:3px;background:var(--ds-green, #16a34a);justify-content:flex-end;">
                <span style="width:20px;height:20px;border-radius:9999px;background:#ffffff;display:block;"></span>
            </span>
        @else
            <span aria-label="Off" title="Off" class="inline-flex items-center shrink-0 rounded-full"
                  style="width:46px;height:26px;padding:3px;background:var(--border, #cbd5e1);justify-content:flex-start;">
                <span style="width:20px;height:20px;border-radius:9999px;background:#ffffff;display:block;"></span>
            </span>
        @endif
    </div>

    <div class="mt-4">
        @if(!$marketingOptedOut)
            <form method="POST" action="{{ route('seller-outreach.public.opt-out.confirm', $token) }}">
                @csrf
                <input type="hidden" name="action" value="stop_marketing">
                <button type="submit"
                        class="w-full px-4 py-3 text-sm font-semibold rounded"
                        style="background: var(--ds-crimson, #dc2626); color: #ffffff; border: none; cursor: pointer;">
                    Turn off marketing messages
                </button>
            </form>
        @else
            <p class="text-sm mb-3" style="color: var(--text-secondary, #4b5563);">
                You're not receiving marketing messages. You can turn them back on below.
            </p>
            <form method="POST" action="{{ route('seller-outreach.public.opt-out.confirm', $token) }}">
                @csrf
                <input type="hidden" name="action" value="resume_marketing">
                <button type="submit"
                        class="w-full px-4 py-3 text-sm font-semibold rounded"
                        style="background: var(--brand-default, #0b2a4a); color: #ffffff; border: none; cursor: pointer;">
                    Turn marketing messages back on
                </button>
            </form>
        @endif
    </div>
</div>

{{-- ── Switch B — Messages about my transaction ── --}}
<div class="p-4 rounded-md"
     style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb);">
    <div class="flex items-start justify-between gap-3">
        <div class="flex-1">
            <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary, #111827);">
                Messages about my transaction
            </h2>
            <p class="text-sm" style="color: var(--text-secondary, #4b5563);">
                Updates about a sale you have on the go with us.
            </p>
        </div>
        @if($inLiveTransaction)
            {{-- Locked ON — cannot be silenced during a live sale --}}
            <span aria-label="On (locked)" title="Locked while your sale is active"
                  class="inline-flex items-center shrink-0 rounded-full"
                  style="width:46px;height:26px;padding:3px;background:color-mix(in srgb, var(--brand-default, #0b2a4a) 55%, var(--border, #cbd5e1));justify-content:flex-end;opacity:0.85;">
                <span style="width:20px;height:20px;border-radius:9999px;background:#ffffff;display:flex;align-items:center;justify-content:center;font-size:11px;line-height:1;">🔒</span>
            </span>
        @elseif($marketingOptedOut)
            {{-- BUG B — already fully stopped: show OFF, not a re-offer of the button. --}}
            <span aria-label="Off" title="Off" class="inline-flex items-center shrink-0 rounded-full"
                  style="width:46px;height:26px;padding:3px;background:var(--border, #cbd5e1);justify-content:flex-start;">
                <span style="width:20px;height:20px;border-radius:9999px;background:#ffffff;display:block;"></span>
            </span>
        @else
            <span aria-label="On" title="On" class="inline-flex items-center shrink-0 rounded-full"
                  style="width:46px;height:26px;padding:3px;background:var(--ds-green, #16a34a);justify-content:flex-end;">
                <span style="width:20px;height:20px;border-radius:9999px;background:#ffffff;display:block;"></span>
            </span>
        @endif
    </div>

    <div class="mt-4">
        @if($inLiveTransaction)
            {{-- No Silent Locks: explain WHY and WHEN it unlocks, naming the sale. --}}
            <div class="p-3 rounded text-sm"
                 style="background: color-mix(in srgb, var(--brand-default, #0b2a4a) 8%, transparent); border: 1px solid color-mix(in srgb, var(--brand-default, #0b2a4a) 25%, transparent); color: var(--text-secondary, #4b5563);">
                Because {{ $lockReason }}@if($extraSales > 0) (and {{ $extraSales }} other active {{ \Illuminate\Support\Str::plural('matter', $extraSales) }})@endif,
                we're required to keep you updated about it until it concludes. You can opt out of
                these messages once it's finalised. Marketing can still be switched off above.
            </div>
        @elseif($marketingOptedOut)
            {{-- BUG B — full stop already in effect; reflect it instead of re-offering "Stop all". --}}
            <div class="p-3 rounded text-sm"
                 style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent); color: var(--text-primary, #111827);">
                ✓ All messages stopped. You won't receive marketing or any other messages from
                {{ $agencyName }}. You can turn marketing back on above at any time.
            </div>
        @else
            <p class="text-sm mb-3" style="color: var(--text-secondary, #4b5563);">
                You don't have an active sale with us, so you can stop all messages.
            </p>
            <form method="POST" action="{{ route('seller-outreach.public.opt-out.confirm', $token) }}"
                  onsubmit="return confirm('Stop ALL messages from {{ $agencyName }}?');">
                @csrf
                <input type="hidden" name="action" value="stop_all">
                <button type="submit"
                        class="w-full px-4 py-3 text-sm font-semibold rounded"
                        style="background: var(--ds-crimson, #dc2626); color: #ffffff; border: none; cursor: pointer;">
                    Stop all messages
                </button>
            </form>
        @endif
    </div>
</div>

@endsection
