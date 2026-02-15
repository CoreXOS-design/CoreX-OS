<x-app-layout>
    <x-slot name="header">
        Targets Management
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white shadow rounded-xl p-4 sm:p-5">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <form method="GET" action="{{ route('admin.targets.manage') }}" class="flex flex-col sm:flex-row gap-2 sm:items-end">
                    <div>
                        <label class="text-xs text-gray-500">Period</label>
                        <input type="month" name="period" value="{{ $period }}" class="border border-gray-300 rounded-lg px-3 py-2" />
                    </div>

                    @if($isAdmin)
                        <div>
                            <label class="text-xs text-gray-500">Branch</label>
                            <select name="branch_id" class="border border-gray-300 rounded-lg px-3 py-2">
                                <option value="0">All</option>
                                @foreach($branchNames as $id => $name)
                                    <option value="{{ $id }}" {{ (int)$branchId === (int)$id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-lg font-semibold">View</button>
                </form>

                <div class="text-xs text-gray-500">
                    Derived (bottom-up) vs Override (management) vs Effective + Actuals.
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.targets.manage.save') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}" />

            @foreach($byBranch as $bid => $pack)
                @php
                    $bname = $branchNames[$bid] ?? ($bid ? "Branch #$bid" : "Unassigned");
                    $tot = $pack['totals'];
                    $rows = $pack['rows'];
                @endphp

                <div class="bg-white shadow rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                        <div class="font-semibold">{{ $bname }}</div>
                        <div class="text-xs text-gray-600">
                            Effective Deals: <b>{{ (int)$tot['effective_deals'] }}</b> |
                            Actual Deals: <b>{{ (int)$tot['actual_deals'] }}</b>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr class="border-b">
                                    <th class="text-left p-2">Agent</th>
                                    <th class="text-right p-2">Derived Deals</th>
                                    <th class="text-right p-2">Override Deals</th>
                                    <th class="text-right p-2">Effective Deals</th>
                                    <th class="text-right p-2">Actual Deals</th>

                                    <th class="text-right p-2">Derived Value</th>
                                    <th class="text-right p-2">Override Value</th>
                                    <th class="text-right p-2">Effective Value</th>
                                    <th class="text-right p-2">Actual Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $r)
                                    @php $a = $r['agent']; @endphp
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="p-2 font-semibold">{{ $a->name }}</td>

                                        <td class="p-2 text-right">{{ (int)$r['derived_deals'] }}</td>
                                        <td class="p-2 text-right">
                                            <input type="number" min="0" class="border border-gray-300 rounded-lg px-2 py-1 w-24 text-right"
                                                   name="rows[{{ $a->id }}][deals_target]"
                                                   value="{{ old('rows.'.$a->id.'.deals_target', (int)$r['override_deals']) }}">
                                        </td>
                                        <td class="p-2 text-right font-semibold">{{ (int)$r['effective_deals'] }}</td>
                                        <td class="p-2 text-right">{{ (int)$r['actual_deals'] }}</td>

                                        <td class="p-2 text-right">{{ number_format((float)$r['derived_value'], 0) }}</td>
                                        <td class="p-2 text-right">
                                            <input type="number" min="0" step="0.01" class="border border-gray-300 rounded-lg px-2 py-1 w-40 text-right"
                                                   name="rows[{{ $a->id }}][value_target]"
                                                   value="{{ old('rows.'.$a->id.'.value_target', (float)$r['override_value']) }}">
                                        </td>
                                        <td class="p-2 text-right font-semibold">{{ number_format((float)$r['effective_value'], 0) }}</td>
                                        <td class="p-2 text-right">{{ number_format((float)$r['actual_value'], 0) }}</td>
                                    </tr>
                                @endforeach

                                <tr class="bg-gray-50">
                                    <td class="p-2 font-semibold">Totals</td>
                                    <td class="p-2 text-right font-semibold">{{ (int)$tot['derived_deals'] }}</td>
                                    <td class="p-2 text-right font-semibold">{{ (int)$tot['override_deals'] }}</td>
                                    <td class="p-2 text-right font-semibold">{{ (int)$tot['effective_deals'] }}</td>
                                    <td class="p-2 text-right font-semibold">{{ (int)$tot['actual_deals'] }}</td>

                                    <td class="p-2 text-right font-semibold">{{ number_format((float)$tot['derived_value'], 0) }}</td>
                                    <td class="p-2 text-right font-semibold">{{ number_format((float)$tot['override_value'], 0) }}</td>
                                    <td class="p-2 text-right font-semibold">{{ number_format((float)$tot['effective_value'], 0) }}</td>
                                    <td class="p-2 text-right font-semibold">{{ number_format((float)$tot['actual_value'], 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-end">
                        <button class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-semibold shadow border">
                            💾 Save Overrides
                        </button>
                    </div>
                </div>
            @endforeach
        </form>
    </div>
</x-app-layout>
