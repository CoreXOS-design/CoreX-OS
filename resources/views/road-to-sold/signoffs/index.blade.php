{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 4 - the manager's level sign-off list. Needs $rows (paginator of RtsAgentLevel), $filters, $progress (agent level row id => AgentLevelService::progress),
     $counts, $levels, $branches, $track, $viewer. Search, sort, filters, pagination and the empty state are all on this page; the rows are the agents
     inside the viewer's breadth (branch manager = their branch, admin = the agency). Owners and principals have no level and never appear. --}}
@extends('layouts.corex-app')
@section('title', 'Level sign-offs')

@section('corex-content')
@php
    $f = $filters;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $statuses = \App\Http\Controllers\RoadToSold\SignoffController::STATUSES;
    $filtered = ($f['q'] ?? '') !== '' || $f['status'] !== 'awaiting_signoff' || ! empty($f['level']) || ! empty($f['branch']) || $f['archived'] !== 'active';
@endphp

<div class="w-full space-y-4 rts-page">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Level sign-offs</h1>
                <span class="text-xs" style="color: var(--text-primary);"><strong>{{ $counts['awaiting'] }}</strong> waiting for sign-off</span>
                <span class="text-xs" style="color: var(--text-primary);"><strong>{{ $counts['active'] }}</strong> working on their level</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Road to Sold</a>
                @if(\Illuminate\Support\Facades\Route::has('road-to-sold.settings.recipe.index') && $viewer->hasPermission('road_to_sold.manage_recipe'))
                    <a href="{{ route('road-to-sold.settings.recipe.index') }}" class="corex-btn-outline corex-btn-xs">Recipe</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('road-to-sold.signoffs.index') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="so-q">Search</label>
            <input id="so-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Agent name" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 14rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="so-status">Status</label>
            <select id="so-status" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($statuses as $k => $l)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="so-level">Level</label>
            <select id="so-level" name="level" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">All levels</option>
                @foreach($levels as $l)<option value="{{ $l->id }}" @selected((string) ($f['level'] ?? '') === (string) $l->id)>{{ $l->name }}</option>@endforeach
            </select>
        </div>
        @if($branches->isNotEmpty())
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="so-branch">Branch</label>
                <select id="so-branch" name="branch" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) ($f['branch'] ?? '') === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="so-arch">Archived</label>
            <select id="so-arch" name="archived" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(\App\Http\Controllers\RoadToSold\SignoffController::ARCHIVED as $k => $l)<option value="{{ $k }}" @selected($f['archived'] === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="so-sort">Sort</label>
            <select id="so-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(\App\Http\Controllers\RoadToSold\SignoffController::SORTS as $k => $l)<option value="{{ $k }}" @selected($f['sort'] === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <select name="dir" aria-label="Sort direction" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="asc" @selected(($f['dir'] ?? 'asc') !== 'desc')>Ascending</option>
                <option value="desc" @selected(($f['dir'] ?? 'asc') === 'desc')>Descending</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route('road-to-sold.signoffs.index', $params) }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-left">Branch</th>
                    <th class="px-3 py-2 text-left">Level</th>
                    <th class="px-3 py-2 text-left">Weeks on target</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-left">Waiting since</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                @php $p = $progress[$r->id] ?? null; $archived = $r->trashed(); @endphp
                <tr style="border-top: 1px solid var(--border);{{ $archived ? ' opacity: .65;' : '' }}">
                    <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $r->agent?->name }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r->agent?->branch?->name ?? '-' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r->level?->name }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @if($p && $p['required'] !== [])
                            <strong>{{ min($p['streak'], $p['needed']) }} of {{ $p['needed'] }}</strong>
                        @else
                            <span style="color: var(--text-muted);">No task is marked needed for sign-off on this level</span>
                        @endif
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ \App\Models\RoadToSold\RtsAgentLevel::STATUSES[$r->status] ?? $r->status }}</td>
                    <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">{{ $r->requested_signoff_at ? \App\Support\AppDate::dateTime($r->requested_signoff_at) : '-' }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2 flex-wrap">
                            <a href="{{ route('road-to-sold.signoffs.show', $r->id) }}" class="corex-btn-outline corex-btn-xs">{{ $r->status === 'awaiting_signoff' ? 'Review' : 'Open' }}</a>
                            @if($archived)
                                <form method="POST" action="{{ route('road-to-sold.signoffs.restore', $r->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                            @else
                                <form method="POST" action="{{ route('road-to-sold.signoffs.archive', $r->id) }}" data-confirm="Archive this agent's level? They stop being tracked on levels until you restore them.">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs">Archive</button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">
                            {{ $filtered ? 'Nothing matches this search.' : 'Nobody is waiting for a sign-off.' }}
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            {{ $filtered
                                ? 'Clear the search or change a filter to see more.'
                                : 'An agent appears here once they have been on target for the number of weeks set in Settings, Road to Sold. Choose "Working on it" to see everyone still on the way.' }}
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
@endsection
