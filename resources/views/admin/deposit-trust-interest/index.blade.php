<x-app-layout>
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
    }">
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">Trust Interest Register</h1>
                    <span class="text-sm flex-shrink-0" style="color: var(--text-muted);">
                        {{ $records->total() }} records
                    </span>
                </div>
                <button @click="adding = !adding; editingId = null"
                        class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium text-white transition-colors"
                        :class="adding ? 'bg-red-600 hover:bg-red-500' : 'bg-teal-600 hover:bg-teal-500'">
                    <span x-text="adding ? 'Cancel' : '+ Add Month'"></span>
                </button>
            </div>
        </div>

        <div class="p-4 lg:p-6">
            {{-- Flash --}}
            @if(session('status'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            {{-- Table --}}
            <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Total Invested Funds</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Interest Earned</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider w-24" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Add new row --}}
                            <tr x-show="adding" x-cloak style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                <td colspan="4" class="px-4 py-2">
                                    <form method="POST" action="{{ route('admin.deposit-trust-interest.store') }}" class="flex items-end gap-3">
                                        @csrf
                                        <div class="flex-1">
                                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Date</label>
                                            <input type="date" name="interest_date" required
                                                   class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Total Invested Funds</label>
                                            <input type="number" step="0.01" min="0" name="total_invested_funds" required
                                                   class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Interest Earned</label>
                                            <input type="number" step="0.01" min="0" name="interest_earned" required
                                                   class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </div>
                                        <button type="submit" class="px-4 py-1.5 rounded-md bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium">Save</button>
                                    </form>
                                </td>
                            </tr>

                            @forelse($records as $record)
                                {{-- Display row --}}
                                <tr x-show="editingId !== {{ $record->id }}"
                                    class="transition-colors"
                                    style="border-bottom: 1px solid var(--border);"
                                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <td class="px-4 py-2" style="color: var(--text-primary);">{{ $record->interest_date->format('d M Y') }}</td>
                                    <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($record->total_invested_funds, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($record->interest_earned, 2) }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button @click="startEdit({{ $record->id }}, '{{ $record->interest_date->format('Y-m-d') }}', '{{ $record->total_invested_funds }}', '{{ $record->interest_earned }}')"
                                                    class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="Edit">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" action="{{ route('admin.deposit-trust-interest.destroy', $record) }}"
                                                  onsubmit="return confirm('Delete this record ({{ $record->interest_date->format('d M Y') }})? It can be recovered by an admin.')">
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

                                {{-- Edit row --}}
                                <tr x-show="editingId === {{ $record->id }}" x-cloak
                                    style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                    <td colspan="4" class="px-4 py-2">
                                        <form method="POST" action="{{ route('admin.deposit-trust-interest.update', $record) }}" class="flex items-end gap-3">
                                            @csrf
                                            @method('PUT')
                                            <div class="flex-1">
                                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Date</label>
                                                <input type="date" name="interest_date" x-model="editDate" required
                                                       class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>
                                            <div class="flex-1">
                                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Total Invested Funds</label>
                                                <input type="number" step="0.01" min="0" name="total_invested_funds" x-model="editFunds" required
                                                       class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>
                                            <div class="flex-1">
                                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Interest Earned</label>
                                                <input type="number" step="0.01" min="0" name="interest_earned" x-model="editInterest" required
                                                       class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>
                                            <button type="submit" class="p-1.5 rounded hover:bg-teal-500/20 text-teal-400 transition-colors" title="Save">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                            </button>
                                            <button type="button" @click="cancelEdit()" class="p-1.5 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="Cancel">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center" style="color: var(--text-muted);">No trust interest records found.</td>
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
    </div>
</x-app-layout>
