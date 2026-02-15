<x-app-layout>
    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-2xl font-bold mb-2">BM: Market Avg Sale Price (per agent)</h1>
        <p class="text-sm text-gray-600 mb-6">Set the market average sale price per agent for a period. This feeds the agent worksheet calculations.</p>

        @if (session('status'))
            <div class="mb-4 p-3 rounded bg-green-100 text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="GET" action="{{ route('bm.worksheet.market') }}" class="mb-4 flex gap-3 items-end">
            <div>
                <label class="block text-sm font-medium">Period (YYYY-MM)</label>
                <input name="period" value="{{ $period }}" class="mt-1 border rounded p-2" />
            </div>
            <button class="bg-gray-800 text-white px-4 py-2 rounded">Load</button>
        </form>

        @php
            $ma = $marketAverages ?? [];
            $aw = $avgWindow ?? 'period';
            $sf = $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true];
        @endphp

        <!-- Deal Register Market Averages -->
        <div class="bg-white shadow rounded p-5 mb-6 border">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <div class="text-lg font-semibold">Deal Register Market Averages</div>
                    <div class="text-xs text-gray-600 mt-1">
                        Uses Deal Register deals for your branch. Window + stage filters apply.
                        @if(!empty($dateFrom) && !empty($dateTo))
                            <span class="ml-2"><b>Window:</b> {{ $dateFrom }} → {{ $dateTo }}</span>
                        @endif
                    </div>
                </div>

                <form method="GET" action="{{ route('bm.worksheet.market') }}" class="flex flex-wrap gap-3 items-end">
                    <input type="hidden" name="period" value="{{ $period }}" />

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Window</label>
                        <select name="avg_window" class="mt-1 border rounded p-2 text-sm">
                            <option value="period" {{ $aw==='period'?'selected':'' }}>This month</option>
                            <option value="3m" {{ $aw==='3m'?'selected':'' }}>Last 3 months</option>
                            <option value="6m" {{ $aw==='6m'?'selected':'' }}>Last 6 months</option>
                            <option value="all" {{ $aw==='all'?'selected':'' }}>All time</option>
                        </select>
                    </div>

                    <div class="flex gap-3">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="st_pending" value="1" {{ !empty($sf['pending'])?'checked':'' }}>
                            Pending
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="st_granted" value="1" {{ !empty($sf['granted'])?'checked':'' }}>
                            Granted
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="st_registered" value="1" {{ !empty($sf['registered'])?'checked':'' }}>
                            Registered
                        </label>
                    </div>

                    <button class="bg-gray-800 text-white px-4 py-2 rounded text-sm">Apply</button>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5">
                <div class="border rounded p-3">
                    <div class="text-xs text-gray-600">Deals counted</div>
                    <div class="text-xl font-bold">{{ (int)($ma['deals_count'] ?? 0) }}</div>
                </div>

                <div class="border rounded p-3">
                    <div class="text-xs text-gray-600">Avg Sale Price (Incl VAT)</div>
                    <div class="text-xl font-bold">R {{ number_format((float)($ma['avg_sale_price_inc_vat'] ?? 0), 2) }}</div>
                    <div class="text-xs text-gray-600 mt-1">Ex VAT: R {{ number_format((float)($ma['avg_sale_price_ex_vat'] ?? 0), 2) }}</div>
                </div>

                <div class="border rounded p-3">
                    <div class="text-xs text-gray-600">Effective Comm % (Ex VAT)</div>
                    <div class="text-xl font-bold">{{ number_format((float)($ma['effective_commission_percent_ex_vat'] ?? 0), 2) }}%</div>
                </div>
            </div>
        </div>


        <form method="POST" action="{{ route('bm.worksheet.market.save') }}" class="bg-white shadow rounded p-5">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}" />

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left p-2">Agent</th>
                            <th class="text-left p-2">Email</th>
                            <th class="text-left p-2">Avg Sale Price (Market / BM)</th>
                            <th class="text-left p-2">Current stored</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($agents as $a)
                            @php
                                $w = $worksheets->get($a->id);
                                $cur = $w->avg_sale_price_admin ?? null;
                            @endphp
                            <tr class="border-b">
                                <td class="p-2">{{ $a->name }}</td>
                                <td class="p-2">{{ $a->email }}</td>
                                <td class="p-2">
                                    <input type="number" step="0.01" name="avg[{{ $a->id }}]" value="{{ old('avg.'.$a->id, $cur) }}"
                                           class="w-56 border rounded p-2" placeholder="e.g. 1200000" />
                                </td>
                                <td class="p-2 text-gray-600">
                                    {{ $cur === null ? 'NULL' : ('R ' . number_format((float)$cur, 2)) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <button class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded font-bold">
                    Save Market Avg Prices
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
