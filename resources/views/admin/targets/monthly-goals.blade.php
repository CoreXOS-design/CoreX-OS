@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:var(--brand-default)" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Monthly Goals</h2>
                <div class="text-sm text-white/60">Company &amp; branch monthly targets.</div>
            </div>

            <form method="GET" action="{{ route('admin.monthly-goals') }}" class="flex items-center gap-2">
                <input type="month" name="period" value="{{ $period }}"
                       class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5">

                @if($isAdmin)
                    <select name="branch_id" class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                        <option value="">Company scope</option>
                        @foreach($branchNames as $id => $name)
                            <option value="{{ $id }}" @selected((int)$branchId === (int)$id)>{{ $name }}</option>
                        @endforeach
                    </select>
                @endif

                <button class="corex-btn-primary text-sm">Load</button>
            </form>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3">{{ implode(', ', $errors->all()) }}</div>
    @endif

    @if($isAdmin)
        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-4">Company Monthly Goal</h3>

            <form method="POST" action="{{ route('admin.monthly-goals.save') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="scope" value="company">
                <input type="hidden" name="period" value="{{ $period }}">

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Listings</label>
                        <input type="number" name="listings_target" value="{{ $companyGoal->listings_target ?? 0 }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Deals</label>
                        <input type="number" name="deals_target" value="{{ $companyGoal->deals_target ?? 0 }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Value</label>
                        <input type="number" step="0.01" name="value_target" value="{{ $companyGoal->value_target ?? 0 }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                    </div>
                </div>

                <div class="flex justify-end">
                    <button class="corex-btn-primary text-sm">Save Company Goal</button>
                </div>
            </form>
        </div>
    @endif

    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-4">Branch Monthly Goal</h3>

        <form method="POST" action="{{ route('admin.monthly-goals.save') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="scope" value="branch">
            <input type="hidden" name="period" value="{{ $period }}">

            @if($isAdmin)
                <div>
                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Branch</label>
                    <select name="branch_id" required
                            class="w-full sm:w-64 rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                        <option value="">-- Select branch --</option>
                        @foreach($branchNames as $id => $name)
                            <option value="{{ $id }}" @selected((int)$branchId === (int)$id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div class="text-sm" style="color:var(--text-secondary)"><strong>Branch:</strong> {{ $branchNames[$branchId] ?? 'Your branch' }}</div>
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Listings</label>
                    <input type="number" name="listings_target" value="{{ $branchGoal->listings_target ?? 0 }}"
                           class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Deals</label>
                    <input type="number" name="deals_target" value="{{ $branchGoal->deals_target ?? 0 }}"
                           class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Value</label>
                    <input type="number" step="0.01" name="value_target" value="{{ $branchGoal->value_target ?? 0 }}"
                           class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
            </div>

            <div class="flex justify-end">
                <button class="corex-btn-primary text-sm">Save Branch Goal</button>
            </div>
        </form>
    </div>

    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-4">Rollups from Agent Targets ({{ $period }})</h3>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="ds-status-card">
                <div class="ds-label">Agents with targets</div>
                <div class="ds-value-xl">{{ $companyRollup['agents_with_targets'] }}</div>
            </div>
            <div class="ds-status-card">
                <div class="ds-label">Listings target</div>
                <div class="ds-value-lg">{{ $companyRollup['listings_target_sum'] }}</div>
            </div>
            <div class="ds-status-card">
                <div class="ds-label">Deals target</div>
                <div class="ds-value-lg">{{ $companyRollup['deals_target_sum'] }}</div>
            </div>
            <div class="ds-status-card">
                <div class="ds-label">Value target</div>
                <div class="ds-value-lg">{{ $companyRollup['value_target_sum'] }}</div>
            </div>
        </div>

        <h4 class="text-sm font-semibold mb-2" style="color:var(--text-secondary)">By Branch</h4>

        <div class="rounded-2xl border overflow-hidden" style="border-color:var(--border); background:var(--surface)">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr class="border-b" style="color:var(--text-muted); background:var(--surface-2); border-color:var(--border)">
                            <th class="text-left px-4 py-3">Branch</th>
                            <th class="text-right px-4 py-3">Agents</th>
                            <th class="text-right px-4 py-3">Listings</th>
                            <th class="text-right px-4 py-3">Deals</th>
                            <th class="text-right px-4 py-3">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y" style="border-color:var(--border)">
                        @foreach($branchRollups as $b)
                            <tr class="hover:bg-[var(--surface-2)]">
                                <td class="px-4 py-3 font-medium" style="color:var(--text-primary)">{{ $b['branch_name'] }}</td>
                                <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ $b['agents_with_targets'] }}</td>
                                <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ $b['listings_target_sum'] }}</td>
                                <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ $b['deals_target_sum'] }}</td>
                                <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ $b['value_target_sum'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
