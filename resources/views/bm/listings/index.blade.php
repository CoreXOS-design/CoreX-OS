{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $activeCount     = (int)($summary->listing_count ?? 0);
    $totalValueRand  = (int) round(((int)($summary->total_price_cents ?? 0)) / 100);
    $contextCount    = (int)($context['count'] ?? 0);
    $hasActiveFilter = ($mandate !== '')
        || ($type !== '')
        || (($filter ?? '') !== '')
        || (($statusFilter ?? 'active') !== 'active');
@endphp

<div class="w-full space-y-5">

    {{-- Page Header (Pattern A: branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Branch Listing Stock</h1>
                <p class="text-sm text-white/60">Read-only view from imported Propcon stock for your branch.</p>
            </div>
        </div>
    </div>

    {{-- KPI Tiles --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Listings" :value="number_format($activeCount)" />
        <x-corex-kpi-card title="Total stock value" :value="'R ' . number_format($totalValueRand)" />
    </div>

    {{-- Context Notice --}}
    @if(!empty($context))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                color: var(--text-primary);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5"
             style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
        </svg>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="ds-badge ds-badge-info" title="Current listing view">{{ strtoupper((string)(($context['filter'] ?? '') ?: 'active')) }}</span>
                <strong>{{ $context['title'] ?? 'Listings' }}</strong>
                <span class="text-xs" style="color: var(--text-muted);">·</span>
                <span class="text-xs" style="color: var(--text-muted);">{{ number_format($contextCount) }} {{ \Illuminate\Support\Str::plural('listing', $contextCount) }}</span>
            </div>
            @if(!empty($context['note']))
            <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $context['note'] }}</div>
            @endif
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="get" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px]">
                <label for="bm-filter-status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="bm-filter-status" name="status" onchange="this.form.submit()"
                        class="w-full rounded-md text-sm px-3 py-2 transition-colors duration-150"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="active" {{ $statusFilter==='active' ? 'selected' : '' }}>Active (contains active/for sale)</option>
                    <option value="all" {{ $statusFilter==='all' ? 'selected' : '' }}>All</option>
                    <option value="sold" {{ $statusFilter==='sold' ? 'selected' : '' }}>Contains: sold</option>
                    <option value="withdrawn" {{ $statusFilter==='withdrawn' ? 'selected' : '' }}>Contains: withdrawn</option>
                    <option value="expired" {{ $statusFilter==='expired' ? 'selected' : '' }}>Contains: expired</option>
                    <option value="under offer" {{ $statusFilter==='under offer' ? 'selected' : '' }}>Contains: under offer</option>
                </select>
            </div>

            <div class="min-w-[180px]">
                <label for="bm-filter-mandate" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Mandate contains</label>
                <input id="bm-filter-mandate" type="text" name="mandate" value="{{ $mandate }}"
                       placeholder="e.g. open / sole"
                       class="w-full rounded-md text-sm px-3 py-2 transition-colors duration-150 placeholder:opacity-50"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="min-w-[180px]">
                <label for="bm-filter-type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type contains</label>
                <input id="bm-filter-type" type="text" name="type" value="{{ $type }}"
                       placeholder="e.g. apartment"
                       class="w-full rounded-md text-sm px-3 py-2 transition-colors duration-150 placeholder:opacity-50"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary">Apply</button>
                @if($hasActiveFilter)
                <a href="{{ route('bm.listings') }}" class="corex-btn-outline">Reset</a>
                @endif
            </div>
        </form>

        <div class="flex flex-wrap items-start gap-x-6 gap-y-3 pt-1" style="border-top: 1px solid var(--border);">
            <div class="flex items-center gap-2 flex-wrap pt-3">
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Mandate</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($byMandate as $m)
                        <a href="{{ route('bm.listings', array_merge(request()->except('page'), ['mandate' => $m->label])) }}"
                           class="bm-filter-pill inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs">
                            <span class="font-semibold">{{ number_format((int)$m->c) }}</span>
                            <span>{{ $m->label }}</span>
                        </a>
                    @empty
                        <span class="text-xs" style="color: var(--text-muted);">None</span>
                    @endforelse
                </div>
            </div>

            <div class="flex items-center gap-2 flex-wrap pt-3">
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Type</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($byType as $t)
                        <a href="{{ route('bm.listings', array_merge(request()->except('page'), ['type' => $t->label])) }}"
                           class="bm-filter-pill inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs">
                            <span class="font-semibold">{{ number_format((int)$t->c) }}</span>
                            <span>{{ $t->label }}</span>
                        </a>
                    @empty
                        <span class="text-xs" style="color: var(--text-muted);">None</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Listings Table Card --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between gap-3 flex-wrap" style="border-bottom: 1px solid var(--border);">
            <span class="text-sm font-semibold" style="color: var(--text-primary);">Listings</span>
            <span class="text-xs" style="color: var(--text-muted);">
                {{ number_format($listings->total()) }} total · page {{ number_format($listings->currentPage()) }} of {{ number_format($listings->lastPage()) }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table bm-listing-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left  px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left  px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Mandate</th>
                        <th class="text-left  px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">DOM</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Since edit</th>
                        <th class="text-left  px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Price</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">CMA (R)</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($listings as $l)
                        @php
                            $statusRaw = strtolower((string)($l->status ?? ''));
                            if ($statusRaw === '') {
                                $statusBadge = 'ds-badge-default';
                            } elseif (str_contains($statusRaw, 'active') || str_contains($statusRaw, 'for sale')) {
                                $statusBadge = 'ds-badge-success';
                            } elseif (str_contains($statusRaw, 'under offer') || str_contains($statusRaw, 'pending') || str_contains($statusRaw, 'hold')) {
                                $statusBadge = 'ds-badge-warning';
                            } elseif (str_contains($statusRaw, 'sold') || str_contains($statusRaw, 'closed')) {
                                $statusBadge = 'ds-badge-info';
                            } else {
                                $statusBadge = 'ds-badge-default';
                            }

                            $address = trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], ' ', (string)($l->property ?? ''))));
                            $address = $address !== '' ? $address : ($l->region ?: '(no address)');

                            $dom  = $l->days_on_market !== null ? (int)$l->days_on_market : null;
                            $edit = $l->days_since_edit !== null ? (int)$l->days_since_edit : null;
                            $domColor  = ($dom  !== null && $dom  >= 90) ? 'var(--ds-amber)' : 'var(--text-primary)';
                            $editColor = ($edit !== null && $edit >= 14) ? 'var(--ds-amber)' : 'var(--text-primary)';
                        @endphp

                        {{-- Address row --}}
                        <tr class="bm-listing-address">
                            <td colspan="9" class="px-4 py-2">
                                <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $address }}</div>
                            </td>
                        </tr>

                        {{-- Data row --}}
                        <tr class="bm-listing-row">
                            <td class="px-4 py-3">
                                @if($l->status)
                                    <span class="ds-badge {{ $statusBadge }}">{{ \Illuminate\Support\Str::limit((string)$l->status, 20, '') }}</span>
                                @else
                                    <span class="ds-badge ds-badge-default">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($l->mandate)
                                    <span class="ds-badge ds-badge-default">{{ \Illuminate\Support\Str::limit((string)$l->mandate, 20, '') }}</span>
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($l->type)
                                    <span class="ds-badge ds-badge-default">{{ \Illuminate\Support\Str::limit((string)$l->type, 20, '') }}</span>
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: {{ $domColor }};">
                                {{ $dom !== null ? number_format($dom) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: {{ $editColor }};">
                                {{ $edit !== null ? number_format($edit) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($l->expires_at)
                                    <div class="font-medium" style="color: var(--text-primary);">{{ $l->expires_on }}</div>
                                    @php $dte = $l->days_to_expiry; @endphp
                                    @if(!is_null($dte))
                                        @if($dte < 0)
                                            <div style="color: var(--ds-amber);">expired {{ number_format(abs((int)$dte)) }}d ago</div>
                                        @else
                                            <div style="color: var(--text-muted);">in {{ number_format((int)$dte) }}d</div>
                                        @endif
                                    @endif
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">
                                @if($l->price_cents !== null)
                                    R {{ number_format($l->price_cents/100, 0) }}
                                @else
                                    <span class="font-normal" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($l->cma_price_cents !== null)
                                    <div class="font-semibold" style="color: var(--text-primary);">R {{ number_format($l->cma_price_cents/100, 0) }}</div>
                                @else
                                    <div style="color: var(--text-muted);">—</div>
                                @endif
                                @if($l->cma_updated_at)
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                        updated {{ is_string($l->cma_updated_at) ? substr($l->cma_updated_at,0,10) : $l->cma_updated_at->format('Y-m-d') }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-xs" style="color: var(--text-muted);">
                                {{ $l->external_ref ?? $l->external_id ?? '—' }}
                            </td>
                        </tr>

                        {{-- Separator --}}
                        <tr aria-hidden="true" class="bm-listing-spacer"><td colspan="9" class="p-0"></td></tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No listings found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($listings->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $listings->links() }}
            </div>
        @endif
    </div>

</div>

<style>
    /* Branded filter pill — token-aware hover via CSS, no inline JS. */
    .bm-filter-pill {
        background: color-mix(in srgb, var(--brand-icon) 12%, transparent);
        color: var(--brand-icon);
        border: 1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);
        white-space: nowrap;
        transition: background 150ms ease, border-color 150ms ease;
    }
    .bm-filter-pill:hover {
        background: color-mix(in srgb, var(--brand-icon) 20%, transparent);
        border-color: color-mix(in srgb, var(--brand-icon) 45%, transparent);
    }

    /* Three-row listing pattern: address banner + data row + spacer are one unit.
       Neutralise the default .ds-table nth-child zebra (hardcoded #f8fafc breaks dark
       mode) and per-row hover; shade + hover the pair with tokens instead. */
    .bm-listing-table tbody tr.bm-listing-address,
    .bm-listing-table tbody tr.bm-listing-row,
    .bm-listing-table tbody tr.bm-listing-spacer {
        background: transparent;
    }
    .bm-listing-table tbody tr.bm-listing-address {
        background: var(--surface-2);
    }
    .bm-listing-table tbody tr.bm-listing-row {
        border-top: 1px solid var(--border);
    }
    .bm-listing-table tbody tr.bm-listing-spacer td {
        height: 0.5rem;
        border: 0;
    }
    .bm-listing-table tbody tr.bm-listing-address:hover,
    .bm-listing-table tbody tr.bm-listing-row:hover {
        background: color-mix(in srgb, var(--brand-icon) 6%, var(--surface));
    }
</style>
@endsection
