<x-app-layout>
    <x-slot name="header">
        Targets
    </x-slot>

    <style>
        /* ========== TARGETS_TABLE_UI_2026 ========== */
        .targets-table-wrap { overflow-x:auto; }
        .targets-table { min-width: 1100px; }
        .targets-sticky { position: sticky; left: 0; background: white; z-index: 2; }
        thead .targets-sticky { z-index: 3; background: #f9fafb; }
        .th-vert {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            padding: 10px 6px !important;
            height: 140px;
            vertical-align: bottom;
            text-align: left;
        }
        .cell-num { width: 72px; }
        .cell-num-sm { width: 60px; }
        .cell-num-lg { width: 110px; }
        .muted { color: #6b7280; font-size: 12px; }
          .targets-table thead th { position: sticky; top: 0; z-index: 4; }
          .targets-table tbody tr:hover td { background: #f9fafb; }

    </style>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        {{-- Top selector --}}
        <div class="bg-white shadow rounded-xl p-4 sm:p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                  @if(!empty($isAdmin))
                      <div class="flex items-center gap-2">
                          <a href="{{ route('admin.targets.activity.setup') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50 text-sm font-semibold">
                              ⚙️ Activity Setup
                          </a>
                      </div>
                  @endif
                @if(!$isAgent)
                    <form method="GET" action="{{ route('admin.targets') }}" class="flex flex-col sm:flex-row gap-2 sm:items-end">
                        <div>
                            <label class="text-xs text-gray-500">Period</label>
                            <select name="period" class="border border-gray-300 rounded-lg px-3 py-2">
                                @foreach($periods as $p)
                                    <option value="{{ $p }}" {{ $p === $period ? 'selected' : '' }}>{{ $p }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-lg font-semibold">
                            View
                        </button>
                    </form>
                @else
                    <div>
                        <div class="text-xs text-gray-500">Period</div>
                        <div class="font-semibold">{{ $period }}</div>
                        <div class="muted">Your targets + daily activity capture</div>
                    </div>
                @endif

                <div class="text-xs text-gray-500">
                    @if($isAdmin) Admin scope
                    @elseif($isBranchManager) Branch Manager scope
                    @else Agent scope
                    @endif
                </div>
            </div>
        </div>

        {{-- MONTHLY TARGETS (Admin/BM only) --}}
        @if($canEditTargets)
            <form method="POST" action="{{ route('admin.targets.save') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}"/>

                <div class="bg-white shadow rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                        <div class="font-semibold">Monthly Targets for {{ $period }}</div>
                        <button class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-semibold shadow border">
                            💾 Save Targets
                        </button>
                    </div>

                    <div class="targets-table-wrap">
                        <table class="min-w-full text-sm targets-table">
                            <thead>
        <tr class="border-b text-gray-600 bg-gray-50">
            <th class="text-left p-2 targets-sticky">Agent</th>
            <th class="text-left p-2">Monthly Points Target</th>
        </tr>
    </thead>

                            <tbody>
                                @forelse($users as $u)
                                    @php
                                        $t = $targets[$u->id] ?? null;
                                        $a = $actuals[$u->id] ?? null;

                                        $lt = (int)($t->listings_target ?? 0);
                                        $la = (int)($a->listings_actual ?? 0);
                                        $lv = $la - $lt;

                                        $dt = (int)($t->deals_target ?? 0);
                                        $da = (int)($a->deals_actual ?? 0);
                                        $dv = $da - $dt;

                                        $vt = (float)($t->value_target ?? 0);
                                        $va = (float)($a->value_actual ?? 0);
                                        $vv = $va - $vt;

                                        $branchName = $branchNames[$u->branch_id] ?? '-';
                                    @endphp

                                    <tr class="border-b">
        <td class="p-2 font-semibold targets-sticky">
            {{ $u->name }}
            <div class="muted">Branch: {{ $branchName }}</div>
        </td>

        <td class="p-2">
            <input type="number" min="0"
                   class="border border-gray-300 rounded-lg px-3 py-2 w-40"
                   name="targets[{{ $u->id }}][points_target]"
                   value="{{ old('targets.'.$u->id.'.points_target', (int)($t->points_target ?? 0)) }}">
        </td>
    </tr>
                                @empty
                                    <tr>
                                        <td class="p-4 text-gray-500" colspan="10">No agents found in scope.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @endif

        
</x-app-layout>
