@extends('layouts.corex')

@section('corex-content')
<div class="p-6 max-w-7xl mx-auto">

    {{-- Page header --}}
    <div class="mb-4 p-4 rounded-md" style="background: var(--brand-default); color: #fff;">
        <h1 class="text-xl font-semibold leading-tight">Tracked Properties</h1>
        <p class="text-sm mt-0.5" style="color: rgba(255,255,255,0.75);">
            Every property CoreX knows about — from CMA reports, P24 alerts, PP listings, portal captures, and more.
        </p>
    </div>

    {{-- Flash messages --}}
    @if(session('status'))
        <div class="mb-3 rounded-md px-4 py-2 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border: 1px solid var(--ds-green);">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-3 rounded-md px-4 py-2 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626);">
            {{ session('error') }}
        </div>
    @endif

    {{-- KPI tiles (agency-wide totals, NOT filter-scoped) --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Total tracked</div>
            <div class="text-3xl font-semibold" style="color: var(--text-primary);">{{ number_format($stats['total']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">across every ingestion source</div>
        </div>
        <div class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Unpromoted</div>
            <div class="text-3xl font-semibold" style="color: var(--brand-button);">{{ number_format($stats['unpromoted']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">opportunities to win mandates</div>
        </div>
        <div class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Promoted to stock</div>
            <div class="text-3xl font-semibold" style="color: var(--ds-green, #10b981);">{{ number_format($stats['promoted']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">won mandates with audit trail intact</div>
        </div>
    </div>

    {{-- Source attribution chips (clickable filters) --}}
    @if($sourceCounts->isNotEmpty())
        <div class="mb-4 p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">By source</div>
            <div class="flex flex-wrap gap-2">
                @foreach($sourceCounts as $type => $row)
                    @php $isActive = request('source') === $type; @endphp
                    <a href="?source={{ $type }}{{ request('search') ? '&search=' . urlencode(request('search')) : '' }}{{ request('suburb') ? '&suburb=' . urlencode(request('suburb')) : '' }}{{ request('status') ? '&status=' . urlencode(request('status')) : '' }}"
                       class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded font-medium no-underline"
                       style="background: {{ $isActive ? 'var(--brand-button)' : 'var(--surface-2)' }};
                              color: {{ $isActive ? '#fff' : 'var(--text-primary)' }};
                              border: 1px solid {{ $isActive ? 'var(--brand-button)' : 'var(--border)' }};">
                        {{ strtoupper($type) }}
                        <span class="font-bold">{{ $row->cnt }}</span>
                    </a>
                @endforeach
                @if(request('source'))
                    <a href="{{ route('corex.tracked-properties.index', request()->except('source')) }}"
                       class="inline-flex items-center px-2 py-1 text-xs rounded no-underline"
                       style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                        × Clear source
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- Filter form --}}
    <form method="GET" class="mb-4 p-4 rounded-md grid grid-cols-1 md:grid-cols-4 gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        @if(request('source'))<input type="hidden" name="source" value="{{ request('source') }}">@endif

        <div>
            <label class="block text-[10px] uppercase tracking-wider font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="address, erf, deed, external_id…"
                   class="w-full px-3 py-2 text-sm rounded"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider font-medium mb-1" style="color: var(--text-secondary);">Suburb</label>
            <select name="suburb" class="w-full px-3 py-2 text-sm rounded"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All suburbs</option>
                @foreach($suburbCounts as $sub)
                    <option value="{{ $sub->suburb }}" @selected(request('suburb') === $sub->suburb)>
                        {{ $sub->suburb }} ({{ $sub->cnt }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="w-full px-3 py-2 text-sm rounded"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="active"   @selected(request('status') === 'active')>Active (unpromoted)</option>
                <option value="promoted" @selected(request('status') === 'promoted')>Promoted to stock</option>
                <option value="archived" @selected(request('status') === 'archived')>Archived</option>
                <option value="duplicate" @selected(request('status') === 'duplicate')>Duplicate</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 px-4 py-2 text-sm font-medium rounded"
                    style="background: var(--brand-button); color: #fff;">
                Apply
            </button>
            @if(request()->hasAny(['search', 'suburb', 'status', 'source']))
                <a href="{{ route('corex.tracked-properties.index') }}"
                   class="px-3 py-2 text-sm rounded no-underline"
                   style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                    Reset
                </a>
            @endif
        </div>
    </form>

    {{-- Result count --}}
    <div class="mb-2 text-xs" style="color: var(--text-muted);">
        Showing {{ number_format($tps->count()) }} of {{ number_format($tps->total()) }} tracked properties
        @if(request()->hasAny(['search', 'suburb', 'status', 'source']))
            <span style="color: var(--brand-button);">· filtered</span>
        @endif
    </div>

    {{-- List table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-3 py-2.5 text-[10px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Property</th>
                        <th class="text-left px-3 py-2.5 text-[10px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Erf</th>
                        <th class="text-left px-3 py-2.5 text-[10px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Sources</th>
                        <th class="text-left px-3 py-2.5 text-[10px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-3 py-2.5 text-[10px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">Last enriched</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tps as $tp)
                        <tr style="border-top: 1px solid var(--border); cursor: pointer;"
                            onclick="window.location.href='{{ route('corex.tracked-properties.show', $tp) }}';">
                            <td class="px-3 py-3">
                                <div class="font-medium" style="color: var(--text-primary);">{{ $tp->displayAddress() }}</div>
                                <div class="text-[11px]" style="color: var(--text-muted);">
                                    @if($tp->property_type){{ ucwords($tp->property_type) }}@endif
                                    @if($tp->bedrooms) · {{ $tp->bedrooms }} bed @endif
                                    @if($tp->bathrooms) · {{ $tp->bathrooms }} bath @endif
                                    @if($tp->erf_size_m2) · {{ rtrim(rtrim(number_format($tp->erf_size_m2, 0), '0'), '.') }} m² @endif
                                </div>
                            </td>
                            <td class="px-3 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ $tp->erf_number ?: '—' }}
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @php
                                        $sourceTypes = collect($tp->source_chain ?? [])
                                            ->pluck('type')
                                            ->filter()
                                            ->unique()
                                            ->values();
                                    @endphp
                                    @forelse($sourceTypes as $t)
                                        <span class="inline-flex items-center px-1.5 py-0.5 text-[10px] font-semibold rounded"
                                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                                            {{ strtoupper($t) }}
                                        </span>
                                    @empty
                                        <span class="text-[10px]" style="color: var(--text-muted);">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                @if($tp->status === 'promoted')
                                    <span class="inline-flex items-center px-2 py-0.5 text-[10px] font-semibold rounded"
                                          style="background: color-mix(in srgb, var(--ds-green, #10b981) 18%, transparent); color: var(--ds-green, #10b981); border: 1px solid var(--ds-green, #10b981);">
                                        Promoted
                                    </span>
                                @elseif($tp->status === 'active')
                                    <span class="inline-flex items-center px-2 py-0.5 text-[10px] font-semibold rounded"
                                          style="background: color-mix(in srgb, var(--brand-button) 18%, transparent); color: var(--brand-button); border: 1px solid var(--brand-button);">
                                        Active
                                    </span>
                                @else
                                    <span class="text-[10px]" style="color: var(--text-muted);">{{ ucfirst($tp->status) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-[11px]" style="color: var(--text-muted);">
                                {{ $tp->last_enriched_at ? $tp->last_enriched_at->diffForHumans() : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No tracked properties match your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $tps->links() }}
    </div>
</div>
@endsection
