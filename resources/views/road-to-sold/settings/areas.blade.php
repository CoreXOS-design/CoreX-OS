@extends('layouts.corex')

{{--
    AT-454 - Road to Sold: which suburbs each agent works.
    Spec: .ai/specs/road-to-sold.md section 5.2. The suburbs themselves are DERIVED from the agency's own stock and its Market
    Intelligence data (Johan, 13 Oct 2026) - nobody chooses them; this screen only gives them to agents.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    No Alpine on this page. Everything is a plain form or link so it works on a phone.
--}}

@php
    $q       = $filters['q'] ?? '';
    $status  = $filters['status'] ?? 'active';
    $sortNow = $filters['sort'] ?? 'name';
    $tabUrl  = fn (array $extra = []) => route($rb . 'areas.index', array_filter(array_merge(
        ['view' => $mode, 'q' => $q ?: null, 'town_id' => ($filters['townId'] ?? 0) ?: null, 'branch_id' => ($filters['branchId'] ?? 0) ?: null,
         'status' => in_array($status, ['removed', 'hidden'], true) ? $status : null, 'source' => ($filters['source'] ?? 'all') === 'all' ? null : $filters['source'], 'sort' => $sortNow === 'name' ? null : $sortNow,
         'no_areas' => !empty($filters['noAreas']) ? 1 : null, 'stale' => !empty($filters['staleOnly']) ? 1 : null,
         'unassigned' => !empty($filters['unassigned']) ? 1 : null],
        $extra
    ), fn ($v) => $v !== null && $v !== ''));
    $fmt = fn ($d) => $d ? $d->diffForHumans() : 'never';
    $portalWords = fn (array $p) => collect($p)->map(fn ($x) => $x === 'pp' ? 'PP' : 'P24')->implode(' and ');
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - areas</h1>
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

    {{-- View switch --}}
    <div class="flex flex-wrap gap-2">
        <a href="{{ route($rb . 'areas.index', ['view' => 'agents']) }}" class="px-3 py-1.5 rounded-md text-sm font-semibold"
           style="{{ $mode === 'agents' ? 'background:var(--brand-icon, #0ea5e9); color:#fff;' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);' }}">Agents and their suburbs</a>
        <a href="{{ route($rb . 'areas.index', ['view' => 'suburbs']) }}" class="px-3 py-1.5 rounded-md text-sm font-semibold"
           style="{{ $mode === 'suburbs' ? 'background:var(--brand-icon, #0ea5e9); color:#fff;' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);' }}">Suburbs and who works them</a>
    </div>

    @if($poolEmpty && $status !== 'hidden')
        {{-- No stock and no Market Intelligence data (or all of it hidden): one line and the way forward. Never the whole country. --}}
        <div class="rounded-md px-4 py-6 flex items-center justify-between gap-4 flex-wrap" style="background:var(--surface); border:1px solid var(--border);" data-qa="areas-empty">
            <span class="text-sm" style="color:var(--text-primary);">
                @if($hiddenCount > 0)
                    Everything Road to Sold found for your agency is hidden.
                @else
                    No stock and no Market Intelligence data yet - your suburbs appear here by themselves once you hold stock or capture adverts.
                @endif
            </span>
            <div class="flex items-center gap-2 flex-wrap">
                @if($hiddenCount > 0 && $canHide)
                    <a href="{{ route($rb . 'areas.index', ['view' => 'suburbs', 'status' => 'hidden']) }}" class="corex-btn-primary" data-qa="areas-empty-hidden">See what is hidden</a>
                @endif
                <a href="{{ $micUrl }}" class="corex-btn-primary" data-qa="areas-empty-mic">Market Intelligence capture</a>
                @if($autoImportUrl)<a href="{{ $autoImportUrl }}" class="corex-btn-outline" data-qa="areas-empty-autoimport">P24 Auto-Import</a>@endif
            </div>
        </div>
    @else
    {{-- Filters --}}
    <form method="GET" action="{{ route($rb . 'areas.index') }}" class="props-filter-panel rounded-md p-4 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="view" value="{{ $mode }}">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="q">Search</label>
            <input class="list-header-filter {{ (!empty($q)) ? 'is-active' : '' }} w-full" id="q" name="q" value="{{ $q }}" type="search"
                   placeholder="{{ $mode === 'agents' ? 'Agent name, email, suburb or town' : 'Suburb, town or agent' }}"
                  >
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="town_id">Town</label>
            <select class="list-header-filter {{ (!empty($filters['townId'])) ? 'is-active' : '' }} w-full" id="town_id" name="town_id">
                <option value="">All towns</option>
                @foreach($towns as $t)
                    <option value="{{ $t->id }}" @selected(($filters['townId'] ?? 0) == $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        @if($mode === 'agents')
            @if($branches->count() > 1)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="branch_id">Branch</label>
                    <select class="list-header-filter {{ (!empty($filters['branchId'])) ? 'is-active' : '' }} w-full" id="branch_id" name="branch_id">
                        <option value="">All branches</option>
                        @foreach($branches as $bid => $bname)
                            <option value="{{ $bid }}" @selected(($filters['branchId'] ?? 0) == $bid)>{{ $bname }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="status">Show</label>
                <select class="list-header-filter {{ ($status !== 'active') ? 'is-active' : '' }} w-full" id="status" name="status">
                    <option value="active" @selected($status === 'active')>Current suburbs</option>
                    <option value="removed" @selected($status === 'removed')>Removed suburbs</option>
                </select>
            </div>
        @endif
        @if($mode === 'suburbs')
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="status">Show</label>
                <select class="list-header-filter {{ ($status !== 'active') ? 'is-active' : '' }} w-full" id="status" name="status">
                    <option value="active" @selected($status === 'active')>Working suburbs</option>
                    @if($canHide || $hiddenCount > 0)<option value="hidden" @selected($status === 'hidden')>Hidden ({{ $hiddenCount }})</option>@endif
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="source">Where from</label>
                <select class="list-header-filter {{ (($filters['source'] ?? 'all') !== 'all') ? 'is-active' : '' }} w-full" id="source" name="source">
                    <option value="all" @selected(($filters['source'] ?? 'all') === 'all')>Stock and Market Intelligence</option>
                    <option value="stock" @selected(($filters['source'] ?? 'all') === 'stock')>We hold stock</option>
                    <option value="mic" @selected(($filters['source'] ?? 'all') === 'mic')>Market Intelligence only</option>
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sort">Sort by</label>
            <select class="list-header-filter {{ (false) ? 'is-active' : '' }} w-full" id="sort" name="sort">
                @if($mode === 'agents')
                    <option value="name" @selected($sortNow === 'name')>Agent name (A-Z)</option>
                    <option value="branch" @selected($sortNow === 'branch')>Branch</option>
                    <option value="areas" @selected($sortNow === 'areas')>Most suburbs</option>
                    <option value="oldest" @selected($sortNow === 'oldest')>Needs a refresh first</option>
                @else
                    <option value="name" @selected($sortNow === 'name')>Suburb (A-Z)</option>
                    <option value="stale" @selected($sortNow === 'stale')>Needs a refresh first</option>
                    <option value="agents" @selected($sortNow === 'agents')>Most agents</option>
                    <option value="stock" @selected($sortNow === 'stock')>Most of our stock</option>
                    <option value="mic" @selected($sortNow === 'mic')>Most Market Intelligence adverts</option>
                @endif
            </select>
        </div>
        <div class="sm:col-span-6 flex flex-wrap items-center gap-4">
            @if($mode === 'agents')
                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);"><input type="checkbox" name="no_areas" value="1" @checked(!empty($filters['noAreas']))> Agents with no suburbs</label>
            @else
                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);"><input type="checkbox" name="unassigned" value="1" @checked(!empty($filters['unassigned']))> Nobody works it</label>
            @endif
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);"><input type="checkbox" name="stale" value="1" @checked(!empty($filters['staleOnly']))> Needs a refresh (a portal not captured in {{ $warnDays }} days)</label>
            <button type="submit" class="corex-btn-primary">Apply</button>
            <a href="{{ route($rb . 'areas.index', ['view' => $mode]) }}" class="corex-btn-outline">Clear</a>
        </div>
    </form>

    @if($mode === 'agents')
        {{-- ── Agents x suburbs ── --}}
        @if($picker)
            <div class="rounded-md p-4 space-y-3" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Add suburbs for {{ $picker['agent']->name }}</h3>
                    <a href="{{ $tabUrl(['add_for' => null]) }}" class="corex-btn-outline">Close</a>
                </div>
                <form method="GET" action="{{ route($rb . 'areas.index') }}" class="flex flex-wrap gap-2 items-end">
                    <input type="hidden" name="view" value="agents">
                    <input type="hidden" name="add_for" value="{{ $picker['agent']->id }}">
                    <input name="suburb_q" value="{{ $picker['suburb_q'] }}" type="search" placeholder="Search suburbs" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <select name="picker_town" class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">All towns</option>
                        @foreach($towns as $t)<option value="{{ $t->id }}" @selected($picker['town'] == $t->id)>{{ $t->name }}</option>@endforeach
                    </select>
                    <button type="submit" class="corex-btn-outline">Find</button>
                </form>
                @if($picker['results']->isEmpty())
                    <p class="text-sm" style="color:var(--text-muted);">No suburbs match.</p>
                @else
                    <form method="POST" action="{{ route($rb . 'areas.sync', $picker['agent']->id) }}" data-rts-suburb-picker>
                        @csrf
                        <input type="hidden" name="view" value="agents">
                        <div class="flex items-center gap-2 mb-2">
                            <button type="button" class="corex-btn-outline" data-tick-all>Tick all</button>
                            <button type="button" class="corex-btn-outline" data-untick-all>Untick all</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-1 max-h-72 overflow-y-auto p-2" style="border:1px solid var(--border);">
                            @foreach($picker['results'] as $r)
                                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                    <input type="checkbox" name="add[]" value="{{ $r->id }}">
                                    @php($cnt = $picker['counts'][(int) $r->id] ?? ['own' => 0, 'mic' => 0])
                                    <span>{{ $r->name }}@if($r->town_name) <span style="color:var(--text-muted);">- {{ $r->town_name }}</span>@endif <span class="text-xs" style="color:var(--text-muted);">({{ $cnt['own'] }} stock, {{ $cnt['mic'] }} MIC)</span></span>
                                </label>
                            @endforeach
                        </div>
                        @if($picker['overflow'])
                            <p class="text-xs mt-1" style="color:var(--text-muted);">Showing the first {{ $picker['results']->count() }} - narrow the search to see more.</p>
                        @endif
                        <button type="submit" class="corex-btn-primary mt-3">Add ticked suburbs</button>
                    </form>
                @endif
            </div>
        @endif

        <div class="space-y-3">
            @forelse($rows as $row)
                <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="text-sm font-bold" style="color:var(--text-primary);">{{ $row['user']->name }}</span>
                            @if($row['user']->branch)<span class="text-xs ml-2" style="color:var(--text-muted);">{{ $row['user']->branch->name }}</span>@endif
                            <span class="text-xs ml-2" style="color:var(--text-muted);">{{ $row['count'] }} {{ $row['count'] === 1 ? 'suburb' : 'suburbs' }}</span>
                        </div>
                        @if($status === 'active')
                            <a href="{{ $tabUrl(['add_for' => $row['user']->id]) }}" class="corex-btn-primary">Add suburbs</a>
                        @endif
                    </div>
                    @if($row['chips']->isEmpty())
                        <p class="text-sm mt-2" style="color:var(--text-muted);">
                            @if($status === 'removed') No removed suburbs. @else No suburbs yet.@if($lockOn) <strong>No call list until you add some.</strong>@endif @endif
                        </p>
                    @else
                        <ul class="mt-3 flex flex-wrap gap-2">
                            @foreach($row['chips'] as $c)
                                <li class="rounded-md px-3 py-2 text-xs" style="border:1px solid {{ $c['needs_refresh'] ? 'var(--ds-amber, #f59e0b)' : 'var(--border)' }}; color:var(--text-primary);">
                                    <div class="font-semibold">{{ $c['name'] }}@if($c['town']) <span style="color:var(--text-muted);">- {{ $c['town'] }}</span>@endif</div>
                                    @unless($c['in_pool'])
                                        <div style="color:var(--text-muted);" data-qa="chip-not-in-pool">Not in your stock or MIC yet</div>
                                    @else
                                        <div style="color:var(--text-muted);">Last captured: {{ collect($portals)->map(fn ($p) => ($p === 'pp' ? 'PP ' : 'P24 ') . $fmt($c[$p]))->implode(' · ') }}</div>
                                        @if($c['needs_refresh'])<div><strong>Needs a refresh: {{ $portalWords($c['stale_portals']) }}</strong></div>@endif
                                    @endunless
                                    <div class="mt-1">
                                        @if($status === 'removed')
                                            <form method="POST" action="{{ route($rb . 'areas.restore', $c['area_id']) }}" class="inline">@csrf<button type="submit" class="corex-btn-outline">Give back</button></form>
                                        @else
                                            <form method="POST" action="{{ route($rb . 'areas.archive', $c['area_id']) }}" class="inline"
                                                  onsubmit="return confirm('Take this suburb off {{ addslashes($row['user']->name) }}? You can give it back later from the Removed suburbs list.');">
                                                @csrf @method('DELETE')<button type="submit" class="corex-btn-outline">Remove</button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <div class="rounded-md p-8 text-center text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                    @if($emptyFiltered)
                        No agents match those filters. <a href="{{ route($rb . 'areas.index', ['view' => 'agents']) }}" class="underline">Clear the filters</a>.
                    @else
                        No agents have the "Access Road to Sold" permission yet. Add it in Role Manager.
                    @endif
                </div>
            @endforelse
        </div>
        {{ $rows->links() }}
    @else
        {{-- ── Suburbs x agents ── --}}
        @if($status === 'hidden')
            <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="color:var(--text-primary);">
                    <thead><tr style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wide">
                        <th class="px-4 py-2">Hidden from Road to Sold</th><th class="px-4 py-2">Town</th><th class="px-4 py-2">Hidden</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody>
                    @forelse($hiddenRows as $h)
                        <tr style="border-bottom:1px solid var(--border);" data-qa="hidden-row">
                            <td class="px-4 py-2 font-semibold">{{ $h['name'] }} <span class="text-xs font-normal" style="color:var(--text-muted);">{{ $h['kind'] === 'town' ? '(whole town)' : '' }}</span></td>
                            <td class="px-4 py-2" style="color:var(--text-muted);">{{ $h['town'] ?? '-' }}</td>
                            <td class="px-4 py-2" style="color:var(--text-muted);">{{ $h['at']?->diffForHumans() }}</td>
                            <td class="px-4 py-2">
                                @if($canHide)
                                    <form method="POST" action="{{ route($rb . 'areas.show-again', $h['id']) }}">@csrf<button type="submit" class="corex-btn-outline">Bring back</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center" style="color:var(--text-muted);">Nothing is hidden.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @else
        @if($canHide && ($filters['townId'] ?? 0) > 0 && !empty($townName))
            <form method="POST" action="{{ route($rb . 'areas.hide') }}" class="flex justify-end" data-confirm="Hide {{ $townName }} and all its suburbs from Road to Sold? Nothing is deleted - you can bring it back.">
                @csrf <input type="hidden" name="kind" value="town"><input type="hidden" name="id" value="{{ $filters['townId'] }}">
                <button type="submit" class="corex-btn-outline" data-qa="hide-town">Hide {{ $townName }}</button>
            </form>
        @endif
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wide">
                        <th class="px-4 py-2">Suburb</th><th class="px-4 py-2">Town</th>
                        <th class="px-4 py-2 text-right" title="Properties of this agency's own in this suburb">Our stock</th>
                        <th class="px-4 py-2 text-right" title="Market Intelligence adverts captured from Property24 and Private Property">MIC adverts</th>
                        <th class="px-4 py-2">Who works it</th>
                        @if(in_array('p24', $portals, true))<th class="px-4 py-2">Last captured (P24)</th>@endif @if(in_array('pp', $portals, true))<th class="px-4 py-2">Last captured (PP)</th>@endif<th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($suburbRows as $s)
                    <tr style="border-bottom:1px solid var(--border);" data-qa="suburb-row">
                        <td class="px-4 py-2 font-semibold">{{ $s['name'] }}</td>
                        <td class="px-4 py-2" style="color:var(--text-muted);">{{ $s['town'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($s['own']) }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($s['mic']) }}</td>
                        <td class="px-4 py-2">{{ $s['agents']->isEmpty() ? 'Nobody yet' : $s['agents']->implode(', ') }}</td>
                        @if(in_array('p24', $portals, true))<td class="px-4 py-2">{{ $fmt($s['p24']) }}</td>@endif
                        @if(in_array('pp', $portals, true))<td class="px-4 py-2">{{ $fmt($s['pp']) }}</td>@endif
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2 flex-wrap">
                            @if($s['needs_refresh'])
                                @permission('access_settings')
                                    <a href="{{ route('admin.minion.setup') }}" class="corex-btn-outline">Refresh now</a>
                                @else
                                    <span class="text-xs font-semibold" style="color:var(--text-muted);">Needs a refresh: {{ $portalWords($s['stale_portals']) }}</span>
                                @endpermission
                            @endif
                            @if($canHide)
                                <form method="POST" action="{{ route($rb . 'areas.hide') }}" data-confirm="Hide {{ $s['name'] }} from Road to Sold? Nothing is deleted - you can bring it back.">
                                    @csrf <input type="hidden" name="kind" value="suburb"><input type="hidden" name="id" value="{{ $s['id'] }}">
                                    <button type="submit" class="corex-btn-outline" data-qa="hide-suburb">Hide</button>
                                </form>
                            @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 6 + count($portals) }}" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                        @if($emptyFiltered) No suburbs match those filters. <a href="{{ route($rb . 'areas.index', ['view' => 'suburbs']) }}" class="underline">Clear the filters</a>.
                        @else No suburbs to show. @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @endif
        @if($status !== 'hidden'){{ $suburbRows->links() }}@endif
    @endif
    @endif
</div>

<script>
{{-- Tick all / Untick all: every suburb checkbox the list is showing for this agent (the ones that match the search). --}}
document.querySelectorAll('[data-rts-suburb-picker]').forEach(function (form) {
    function setAll(on) {
        form.querySelectorAll('input[type=checkbox][name="add[]"]').forEach(function (box) { box.checked = on; });
    }
    var all = form.querySelector('[data-tick-all]');
    var none = form.querySelector('[data-untick-all]');
    if (all) { all.addEventListener('click', function () { setAll(true); }); }
    if (none) { none.addEventListener('click', function () { setAll(false); }); }
});
</script>
@endsection
