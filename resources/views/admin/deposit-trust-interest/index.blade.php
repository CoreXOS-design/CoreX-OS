{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
    <div x-data="{
        adding: false,
        editingId: null,
        editDate: '',
        editFunds: '',
        editInterest: '',
        startEdit(id, date, funds, interest) {
            this.editingId = id;
            this.editDate = date;
            this.editFunds = funds;
            this.editInterest = interest;
        },
        cancelEdit() {
            this.editingId = null;
        }
    }" class="w-full space-y-5">

        {{-- Page header (Pattern A — branded) --}}
        <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Trust Interest Register</h1>
                    <p class="text-sm text-white/60">{{ number_format($records->total()) }} {{ Str::plural('record', $records->total()) }} on file.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button"
                            @click="adding = !adding; editingId = null"
                            class="corex-btn-primary"
                            x-text="adding ? 'Cancel' : '+ Add Month'">+ Add Month</button>
                </div>
            </div>
        </div>

        {{-- Flash --}}
        @if(session('status'))
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <div class="flex-1">{{ session('status') }}</div>
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.007v.008H12v-.008ZM12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z" />
                </svg>
                <div class="flex-1 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Table --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total Invested Funds</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Interest Earned</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Add new row --}}
                        <tr x-show="adding" x-cloak style="background: var(--surface-2); border-top: 1px solid var(--border);">
                            <td colspan="4" class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.deposit-trust-interest.store') }}" class="flex flex-col md:flex-row md:items-end gap-3">
                                    @csrf
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Date</label>
                                        <input type="date" name="interest_date" required
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Total Invested Funds</label>
                                        <input type="number" step="0.01" min="0" name="total_invested_funds" required
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Interest Earned</label>
                                        <input type="number" step="0.01" min="0" name="interest_earned" required
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="submit" class="corex-btn-primary">Save</button>
                                        <button type="button" @click="adding = false" class="corex-btn-outline">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>

                        @forelse($records as $record)
                            {{-- Display row --}}
                            <tr x-show="editingId !== {{ $record->id }}"
                                style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3" style="color: var(--text-primary);">{{ $record->interest_date->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($record->total_invested_funds, 2) }}</td>
                                <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($record->interest_earned, 2) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button"
                                                @click="startEdit({{ $record->id }}, '{{ $record->interest_date->format('Y-m-d') }}', '{{ $record->total_invested_funds }}', '{{ $record->interest_earned }}')"
                                                class="p-1 rounded-md transition-colors" style="color: var(--text-muted);" title="Edit"
                                                onmouseover="this.style.color='var(--brand-icon)'" onmouseout="this.style.color='var(--text-muted)'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                            </svg>
                                        </button>
                                        <form method="POST" action="{{ route('admin.deposit-trust-interest.destroy', $record) }}"
                                              onsubmit="return confirm('Delete this record ({{ $record->interest_date->format('d M Y') }})? It can be recovered by an admin.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="p-1 rounded-md transition-colors" style="color: var(--text-muted);" title="Delete"
                                                    onmouseover="this.style.color='var(--ds-crimson)'" onmouseout="this.style.color='var(--text-muted)'">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            {{-- Edit row --}}
                            <tr x-show="editingId === {{ $record->id }}" x-cloak
                                style="background: var(--surface-2); border-top: 1px solid var(--border);">
                                <td colspan="4" class="px-4 py-3">
                                    <form method="POST" action="{{ route('admin.deposit-trust-interest.update', $record) }}" class="flex flex-col md:flex-row md:items-end gap-3">
                                        @csrf
                                        @method('PUT')
                                        <div class="flex-1">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Date</label>
                                            <input type="date" name="interest_date" x-model="editDate" required
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Total Invested Funds</label>
                                            <input type="number" step="0.01" min="0" name="total_invested_funds" x-model="editFunds" required
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Interest Earned</label>
                                            <input type="number" step="0.01" min="0" name="interest_earned" x-model="editInterest" required
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="submit" class="corex-btn-primary">Save</button>
                                            <button type="button" @click="cancelEdit()" class="corex-btn-outline">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m16.5 0h.375a1.125 1.125 0 0 0 1.125-1.125V15m-1.5 1.5H3.75m16.5 0v-.375a1.125 1.125 0 0 0-1.125-1.125h-.375M3.75 18.75v-.75A.75.75 0 0 0 3 17.25h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                    </div>
                                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No trust interest records yet</h3>
                                    <p class="text-sm mb-4" style="color: var(--text-muted);">Add the first month to start the register.</p>
                                    <button type="button" @click="adding = true; editingId = null" class="corex-btn-primary">+ Add Month</button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($records->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $records->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
