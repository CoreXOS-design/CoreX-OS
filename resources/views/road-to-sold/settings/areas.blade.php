@extends('layouts.corex')

{{--
    AT-454 - Road to Sold: which suburbs each agent works.
    Spec: .ai/specs/road-to-sold.md section 5.2.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    No Alpine on this page. Everything is a plain form or link so it works on a phone.
--}}

@php
    $q       = $filters['q'] ?? '';
    $status  = $filters['status'] ?? 'active';
    $sortNow = $filters['sort'] ?? 'name';
    $tabUrl  = fn (array $extra = []) => route($rb . 'areas.index', array_filter(array_merge(
        ['view' => $mode, 'q' => $q ?: null, 'town_id' => ($filters['townId'] ?? 0) ?: null, 'branch_id' => ($filters['branchId'] ?? 0) ?: null,
         'status' => $status === 'removed' ? 'removed' : null, 'sort' => $sortNow === 'name' ? null : $sortNow,
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

    {{-- Filters --}}
    <form method="GET" action="{{ route($rb . 'areas.index') }}" class="rounded-md p-4 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="view" value="{{ $mode }}">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="q">Search</label>
            <input id="q" name="q" value="{{ $q }}" type="search"
                   placeholder="{{ $mode === 'agents' ? 'Agent name, email, suburb or town' : 'Suburb, town or agent' }}"
                   class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="town_id">Town</label>
            <select id="town_id" name="town_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
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
                    <select id="branch_id" name="branch_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">All branches</option>
                        @foreach($branches as $bid => $bname)
                            <option value="{{ $bid }}" @selected(($filters['branchId'] ?? 0) == $bid)>{{ $bname }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="status">Show</label>
                <select id="status" name="status" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="active" @selected($status === 'active')>Current suburbs</option>
                    <option value="removed" @selected($status === 'removed')>Removed suburbs</option>
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sort">Sort by</label>
            <select id="sort" name="sort" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                @if($mode === 'agents')
                    <option value="name" @selected($sortNow === 'name')>Agent name (A-Z)</option>
                    <option value="branch" @selected($sortNow === 'branch')>Branch</option>
                    <option value="areas" @selected($sortNow === 'areas')>Most suburbs</option>
                    <option value="oldest" @selected($sortNow === 'oldest')>Needs a refresh first</option>
                @else
                    <option value="name" @selected($sortNow === 'name')>Suburb (A-Z)</option>
                    <option value="stale" @selected($sortNow === 'stale')>Needs a refresh first</option>
                    <option value="agents" @selected($sortNow === 'agents')>Most agents</option>
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
                    <form method="POST" action="{{ route($rb . 'areas.sync', $picker['agent']->id) }}">
                        @csrf
                        <input type="hidden" name="view" value="agents">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-1 max-h-72 overflow-y-auto p-2" style="border:1px solid var(--border);">
                            @foreach($picker['results'] as $r)
                                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                    <input type="checkbox" name="add[]" value="{{ $r->id }}">
                                    <span>{{ $r->name }}@if($r->town_name) <span style="color:var(--text-muted);">- {{ $r->town_name }}</span>@endif</span>
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
                                        <div style="color:var(--text-muted);">No longer in your Prospecting Setup</div>
                                    @else
                                        <div style="color:var(--text-muted);">Last captured: P24 {{ $fmt($c['p24']) }} &middot; PP {{ $fmt($c['pp']) }}</div>
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
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); color:var(--text-muted);" class="text-left text-xs">
                        <th class="px-4 py-2">Suburb</th><th class="px-4 py-2">Town</th><th class="px-4 py-2">Who works it</th>
                        <th class="px-4 py-2">Last captured (P24)</th><th class="px-4 py-2">Last captured (PP)</th><th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($suburbRows as $s)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-2 font-semibold">{{ $s['name'] }}</td>
                        <td class="px-4 py-2" style="color:var(--text-muted);">{{ $s['town'] ?? '-' }}</td>
                        <td class="px-4 py-2">{{ $s['agents']->isEmpty() ? 'Nobody yet' : $s['agents']->implode(', ') }}</td>
                        <td class="px-4 py-2">{{ $fmt($s['p24']) }}</td>
                        <td class="px-4 py-2">{{ $fmt($s['pp']) }}</td>
                        <td class="px-4 py-2">
                            @if($s['needs_refresh'])
                                @permission('access_settings')
                                    <a href="{{ route('admin.minion.setup') }}" class="corex-btn-outline">Refresh now</a>
                                @else
                                    <span class="text-xs font-semibold" style="color:var(--text-muted);">Needs a refresh: {{ $portalWords($s['stale_portals']) }}</span>
                                @endpermission
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                        @if($emptyFiltered) No suburbs match those filters. <a href="{{ route($rb . 'areas.index', ['view' => 'suburbs']) }}" class="underline">Clear the filters</a>.
                        @else Your agency has no suburbs set up yet. Add a town under Prospecting Setup first. @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $suburbRows->links() }}
    @endif
</div>
@endsection
