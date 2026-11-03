{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Video ads -> Scheduled posts: every Post now / Schedule of a video ad to the CoreX Facebook page. System Owner only.
    Spec: .ai/specs/training-videos.md 9.22
--}}
@extends('layouts.corex')

@section('title', 'Scheduled posts · Video ads')

@section('corex-content')
@php
    $sortLink = function (string $key, string $label) use ($sort, $dir) {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => null])) . '" class="hover:underline">' . e($label) . $arrow . '</a>';
    };
    $th = 'text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider';
    $field = 'rounded-md px-3 py-2 text-sm';
    $fieldStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $filtered = collect($filters)->except('status')->filter()->isNotEmpty() || $filters['status'] !== 'all';
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Scheduled posts @include('admin.video-ads._dry-run-badge')</h1>
                <p class="text-xs" style="color: var(--text-muted);">Video ads posted, or waiting to be posted, to {{ $account ? 'the CoreX Facebook page "' . $account->platform_page_name . '"' : 'the CoreX Facebook page (not connected yet)' }}. Times are South African time.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.video-ads.facebook') }}" class="corex-btn-outline corex-btn-on-brand text-xs">Facebook page</a>
                <a href="{{ route('admin.video-ads.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">Video ads</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <form method="GET" action="{{ route('admin.video-ads.posts') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <div class="flex-1 min-w-[200px]">
            <label for="q" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Ad name or post text" class="w-full {{ $field }}" style="{{ $fieldStyle }}">
        </div>
        <div>
            <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select id="status" name="status" class="{{ $field }}" style="{{ $fieldStyle }}">
                @foreach (['all' => 'All', 'scheduled' => 'Waiting', 'published' => 'Posted', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $k => $l)
                    <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">From</label><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
        <div><label for="to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">To</label><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs">Apply</button>
            @if ($filtered)<a href="{{ route('admin.video-ads.posts') }}" class="corex-btn-outline text-xs">Clear</a>@endif
        </div>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if ($page->isEmpty())
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium" style="color: var(--text-primary);">{{ $filtered ? 'No posts match these filters' : 'No posts yet' }}</p>
                <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $filtered ? '' : 'Press Post now or Schedule on a finished ad in Video ads.' }}</p>
                @if ($filtered)<a href="{{ route('admin.video-ads.posts') }}" class="text-xs hover:underline" style="color: var(--corex-accent);">Clear filters</a>@endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs ds-table">
                    <thead><tr style="color: var(--text-muted);">
                        <th class="{{ $th }}">Ad</th><th class="{{ $th }}">Format</th>
                        <th class="{{ $th }}">{!! $sortLink('when', 'When') !!}</th>
                        <th class="{{ $th }}">{!! $sortLink('status', 'Status') !!}</th>
                        <th class="{{ $th }}">Post text</th><th class="px-3 py-2"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($page as $post)
                            <tr style="border-top: 1px solid var(--border);" data-qa="post-row" data-post-id="{{ $post->id }}">
                                <td class="px-3 py-2"><a href="{{ route('admin.video-ads.show', $post->ad) }}" class="font-semibold hover:underline" style="color: var(--corex-accent);">{{ $post->ad?->name }}</a>
                                    <div style="color: var(--text-muted);">{{ data_get($post->ad?->snapshot, 'feature_title') }}</div></td>
                                <td class="px-3 py-2">{{ $post->format }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $post->scheduled_for?->setTimezone($tz)->format('j M Y H:i') }}</td>
                                <td class="px-3 py-2">
                                    @switch($post->status)
                                        @case('scheduled') Waiting @break
                                        @case('publishing') Posting… @break
                                        @case('published') {{ $post->dry_run ? 'Dry run - not posted' : 'Posted' }}@if($post->published_at) <span style="color: var(--text-muted);">{{ $post->published_at->setTimezone($tz)->format('j M H:i') }}</span>@endif @break
                                        @case('failed') <span style="color: var(--ds-crimson);">Failed</span><div style="color: var(--text-muted);">{{ $post->failure_reason }}</div> @break
                                        @default Cancelled
                                    @endswitch
                                </td>
                                <td class="px-3 py-2" style="max-width: 320px; color: var(--text-secondary);">{{ \Illuminate\Support\Str::limit($post->message, 90) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right">
                                    @if ($post->isOpen())
                                        <form method="POST" action="{{ route('admin.video-ads.posts.reschedule', $post) }}" class="inline-flex items-center gap-1">@csrf
                                            <input type="datetime-local" name="scheduled_at" value="{{ $post->scheduled_for?->setTimezone($tz)->format('Y-m-d\TH:i') }}" class="{{ $field }}" style="{{ $fieldStyle }} padding: 2px 6px;">
                                            <button type="submit" class="corex-btn-outline text-xs" style="padding: 2px 8px;">Move</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.video-ads.posts.cancel', $post) }}" class="inline" data-confirm="Cancel this scheduled post?">@csrf
                                            <button type="submit" class="corex-btn-outline text-xs" style="padding: 2px 8px;">Cancel</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div>{{ $page->links() }}</div>
</div>
@endsection
