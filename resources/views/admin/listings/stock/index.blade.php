@extends('layouts.corex')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Company Listing Stock</h1>
                <p class="text-sm text-white/60">Read-only view from imported Propcon stock. Filters affect totals.</p>
            </div>
        </div>
    </div>

    {{-- KPI Stats --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card
            title="Listings"
            :value="number_format((int)($summary->listing_count ?? 0))" />
        <x-corex-kpi-card
            title="Total Value"
            :value="'R ' . number_format(((int)($summary->total_price_cents ?? 0))/100, 0)" />
        <x-corex-kpi-card
            title="Avg DOM"
            :value="number_format((int)($context['avg_dom'] ?? 0))" />
        <x-corex-kpi-card
            title="Filtered"
            :value="number_format((int)($context['count'] ?? 0))" />
    </div>

    {{-- Context Card --}}
    @if(!empty($context))
    <div class="ds-status-card" style="border-left: 3px solid var(--brand-icon, #0ea5e9);">
        <div class="flex items-center justify-between gap-6">
            <div class="min-w-0">
                <div class="ds-label">{{ strtoupper((string)($context['filter'] ?? 'view')) }}</div>
                <div class="ds-value text-xl">{{ $context['title'] ?? 'Listings' }}</div>
                <div class="text-sm mt-1" style="color: var(--text-muted);">{{ $context['note'] ?? '' }}</div>
            </div>
            <div class="text-right shrink-0">
                <div class="ds-label">Count</div>
                <div class="ds-value-lg">{{ number_format((int)($context['count'] ?? 0)) }}</div>
            </div>
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="get" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select name="status" class="w-full rounded-md text-sm px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="active" {{ $statusFilter==='active' ? 'selected' : '' }}>Active (contains active/for sale)</option>
                    <option value="all" {{ $statusFilter==='all' ? 'selected' : '' }}>All</option>
                    <option value="sold" {{ $statusFilter==='sold' ? 'selected' : '' }}>Contains: sold</option>
                    <option value="withdrawn" {{ $statusFilter==='withdrawn' ? 'selected' : '' }}>Contains: withdrawn</option>
                    <option value="expired" {{ $statusFilter==='expired' ? 'selected' : '' }}>Contains: expired</option>
                    <option value="under offer" {{ $statusFilter==='under offer' ? 'selected' : '' }}>Contains: under offer</option>
                </select>
            </div>

            <div class="min-w-[180px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Mandate contains</label>
                <input type="text" name="mandate" value="{{ $mandate }}"
                       placeholder="e.g. open / sole"
                       class="w-full rounded-md text-sm px-3 py-2 placeholder:opacity-50" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="min-w-[180px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type contains</label>
                <input type="text" name="type" value="{{ $type }}"
                       placeholder="e.g. apartment"
                       class="w-full rounded-md text-sm px-3 py-2 placeholder:opacity-50" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>

            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary">Apply</button>
                <a href="{{ route('admin.listings.stock') }}" class="corex-btn-outline">Reset</a>
            </div>
        </form>

        <div class="flex flex-wrap items-start gap-4">
            <div class="flex items-center gap-2">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Mandate</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($byMandate as $m)
                        <a href="{{ route('admin.listings.stock', array_merge(request()->except('page'), ['mandate' => $m->label])) }}"
                           class="stock-chip inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary);">
                            <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((int)$m->c) }}</span>
                            <span>{{ $m->label }}</span>
                        </a>
                    @empty
                        <span class="text-xs" style="color: var(--text-muted);">No mandate data</span>
                    @endforelse
                </div>
            </div>

            <div class="px-1 font-semibold select-none" style="color: var(--text-muted);">|</div>

            <div class="flex items-center gap-2">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Type</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($byType as $t)
                        <a href="{{ route('admin.listings.stock', array_merge(request()->except('page'), ['type' => $t->label])) }}"
                           class="stock-chip inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary);">
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
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Listings</div>
            <div class="text-xs" style="color: var(--text-muted);">
                {{ number_format($listings->total()) }} total — page {{ $listings->currentPage() }} of {{ $listings->lastPage() }}
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Mandate</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">DOM</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Since edit</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Price</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">CMA (R)</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($listings as $l)
                    @php
                        $statusRaw = trim((string)($l->status ?? ''));
                        $statusLower = strtolower($statusRaw);
                        $statusClass = 'ds-badge-default';
                        if ($statusLower === '') {
                            $statusClass = 'ds-badge-default';
                        } elseif (str_contains($statusLower, 'active') || str_contains($statusLower, 'for sale')) {
                            $statusClass = 'ds-badge-success';
                        } elseif (str_contains($statusLower, 'under offer') || str_contains($statusLower, 'pending')) {
                            $statusClass = 'ds-badge-warning';
                        } elseif (str_contains($statusLower, 'sold')) {
                            $statusClass = 'ds-badge-info';
                        } elseif (str_contains($statusLower, 'expired') || str_contains($statusLower, 'withdrawn')) {
                            $statusClass = 'ds-badge-default';
                        }
                        $address = trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], ' ', (string)($l->property ?? '')))) ?: ($l->region ?: '(no address)');
                        $dte = $l->days_to_expiry;
                    @endphp
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3">
                            <div class="font-semibold" style="color: var(--text-primary);">{{ $address }}</div>
                            @if($l->region && $address !== $l->region)
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $l->region }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($statusRaw !== '')
                                <span class="ds-badge {{ $statusClass }}">{{ \Illuminate\Support\Str::limit($statusRaw, 20, '') }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $l->mandate ?: '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $l->type ?: '—' }}</td>
                        <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">
                            {{ $l->days_on_market !== null ? number_format((int)$l->days_on_market) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">
                            {{ $l->days_since_edit !== null ? number_format((int)$l->days_since_edit) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($l->expires_at)
                                <div class="font-medium" style="color: var(--text-primary);">{{ $l->expires_on }}</div>
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
                        <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">
                            @if($l->price_cents !== null)
                                R {{ number_format($l->price_cents/100, 0) }}
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($l->cma_price_cents !== null)
                                <div class="font-semibold" style="color: var(--text-primary);">R {{ number_format($l->cma_price_cents/100, 0) }}</div>
                                @if($l->cma_updated_at)
                                    <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                        updated {{ is_string($l->cma_updated_at) ? substr($l->cma_updated_at,0,10) : $l->cma_updated_at->format('Y-m-d') }}
                                    </div>
                                @endif
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-xs" style="color: var(--text-muted);">
                            {{ $l->external_ref ?? $l->external_id ?? '—' }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center" style="color: var(--text-muted);">
                            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 2.25 21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                                </svg>
                            </div>
                            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No listings found</h3>
                            <p class="text-sm" style="color: var(--text-muted);">Try clearing filters or importing fresh stock.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $listings->links() }}
        </div>
    </div>

</div>

@push('head')
<style>
    .stock-chip { transition: background-color 150ms ease, border-color 150ms ease; }
    .stock-chip:hover { background: var(--surface) !important; border-color: var(--border-hover) !important; }
    .ds-table tbody tr { transition: background-color 150ms ease; }
    .ds-table tbody tr:hover { background: var(--surface-2); }
</style>
@endpush
@endsection
