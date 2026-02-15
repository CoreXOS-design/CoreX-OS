<x-app-layout>
    <x-slot name="header">
        Activity Setup
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white shadow rounded-xl p-4 sm:p-5 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <div class="font-semibold">Daily Activity Columns</div>
                    <div class="text-sm text-gray-500">
                        Choose which activity fields appear on the Daily Capture table, and in what order.
                        @if($branchId)
                            <span class="font-semibold text-gray-700">Editing branch override.</span>
                        @else
                            <span class="font-semibold text-gray-700">Editing global defaults.</span>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.targets') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50 text-sm font-semibold">
                        ← Back to Targets
                    </a>
                </div>
            </div>

                        @if($isAdmin)
                <form method="GET" action="{{ route('admin.targets.activity.setup') }}" class="space-y-2">
                    <div class="text-xs text-gray-500">Branch override (tick one)</div>

                    <div class="flex flex-wrap gap-2">
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-full border text-sm font-semibold cursor-pointer {{ !$branchId ? 'bg-gray-900 text-white border-gray-900' : 'bg-white hover:bg-gray-50' }}">
                            <input type="radio" name="branch_id" value="" class="mr-1" {{ !$branchId ? 'checked' : '' }}>
                            Global Defaults
                        </label>

                        @foreach($branches as $b)
                            @php $active = ((int)$branchId === (int)$b->id); @endphp
                            <label class="inline-flex items-center gap-2 px-3 py-2 rounded-full border text-sm font-semibold cursor-pointer {{ $active ? 'bg-gray-900 text-white border-gray-900' : 'bg-white hover:bg-gray-50' }}">
                                <input type="radio" name="branch_id" value="{{ $b->id }}" class="mr-1" {{ $active ? 'checked' : '' }}>
                                {{ $b->name }}
                            </label>
                        @endforeach

                        <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-lg font-semibold text-sm ml-2">
                            Load
                        </button>
                    </div>
                </form>
            @endif

            
            @if($isAdmin && empty($branchId))
                <div class="border rounded-xl p-4 bg-gray-50">
                    <div class="font-semibold mb-2">Add New Activity Column</div>
                    <div class="text-sm text-gray-600 mb-3">
                        Note: The <span class="font-mono">key</span> must already exist as a real column on <span class="font-mono">daily_activities</span>.
                    </div>

                    <form method="POST" action="{{ route('admin.targets.activity.columns.create') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-2 items-end">
                        @csrf
                        <div class="sm:col-span-2">
                            <label class="text-xs text-gray-600 font-semibold">Key (snake_case)</label>
                            <input name="key" value="{{ old('key') }}" class="w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="e.g. door_knocks" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs text-gray-600 font-semibold">Label</label>
                            <input name="label" value="{{ old('label') }}" class="w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="Door knocks" />
                        </div>
                        <div class="sm:col-span-1">
                            <label class="text-xs text-gray-600 font-semibold">Group</label>
                            <input name="group" value="{{ old('group') }}" class="w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="Prospecting" />
                        </div>
                        <div class="sm:col-span-1">
                            <label class="text-xs text-gray-600 font-semibold">Weight</label>
                            <input name="points_weight" type="number" step="0.01" min="0" value="{{ old('points_weight', '1.00') }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-right" />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm font-semibold">
                                <input type="checkbox" name="default_enabled" value="1" checked />
                                Enabled by default
                            </label>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs text-gray-600 font-semibold">Sort order</label>
                            <input name="sort_order" type="number" min="0" value="{{ old('sort_order', 100) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-right" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs text-gray-600 font-semibold">Input type</label>
                            <input name="input_type" value="{{ old('input_type', 'number') }}" class="w-full border border-gray-300 rounded-lg px-3 py-2" />
                        </div>

                        <div class="sm:col-span-6">
                            <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-lg font-semibold text-sm">
                                ➕ Add Column
                            </button>
                        </div>
                    </form>
                </div>
            @endif


<form method="POST" action="{{ route('admin.targets.activity.setup.save') }}">
                @csrf
                @if($branchId)
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @endif

                <div class="overflow-x-auto border rounded-xl">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr class="border-b">
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

                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-3">
                                        <input type="checkbox" name="cols[{{ $key }}][enabled]" value="1" @checked($enabled === 1) />
                                    </td>

                                    <td class="p-3">
                                        @if(!$branchId)
                                            <input type="text"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2"
                                                   name="cols[{{ $key }}][label]"
                                                   value="{{ old('cols.'.$key.'.label', $c->label) }}">
                                        @else
                                            <div class="text-gray-800">{{ $c->label }}</div>
                                            <div class="text-xs text-gray-500">Label is global</div>
                                        @endif
                                    </td>

                                    <td class="p-3">
                                        @if(!$branchId)
                                            <input type="text"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2"
                                                   name="cols[{{ $key }}][group]"
                                                   value="{{ old('cols.'.$key.'.group', $c->group) }}">
                                        @else
                                            <div class="text-gray-800">{{ $c->group ?? '-' }}</div>
                                            <div class="text-xs text-gray-500">Group is global</div>
                                        @endif
                                    </td>

                                    <td class="p-3">
                                        <input type="number" min="0"
                                               class="w-24 border border-gray-300 rounded-lg px-3 py-2 text-right"
                                               name="cols[{{ $key }}][order]"
                                               value="{{ old('cols.'.$key.'.order', $order) }}">
                                    </td>

                                    <td class="p-3">
                                        @if(!$branchId)
                                            <input type="number" step="0.01" min="0"
                                                   class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-right"
                                                   name="cols[{{ $key }}][weight]"
                                                   value="{{ old('cols.'.$key.'.weight', number_format($weight, 2, '.', '')) }}">
                                        @else
                                            @php $shown = ($weightOverride === null) ? '' : number_format((float)$weightOverride, 2, '.', ''); @endphp
                                            <input type="number" step="0.01" min="0"
                                                   class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-right"
                                                   name="cols[{{ $key }}][weight]"
                                                   placeholder="inherit ({{ number_format($weight, 2, '.', '') }})"
                                                   value="{{ old('cols.'.$key.'.weight', $shown) }}">
                                        @endif
                                    </td>

                                    <td class="p-3 font-mono text-xs text-gray-600">
                                        {{ $key }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pt-4 flex items-center justify-end">
                    <button class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-semibold shadow border">
                        💾 Save Activity Columns
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
