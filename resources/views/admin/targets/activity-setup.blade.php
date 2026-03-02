@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Activity Setup</h2>
                <div class="text-sm text-white/60">
                    Choose which activity fields appear on the Daily Capture table, and in what order.
                    @if($branchId)
                        Editing branch override.
                    @else
                        Editing global defaults.
                    @endif
                </div>
            </div>

            <a href="{{ route('admin.targets') }}" class="corex-btn-outline text-sm">&larr; Back to Targets</a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3">{{ $errors->first() }}</div>
    @endif

    @if($isAdmin)
        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-3">Branch Override</h3>

            <form method="GET" action="{{ route('admin.targets.activity.setup') }}" class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 px-3 py-2 rounded-full border text-sm font-semibold cursor-pointer {{ !$branchId ? 'bg-[#0b2a4a] text-white border-[#0b2a4a]' : 'bg-white hover:bg-slate-50 border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100' }}">
                        <input type="radio" name="branch_id" value="" class="mr-1" {{ !$branchId ? 'checked' : '' }}>
                        Global Defaults
                    </label>

                    @foreach($branches as $b)
                        @php $active = ((int)$branchId === (int)$b->id); @endphp
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-full border text-sm font-semibold cursor-pointer {{ $active ? 'bg-[#0b2a4a] text-white border-[#0b2a4a]' : 'bg-white hover:bg-slate-50 border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100' }}">
                            <input type="radio" name="branch_id" value="{{ $b->id }}" class="mr-1" {{ $active ? 'checked' : '' }}>
                            {{ $b->name }}
                        </label>
                    @endforeach

                    <button class="corex-btn-primary text-sm ml-2">Load</button>
                </div>
            </form>
        </div>
    @endif

    @if($isAdmin && empty($branchId))
        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-3">Add New Activity Column</h3>
            <div class="text-sm text-slate-600 dark:text-slate-300 mb-3">
                Note: The <span class="font-mono">key</span> must already exist as a real column on <span class="font-mono">daily_activities</span>.
            </div>

            <form method="POST" action="{{ route('admin.targets.activity.columns.create') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-2 items-end">
                @csrf
                <div class="sm:col-span-2">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Key (snake_case)</label>
                    <input name="key" value="{{ old('key') }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="e.g. door_knocks" />
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Label</label>
                    <input name="label" value="{{ old('label') }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Door knocks" />
                </div>
                <div class="sm:col-span-1">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Group</label>
                    <input name="group" value="{{ old('group') }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Prospecting" />
                </div>
                <div class="sm:col-span-1">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Weight</label>
                    <input name="points_weight" type="number" step="0.01" min="0" value="{{ old('points_weight', '1.00') }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right" />
                </div>

                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" name="default_enabled" value="1" checked class="rounded border-slate-300 dark:border-slate-700" />
                        Enabled by default
                    </label>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                    <input name="sort_order" type="number" min="0" value="{{ old('sort_order', 100) }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right" />
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Input type</label>
                    <input name="input_type" value="{{ old('input_type', 'number') }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" />
                </div>

                <div class="sm:col-span-6">
                    <button class="corex-btn-primary text-sm">Add Column</button>
                </div>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.targets.activity.setup.save') }}">
        @csrf
        @if($branchId)
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
        @endif

        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h3 class="ds-section-header">Activity Columns</h3>
                <button class="corex-btn-primary text-sm">Save Activity Columns</button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                            <th class="text-left p-3 w-20">On</th>
                            <th class="text-left p-3">Label</th>
                            <th class="text-left p-3">Group</th>
                            <th class="text-left p-3 w-24">Order</th>
                            <th class="text-left p-3 w-28">Weight</th>
                            <th class="text-left p-3">Key</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($columns as $c)
                            @php
                                $key = (string)$c->key;

                                $enabled = (int)$c->default_enabled;
                                $order = (int)$c->sort_order;
                                $weight = (float)($c->points_weight ?? 1);
                                $weightOverride = null;

                                if($branchId && isset($branchOverrides[$key])) {
                                    $enabled = (int)($branchOverrides[$key]['is_enabled'] ?? $enabled);
                                    $bo = $branchOverrides[$key]['sort_order'] ?? null;
                                    if($bo !== null) $order = (int)$bo;
                                    $weightOverride = $branchOverrides[$key]['points_weight'] ?? null;
                                }
                            @endphp

                            <tr class="border-b hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                                <td class="p-3">
                                    <input type="checkbox" name="cols[{{ $key }}][enabled]" value="1" @checked($enabled === 1) class="rounded border-slate-300 dark:border-slate-700" />
                                </td>

                                <td class="p-3">
                                    @if(!$branchId)
                                        <input type="text"
                                               class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                                               name="cols[{{ $key }}][label]"
                                               value="{{ old('cols.'.$key.'.label', $c->label) }}">
                                    @else
                                        <div class="text-slate-900 dark:text-slate-100">{{ $c->label }}</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">Label is global</div>
                                    @endif
                                </td>

                                <td class="p-3">
                                    @if(!$branchId)
                                        <input type="text"
                                               class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                                               name="cols[{{ $key }}][group]"
                                               value="{{ old('cols.'.$key.'.group', $c->group) }}">
                                    @else
                                        <div class="text-slate-900 dark:text-slate-100">{{ $c->group ?? '-' }}</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">Group is global</div>
                                    @endif
                                </td>

                                <td class="p-3">
                                    <input type="number" min="0"
                                           class="w-24 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right"
                                           name="cols[{{ $key }}][order]"
                                           value="{{ old('cols.'.$key.'.order', $order) }}">
                                </td>

                                <td class="p-3">
                                    @if(!$branchId)
                                        <input type="number" step="0.01" min="0"
                                               class="w-28 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right"
                                               name="cols[{{ $key }}][weight]"
                                               value="{{ old('cols.'.$key.'.weight', number_format($weight, 2, '.', '')) }}">
                                    @else
                                        @php $shown = ($weightOverride === null) ? '' : number_format((float)$weightOverride, 2, '.', ''); @endphp
                                        <input type="number" step="0.01" min="0"
                                               class="w-28 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm text-right"
                                               name="cols[{{ $key }}][weight]"
                                               placeholder="inherit ({{ number_format($weight, 2, '.', '') }})"
                                               value="{{ old('cols.'.$key.'.weight', $shown) }}">
                                    @endif
                                </td>

                                <td class="p-3 font-mono text-xs text-slate-500 dark:text-slate-400">
                                    {{ $key }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </form>

</div>
@endsection
