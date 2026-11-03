{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    One video ad: preview player, a Download per format, the post text with Copy. System Owner only.
    Spec: .ai/specs/training-videos.md 9.21.1
--}}
@extends('layouts.corex')

@section('title', $ad->name . ' · Video ads')

@section('corex-content')
@php
    $card = 'rounded-md p-4 space-y-3';
    $cardStyle = 'background: var(--surface); border: 1px solid var(--border);';
    $voices = app(\App\Services\TrainingVideos\Tts\TrainingVoices::class);
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $ad->name }} · {{ data_get($ad->snapshot, 'feature_title') }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $ad->headline() }} · {{ $ad->length_seconds }} s · {{ $voices->labelFor($ad->voice_key) }} · made {{ $ad->created_at->format('j M Y H:i') }}{{ $ad->trashed() ? ' · archived' : '' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.video-ads.duplicate', $ad) }}" class="corex-btn-outline corex-btn-on-brand text-xs">Duplicate</a>
                <a href="{{ route('admin.video-ads.index', ['feature' => $ad->feature_key]) }}" class="corex-btn-outline corex-btn-on-brand text-xs">Back to Video ads</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif

    @if ($ad->isWorking())
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <p class="text-sm" style="color: var(--text-primary);" data-qa="show-working">{{ $ad->status === 'queued' ? 'Waiting in line…' : 'Making it ' . (int) $ad->progress_percent . '%' }}</p>
            <div class="h-2 rounded" style="background: var(--surface-2);"><div class="h-2 rounded" style="width: {{ (int) $ad->progress_percent }}%; background: var(--corex-accent);"></div></div>
            <p class="text-xs" style="color: var(--text-muted);">You can leave this page. Reload to see it when it is ready.</p>
        </section>
    @elseif ($ad->status === 'failed')
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <p class="text-sm font-medium" style="color: var(--ds-crimson);">This ad could not be made.</p>
            <p class="text-xs" style="color: var(--text-secondary);">{{ $ad->failure_reason }}</p>
            @unless ($ad->trashed())
                <form method="POST" action="{{ route('admin.video-ads.retry', $ad) }}">@csrf <button type="submit" class="corex-btn-primary text-xs">Try again</button></form>
            @endunless
        </section>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            @foreach ($ad->readyFormats() as $fmt => $info)
                @php $slug = str_replace(':', 'x', $fmt); @endphp
                <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="format-{{ $slug }}">
                    <h2 class="text-sm font-bold" style="color: var(--text-primary);">{{ $fmt }} <span class="text-xs font-normal" style="color: var(--text-muted);">{{ $formats[$fmt] ?? '' }}</span></h2>
                    <video controls preload="metadata" playsinline class="rounded-md w-full" style="max-height: 480px; background: #000;"
                           poster="{{ route('admin.video-ads.file', [$ad, $slug, 'jpg']) }}">
                        <source src="{{ route('admin.video-ads.file', [$ad, $slug, 'mp4']) }}" type="video/mp4">
                    </video>
                    <a href="{{ route('admin.video-ads.download', [$ad, $slug]) }}" class="corex-btn-primary text-xs inline-flex">Download {{ $fmt }}</a>
                </section>
            @endforeach
        </div>
    @endif

    @if ($ad->isReady() && ! $ad->trashed())
        @php $postSvc = app(\App\Services\AdStudio\VideoAdPostService::class); $fbAccount = $postSvc->account(); @endphp
        <section class="{{ $card }}" style="{{ $cardStyle }}" id="schedule" data-qa="post-panel">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Post to the CoreX Facebook page @include('admin.video-ads._dry-run-badge')</h2>
            @if ($errors->any())<p class="text-xs" style="color: var(--ds-crimson);">{{ $errors->first() }}</p>@endif
            @if (! $fbAccount)
                <p class="text-xs" style="color: var(--text-secondary);">The CoreX Facebook page is not connected yet. <a href="{{ route('admin.video-ads.facebook') }}" class="hover:underline" style="color: var(--corex-accent);">Connect it</a> to post from here.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);">Posts the video and the post text below to <strong>{{ $fbAccount->platform_page_name }}</strong>. Facebook only.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <form method="POST" action="{{ route('admin.video-ads.post-now', $ad) }}" class="space-y-2" data-confirm="Post {{ $ad->name }} to the CoreX Facebook page now?">@csrf
                        <label class="block text-xs font-medium" style="color: var(--text-secondary);">Format
                            <select name="format" class="rounded-md px-3 py-2 text-sm w-full" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                @foreach (array_keys($ad->readyFormats()) as $f)<option value="{{ $f }}" @selected($f === $postSvc->defaultFormat($ad))>{{ $f }}</option>@endforeach
                            </select></label>
                        <button type="submit" class="corex-btn-primary text-xs">Post now @include('admin.video-ads._dry-run-badge')</button>
                    </form>
                    <form method="POST" action="{{ route('admin.video-ads.schedule', $ad) }}" class="space-y-2">@csrf
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-xs font-medium" style="color: var(--text-secondary);">Format
                                <select name="format" class="rounded-md px-3 py-2 text-sm w-full" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    @foreach (array_keys($ad->readyFormats()) as $f)<option value="{{ $f }}" @selected($f === $postSvc->defaultFormat($ad))>{{ $f }}</option>@endforeach
                                </select></label>
                            <label class="block text-xs font-medium" style="color: var(--text-secondary);">When (South African time)
                                <input type="datetime-local" name="scheduled_at" required class="rounded-md px-3 py-2 text-sm w-full" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></label>
                        </div>
                        <button type="submit" class="corex-btn-outline text-xs">Schedule @include('admin.video-ads._dry-run-badge')</button>
                        <a href="{{ route('admin.video-ads.posts') }}" class="text-xs hover:underline" style="color: var(--corex-accent);">Scheduled posts</a>
                    </form>
                </div>
            @endif
        </section>
    @endif

    <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="post-text">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Post text</h2>
            <button type="button" class="corex-btn-outline text-xs" id="copy-post" data-copy="{{ $ad->postText() }}">Copy post text</button>
        </div>
        <pre class="text-sm whitespace-pre-wrap" style="color: var(--text-primary); font-family: inherit;">{{ $ad->postText() }}</pre>
        <p class="text-xs" style="color: var(--text-muted);">Nothing is posted for you. Download the video and paste this text where you post it.</p>
    </section>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return;
    var text = b.getAttribute('data-copy');
    var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
    else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} ta.remove(); }
});
</script>
@endsection
