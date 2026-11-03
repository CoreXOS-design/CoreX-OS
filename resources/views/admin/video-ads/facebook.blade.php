{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Video ads -> Facebook page: connect the CoreX Facebook page (Facebook's own login). System Owner only.
    Spec: .ai/specs/training-videos.md 9.22
--}}
@extends('layouts.corex')

@section('title', 'Facebook page · Video ads')

@section('corex-content')
@php
    $card = 'rounded-md p-4 space-y-3';
    $cardStyle = 'background: var(--surface); border: 1px solid var(--border);';
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Video ads · Facebook page</h1>
                <p class="text-xs" style="color: var(--text-muted);">Connect the CoreX Facebook page, then post a video ad to it now or at a chosen time. You sign in on Facebook's own screen; CoreX never sees your password.</p>
            </div>
            <a href="{{ route('admin.video-ads.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">Back to Video ads</a>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="fb-status">
        <h2 class="text-sm font-bold" style="color: var(--text-primary);">CoreX Facebook page @include('admin.video-ads._dry-run-badge')</h2>
        @if ($account)
            <p class="text-sm" style="color: var(--text-primary);">Connected: <strong>{{ $account->platform_page_name }}</strong>
                <span class="text-xs" style="color: var(--text-muted);">(connected {{ $account->updated_at->format('j M Y') }}; the connection renews itself every 60 days while it is used)</span></p>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.video-ads.facebook.connect') }}" class="corex-btn-outline text-xs">Connect a different page</a>
                <form method="POST" action="{{ route('admin.video-ads.facebook.disconnect') }}" data-confirm="Disconnect the CoreX Facebook page? Scheduled posts will not be posted.">@csrf
                    <button type="submit" class="corex-btn-outline text-xs">Disconnect</button></form>
                <a href="{{ route('admin.video-ads.posts') }}" class="corex-btn-primary text-xs">Scheduled posts</a>
            </div>
        @else
            <p class="text-sm" style="color: var(--text-secondary);">No page is connected yet.</p>
            @if ($metaConfigured)
                <a href="{{ route('admin.video-ads.facebook.connect') }}" class="corex-btn-primary text-xs inline-flex" data-qa="fb-connect">Connect the CoreX Facebook page</a>
            @else
                <p class="text-xs" style="color: var(--ds-crimson);">The Meta app is not set up on this server, so the page cannot be connected here.</p>
            @endif
        @endif
        @if ($dryRun)
            <p class="text-xs" style="color: var(--text-muted);">On this server posting is switched off: Post now and Schedule only write down what they would send. Real posting is switched on separately, by Johan.</p>
        @endif
    </section>

    <section class="{{ $card }}" style="{{ $cardStyle }}">
        <h2 class="text-sm font-bold" style="color: var(--text-primary);">For whoever looks after the Meta app</h2>
        <p class="text-xs" style="color: var(--text-secondary);">Facebook only lets you return from its login to an address that is on the Meta app's list. This screen uses the same return address as the agents' Social Media connection:</p>
        <p class="text-xs"><code data-qa="needed-redirect">{{ $neededRedirect }}</code></p>
        <p class="text-xs" style="color: var(--text-muted);">This server is currently set to return to <code>{{ $redirectUri ?: '(not set)' }}</code>.@if ($redirectUri !== $neededRedirect) These two differ, so connecting from this server will not come back here until the Meta app's list and this server's setting are lined up. CoreX does not change either.@endif</p>
    </section>
</div>
@endsection
