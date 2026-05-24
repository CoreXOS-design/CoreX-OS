{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $sourceLabels = [
        'cma'     => 'CMA report',
        'p24'     => 'Property24 alert',
        'pp'      => 'Private Property',
        'portal'  => 'Portal capture',
        'manual'  => 'Manual entry',
        'deeds'   => 'Deeds office',
        'mandate' => 'Mandate signed',
        'scrape'  => 'Scraping',
        'chrome'  => 'Chrome capture',
    ];
@endphp

@section('corex-content')
<div class="p-4 lg:p-6 max-w-7xl mx-auto space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Tracked Properties</h1>
                <p class="text-sm text-white/60 mt-0.5">
                    Every property CoreX knows about — from CMA reports, P24 alerts, PP listings, portal captures, and more.
                </p>
            </div>
        </div>
    </div>

    {{-- Flash messages (§3.9 Alert block) --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ session('error') }}</div>
        </div>
    @endif

    {{-- KPI tiles --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[0.6875rem] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Total tracked</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">{{ number_format($stats['total']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">across every ingestion source</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[0.6875rem] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Unpromoted</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--brand-button, #0ea5e9);">{{ number_format($stats['unpromoted']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">opportunities to win mandates</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[0.6875rem] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Promoted to stock</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-green, #059669);">{{ number_format($stats['promoted']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">won mandates with audit trail intact</div>
        </div>
    </div>

    {{-- Source attribution chips (clickable filters) --}}
    @if($sourceCounts->isNotEmpty())
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[0.6875rem] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">By source</div>
            <div class="flex flex-wrap gap-2">
                @foreach($sourceCounts as $type => $row)
                    @php
                        $isActive = request('source') === $type;
                        $label = $sourceLabels[strtolower($type)] ?? ucwords(str_replace('_', ' ', $type));
                    @endphp
                    <a href="?source={{ $type }}{{ request('search') ? '&search=' . urlencode(request('search')) : '' }}{{ request('suburb') ? '&suburb=' . urlencode(request('suburb')) : '' }}{{ request('status') ? '&status=' . urlencode(request('status')) : '' }}"
                       title="Filter by {{ $label }}"
                       class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md font-medium no-underline transition-colors"
                       style="background: {{ $isActive ? 'var(--brand-button, #0ea5e9)' : 'var(--surface-2)' }};
                              color: {{ $isActive ? '#ffffff' : 'var(--text-primary)' }};
                              border: 1px solid {{ $isActive ? 'var(--brand-button, #0ea5e9)' : 'var(--border)' }};
                              white-space: nowrap;">
                        <span>{{ $label }}</span>
                        <span class="font-bold">{{ number_format($row->cnt) }}</span>
                    </a>
                @endforeach
                @if(request('source'))
                    <a href="{{ route('corex.tracked-properties.index', request()->except('source')) }}"
                       class="inline-flex items-center px-2 py-1 text-xs rounded-md no-underline transition-colors"
                       style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); white-space: nowrap;">
                        × Clear source
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- Filter form --}}
    <form method="GET" class="rounded-md p-4 grid grid-cols-1 md:grid-cols-4 gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        @if(request('source'))<input type="hidden" name="source" value="{{ request('source') }}">@endif

        <div>
            <label for="tp-search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input id="tp-search" type="text" name="search" value="{{ request('search') }}"
                   placeholder="Address, erf, deed or external ID…"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        </div>

        <div>
            <label for="tp-suburb" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Suburb</label>
            <select id="tp-suburb" name="suburb" class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All suburbs</option>
                @foreach($suburbCounts as $sub)
                    <option value="{{ $sub->suburb }}" @selected(request('suburb') === $sub->suburb)>
                        {{ $sub->suburb }} ({{ number_format($sub->cnt) }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="tp-status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select id="tp-status" name="status" class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="active"    @selected(request('status') === 'active')>Active (unpromoted)</option>
                <option value="promoted"  @selected(request('status') === 'promoted')>Promoted to stock</option>
                <option value="archived"  @selected(request('status') === 'archived')>Archived</option>
                <option value="duplicate" @selected(request('status') === 'duplicate')>Duplicate</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="corex-btn-primary flex-1">Apply</button>
            @if(request()->hasAny(['search', 'suburb', 'status', 'source']))
                <a href="{{ route('corex.tracked-properties.index') }}" class="corex-btn-outline">Reset</a>
            @endif
        </div>
    </form>

    {{-- Result count --}}
    <div class="text-xs" style="color: var(--text-muted);">
        Showing {{ number_format($tps->count()) }} of {{ number_format($tps->total()) }} tracked properties
        @if(request()->hasAny(['search', 'suburb', 'status', 'source']))
            <span style="color: var(--brand-button, #0ea5e9);">· filtered</span>
        @endif
    </div>

    {{-- List table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Erf</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Sources</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last enriched</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tps as $tp)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border); cursor: pointer;"
                            onclick="window.location.href='{{ route('corex.tracked-properties.show', $tp) }}';"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3">
                                <div class="font-medium" style="color: var(--text-primary);">{{ $tp->displayAddress() }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    @if($tp->property_type){{ ucwords($tp->property_type) }}@endif
                                    @if($tp->bedrooms) · {{ number_format($tp->bedrooms) }} bed @endif
                                    @if($tp->bathrooms) · {{ number_format($tp->bathrooms) }} bath @endif
                                    @if($tp->erf_size_m2) · {{ number_format($tp->erf_size_m2, 0) }} m² @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ $tp->erf_number ?: '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @php
                                        $sourceTypes = collect($tp->source_chain ?? [])
                                            ->pluck('type')
                                            ->filter()
                                            ->unique()
                                            ->values();
                                    @endphp
                                    @forelse($sourceTypes as $t)
                                        @php $tLabel = $sourceLabels[strtolower($t)] ?? ucwords(str_replace('_', ' ', $t)); @endphp
                                        <span class="ds-badge ds-badge-default" title="{{ $tLabel }}">{{ $tLabel }}</span>
                                    @empty
                                        <span class="text-xs" style="color: var(--text-muted);">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if($tp->status === 'promoted')
                                    <span class="ds-badge ds-badge-success">Promoted</span>
                                @elseif($tp->status === 'active')
                                    <span class="ds-badge ds-badge-info">Active</span>
                                @elseif($tp->status === 'archived')
                                    <span class="ds-badge ds-badge-default">Archived</span>
                                @elseif($tp->status === 'duplicate')
                                    <span class="ds-badge ds-badge-warning">Duplicate</span>
                                @else
                                    <span class="ds-badge ds-badge-default">{{ ucfirst($tp->status) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                {{ $tp->last_enriched_at ? $tp->last_enriched_at->diffForHumans() : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No tracked properties match your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        {{ $tps->links() }}
    </div>
</div>
@endsection
