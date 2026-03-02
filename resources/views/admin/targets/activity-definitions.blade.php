@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Activity Definitions (V2)</h2>
        <div class="text-sm text-white/60">Define activities, weights, and scoring modes.</div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3">{{ $errors->first() }}</div>
    @endif

    <div class="ds-status-card p-5 space-y-6">

        {{-- Add Activity --}}
        <form method="POST" action="{{ route('admin.targets.activity.definitions.save') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
            @csrf

            <div class="sm:col-span-2">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input name="name" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Appointments" />
            </div>

            <div class="sm:col-span-1">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Weight</label>
                <input name="weight" type="number" step="0.01" min="0" value="1" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right" />
            </div>

            <div class="sm:col-span-1">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Order</label>
                <input name="sort_order" type="number" min="0" value="100" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right" />
            </div>

            <div class="sm:col-span-1">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Scoring</label>
                <select name="scoring_mode" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="count" selected>Per action</option>
                    <option value="once">Once (tick)</option>
                </select>
            </div>

            <div class="sm:col-span-1 flex items-center gap-2">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input type="checkbox" name="is_enabled" value="1" checked class="rounded border-slate-300 dark:border-slate-700">
                    Active
                </label>
                <button class="corex-btn-primary text-sm ml-auto">Add</button>
            </div>
        </form>

        {{-- Existing Definitions --}}
        <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
            <table class="min-w-full text-sm ds-table">
                <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
                    <tr>
                        <th class="text-left p-3">Name</th>
                        <th class="text-left p-3 w-28">Weight</th>
                        <th class="text-left p-3 w-28">Order</th>
                        <th class="text-left p-3 w-36">Scoring</th>
                        <th class="text-left p-3 w-28">Enabled</th>
                        <th class="text-left p-3 w-24"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($definitions as $d)
                        <tr>
                            <form method="POST" action="{{ route('admin.targets.activity.definitions.save') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $d->id }}">

                                <td class="p-3">
                                    <input name="name"
                                           value="{{ $d->name }}"
                                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm">
                                </td>

                                <td class="p-3">
                                    <input name="weight"
                                           type="number"
                                           step="0.01"
                                           min="0"
                                           value="{{ number_format((float)$d->weight, 2, '.', '') }}"
                                           class="w-24 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm text-right">
                                </td>

                                <td class="p-3">
                                    <input name="sort_order"
                                           type="number"
                                           min="0"
                                           value="{{ (int)$d->sort_order }}"
                                           class="w-24 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm text-right">
                                </td>

                                <td class="p-3">
                                    <select name="scoring_mode" class="w-32 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-1 text-sm">
                                        @php($sm = (string)($d->scoring_mode ?? 'count'))
                                        <option value="count" @selected($sm === 'count')>Per action</option>
                                        <option value="once" @selected($sm === 'once')>Once (tick)</option>
                                    </select>
                                </td>

                                <td class="p-3">
                                    <input type="checkbox"
                                           name="is_enabled"
                                           value="1"
                                           @checked((int)$d->is_enabled === 1)
                                           class="rounded border-slate-300 dark:border-slate-700">
                                </td>

                                <td class="p-3 text-right">
                                    <button class="corex-btn-primary text-xs">Save</button>
                                </td>
                            </form>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-4 text-sm text-slate-500 dark:text-slate-400">
                                No activity definitions yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>
</div>
@endsection
