{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Dev Settings → Feature rollout → Feature wording & videos
     (AT-478 §19.4). Same shell as Dev Settings → Feature rollout. Owner-only. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $th = 'text-left px-5 py-2.5 text-xs font-semibold uppercase tracking-wider';
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $sortLink = function (string $col) use ($filters) {
        $dir = ($filters['sort'] === $col && $filters['dir'] === 'asc') ? 'desc' : 'asc';
        return request()->fullUrlWithQuery(['sort' => $col, 'dir' => $dir, 'page' => null]);
    };
    $arrow = fn (string $col) => $filters['sort'] === $col ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $shows = ['all' => 'All features', 'edited' => 'Edited wording', 'default' => 'Default wording', 'video' => 'Has a video', 'no_video' => 'No video yet', 'reverted' => 'Reverted edits'];
    $filtered = request()->hasAny(['q', 'from', 'to']) || $filters['show'] !== 'all';
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Feature wording &amp; videos</h1>
                <p class="text-sm text-white/60">What the "New in CoreX" and access pop-ups say about each feature: the one-line summary, the full explanation, a video link and extra links. The same for every agency. {{ $editedCount }} of {{ $total }} features have edited wording.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.feature-rollout-defaults.index') }}" class="corex-btn-outline">← Feature rollout</a>
            </div>
        </div>
    </div>
    @include('admin.partials.platform-flash')

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <div class="ds-section-header">Features <span class="ds-badge ds-badge-muted ml-1">{{ $rows->total() }}</span></div>
            <div class="ds-section-sub">Every feature a rollout group can hold. Open one to change its wording or add a video.</div>
        </div>

        <form method="GET" class="px-5 py-3 flex flex-wrap items-end gap-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Search name or wording</span>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Show</span>
                <select name="show" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                    @foreach($shows as $value => $text)<option value="{{ $value }}" @selected($filters['show'] === $value)>{{ $text }}</option>@endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">Changed from</span>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold" style="color:var(--text-muted);">to</span>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
            </label>
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="dir" value="{{ $filters['dir'] }}">
            <button type="submit" class="corex-btn-outline">Apply</button>
            <a href="{{ route('admin.feature-rollout-defaults.copy.index') }}" class="corex-btn-outline">Clear</a>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}"><a href="{{ $sortLink('name') }}" style="color: var(--text-muted);">Feature{{ $arrow('name') }}</a></th>
                    <th class="{{ $th }}"><a href="{{ $sortLink('category') }}" style="color: var(--text-muted);">Area{{ $arrow('category') }}</a></th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">Wording</th>
                    <th class="{{ $th }}"><a href="{{ $sortLink('updated') }}" style="color: var(--text-muted);">Changed{{ $arrow('updated') }}</a></th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr style="border-top: 1px solid var(--border);" data-copy-row="{{ $r['key'] }}">
                            <td class="px-5 py-3 align-top">
                                <div class="font-semibold" style="color: var(--text-primary);">{{ $r['label'] }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($r['summary'], 140) }}</div>
                            </td>
                            <td class="px-5 py-3 align-top whitespace-nowrap" style="color: var(--text-secondary);">{{ $r['category'] }}</td>
                            <td class="px-5 py-3 align-top">
                                <div class="flex flex-wrap gap-1">
                                    @if($r['edited'])<span class="ds-badge ds-badge-info">Edited</span>@else<span class="ds-badge ds-badge-muted">Default</span>@endif
                                    @if($r['reverted'])<span class="ds-badge ds-badge-warning">Reverted edit kept</span>@endif
                                    @if($r['video'])<span class="ds-badge ds-badge-success">Video</span>@endif
                                    @if($r['links'] > 0)<span class="ds-badge ds-badge-default">{{ $r['links'] }} {{ $r['links'] === 1 ? 'link' : 'links' }}</span>@endif
                                </div>
                            </td>
                            <td class="px-5 py-3 align-top whitespace-nowrap text-xs" style="color: var(--text-muted);">{{ $r['updated']?->format('j M Y') ?? '—' }}</td>
                            <td class="px-5 py-3 align-top text-right whitespace-nowrap">
                                <a href="{{ route('admin.feature-rollout-defaults.copy.edit', $r['key']) }}" class="corex-btn-outline text-xs">Edit wording</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-sm" style="color: var(--text-muted);">
                            {{ $filtered ? 'No features match these filters.' : 'No features can be part of a gradual introduction yet.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rows->hasPages())
            <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
@endsection
