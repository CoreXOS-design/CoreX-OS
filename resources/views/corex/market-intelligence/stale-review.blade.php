{{-- MIC funnel phase 2 (Johan 2026-08-13) — BM/admin stale-claim review + reassignment.
     Anti-poaching: agents never grab stale stock; the BM/admin moves-or-keeps here.
     AT-393 — header + filter card are frozen (full-height flex column); only the
     scroll region (flash + table + pagination) scrolls. Spec: .ai/specs/mic-stale-review-list.md --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full h-full flex flex-col">
    <div class="flex-shrink-0">
        <x-mic-page-header
            title="Stale claims review"
            subtitle="Pitched/claimed properties sitting unworked. Warned at {{ $warnDays }} days; ready for move-or-keep at {{ $releaseDays }} days. Agents can't take these from each other — you decide." />
    </div>

    {{-- Filters — directly under the header. One GET form so every control composes
         (address search + agent + state all apply together); the pagination links
         carry the same query string so a filtered page 2 stays filtered. --}}
    @php $filtersActive = ($filters['q'] ?? '') !== '' || ($filters['agent_id'] ?? '') !== '' || ($filters['state'] ?? '') !== ''; @endphp
    <form method="GET" action="{{ route('market-intelligence.stale-review') }}"
          class="rounded-md px-4 py-3 flex-shrink-0 flex flex-wrap items-center gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">

        {{-- Address search --}}
        <div class="relative flex-1 min-w-[180px] max-w-xs">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Search address…"
                   class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
        </div>

        {{-- Agent ("On it") --}}
        <select name="agent_id" onchange="this.form.submit()" class="list-header-filter">
            <option value="">All agents</option>
            @foreach($agents as $a)
                <option value="{{ $a->id }}" {{ (string) ($filters['agent_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
            @endforeach
        </select>

        {{-- State: warned (at the warn line) vs stale (past release, ready for move-or-keep) --}}
        <select name="state" onchange="this.form.submit()" class="list-header-filter">
            <option value="" {{ ($filters['state'] ?? '') === '' ? 'selected' : '' }}>Warned + stale</option>
            <option value="warned" {{ ($filters['state'] ?? '') === 'warned' ? 'selected' : '' }}>Warned only</option>
            <option value="stale" {{ ($filters['state'] ?? '') === 'stale' ? 'selected' : '' }}>Stale only</option>
        </select>

        <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
        @if($filtersActive)
        <a href="{{ route('market-intelligence.stale-review') }}"
           class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear</a>
        @endif

        <span class="ml-auto text-xs" style="color:var(--text-muted);">{{ number_format($items->total()) }} claim{{ $items->total() === 1 ? '' : 's' }}</span>
    </form>

    {{-- Scroll region — everything from here down scrolls; header + filters stay put. --}}
    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-4 space-y-5">

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="min-w-full text-sm ds-table">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">On it</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Unworked</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Move or keep</th>
                </tr>
            </thead>
            <tbody>
            @forelse($items as $it)
                @php $c = $it['claim']; @endphp
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-3">
                        <div class="font-medium" style="color:var(--text-primary);">{{ $it['address'] }}</div>
                        @if($c->release_reason)
                            <div class="text-xs" style="color:var(--ds-crimson,#c41e3a);">Released: {{ str_replace('_',' ',$c->release_reason) }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $it['agent_name'] }}</td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded-full font-bold"
                              style="background:color-mix(in srgb, {{ $it['is_stale'] ? 'var(--ds-crimson,#c41e3a)' : 'var(--ds-amber,#f59e0b)' }} 15%, transparent); color:{{ $it['is_stale'] ? 'var(--ds-crimson,#c41e3a)' : 'var(--ds-amber,#f59e0b)' }};">
                            {{ $it['days'] }} day{{ $it['days'] === 1 ? '' : 's' }}{{ $it['is_stale'] ? ' · STALE' : ' · warned' }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2" x-data="{ mode:false }">
                            <form method="POST" action="{{ route('market-intelligence.stale-review.keep', $c->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded" style="border:1px solid var(--border); color:var(--text-secondary);">Keep</button>
                            </form>
                            <template x-if="!mode">
                                <button type="button" @click="mode=true" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background:var(--brand-button,#0ea5e9);">Reassign</button>
                            </template>
                            <template x-if="mode">
                                <form method="POST" action="{{ route('market-intelligence.stale-review.reassign', $c->id) }}" class="inline-flex items-center gap-2">
                                    @csrf
                                    <select name="new_agent_id" required class="text-xs rounded px-2 py-1.5" style="border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary);">
                                        <option value="">Reassign to…</option>
                                        @foreach($agents as $a)
                                            @if($a->id !== $c->user_id)<option value="{{ $a->id }}">{{ $a->name }}</option>@endif
                                        @endforeach
                                    </select>
                                    <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background:var(--ds-green,#059669);">Move</button>
                                    <button type="button" @click="mode=false" class="text-xs" style="color:var(--text-muted);">Cancel</button>
                                </form>
                            </template>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-sm" style="color:var(--text-muted);">
                    @if($filtersActive)
                        No claims match these filters.
                    @else
                        No stale or warned claims — everyone's on top of their stock.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        </div>

        @if($items->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $items->links() }}
        </div>
        @endif
    </div>

    </div>{{-- /scroll region --}}
</div>
@endsection
