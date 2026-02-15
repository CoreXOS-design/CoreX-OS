<x-app-layout>
    <div class="max-w-6xl mx-auto p-6">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold mb-2">Company Cashflow Dashboard (Legacy)</h1>
                <p class="text-sm text-gray-600">Worksheet-based view for selected period.</p>
            </div>
            <a href="{{ route('admin.dashboard') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10 hover:bg-white/15 text-white border border-white/10">
                <span>Back to Control Centre</span>
                <span class="text-white/50">→</span>
            </a>
        </div>

        @if (session('status'))
            <div class="mb-4 p-3 rounded bg-green-100 text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <!-- Period + Expenses -->
        <form method="GET" action="{{ route('admin.dashboard') }}" class="bg-white shadow rounded p-5 mb-6">
            <input type="hidden" name="view" value="cashflow" />
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium">Period (YYYY-MM)</label>
                    <input name="period"
                           value="{{ $period }}"
                           class="mt-1 w-full border rounded p-2"
                           placeholder="2026-01" />
                </div>

                <div class="md:col-span-2 text-sm text-gray-600">
                    Change the period and press Enter to reload data for that month.
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('admin.expenses.save') }}" class="bg-white shadow rounded p-5 mb-8">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}">
            <input type="hidden" name="view" value="cashflow" />

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium">Monthly Company Expenses (R)</label>
                    <input type="number" step="0.01" name="monthly_expenses"
                           value="{{ old('monthly_expenses', $expense->monthly_expenses) }}"
                           class="mt-1 w-full border rounded p-2" />
                </div>

                <div>
                    <button class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded font-semibold shadow border">
                        💾 Save Expenses
                    </button>
                </div>

                <div class="text-sm text-gray-600">
                    Applies to selected period.
                </div>
            </div>
        </form>

        <!-- Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Agents Included</div>
                <div class="text-xl font-bold">{{ $totals['agents_count'] }}</div>
            </div>

            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Agency Gross Commission</div>
                <div class="text-xl font-bold">R {{ number_format($totals['agency_gross_commission'], 2) }}</div>
            </div>

            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Company Income (After Splits)</div>
                <div class="text-xl font-bold">R {{ number_format($totals['company_income'], 2) }}</div>
            </div>

            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Agent Income Total</div>
                <div class="text-xl font-bold">R {{ number_format($totals['agent_income'], 2) }}</div>
            </div>

            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Monthly Expenses</div>
                <div class="text-xl font-bold">R {{ number_format($totals['monthly_expenses'], 2) }}</div>
            </div>

            <div class="bg-white shadow rounded p-4">
                <div class="text-xs text-gray-500">Cashflow</div>
                <div class="text-xl font-bold {{ $totals['cashflow'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                    R {{ number_format($totals['cashflow'], 2) }}
                </div>
            </div>
        </div>

        <!-- Agent breakdown (safe view) -->
        <div class="bg-white shadow rounded p-5">
            <h2 class="text-lg font-semibold mb-3">Agent Contributions (Period {{ $period }})</h2>

            @if(empty($agentRows))
                <p class="text-gray-600">No agent worksheets captured for this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left p-2">Agent</th>
                                <th class="text-left p-2">Sales Needed</th>
                                <th class="text-left p-2">Commission / Sale</th>
                                <th class="text-left p-2">Split %</th>
                                <th class="text-left p-2">Company Income</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($agentRows as $r)
                                <tr class="border-b">
                                    <td class="p-2 font-medium">{{ $r['name'] }}</td>
                                    <td class="p-2">{{ number_format($r['sales_needed_per_month'], 2) }}</td>
                                    <td class="p-2">R {{ number_format($r['commission_per_sale'], 2) }}</td>
                                    <td class="p-2">{{ number_format($r['agent_split_percent'], 1) }}%</td>
                                    <td class="p-2 font-semibold">R {{ number_format($r['company_income'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
