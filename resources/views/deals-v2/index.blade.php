@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded banner) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Register</h1>
                <p class="text-sm text-white/60">Track transactions from offer through to registration.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('deals_v2.create')
                <a href="{{ route('deals-v2.create') }}" class="corex-btn-primary inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Deal
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('deals-v2.index') }}" class="flex flex-wrap items-center gap-3">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search by reference, property..."
                       onchange="this.form.submit()"
                       class="list-header-filter w-full"
                       style="padding-left: 2.25rem;">
            </div>

            <select name="deal_type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All Types</option>
                <option value="bond" {{ request('deal_type') === 'bond' ? 'selected' : '' }}>Bond</option>
                <option value="cash" {{ request('deal_type') === 'cash' ? 'selected' : '' }}>Cash</option>
                <option value="sale_of_2nd" {{ request('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd</option>
            </select>

            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                <option value="on_hold" {{ request('status') === 'on_hold' ? 'selected' : '' }}>On Hold</option>
            </select>

            @if(request('search') || request('deal_type') || request('status'))
                <a href="{{ route('deals-v2.index') }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">Clear</a>
            @endif

            <span class="text-xs ml-auto" style="color: var(--text-muted);">
                Showing {{ number_format($deals->count()) }} of {{ number_format($deals->total()) }}
            </span>
        </form>
    </div>

    {{-- Deals table --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">RAG</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Exp. Reg</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Value</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-28" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deals as $deal)
                        @php
                            $typeBadge = match($deal->deal_type) {
                                'bond' => 'ds-badge-info',
                                'cash' => 'ds-badge-success',
                                'sale_of_2nd' => 'ds-badge-warning',
                                default => 'ds-badge-default',
                            };
                            $typeLabel = match($deal->deal_type) {
                                'bond' => 'Bond', 'cash' => 'Cash', 'sale_of_2nd' => '2nd Prop', default => $deal->deal_type,
                            };
                            $statusBadge = match($deal->status) {
                                'active' => 'ds-badge-info',
                                'completed' => 'ds-badge-success',
                                'cancelled' => 'ds-badge-danger',
                                'on_hold' => 'ds-badge-warning',
                                default => 'ds-badge-default',
                            };
                            $ragColor = match($deal->overall_rag) {
                                'green' => 'var(--ds-green)',
                                'amber' => 'var(--ds-amber)',
                                'red', 'overdue' => 'var(--ds-crimson)',
                                default => 'var(--text-muted)',
                            };
                        @endphp
                        <tr class="cursor-pointer"
                            style="border-top: 1px solid var(--border);"
                            onclick="window.location='{{ route('deals-v2.show', $deal) }}'">
                            <td class="px-4 py-3 font-mono font-medium" style="color: var(--text-primary);">{{ $deal->reference }}</td>
                            <td class="px-4 py-3" style="color: var(--text-primary);">{{ Str::limit($deal->property->address ?? '—', 40) }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $deal->listingAgent->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="ds-badge {{ $typeBadge }}">{{ $typeLabel }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="ds-badge {{ $statusBadge }}">{{ str_replace('_', ' ', $deal->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-block w-2.5 h-2.5 rounded-full" style="background: {{ $ragColor }};"></span>
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $deal->expected_registration ? $deal->expected_registration->format('d M Y') : '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($deal->purchase_price, 0) }}</td>
                            <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('deals-v2.show', $deal) }}" class="p-1 rounded transition-colors" style="color: var(--text-muted);" title="View">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                                    </a>
                                    @permission('deals_v2.edit')
                                    <a href="{{ route('deals-v2.edit', $deal) }}" class="p-1 rounded transition-colors" style="color: var(--text-muted);" title="Edit">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                                    </a>
                                    <a href="{{ route('deals-v2.settlement.index', $deal) }}" class="p-1 rounded transition-colors" style="color: var(--text-muted);" title="Settlement">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75V18m15-8.25v.75m-21-.75v.75M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0h18"/></svg>
                                    </a>
                                    @endpermission
                                    @permission('deals_v2.archive')
                                    <form method="POST" action="{{ route('deals-v2.destroy', $deal) }}" onsubmit="return confirm('Archive this deal?')" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="p-1 rounded transition-colors" style="color: var(--text-muted);" title="Archive">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                        </button>
                                    </form>
                                    @endpermission
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center">
                                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                </div>
                                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No deals yet</h3>
                                <p class="text-sm mb-4" style="color: var(--text-muted);">Create your first deal to start tracking transactions.</p>
                                @permission('deals_v2.create')
                                <a href="{{ route('deals-v2.create') }}" class="corex-btn-primary">Create Deal</a>
                                @endpermission
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($deals->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $deals->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
