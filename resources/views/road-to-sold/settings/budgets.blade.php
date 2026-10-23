@extends('layouts.corex')

{{--
    Road to Sold settings -> Budgets & requirements.
    Spec: .ai/specs/agency-tracker-rehome.md 4.3 (step 4 of the Agency Tracker re-home).
    Replaces the Worksheet budget boxes, the branch-budget box on Branch Performance and Admin Monthly Goals.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    No Alpine on this page: every row is a plain form (inputs use the form="" attribute so the table stays
    valid HTML); the confirm prompts are inline onclick handlers.
--}}

@php
    $q = $filters['q']; $show = $filters['show']; $sortNow = $filters['sort'];
    $keep = array_filter(['q' => $q ?: null, 'show' => $show === 'all' ? null : $show, 'branch_id' => $filters['branchId'] ?: null,
        'sort' => $sortNow === 'name' ? null : $sortNow, 'page' => request('page')]);
    $money = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ' ');
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $period)->format('F Y');
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - budgets &amp; requirements</h1>
        </div>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @unless($canWrite)
        <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            You can look at these figures but not change them.
        </div>
    @endunless

    {{-- Month --}}
    <div class="rounded-md p-4 flex flex-wrap items-center gap-4" style="background:var(--surface); border:1px solid var(--border);">
        <x-period-selector :months="$months" />
        @if($canWrite)
            <form method="POST" action="{{ route($rb . 'budgets.copy-previous') }}" class="ml-auto">
                @csrf <input type="hidden" name="period" value="{{ $period }}">
                @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <button type="submit" class="corex-btn-outline"
                        onclick="return confirm('Give every agent you can see who has no budget for {{ $monthLabel }} the figures they had last month? Anyone who already has a budget is left alone.');"
                        title="For agents with no budget this month: copies last month's figures">Copy last month's budgets to agents with none</button>
            </form>
        @endif
    </div>

    {{-- The agency --}}
    @if($canEditAgency || $agencyDetail['own'] > 0)
    <div class="rounded-md p-4" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);">
        <h3 class="text-sm font-bold mb-2" style="color:var(--text-primary);">What the agency needs for {{ $monthLabel }}</h3>
        <form method="POST" action="{{ route($rb . 'budgets.agency') }}" class="flex flex-wrap items-end gap-3">
            @csrf @method('PUT')
            <input type="hidden" name="period" value="{{ $period }}">
            @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="agency-req">Amount (rand)</label>
                <input id="agency-req" type="number" min="0" step="0.01" name="requirement" value="{{ old('requirement', $agencyDetail['own'] ?: '') }}" placeholder="{{ $agencyDetail['source'] === 'branches' ? number_format($agencyDetail['branchSum'], 2, '.', '') : '0' }}"
                       @disabled(! $canEditAgency) class="w-48 rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
            </div>
            @if($canEditAgency)<button type="submit" class="corex-btn-primary">Save</button>@endif
            @if($agencyDetail['source'] === 'branches')
                <span class="text-sm pb-2" style="color:var(--text-muted);">Branches add up to <strong style="color:var(--text-primary);">{{ $money($agencyDetail['branchSum']) }}</strong></span>
            @endif
        </form>
    </div>
    @endif

    {{-- Branches --}}
    @if($branchRows->count())
    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm" style="color:var(--text-primary);">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                    <th class="px-4 py-2">Branch</th>
                    <th class="px-3 py-2">Needs for {{ $monthLabel }} (rand)</th>
                    <th class="px-3 py-2 text-right" title="Active agents in the branch who count toward the branch split">Agents counted</th>
                    <th class="px-3 py-2 text-right">Each agent</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @foreach($branchRows as $br)
                @php $fid = 'rts-branch-' . $br['branch']->id; @endphp
                <tr style="border-bottom:1px solid var(--border);">
                    <td class="px-4 py-2 font-semibold">{{ $br['branch']->name }}</td>
                    <td class="px-3 py-2">
                        <input type="number" min="0" step="0.01" form="{{ $fid }}" name="requirement" value="{{ $br['requirement'] ?: '' }}" placeholder="0"
                               @disabled(! $br['canEdit']) class="w-44 rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                    </td>
                    <td class="px-3 py-2 text-right">{{ $br['agents'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $br['perAgent'] > 0 ? $money($br['perAgent']) : '-' }}</td>
                    <td class="px-4 py-2">
                        <form id="{{ $fid }}" method="POST" action="{{ route($rb . 'budgets.branch', $br['branch']->id) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="period" value="{{ $period }}">
                            @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            @if($br['canEdit'])<button type="submit" class="corex-btn-primary">Save</button>@endif
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route($rb . 'budgets.index') }}" class="props-filter-panel rounded-md p-4 grid grid-cols-1 sm:grid-cols-5 gap-3 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="period" value="{{ $period }}">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="q">Search</label>
            <input class="list-header-filter {{ (!empty($q)) ? 'is-active' : '' }} w-full" id="q" name="q" value="{{ $q }}" type="search" placeholder="Agent name or email">
        </div>
        @if($branches->count() > 1)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="branch_id">Branch</label>
                <select class="list-header-filter {{ (!empty($filters['branchId'])) ? 'is-active' : '' }} w-full" id="branch_id" name="branch_id">
                    <option value="">All branches</option>
                    @foreach($branches as $bid => $bname)<option value="{{ $bid }}" @selected($filters['branchId'] == $bid)>{{ $bname }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="show">Show</label>
            <select class="list-header-filter {{ ($show !== 'all') ? 'is-active' : '' }} w-full" id="show" name="show">
                <option value="all" @selected($show === 'all')>All agents</option>
                <option value="set" @selected($show === 'set')>With a budget</option>
                <option value="notset" @selected($show === 'notset')>Without a budget</option>
                <option value="archived" @selected($show === 'archived')>Removed budgets</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sort">Sort by</label>
            <select class="list-header-filter w-full" id="sort" name="sort">
                <option value="name" @selected($sortNow === 'name')>Agent name (A-Z)</option>
                <option value="branch" @selected($sortNow === 'branch')>Branch</option>
                <option value="personal" @selected($sortNow === 'personal')>Personal budget, biggest first</option>
                <option value="business" @selected($sortNow === 'business')>Business budget, biggest first</option>
                <option value="total" @selected($sortNow === 'total')>Total, biggest first</option>
            </select>
        </div>
        <div class="sm:col-span-5 flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">Apply</button>
            <a href="{{ route($rb . 'budgets.index', ['period' => $period]) }}" class="corex-btn-outline">Clear</a>
        </div>
    </form>

    {{-- Agents --}}
    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm" style="color:var(--text-primary);">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                    <th class="px-4 py-2">Agent</th>
                    <th class="px-3 py-2">Personal budget (rand)</th>
                    <th class="px-3 py-2">Business budget (rand)</th>
                    <th class="px-3 py-2 text-right">Total</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                @php
                    $u = $row['user']; $fid = 'rts-budget-' . $u->id; $b = $row['row']; $removed = $show === 'archived';
                    $src = $removed ? $row['archived'] : $b;
                @endphp
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2">
                        <div class="font-semibold">{{ $u->name }}</div>
                        <div class="text-xs" style="color:var(--text-muted);">
                            {{ $u->branch?->name }} &middot;
                            @if($removed) removed budget @elseif($b) own budget @elseif($row['carried']) using {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $row['carried']['setForPeriod'])->format('F Y') }}'s figures until you set this month @else not set @endif
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" min="0" step="0.01" form="{{ $fid }}" name="personal_budget" value="{{ $removed ? '' : ($b ? $b->personal_budget : '') }}"
                               placeholder="{{ $removed ? number_format((float) ($src->personal_budget ?? 0), 2, '.', '') : ($row['carried'] ? number_format($row['personal'], 2, '.', '') : '0') }}"
                               @disabled(! $canWrite || $removed) class="w-40 rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" min="0" step="0.01" form="{{ $fid }}" name="business_budget" value="{{ $removed ? '' : ($b ? $b->business_budget : '') }}"
                               placeholder="{{ $removed ? number_format((float) ($src->business_budget ?? 0), 2, '.', '') : ($row['carried'] ? number_format($row['business'], 2, '.', '') : '0') }}"
                               @disabled(! $canWrite || $removed) class="w-40 rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                    </td>
                    <td class="px-3 py-2 text-right font-semibold" @if(! $src && $row['carried']) style="color:var(--text-muted);" @endif>{{ $src ? $money((float) $src->personal_budget + (float) $src->business_budget) : ($row['carried'] ? $money($row['personal'] + $row['business']) : '-') }}</td>
                    <td class="px-4 py-2">
                        <form id="{{ $fid }}" method="POST" action="{{ route($rb . 'budgets.agent', $u->id) }}" class="flex flex-wrap gap-2">
                            @csrf @method('PUT')
                            <input type="hidden" name="period" value="{{ $period }}">
                            @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            @if($removed)
                                @if($canWrite)
                                    <button type="submit" formaction="{{ route($rb . 'budgets.agent.restore', $u->id) }}" name="_method" value="POST" class="corex-btn-primary">Bring back</button>
                                @endif
                            @elseif($canWrite)
                                <button type="submit" class="corex-btn-primary">Save</button>
                                @if($b)
                                    <button type="submit" formaction="{{ route($rb . 'budgets.agent.archive', $u->id) }}" name="_method" value="DELETE"
                                            onclick="return confirm('Remove {{ addslashes($u->name) }}\'s budget for {{ $monthLabel }}? You can bring it back from Removed budgets.');"
                                            class="corex-btn-outline">Remove</button>
                                @endif
                            @endif
                        </form>
                    </td>
                </tr>
                @if(! $removed)
                <tr>
                    <td colspan="5" class="px-4 pb-2">
                        <details>
                            <summary class="text-xs cursor-pointer" style="color:var(--text-muted);">Planning figures</summary>
                            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 mt-2">
                                @foreach($planningKeys as $key => [$label, $hint])
                                    <div>
                                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $fid }}-{{ $key }}" title="{{ $hint }}">{{ $label }}</label>
                                        <input id="{{ $fid }}-{{ $key }}" type="number" min="0" @if($key !== 'avg_sale_price') max="100" @endif step="0.01" form="{{ $fid }}" name="planning[{{ $key }}]"
                                               value="{{ $b?->{$key} }}" placeholder="not set" @disabled(! $canWrite)
                                               class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </td>
                </tr>
                @endif
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                    @if($emptyFiltered) No agents match those filters. <a class="underline" href="{{ route($rb . 'budgets.index', ['period' => $period]) }}">Clear the filters</a>.
                    @else No agents have the "Access Road to Sold" permission yet. Add it in Role Manager, then set their budgets here. @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
@endsection
