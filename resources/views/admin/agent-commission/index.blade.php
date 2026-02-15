<x-app-layout>
    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-2xl font-bold mb-2">Agent Commission</h1>
        <p class="text-sm text-gray-600 mb-6">
            Admin-only. Allocations come from Deal Register. Gross/Company uses the agent’s Worksheet split for the same period.
        </p>

        <!-- Period selector -->
        <form method="GET" action="{{ route('admin.agent-commission') }}" class="bg-white shadow rounded p-5 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium">Period (YYYY-MM)</label>
                    <input name="period"
                           value="{{ $period }}"
                           class="mt-1 w-full border rounded p-2"
                           placeholder="2026-01" />
                </div>

                <div class="md:col-span-2 text-sm text-gray-600">
                    Change the period and press Enter.
                </div>
            </div>
        </form>

        <!-- Totals -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Total Allocated (Deals)</div>
                <div class="text-xl font-bold">R {{ number_format((float)$totals['allocated'], 2) }}</div>
            </div>
            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Agent Gross (After Split)</div>
                <div class="text-xl font-bold">R {{ number_format((float)$totals['agent_gross'], 2) }}</div>
            </div>
            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Company Income (Remainder)</div>
                <div class="text-xl font-bold">R {{ number_format((float)$totals['company'], 2) }}</div>
            </div>
        </div>

        @if(($totals['missing_split_count'] ?? 0) > 0)
            <div class="mb-6 p-3 bg-yellow-100 text-yellow-900 rounded">
                {{ $totals['missing_split_count'] }} agent(s) have deals in this period but no Worksheet captured for the same period.
                Their Gross/Company is left blank for safety.
            </div>
        @endif

        <!-- Results -->
        <div class="bg-white shadow rounded p-5">
            <h2 class="text-lg font-semibold mb-3">Breakdown for {{ $period }}</h2>

            @if(empty($rows))
                <p class="text-gray-600">No deal allocations found for this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left p-2">Agent</th>
                                <th class="text-left p-2">Allocated (Deals)</th>
                                <th class="text-left p-2">Split % (Worksheet)</th>
                                <th class="text-left p-2">Agent Gross</th>
                                <th class="text-left p-2">Company</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr class="border-b">
                                    <td class="p-2 font-medium">{{ $r['name'] }}</td>
                                    <td class="p-2 font-semibold">R {{ number_format((float)$r['allocated'], 2) }}</td>

                                    @if($r['has_split'])
                                        <td class="p-2">{{ number_format((float)$r['split_percent'], 1) }}%</td>
                                        <td class="p-2 font-semibold">R {{ number_format((float)$r['agent_gross'], 2) }}</td>
                                        <td class="p-2 font-semibold">R {{ number_format((float)$r['company'], 2) }}</td>
                                    @else
                                        <td class="p-2 text-gray-400 italic">Missing</td>
                                        <td class="p-2 text-gray-400 italic">—</td>
                                        <td class="p-2 text-gray-400 italic">—</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
