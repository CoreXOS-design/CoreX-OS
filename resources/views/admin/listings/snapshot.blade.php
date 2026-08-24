<x-app-layout>
    <x-slot name="header">
        Listing Snapshot
    </x-slot>

    <style>
        .snapshot-row:hover { background: var(--surface-2); }
    </style>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="shadow rounded-xl p-4 sm:p-5" style="background:var(--surface)">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <form method="GET" action="{{ route('admin.listings.snapshot') }}" class="flex flex-col sm:flex-row gap-2 sm:items-end">
                    <div>
                        <label class="text-xs" style="color:var(--text-muted)">Period</label>
                        <input type="month" name="period" value="{{ $period }}" class="border rounded-lg px-3 py-2" style="border-color:var(--border)" />
                    </div>

                    @if($isAdmin)
                        <div>
                            <label class="text-xs" style="color:var(--text-muted)">Branch</label>
                            <select name="branch_id" class="border rounded-lg px-3 py-2" style="border-color:var(--border)">
                                <option value="0">All</option>
                                @foreach($branchNames as $id => $name)
                                    <option value="{{ $id }}" {{ (int)$branchId === (int)$id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <button class="px-4 py-2 rounded-lg font-semibold" style="background:var(--brand-button); color:#fff">View</button>
                </form>

                <div class="text-xs" style="color:var(--text-muted)">
                    BM captures monthly listing stock + avg price per agent (manual input).
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.listings.snapshot.save') }}" class="shadow rounded-xl overflow-hidden" style="background:var(--surface)">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}" />

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead style="background:var(--surface-2); color:var(--text-secondary)">
                        <tr class="border-b" style="border-color:var(--border)">
                            <th class="text-left p-2">Agent</th>
                            <th class="text-left p-2">Listings</th>
                            <th class="text-left p-2">Avg Listing Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $u)
                            @php $s = $snapshots[$u->id] ?? null; @endphp
                            <tr class="border-b snapshot-row" style="border-color:var(--border)">
                                <td class="p-2 font-semibold">{{ $u->name }}</td>
                                <td class="p-2">
                                    <input type="number" min="0" class="border rounded-lg px-2 py-1 w-28 text-right" style="border-color:var(--border)"
                                           name="rows[{{ $u->id }}][listing_count]"
                                           value="{{ old('rows.'.$u->id.'.listing_count', (int)($s->listing_count ?? 0)) }}">
                                </td>
                                <td class="p-2">
                                    <input type="number" min="0" step="0.01" class="border rounded-lg px-2 py-1 w-40 text-right" style="border-color:var(--border)"
                                           name="rows[{{ $u->id }}][avg_listing_price]"
                                           value="{{ old('rows.'.$u->id.'.avg_listing_price', (float)($s->avg_listing_price ?? 0)) }}">
                                </td>
                            </tr>
                        @empty
                            <tr><td class="p-4" style="color:var(--text-muted)" colspan="3">No agents found in scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t flex items-center justify-end" style="background:var(--surface-2); border-color:var(--border)">
                <button class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-semibold shadow border">💾 Save Snapshot</button>
            </div>
        </form>
    </div>
</x-app-layout>
