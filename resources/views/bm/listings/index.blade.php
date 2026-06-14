{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
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

    {{-- Context / Summary Card --}}
    @if(!empty($context))
    <div class="ds-status-card" style="border-left: 3px solid var(--brand-icon, #0ea5e9);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="min-w-0">
                <div class="ds-label">{{ strtoupper((string)($context['filter'] ?? '')) ?: 'VIEW' }}</div>
                <div class="ds-value text-xl">{{ $context['title'] ?? 'Listings' }}</div>
                <p class="text-sm mt-1" style="color: var(--text-muted);">{{ $context['note'] ?? '' }}</p>
            </div>
            <div class="flex items-center gap-6 shrink-0">
                <div class="text-right">
                    <div class="ds-label">Listings</div>
                    <div class="ds-value-lg">{{ number_format((int)($summary->listing_count ?? 0)) }}</div>
                </div>
                <div class="text-right">
                    <div class="ds-label">Total value</div>
                    <div class="ds-value-lg">R {{ number_format(((int)($summary->total_price_cents ?? 0))/100, 0) }}</div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="get" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px]">
                <label for="bm-filter-status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="bm-filter-status" name="status" onchange="this.form.submit()" class="w-full rounded-md text-sm px-3 py-1.5 transition-colors duration-150"
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
                       class="w-full rounded-md text-sm px-3 py-1.5 transition-colors duration-150 placeholder:opacity-50"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="min-w-[180px]">
                <label for="bm-filter-type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type contains</label>
                <input id="bm-filter-type" type="text" name="type" value="{{ $type }}"
                       placeholder="e.g. apartment"
                       class="w-full rounded-md text-sm px-3 py-1.5 transition-colors duration-150 placeholder:opacity-50"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary">Apply</button>
                <a href="{{ route('agent.listings') }}" class="corex-btn-outline">Reset</a>
            </div>
        </form>

        <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
            <div class="flex items-center gap-2 flex-wrap">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Mandate</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($byMandate as $m)
                        <a href="{{ route('agent.listings', array_merge(request()->except('page'), ['mandate' => $m->label])) }}"
                           class="bm-filter-pill inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs">
                            <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((int)$m->c) }}</span>
                            <span>{{ $m->label }}</span>
                        </a>
                    @empty
                        <span class="text-xs" style="color: var(--text-muted);">No mandate data</span>
                    @endforelse
                </div>
            </div>

            <div class="hidden md:block px-1 font-semibold select-none" style="color: var(--text-muted);">|</div>

            <div class="flex items-center gap-2 flex-wrap">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Type</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($byType as $t)
                        <a href="{{ route('agent.listings', array_merge(request()->except('page'), ['type' => $t->label])) }}"
                           class="bm-filter-pill inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs">
                            <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((int)$t->c) }}</span>
                            <span>{{ $t->label }}</span>
                        </a>
                    @empty
                        <span class="text-xs" style="color: var(--text-muted);">No type data</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Listings Table --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-4 py-3 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Listings</div>
            <div class="text-xs" style="color: var(--text-muted);">
                {{ number_format($listings->total()) }} total · page {{ number_format($listings->currentPage()) }} of {{ number_format($listings->lastPage()) }}
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table bm-listing-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left  px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left  px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Mandate</th>
                        <th class="text-left  px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">DOM</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Since edit</th>
                        <th class="text-left  px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Price</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">CMA (R)</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($listings as $l)
                        @php
                            $statusRaw = strtolower((string)($l->status ?? ''));
                            $statusClass = 'ds-badge-default';
                            if (str_contains($statusRaw, 'sold')) {
                                $statusClass = 'ds-badge-success';
                            } elseif (str_contains($statusRaw, 'under offer')) {
                                $statusClass = 'ds-badge-warning';
                            } elseif (str_contains($statusRaw, 'expired') || str_contains($statusRaw, 'withdrawn')) {
                                $statusClass = 'ds-badge-default';
                            } elseif (str_contains($statusRaw, 'active') || str_contains($statusRaw, 'for sale')) {
                                $statusClass = 'ds-badge-info';
                            }
                            $address = trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], ' ', (string)($l->property ?? ''))));
                            $address = $address !== '' ? $address : ($l->region ?: '(no address)');
                        @endphp
                        <tr class="bm-listing-address">
                            <td colspan="9" class="px-4 py-2.5">
                                <div class="font-semibold" style="color: var(--text-primary);">{{ $address }}</div>
                            </td>
                        </tr>
                        <tr class="bm-listing-row">
                            <td class="px-4 py-2.5">
                                @if($l->status)
                                    <span class="ds-badge {{ $statusClass }}">{{ $l->status }}</span>
                                @else
                                    <span class="ds-badge ds-badge-default">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $l->mandate ?: '—' }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $l->type ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold" style="color: var(--text-primary);">
                                {{ $l->days_on_market !== null ? number_format((int)$l->days_on_market) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold" style="color: var(--text-primary);">
                                {{ $l->days_since_edit !== null ? number_format((int)$l->days_since_edit) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                @if($l->expires_at)
                                    <div class="font-medium" style="color: var(--text-primary);">{{ $l->expires_on }}</div>
                                    @php $dte = $l->days_to_expiry; @endphp
                                    @if(!is_null($dte))
                                        @if($dte < 0)
                                            <div style="color: var(--text-muted);">expired {{ number_format(abs((int)$dte)) }}d ago</div>
                                        @else
                                            <div style="color: var(--text-muted);">in {{ number_format((int)$dte) }}d</div>
                                        @endif
                                    @endif
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold" style="color: var(--text-primary);">
                                @if($l->price_cents !== null)
                                    R {{ number_format($l->price_cents/100, 0) }}
                                @else
                                    <span class="font-normal" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if($l->cma_price_cents !== null)
                                    <div class="font-semibold" style="color: var(--text-primary);">R {{ number_format($l->cma_price_cents/100, 0) }}</div>
                                @else
                                    <div style="color: var(--text-muted);">—</div>
                                @endif
                                @if($l->cma_updated_at)
                                    <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                        updated {{ is_string($l->cma_updated_at) ? substr($l->cma_updated_at,0,10) : $l->cma_updated_at->format('Y-m-d') }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-xs" style="color: var(--text-muted);">
                                {{ $l->external_ref ?? $l->external_id ?? '—' }}
                            </td>
                        </tr>
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
    /* Filter pill — token-aware hover via CSS, no inline JS */
    .bm-filter-pill {
        border: 1px solid var(--border);
        background: var(--surface-2);
        color: var(--text-secondary);
        transition: background 150ms ease, border-color 150ms ease, color 150ms ease;
    }
    .bm-filter-pill:hover {
        background: var(--surface);
        border-color: var(--border-hover);
    }

    /* Two-row listing pattern: address banner + data row are visually grouped.
       Disable the default ds-table zebra and per-row hover; hover the pair as one unit. */
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
