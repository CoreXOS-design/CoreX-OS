@extends('layouts.corex')

@section('content')
    <style>
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

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">Targets</h2>
                    <div class="text-sm text-white/60">
                        @if($isAdmin) Admin scope
                        @elseif($isBranchManager) Branch Manager scope
                        @else Agent scope
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @if(!empty($isAdmin))
                        <a href="{{ route('admin.targets.activity.setup') }}" class="corex-btn-outline text-sm">Activity Setup</a>
                    @endif

                    @if(!$isAgent)
                        <form method="GET" action="{{ route('admin.targets') }}" class="flex items-center gap-2">
                            <select name="period" class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                                @foreach($periods as $p)
                                    <option value="{{ $p }}" {{ $p === $period ? 'selected' : '' }}>{{ $p }}</option>
                                @endforeach
                            </select>
                            <button class="corex-btn-primary text-sm">View</button>
                        </form>
                    @else
                        <div class="text-white/80 text-sm font-medium">{{ $period }}</div>
                    @endif
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3">{{ $errors->first() }}</div>
        @endif

        {{-- MONTHLY TARGETS (Admin/BM only) --}}
        @if($canEditTargets)
            <form method="POST" action="{{ route('admin.targets.save') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}"/>

                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <h3 class="ds-section-header">Monthly Targets for {{ $period }}</h3>
                        <button class="corex-btn-primary text-sm">Save Targets</button>
                    </div>

                    <div class="targets-table-wrap">
                        <table class="min-w-full text-sm targets-table ds-table">
                            <thead>
        <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
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
                   class="border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-lg px-3 py-2 w-40"
                   name="targets[{{ $u->id }}][points_target]"
                   value="{{ old('targets.'.$u->id.'.points_target', (int)($t->points_target ?? 0)) }}">
        </td>
    </tr>
                                @empty
                                    <tr>
                                        <td class="p-4 text-slate-500 dark:text-slate-400" colspan="10">No agents found in scope.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @endif

    </div>
@endsection
