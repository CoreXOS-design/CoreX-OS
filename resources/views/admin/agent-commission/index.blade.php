<x-app-layout>
    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-2xl font-bold mb-2">Agent Commission</h1>
        <p class="text-sm mb-6" style="color:var(--text-secondary)">
            Admin-only. Allocations come from Deal Register. Gross/Company uses the agent’s Worksheet split for the same period.
        </p>

        <!-- Period selector -->
        <form method="GET" action="{{ route('admin.agent-commission') }}" class="shadow rounded p-5 mb-6" style="background:var(--surface)">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium" style="color:var(--text-primary)">Period (YYYY-MM)</label>
                    <input name="period"
                           value="{{ $period }}"
                           class="mt-1 w-full border rounded p-2"
                           style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                           placeholder="2026-01" />
                </div>

                <div class="md:col-span-2 text-sm" style="color:var(--text-secondary)">
                    Change the period and press Enter.
                </div>
            </div>
        </form>

        <!-- Totals -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="shadow rounded p-4" style="background:var(--surface)">
                <div class="text-xs" style="color:var(--text-muted)">Total Allocated (Deals)</div>
                <div class="text-xl font-bold" style="color:var(--text-primary)">R {{ number_format((float)$totals['allocated'], 2) }}</div>
            </div>
            <div class="shadow rounded p-4" style="background:var(--surface)">
                <div class="text-xs" style="color:var(--text-muted)">Agent Gross (After Split)</div>
                <div class="text-xl font-bold" style="color:var(--text-primary)">R {{ number_format((float)$totals['agent_gross'], 2) }}</div>
            </div>
            <div class="shadow rounded p-4" style="background:var(--surface)">
                <div class="text-xs" style="color:var(--text-muted)">Company Income (Remainder)</div>
                <div class="text-xl font-bold" style="color:var(--text-primary)">R {{ number_format((float)$totals['company'], 2) }}</div>
            </div>
        </div>

        @if(($totals['missing_split_count'] ?? 0) > 0)
            <div class="mb-6 p-3 bg-yellow-100 text-yellow-900 rounded">
                {{ $totals['missing_split_count'] }} agent(s) have deals in this period but no Worksheet captured for the same period.
                Their Gross/Company is left blank for safety.
            </div>
        @endif

        <!-- Results -->
        <div class="shadow rounded p-5" style="background:var(--surface)">
            <h2 class="text-lg font-semibold mb-3" style="color:var(--text-primary)">Breakdown for {{ $period }}</h2>

            @if(empty($rows))
                <p style="color:var(--text-secondary)">No deal allocations found for this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b" style="border-color:var(--border)">
                                <th class="text-left p-2" style="color:var(--text-primary)">Agent</th>
                                <th class="text-left p-2" style="color:var(--text-primary)">Allocated (Deals)</th>
                                <th class="text-left p-2" style="color:var(--text-primary)">Split % (Worksheet)</th>
                                <th class="text-left p-2" style="color:var(--text-primary)">Agent Gross</th>
                                <th class="text-left p-2" style="color:var(--text-primary)">Company</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr class="border-b" style="border-color:var(--border)">
                                    <td class="p-2 font-medium" style="color:var(--text-primary)">{{ $r['name'] }}</td>
                                    <td class="p-2 font-semibold" style="color:var(--text-primary)">R {{ number_format((float)$r['allocated'], 2) }}</td>

                                    @if($r['has_split'])
                                        <td class="p-2" style="color:var(--text-secondary)">{{ number_format((float)$r['split_percent'], 1) }}%</td>
                                        <td class="p-2 font-semibold" style="color:var(--text-primary)">R {{ number_format((float)$r['agent_gross'], 2) }}</td>
                                        <td class="p-2 font-semibold" style="color:var(--text-primary)">R {{ number_format((float)$r['company'], 2) }}</td>
                                    @else
                                        <td class="p-2 italic" style="color:var(--text-faint)">Missing</td>
                                        <td class="p-2 italic" style="color:var(--text-faint)">—</td>
                                        <td class="p-2 italic" style="color:var(--text-faint)">—</td>
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
