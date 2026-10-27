{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $hasFilters = request()->filled('q') || request()->filled('status') || request()->filled('from') || request()->filled('to');
    // A sortable column header: flips direction when it is the current sort, keeps the other filters.
    $sortLink = function (string $key) use ($sort, $dir) {
        $next = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
        return route('corex.desk.log.index', array_merge(request()->query(), ['sort' => $key, 'dir' => $next, 'page' => null]));
    };
    $arrow = fn (string $key) => $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $statusBadge = fn (string $s) => match ($s) { 'live' => 'ds-badge-success', 'waiting' => 'ds-badge-info', default => 'ds-badge-default' };
    $endedLabel = ['agent' => 'The agent', 'support' => 'Support', 'logout' => 'Agent signed out', 'timeout' => 'Connection lost', 'expired' => 'Nobody joined', 'disabled' => 'Switched off', 'replaced' => 'New session started'];
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Desk sessions</h1>
            <p class="text-xs" style="color: var(--text-muted);">Every time CoreX support viewed an agent's CoreX window with the agent's code: who, when, what was highlighted. Nothing from the screen itself is kept.</p>
        </div>
    </div>


    <div class="rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" class="flex items-center gap-3 flex-wrap">
            <input type="search" name="q" value="{{ request('q') }}" class="list-header-filter {{ request('q') ? 'is-active' : '' }}"
                   placeholder="{{ $isOwner ? 'Search agent, support or agency' : 'Search agent or support' }}" style="min-width:16rem;">
            <select class="list-header-filter {{ request('status') ? 'is-active' : '' }}" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach(['waiting' => 'Waiting', 'live' => 'Live', 'ended' => 'Ended'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label class="text-xs" style="color:var(--text-muted);">From <input type="date" name="from" value="{{ request('from') }}" class="list-header-filter" onchange="this.form.submit()"></label>
            <label class="text-xs" style="color:var(--text-muted);">To <input type="date" name="to" value="{{ request('to') }}" class="list-header-filter" onchange="this.form.submit()"></label>
            <button type="submit" class="text-xs font-semibold" style="color:var(--brand-icon);">Search</button>
            @if($hasFilters)
                <a href="{{ route('corex.desk.log.index') }}" class="text-xs font-semibold" style="color:var(--brand-icon);">Clear</a>
            @endif
            <span class="ml-auto text-xs" style="color:var(--text-muted);">Showing {{ number_format($sessions->count()) }} of {{ number_format($sessions->total()) }}</span>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);"><a href="{{ $sortLink('started') }}">Started{{ $arrow('started') }}</a></th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Agent</th>
                        @if($isOwner)<th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Agency</th>@endif
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Support</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);"><a href="{{ $sortLink('duration') }}">Length{{ $arrow('duration') }}</a></th>
                        <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);"><a href="{{ $sortLink('status') }}">Status{{ $arrow('status') }}</a></th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sessions as $s)
                    @php $secs = $s->durationSeconds(); @endphp
                    <tr>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $s->started_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ $s->agent?->name ?? '—' }}</td>
                        @if($isOwner)<td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->agency?->name ?? '—' }}</td>@endif
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->support_display ?: ($s->supportUser?->name ?? 'Nobody joined') }}</td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $s->joined_at ? ($secs >= 60 ? floor($secs / 60) . ' min ' . ($secs % 60) . ' s' : $secs . ' s') : '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge ds-badge-pill {{ $statusBadge($s->status) }}">{{ ucfirst($s->status) }}</span>
                            @if($s->ended_by)<span class="text-xs" style="color:var(--text-muted);">{{ $endedLabel[$s->ended_by] ?? $s->ended_by }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('corex.desk.log.show', $s->id) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">Open</a></td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $isOwner ? 7 : 6 }}" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No Desk sessions yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($sessions->hasPages())
        <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $sessions->links() }}</div>
        @endif
    </div>
</div>
@endsection
