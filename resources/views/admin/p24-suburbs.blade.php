@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="{
         search: '',
         regionFilter: '',
         confirmedFilter: '',
         get filteredCount() {
             return document.querySelectorAll('#suburbs-table tbody tr.suburb-row:not([style*=\'display: none\'])').length;
         }
     }"
     x-effect="
         document.querySelectorAll('#suburbs-table tbody tr.suburb-row').forEach(row => {
             const name = (row.dataset.name || '').toLowerCase();
             const p24id = (row.dataset.p24id || '');
             const region = (row.dataset.region || '');
             const confirmed = (row.dataset.confirmed || '');
             const s = search.toLowerCase();

             let show = true;
             if (s && !name.includes(s) && !p24id.includes(s)) show = false;
             if (regionFilter && region !== regionFilter) show = false;
             if (confirmedFilter !== '' && confirmed !== confirmedFilter) show = false;

             row.style.display = show ? '' : 'none';
         });
         // Update visible count
         const visCount = document.querySelectorAll('#suburbs-table tbody tr.suburb-row:not([style*=\'display: none\'])').length;
         const countEl = document.getElementById('suburb-count');
         if (countEl) countEl.textContent = 'Showing ' + visCount + ' of {{ $suburbs->count() }}';
     "
>
    <x-list-header
        title="P24 Suburb Mappings"
        :count="$suburbs->count()"
        search-placeholder="Search name or P24 ID..."
        search-model="search"
    >
        <x-slot:filters>
            @php $regions = $suburbs->pluck('region')->filter()->unique()->sort()->values(); @endphp
            <select x-model="regionFilter" class="list-header-filter">
                <option value="">All regions</option>
                @foreach($regions as $r)
                <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>

            <select x-model="confirmedFilter" class="list-header-filter">
                <option value="">All</option>
                <option value="1">Confirmed</option>
                <option value="0">Unconfirmed</option>
            </select>
        </x-slot:filters>
    </x-list-header>

    <span id="suburb-count" class="text-sm text-gray-400"></span>

    {{-- Add New Suburb --}}
    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-3">Add New Suburb</h3>

        <form method="POST" action="{{ route('admin.p24-suburbs.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs text-slate-600 mb-1">Suburb Name</label>
                <input type="text" name="name" required class="rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-1.5 text-sm w-44" placeholder="e.g. Margate">
            </div>
            <div>
                <label class="block text-xs text-slate-600 mb-1">P24 ID</label>
                <input type="number" name="p24_id" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-1.5 text-sm w-28" placeholder="e.g. 6348">
            </div>
            <div>
                <label class="block text-xs text-slate-600 mb-1">Region</label>
                <input type="text" name="region" value="kzn-south-coast" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-1.5 text-sm w-36">
            </div>
            <div>
                <label class="block text-xs text-slate-600 mb-1">Surrounding IDs</label>
                <input type="text" name="surrounding_ids" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-1.5 text-sm w-36" placeholder="6357,6358">
            </div>
            <div class="flex items-center gap-2">
                <input type="hidden" name="confirmed" value="0">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="confirmed" value="1" class="rounded border-slate-300">
                    Confirmed
                </label>
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Add</button>
        </form>
    </div>

    {{-- Suburbs Table --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table id="suburbs-table" class="min-w-full text-sm ds-table">
                <thead>
                    <tr class="border-b text-slate-600 bg-slate-50">
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Slug</th>
                        <th class="text-right px-4 py-3">P24 ID</th>
                        <th class="text-left px-4 py-3">Region</th>
                        <th class="text-left px-4 py-3">Surrounding</th>
                        <th class="text-center px-4 py-3">Confirmed</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($suburbs as $suburb)
                    <tr class="hover:bg-slate-50/80 suburb-row"
                        id="row-{{ $suburb->id }}"
                        data-name="{{ strtolower($suburb->name) }}"
                        data-p24id="{{ $suburb->p24_id }}"
                        data-region="{{ $suburb->region }}"
                        data-confirmed="{{ $suburb->confirmed ? '1' : '0' }}">
                        <form method="POST" action="{{ route('admin.p24-suburbs.update', $suburb) }}">
                            @csrf
                            @method('PUT')
                            <td class="px-4 py-3">
                                <input type="text" name="name" value="{{ $suburb->name }}" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-1 text-sm w-full">
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $suburb->slug }}</td>
                            <td class="px-4 py-3">
                                <input type="number" name="p24_id" value="{{ $suburb->p24_id }}" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-1 text-sm w-20 text-right">
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" name="region" value="{{ $suburb->region }}" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-1 text-sm w-32">
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" name="surrounding_ids" value="{{ is_array($suburb->surrounding_ids) ? implode(',', $suburb->surrounding_ids) : '' }}" class="rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-1 text-sm w-28" placeholder="6357,6358">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <input type="hidden" name="confirmed" value="0">
                                <input type="checkbox" name="confirmed" value="1" {{ $suburb->confirmed ? 'checked' : '' }} class="rounded border-slate-300">
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="submit" class="corex-btn-primary text-xs">Save</button>
                        </form>
                                    <form method="POST" action="{{ route('admin.p24-suburbs.destroy', $suburb) }}" class="inline" onsubmit="return confirm('Delete {{ $suburb->name }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1 rounded-lg text-xs text-rose-600 border border-rose-200 hover:bg-rose-50">Del</button>
                                    </form>
                                </div>
                            </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500">No suburbs configured. Add one above or run the seeder.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
