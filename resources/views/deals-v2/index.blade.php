<x-app-layout>
    <div>
        <x-list-header
            title="Deal Register V2"
            :form-action="route('deals-v2.index')"
            :paginator="$deals"
            search-placeholder="Search by reference, property..."
        >
            <x-slot:filters>
                <select name="deal_type" onchange="this.form.submit()" class="text-sm rounded-md px-3 py-2 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Types</option>
                    <option value="bond" {{ request('deal_type') === 'bond' ? 'selected' : '' }}>Bond</option>
                    <option value="cash" {{ request('deal_type') === 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="sale_of_2nd" {{ request('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd</option>
                </select>
                <select name="status" onchange="this.form.submit()" class="text-sm rounded-md px-3 py-2 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Statuses</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    <option value="on_hold" {{ request('status') === 'on_hold' ? 'selected' : '' }}>On Hold</option>
                </select>
            </x-slot:filters>
            <x-slot:actions>
                @permission('deals_v2.create')
                <a href="{{ route('deals-v2.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                    + New Deal
                </a>
                @endpermission
            </x-slot:actions>
        </x-list-header>

        <div class="p-4 lg:p-6">
            @if(session('status'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                                <th class="text-center px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                                <th class="text-center px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                                <th class="text-center px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">RAG</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Exp. Reg</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Value</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider w-28" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($deals as $deal)
                                @php
                                    $typeBadge = match($deal->deal_type) {
                                        'bond' => 'background:rgba(59,130,246,0.15);color:#60a5fa;',
                                        'cash' => 'background:rgba(16,185,129,0.15);color:#34d399;',
                                        'sale_of_2nd' => 'background:rgba(245,158,11,0.15);color:#fbbf24;',
                                        default => '',
                                    };
                                    $typeLabel = match($deal->deal_type) {
                                        'bond' => 'Bond', 'cash' => 'Cash', 'sale_of_2nd' => '2nd Prop', default => $deal->deal_type,
                                    };
                                    $statusBadge = match($deal->status) {
                                        'active' => 'background:rgba(59,130,246,0.15);color:#60a5fa;',
                                        'completed' => 'background:rgba(16,185,129,0.15);color:#34d399;',
                                        'cancelled' => 'background:rgba(239,68,68,0.15);color:#f87171;',
                                        'on_hold' => 'background:rgba(245,158,11,0.15);color:#fbbf24;',
                                        default => '',
                                    };
                                    $ragColor = match($deal->overall_rag) {
                                        'green' => '#22c55e', 'amber' => '#f59e0b', 'red' => '#ef4444', 'overdue' => '#dc2626', default => '#6b7280',
                                    };
                                @endphp
                                <tr class="transition-colors cursor-pointer"
                                    style="border-bottom: 1px solid var(--border);"
                                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'"
                                    onclick="window.location='{{ route('deals-v2.show', $deal) }}'">
                                    <td class="px-4 py-2 font-mono font-medium" style="color: var(--text-primary);">{{ $deal->reference }}</td>
                                    <td class="px-4 py-2" style="color: var(--text-primary);">{{ Str::limit($deal->property->address ?? '—', 40) }}</td>
                                    <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $deal->listingAgent->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium" style="{{ $typeBadge }}">{{ $typeLabel }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium capitalize" style="{{ $statusBadge }}">{{ str_replace('_', ' ', $deal->status) }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <span class="inline-block w-2.5 h-2.5 rounded-full" style="background: {{ $ragColor }};"></span>
                                    </td>
                                    <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $deal->expected_registration ? $deal->expected_registration->format('d M Y') : '—' }}</td>
                                    <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($deal->purchase_price, 0) }}</td>
                                    <td class="px-4 py-2 text-right" onclick="event.stopPropagation()">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('deals-v2.show', $deal) }}" class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="View">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                                            </a>
                                            @permission('deals_v2.edit')
                                            <a href="{{ route('deals-v2.edit', $deal) }}" class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="Edit">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                                            </a>
                                            <a href="{{ route('deals-v2.settlement.index', $deal) }}" class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="Settlement">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75V18m15-8.25v.75m-21-.75v.75M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0h18"/></svg>
                                            </a>
                                            @endpermission
                                            @permission('deals_v2.archive')
                                            <form method="POST" action="{{ route('deals-v2.destroy', $deal) }}" onsubmit="return confirm('Archive this deal?')" class="inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1 rounded hover:bg-red-500/20 transition-colors" style="color: var(--text-muted);" title="Archive">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                                </button>
                                            </form>
                                            @endpermission
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center" style="color: var(--text-muted);">
                                        No deals yet. <a href="{{ route('deals-v2.create') }}" class="underline" style="color: #2dd4bf;">Create your first deal</a>.
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
    </div>
</x-app-layout>
