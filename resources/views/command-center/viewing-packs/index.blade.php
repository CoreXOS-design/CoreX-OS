{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full h-full flex flex-col">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Viewing Packs</h1>
                <p class="text-xs" style="color: var(--text-muted);">Buyer-facing property packs assembled for viewings.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($showArchived)
                    <a href="{{ route('corex.viewing-packs.index') }}" class="corex-btn-outline text-xs">Active packs</a>
                @else
                    <a href="{{ route('corex.viewing-packs.index', ['archived' => 1]) }}" class="corex-btn-outline text-xs">View archived</a>
                @endif
            </div>
        </div>
    </div>

    {{-- Filters (AT-393, spec .ai/specs/viewing-pack.md §"List page filters") — directly
         under the header; one GET form so search + agent + status compose with each other
         AND with the archived toggle (carried as a hidden field). --}}
    @php $filtersActive = ($filters['q'] ?? '') !== '' || ($filters['agent_id'] ?? '') !== '' || ($filters['status'] ?? '') !== ''; @endphp
    <form method="GET" action="{{ route('corex.viewing-packs.index') }}"
          class="rounded-md px-4 py-3 mt-3 flex-shrink-0 flex flex-wrap items-center gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif

        {{-- Pack title / buyer name --}}
        <div class="relative flex-1 min-w-[180px] max-w-xs">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Search pack or buyer…"
                   class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
        </div>

        {{-- Agent — only above 'own' scope (an own-scope agent's list is already theirs) --}}
        @if($agents->isNotEmpty())
        <select name="agent_id" onchange="this.form.submit()" class="list-header-filter">
            <option value="">All agents</option>
            @foreach($agents as $a)
                <option value="{{ $a->id }}" {{ (string) ($filters['agent_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
            @endforeach
        </select>
        @endif

        {{-- Status --}}
        <select name="status" onchange="this.form.submit()" class="list-header-filter">
            <option value="" {{ ($filters['status'] ?? '') === '' ? 'selected' : '' }}>All statuses</option>
            @foreach(\App\Models\ViewingPack::STATUSES as $s)
                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>

        <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
        @if($filtersActive)
        <a href="{{ route('corex.viewing-packs.index', $showArchived ? ['archived' => 1] : []) }}"
           class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear</a>
        @endif

        <span class="ml-auto text-xs" style="color:var(--text-muted);">{{ number_format($packs->total()) }} pack{{ $packs->total() === 1 ? '' : 's' }}</span>
    </form>

    {{-- Scroll region (AT-393) — header + filters are frozen: the page wrapper is a full-height
         flex column and ONLY this region (flash + packs table + pagination) scrolls. --}}
    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-4 space-y-5">
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if($packs->isEmpty())
            <div class="py-12 px-6 text-center">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                    {{ $filtersActive ? 'No packs match these filters' : ($showArchived ? 'No archived packs' : 'No viewing packs yet') }}
                </h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">
                    {{ $showArchived ? 'Archived packs will appear here.' : 'Open a buyer in the Buyer Pipeline and click “Build Viewing Pack”.' }}
                </p>
                @unless($showArchived)
                    <a href="{{ route('command-center.buyers.pipeline') }}" class="corex-btn-primary no-underline">Open Buyer Pipeline</a>
                @endunless
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pack</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Buyer</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Properties</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($packs as $pack)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3" style="color: var(--text-primary);">{{ $pack->title ?: ('Pack #' . $pack->id) }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ optional($pack->contact)->full_name ?? '—' }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ optional($pack->agent)->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-center" style="color: var(--text-secondary);">{{ number_format($pack->viewing_pack_properties_count) }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $statusVariant = match($pack->status) {
                                            \App\Models\ViewingPack::STATUS_READY => 'ds-badge-success',
                                            default => 'ds-badge-default',
                                        };
                                    @endphp
                                    <span class="ds-badge {{ $statusVariant }}">{{ ucfirst($pack->status) }}</span>
                                </td>
                                <td class="px-4 py-3" style="color: var(--text-muted);">{{ optional($pack->created_at)->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($showArchived)
                                        <form method="POST" action="{{ route('corex.viewing-packs.restore', $pack->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Recover</button>
                                        </form>
                                    @else
                                        <a href="{{ route('corex.viewing-packs.show', $pack) }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon);">Open</a>
                                        <span style="color: var(--border);">·</span>
                                        <form method="POST" action="{{ route('corex.viewing-packs.destroy', $pack) }}" class="inline"
                                              onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($packs->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $packs->links() }}
                </div>
            @endif
        @endif
    </div>
    </div>{{-- /scroll region --}}
</div>
@endsection
