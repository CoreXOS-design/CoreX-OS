<x-app-layout>
    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-2xl font-bold mb-2">Worksheet Market Inputs (BM)</h1>
        <p class="text-sm text-gray-600 mb-6">Set the planned average sale price per agent for a period. Agents will see this as their “Planned (BM input)”.</p>

        @if (session('status'))
            <div class="mb-4 p-3 rounded bg-green-100 text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @php
  $aw = $avgWindow ?? 'period';
  $sf = $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true];
  $mb = $marketByBranch ?? [];
@endphp

<div class="bg-white shadow rounded p-5 mb-6 border">
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
    <div>
      <div class="text-lg font-semibold">Deal Register Market Averages (per branch)</div>
      <div class="text-xs text-gray-600 mt-1">
        Window + stage filters apply.
        @if(!empty($dateFrom) && !empty($dateTo))
          <span class="ml-2"><b>Window:</b> {{ $dateFrom }} → {{ $dateTo }}</span>
        @endif
      </div>
    </div>

    <form method="GET" class="flex flex-wrap gap-3 items-end">
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
          <input type="checkbox" name="st_pending" value="1" {{ !empty($sf['pending'])?'checked':'' }}> Pending
        </label>
        <label class="text-sm flex items-center gap-2">
          <input type="checkbox" name="st_granted" value="1" {{ !empty($sf['granted'])?'checked':'' }}> Granted
        </label>
        <label class="text-sm flex items-center gap-2">
          <input type="checkbox" name="st_registered" value="1" {{ !empty($sf['registered'])?'checked':'' }}> Registered
        </label>
      </div>

      <button class="bg-gray-900 text-white px-4 py-2 rounded text-sm">Apply</button>
    </form>
  </div>
</div>

<form method="GET" class="mb-4">
            <label class="text-sm font-medium">Period</label>
            <input name="period" value="{{ $period }}" class="ml-2 border rounded p-2" placeholder="2026-01" />
            <button class="ml-2 bg-gray-900 text-white px-4 py-2 rounded">Load</button>
        </form>

        <div class="space-y-8">

    @php
        $agentsByBranch = $agents->groupBy('branch_id');
        $bm = $branchMarket ?? [];
    @endphp

    @foreach($agentsByBranch as $bid => $group)
        @php
            $branchName = $bid ? ($branches[$bid]->name ?? '—') : '—';
            $ma = $bm[(int)$bid] ?? ['deals_count'=>0,'avg_sale_price_inc_vat'=>0,'avg_sale_price_ex_vat'=>0,'effective_commission_percent_ex_vat'=>0];
        @endphp

        <div class="bg-white shadow rounded p-5 border">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
                <div>
                    <div class="text-lg font-semibold text-gray-900">{{ $branchName }}</div>
                    <div class="text-xs text-gray-600 mt-1">
                        Deal Register Market Averages
                        @if(!empty($dateFrom) && !empty($dateTo))
                            <span class="ml-2"><b>Window:</b> {{ $dateFrom }} → {{ $dateTo }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
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

            <form method="POST" action="{{ route('admin.worksheet-market.store') }}">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}"/>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left p-2">Agent</th>
                                <th class="text-left p-2">Email</th>
                                <th class="text-left p-2">Planned Avg Sale Price (R)</th>
                                <th class="text-left p-2">Current stored</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group as $a)
                                @php
                                    $w = $worksheets->get($a->id);
                                    $cur = $w->avg_sale_price_admin ?? null;
                                @endphp
                                <tr class="border-b">
                                    <td class="p-2">
                                        <div class="flex items-center gap-2">
                                            <div class="font-semibold">{{ $a->name }}</div>
                                            @if(($a->role ?? '') === 'branch_manager')
                                                <span class="text-[10px] px-2 py-0.5 rounded bg-indigo-100 text-indigo-800">BM</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-2 text-gray-600">{{ $a->email }}</td>
                                    <td class="p-2">
                                        <input type="number" step="0.01" name="avg[{{ $a->id }}]"
                                               value="{{ old('avg.'.$a->id, $cur) }}"
                                               class="w-56 border rounded p-2"
                                               placeholder="e.g. 1200000" />
                                    </td>
                                    <td class="p-2 text-gray-600">
                                        {{ $cur === null ? 'NULL' : ('R ' . number_format((float)$cur, 2)) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <div class="text-xs text-gray-500">Saves only this branch’s users.</div>
                    <button class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded font-bold">
                        Save {{ $branchName }}
                    </button>
                </div>
            </form>
        </div>
    @endforeach

</div>
    </div>
</x-app-layout>
