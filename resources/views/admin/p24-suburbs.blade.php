@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">P24 Suburb Mappings</h2>
        <div class="text-sm text-white/60">Manage Property24 suburb IDs for the presentation search button.</div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-3">Add New Suburb</h3>

        <form method="POST" action="{{ route('admin.p24-suburbs.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Suburb Name</label>
                <input type="text" name="name" required class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 text-sm w-44" placeholder="e.g. Margate">
            </div>
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">P24 ID</label>
                <input type="number" name="p24_id" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 text-sm w-28" placeholder="e.g. 6348">
            </div>
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Region</label>
                <input type="text" name="region" value="kzn-south-coast" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 text-sm w-36">
            </div>
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Surrounding IDs</label>
                <input type="text" name="surrounding_ids" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 text-sm w-36" placeholder="6357,6358">
            </div>
            <div class="flex items-center gap-2">
                <input type="hidden" name="confirmed" value="0">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input type="checkbox" name="confirmed" value="1" id="new_confirmed" class="rounded border-slate-300 dark:border-slate-700">
                    Confirmed
                </label>
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Add</button>
        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="ds-section-header">Current Suburbs ({{ $suburbs->count() }})</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Slug</th>
                        <th class="text-right px-4 py-3">P24 ID</th>
                        <th class="text-left px-4 py-3">Region</th>
                        <th class="text-left px-4 py-3">Surrounding</th>
                        <th class="text-center px-4 py-3">Confirmed</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($suburbs as $suburb)
                    <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30" id="row-{{ $suburb->id }}">
                        <form method="POST" action="{{ route('admin.p24-suburbs.update', $suburb) }}">
                            @csrf
                            @method('PUT')
                            <td class="px-4 py-3">
                                <input type="text" name="name" value="{{ $suburb->name }}" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm w-full">
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ $suburb->slug }}</td>
                            <td class="px-4 py-3">
                                <input type="number" name="p24_id" value="{{ $suburb->p24_id }}" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm w-20 text-right">
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" name="region" value="{{ $suburb->region }}" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm w-32">
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" name="surrounding_ids" value="{{ is_array($suburb->surrounding_ids) ? implode(',', $suburb->surrounding_ids) : '' }}" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm w-28" placeholder="6357,6358">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <input type="hidden" name="confirmed" value="0">
                                <input type="checkbox" name="confirmed" value="1" {{ $suburb->confirmed ? 'checked' : '' }} class="rounded border-slate-300 dark:border-slate-700">
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
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">No suburbs configured. Add one above or run the seeder.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
