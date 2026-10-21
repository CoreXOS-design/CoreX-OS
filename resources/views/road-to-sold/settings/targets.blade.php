@extends('layouts.corex')

{{--
    AT-454 - Road to Sold: weekday targets, agents x weekdays.
    Spec: .ai/specs/road-to-sold.md section 5.3.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    No Alpine on this page: every row is a plain form (inputs use the form="" attribute so the
    table stays valid HTML); the confirm prompts are inline onclick handlers.
--}}

@php
    $q = $filters['q']; $status = $filters['status']; $mode = $filters['mode']; $sortNow = $filters['sort'];
    $keep = array_filter(['q' => $q ?: null, 'status' => $status === 'cleared' ? 'cleared' : null, 'mode' => $mode === 'all' ? null : $mode,
        'branch_id' => $filters['branchId'] ?: null, 'sort' => $sortNow === 'name' ? null : $sortNow, 'page' => request('page')]);
    $mo = $monthly;
    $mkeep = array_filter(['m_q' => $mo['filters']['q'] ?: null, 'm_show' => $mo['filters']['show'] === 'all' ? null : $mo['filters']['show'],
        'm_branch' => $mo['filters']['branchId'] ?: null, 'm_sort' => $mo['filters']['sort'] === 'name' ? null : $mo['filters']['sort'], 'mpage' => request('mpage')]);
    $moLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $mo['period'])->format('F Y');
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $fmtVal = fn ($v) => number_format((float) $v, 0, '.', ' ');
    $diffCell = function ($target, $actual, $isMoney = false) use ($fmtVal) {
        if (! $target) { return 'no target'; }
        $d = $actual - $target;
        $txt = ($d >= 0 ? '+' : '-') . $fmtVal(abs($d));
        return $txt . ' ' . ($d >= 0 ? 'ahead' : 'to go');
    };
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - targets</h1>
        </div>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @unless($canWrite)
        <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            You can look at these numbers but not change them.
        </div>
    @endunless


    {{-- ───────────────────────── Monthly targets ───────────────────────── --}}
    <section id="monthly" class="space-y-4">
        <div>
            <h2 class="text-base font-bold" style="color:var(--text-primary);">Monthly targets</h2>
            <p class="text-sm" style="color:var(--text-muted);">What each agent works towards in a month: points, deals, sales value and new listings. Under each box is what the agent has actually done so far, and how far ahead or behind that is. A box left blank is left as it was; type 0 to set zero.</p>
        </div>

        <div class="rounded-md p-4 flex flex-wrap items-center gap-4" style="background:var(--surface); border:1px solid var(--border);">
            <x-period-selector :months="$mo['months']" />
            @if($canWrite)
                <form method="POST" action="{{ route($rb . 'targets.monthly.apply-default') }}" class="ml-auto">
                    @csrf <input type="hidden" name="period" value="{{ $mo['period'] }}">
                    @foreach($mkeep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <button type="submit" class="corex-btn-outline"
                            onclick="return confirm('Give every agent you can see who has no targets for {{ $moLabel }} the agency default? Anyone who already has targets is left alone.');">Give agents without targets the default</button>
                </form>
            @endif
        </div>

        {{-- Agency default --}}
        <div class="rounded-md p-4" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);">
            <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Agency default monthly targets</h3>
            <p class="text-xs mb-3" style="color:var(--text-muted);">A starting point for agents who have no targets of their own. Nothing changes for anyone until you press "Give agents without targets the default". 0 means you have not set one.</p>
            <form method="POST" action="{{ route($rb . 'targets.monthly.default') }}" class="flex flex-wrap items-end gap-3">
                @csrf @method('PUT')
                <input type="hidden" name="period" value="{{ $mo['period'] }}">
                @foreach($mkeep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                @foreach(['points' => 'Points', 'deals' => 'Deals', 'listings' => 'New listings', 'value' => 'Sales value (rand)'] as $key => $label)
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="mdef-{{ $key }}">{{ $label }}</label>
                        <input id="mdef-{{ $key }}" type="number" min="0" step="{{ $key === 'value' ? '0.01' : '1' }}" name="default_monthly[{{ $key }}]" value="{{ old('default_monthly.' . $key, $mo['default'][$key]) }}"
                               @disabled(! $canEditDefault) class="{{ $key === 'value' ? 'w-44' : 'w-28' }} rounded-md px-2 py-2 text-sm" style="{{ $fieldStyle }}">
                    </div>
                @endforeach
                @if($canEditDefault)<button type="submit" class="corex-btn-primary">Save default</button>@endif
            </form>
        </div>

        {{-- Totals --}}
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-4 py-2">Added up for {{ $moLabel }}</th>
                        <th class="px-3 py-2 text-right">Agents with targets</th>
                        <th class="px-3 py-2 text-right">Points</th><th class="px-3 py-2 text-right">Deals</th>
                        <th class="px-3 py-2 text-right">New listings</th><th class="px-3 py-2 text-right">Sales value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mo['totalsByBranch'] as $bid => $t)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-4 py-2">{{ $branches[$bid] ?? 'No branch' }}</td>
                            <td class="px-3 py-2 text-right">{{ $t['withTargets'] }} of {{ $t['agents'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmtVal($t['points']) }}</td><td class="px-3 py-2 text-right">{{ $fmtVal($t['deals']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmtVal($t['listings']) }}</td><td class="px-3 py-2 text-right">R {{ $fmtVal($t['value']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold" style="background:var(--surface-2);">
                        <td class="px-4 py-2">Everyone you can see</td>
                        <td class="px-3 py-2 text-right">{{ $mo['totalAll']['withTargets'] }} of {{ $mo['totalAll']['agents'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmtVal($mo['totalAll']['points']) }}</td><td class="px-3 py-2 text-right">{{ $fmtVal($mo['totalAll']['deals']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmtVal($mo['totalAll']['listings']) }}</td><td class="px-3 py-2 text-right">R {{ $fmtVal($mo['totalAll']['value']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route($rb . 'targets.index') }}#monthly" class="props-filter-panel rounded-md p-4 grid grid-cols-1 sm:grid-cols-5 gap-3 items-end"
              style="background:var(--surface); border:1px solid var(--border);">
            <input type="hidden" name="period" value="{{ $mo['period'] }}">
            @foreach(array_diff_key(array_filter(request()->only(['q', 'status', 'mode', 'branch_id', 'sort', 'page'])), []) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="m_q">Search</label>
                <input class="list-header-filter {{ (!empty($mo['filters']['q'])) ? 'is-active' : '' }} w-full" id="m_q" name="m_q" value="{{ $mo['filters']['q'] }}" type="search" placeholder="Agent name or email">
            </div>
            @if($branches->count() > 1)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="m_branch">Branch</label>
                    <select class="list-header-filter {{ (!empty($mo['filters']['branchId'])) ? 'is-active' : '' }} w-full" id="m_branch" name="m_branch">
                        <option value="">All branches</option>
                        @foreach($branches as $bid => $bname)<option value="{{ $bid }}" @selected($mo['filters']['branchId'] == $bid)>{{ $bname }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="m_show">Show</label>
                <select class="list-header-filter {{ ($mo['filters']['show'] !== 'all') ? 'is-active' : '' }} w-full" id="m_show" name="m_show">
                    <option value="all" @selected($mo['filters']['show'] === 'all')>All agents</option>
                    <option value="set" @selected($mo['filters']['show'] === 'set')>With targets</option>
                    <option value="notset" @selected($mo['filters']['show'] === 'notset')>Without targets</option>
                    <option value="archived" @selected($mo['filters']['show'] === 'archived')>Removed targets</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="m_sort">Sort by</label>
                <select class="list-header-filter w-full" id="m_sort" name="m_sort">
                    @foreach(['name' => 'Agent name (A-Z)', 'branch' => 'Branch', 'points' => 'Points, biggest first', 'deals' => 'Deals, biggest first', 'listings' => 'Listings, biggest first', 'value' => 'Sales value, biggest first'] as $v => $l)
                        <option value="{{ $v }}" @selected($mo['filters']['sort'] === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-5 flex items-center gap-3">
                <button type="submit" class="corex-btn-primary">Apply</button>
                <a href="{{ route($rb . 'targets.index', ['period' => $mo['period']]) }}#monthly" class="corex-btn-outline">Clear</a>
            </div>
        </form>

        {{-- Grid --}}
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-4 py-2">Agent</th><th class="px-2 py-2">Points</th><th class="px-2 py-2">Deals</th>
                        <th class="px-2 py-2">New listings</th><th class="px-2 py-2">Sales value (rand)</th><th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($mo['rows'] as $row)
                    @php
                        $u = $row['user']; $fid = 'rts-mt-' . $u->id; $t = $row['row']; $removed = $mo['filters']['show'] === 'archived'; $src = $removed ? $row['archived'] : $t;
                        $a = $row['actual'];
                        $cols = [['points_target', 'points', 'w-24', '1'], ['deals_target', 'deals', 'w-20', '1'], ['listings_target', 'listings', 'w-20', '1'], ['value_target', 'value', 'w-36', '0.01']];
                    @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-2">
                            <div class="font-semibold">{{ $u->name }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $u->branch?->name }} &middot; {{ $removed ? 'removed targets' : ($t ? 'own targets' : 'no targets set') }}</div>
                        </td>
                        @foreach($cols as [$field, $key, $w, $step])
                            <td class="px-2 py-2 align-top">
                                <input type="number" min="0" step="{{ $step }}" form="{{ $fid }}" name="{{ $field }}" value="{{ $removed ? '' : ($t?->{$field} ?? '') }}"
                                       placeholder="{{ $removed ? $fmtVal($src?->{$field} ?? 0) : '0' }}" @disabled(! $canWrite || $removed)
                                       class="{{ $w }} rounded-md px-2 py-2 text-sm" style="{{ $fieldStyle }}">
                                <div class="text-xs mt-1" style="color:var(--text-muted);" title="What {{ $u->name }} has actually done in {{ $moLabel }}">
                                    done {{ $key === 'value' ? 'R ' : '' }}{{ $fmtVal($a[$key]) }}<br>
                                    {{ $t ? $diffCell((float) $t->{$field}, (float) $a[$key]) : 'no target' }}
                                </div>
                            </td>
                        @endforeach
                        <td class="px-4 py-2 align-top">
                            <form id="{{ $fid }}" method="POST" action="{{ route($rb . 'targets.monthly.save', $u->id) }}" class="flex flex-wrap gap-2">
                                @csrf @method('PUT')
                                <input type="hidden" name="period" value="{{ $mo['period'] }}">
                                @foreach($mkeep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                                @if($removed)
                                    @if($canWrite)
                                        <button type="submit" formaction="{{ route($rb . 'targets.monthly.restore', $u->id) }}" name="_method" value="POST" class="corex-btn-primary">Bring back</button>
                                    @endif
                                @elseif($canWrite)
                                    <button type="submit" class="corex-btn-primary">Save</button>
                                    @if($t)
                                        <button type="submit" formaction="{{ route($rb . 'targets.monthly.archive', $u->id) }}" name="_method" value="DELETE"
                                                onclick="return confirm('Remove {{ addslashes($u->name) }}\'s targets for {{ $moLabel }}? You can bring them back from Removed targets.');"
                                                class="corex-btn-outline">Remove</button>
                                    @endif
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                        @if($mo['emptyFiltered']) No agents match those filters. <a class="underline" href="{{ route($rb . 'targets.index', ['period' => $mo['period']]) }}#monthly">Clear the filters</a>.
                        @else No agents have the "Access Road to Sold" permission yet. Add it in Role Manager, then set their targets here. @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $mo['rows']->links() }}
    </section>

    <div>
        <h2 class="text-base font-bold" style="color:var(--text-primary);">Daily call targets</h2>
        <p class="text-sm" style="color:var(--text-muted);">How many properties each agent works on each day of the week.</p>
    </div>

    {{-- Agency default --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);">
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Agency default week</h3>
        <form method="POST" action="{{ route($rb . 'targets.default') }}" class="flex flex-wrap items-end gap-3">
            @csrf @method('PUT')
            @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @foreach($weekdays as $d => $label)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="def-{{ $d }}">{{ $label }}</label>
                    <input id="def-{{ $d }}" type="number" min="0" max="{{ $max }}" name="default_targets[{{ $d }}]" value="{{ old('default_targets.' . $d, $default[$d]) }}"
                           @disabled(! $canEditDefault) class="w-16 rounded-md px-2 py-2 text-sm text-center" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            @endforeach
            @if($canEditDefault)
                <button type="submit" class="corex-btn-primary">Save default</button>
                <button type="submit" formaction="{{ route($rb . 'targets.copy-agents') }}" formmethod="POST" name="_method" value="POST"
                        onclick="return confirm('Give EVERY agent this week as their own numbers? Their current numbers are replaced (you can clear them again later).');"
                        class="corex-btn-outline">Give every agent this week</button>
            @endif
        </form>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route($rb . 'targets.index') }}" class="props-filter-panel rounded-md p-4 grid grid-cols-1 sm:grid-cols-5 gap-3 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="q">Search</label>
            <input class="list-header-filter {{ (!empty($q)) ? 'is-active' : '' }} w-full" id="q" name="q" value="{{ $q }}" type="search" placeholder="Agent name or email"
                  >
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
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="mode">Numbers</label>
            <select class="list-header-filter {{ ($mode !== 'all') ? 'is-active' : '' }} w-full" id="mode" name="mode">
                <option value="all" @selected($mode === 'all')>All agents</option>
                <option value="custom" @selected($mode === 'custom')>Own numbers only</option>
                <option value="default" @selected($mode === 'default')>On the agency default</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="status">Show</label>
            <select class="list-header-filter {{ ($status !== 'active') ? 'is-active' : '' }} w-full" id="status" name="status">
                <option value="active" @selected($status === 'active')>Current numbers</option>
                <option value="cleared" @selected($status === 'cleared')>Cleared numbers</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sort">Sort by</label>
            <select class="list-header-filter {{ (false) ? 'is-active' : '' }} w-full" id="sort" name="sort">
                <option value="name" @selected($sortNow === 'name')>Agent name (A-Z)</option>
                <option value="branch" @selected($sortNow === 'branch')>Branch</option>
                <option value="total" @selected($sortNow === 'total')>Biggest week first</option>
            </select>
        </div>
        <div class="sm:col-span-5 flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">Apply</button>
            <a href="{{ route($rb . 'targets.index') }}" class="corex-btn-outline">Clear</a>
        </div>
    </form>

    {{-- The grid --}}
    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm" style="color:var(--text-primary);">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                    <th class="px-4 py-2">Agent</th>
                    @foreach($weekdays as $label)<th class="px-2 py-2 text-center">{{ $label }}</th>@endforeach
                    <th class="px-3 py-2 text-center">Week</th>
                    <th class="px-3 py-2 text-center" title="What the agent should have done by today / the whole month, after taking off public holidays and approved leave">{{ now()->format('F') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                @php $u = $row['user']; $fid = 'rts-row-' . $u->id; @endphp
                <tr style="border-bottom:1px solid var(--border);">
                    <td class="px-4 py-2">
                        <div class="font-semibold">{{ $u->name }}</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $u->branch?->name }} &middot; {{ $row['custom'] ? 'own numbers' : 'agency default' }}</div>
                    </td>
                    @foreach($weekdays as $d => $label)
                        <td class="px-2 py-2 text-center">
                            <input type="number" min="0" max="{{ $max }}" form="{{ $fid }}" name="week[{{ $d }}]"
                                   value="{{ $status === 'cleared' ? '' : ($row['own'][$d] ?? '') }}" placeholder="{{ $default[$d] }}"
                                   @disabled(! $canWrite || $status === 'cleared') title="{{ $label }} - blank uses the agency default of {{ $default[$d] }}"
                                   class="w-14 rounded-md px-2 py-2 text-sm text-center" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </td>
                    @endforeach
                    <td class="px-3 py-2 text-center font-semibold" title="What this agent owes in a normal week (own numbers over the default)">{{ array_sum($row['week']) }}</td>
                    <td class="px-3 py-2 text-center text-xs" style="color:var(--text-muted);" title="Should be {{ $row['month_to_date'] }} by today; {{ $row['month_total'] }} for the whole month. Public holidays and approved leave are already taken off.">{{ $row['month_to_date'] }} / {{ $row['month_total'] }}</td>
                    <td class="px-4 py-2">
                        <form id="{{ $fid }}" method="POST" action="{{ route($rb . 'targets.agent', $u->id) }}" class="flex flex-wrap gap-2">
                            @csrf @method('PUT')
                            @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            @if($status === 'cleared')
                                @if($canWrite)
                                    <button type="submit" formaction="{{ route($rb . 'targets.restore', $u->id) }}" name="_method" value="POST"
                                            class="corex-btn-primary">Bring back</button>
                                @endif
                            @elseif($canWrite)
                                <button type="submit" class="corex-btn-primary">Save</button>
                                <button type="submit" formaction="{{ route($rb . 'targets.fill-weekdays', $u->id) }}" name="_method" value="POST"
                                        title="Copies the Monday number onto Tuesday to Friday" class="corex-btn-outline">Monday to Friday</button>
                                <button type="submit" formaction="{{ route($rb . 'targets.copy-agents') }}" name="_method" value="POST"
                                        onclick="return confirm('Give EVERY agent the numbers shown on this row? Their current numbers are replaced (blank boxes clear that day).');"
                                        title="Copies the boxes on this row to every agent you can see" class="corex-btn-outline">To all agents</button>
                                @if($row['custom'])
                                    <button type="submit" formaction="{{ route($rb . 'targets.clear', $u->id) }}" name="_method" value="DELETE"
                                            onclick="return confirm('Clear {{ addslashes($u->name) }}\'s own numbers? They go back to the agency default and you can bring them back later.');"
                                            class="corex-btn-outline">Clear</button>
                                @endif
                            @endif
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($weekdays) + 4 }}" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                    @if($emptyFiltered) No agents match those filters. <a class="underline" href="{{ route($rb . 'targets.index') }}">Clear the filters</a>.
                    @else No agents have the "Access Road to Sold" permission yet. Add it in Role Manager. @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
@endsection
