<x-app-layout>
    <div>
        {{-- Sticky header with search --}}
        <x-list-header
            title="Calculation History"
            :form-action="route('deposit-interest-calculator.history')"
            :paginator="$calculations"
            search-placeholder="Search by property name..."
        >
            <x-slot:actions>
                <a href="{{ route('deposit-interest-calculator.index') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                    + New Calculation
                </a>
            </x-slot:actions>
        </x-list-header>

        <div class="p-4 lg:p-6">
            {{-- Flash --}}
            @if(session('status'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Table --}}
            <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Date Calculated</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Deposit</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Interest</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Grand Total</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">By</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider w-24" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($calculations as $calc)
                                <tr class="transition-colors"
                                    style="border-bottom: 1px solid var(--border);"
                                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <td class="px-4 py-2" style="color: var(--text-muted);">{{ $calc->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-2 font-medium" style="color: var(--text-primary);">{{ $calc->property_name }}</td>
                                    <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($calc->total_deposited, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-mono" style="color: #2dd4bf;">R {{ number_format($calc->total_interest, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-medium" style="color: var(--text-primary);">R {{ number_format($calc->grand_total, 2) }}</td>
                                    <td class="px-4 py-2" style="color: var(--text-muted);">{{ $calc->user->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('deposit-interest-calculator.show', $calc) }}"
                                               class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="View">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                            </a>
                                            <form method="POST" action="{{ route('deposit-interest-calculator.destroy', $calc) }}"
                                                  onsubmit="return confirm('Delete this calculation for {{ addslashes($calc->property_name) }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="p-1 rounded hover:bg-red-500/20 transition-colors" style="color: var(--text-muted);" title="Delete">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center" style="color: var(--text-muted);">
                                        No saved calculations yet. <a href="{{ route('deposit-interest-calculator.index') }}" class="underline" style="color: #2dd4bf;">Run a calculation</a> and save it.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($calculations->hasPages())
                    <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                        {{ $calculations->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
