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
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - targets</h1>
            <p class="text-sm text-white/60">How many properties each agent should work on each day of the week. Public holidays and approved leave carry no target. Leave a box blank to use the agency default.</p>
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
            You can look at these numbers but not change them. Targets are set by your manager.
        </div>
    @endunless

    {{-- Agency default --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);">
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Agency default week</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">Used for any agent and any day where the agent has no number of their own. Use 0 for a day nobody works.</p>
        <form method="POST" action="{{ route('road-to-sold.settings.targets.default') }}" class="flex flex-wrap items-end gap-3">
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
                <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9);">Save default</button>
                <button type="submit" formaction="{{ route('road-to-sold.settings.targets.copy-agents') }}" formmethod="POST" name="_method" value="POST"
                        onclick="return confirm('Give EVERY agent this week as their own numbers? Their current numbers are replaced (you can clear them again later).');"
                        class="px-4 py-2 rounded-md text-sm font-semibold" style="border:1px solid var(--border); color:var(--text-primary);">Give every agent this week</button>
            @endif
        </form>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('road-to-sold.settings.targets.index') }}" class="rounded-md p-4 grid grid-cols-1 sm:grid-cols-5 gap-3 items-end"
          style="background:var(--surface); border:1px solid var(--border);">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="q">Search</label>
            <input id="q" name="q" value="{{ $q }}" type="search" placeholder="Agent name or email"
                   class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        @if($branches->count() > 1)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All branches</option>
                    @foreach($branches as $bid => $bname)<option value="{{ $bid }}" @selected($filters['branchId'] == $bid)>{{ $bname }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="mode">Numbers</label>
            <select id="mode" name="mode" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="all" @selected($mode === 'all')>All agents</option>
                <option value="custom" @selected($mode === 'custom')>Own numbers only</option>
                <option value="default" @selected($mode === 'default')>On the agency default</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="status">Show</label>
            <select id="status" name="status" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="active" @selected($status === 'active')>Current numbers</option>
                <option value="cleared" @selected($status === 'cleared')>Cleared numbers</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sort">Sort by</label>
            <select id="sort" name="sort" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="name" @selected($sortNow === 'name')>Agent name (A-Z)</option>
                <option value="branch" @selected($sortNow === 'branch')>Branch</option>
                <option value="total" @selected($sortNow === 'total')>Biggest week first</option>
            </select>
        </div>
        <div class="sm:col-span-5 flex items-center gap-3">
            <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9);">Apply</button>
            <a href="{{ route('road-to-sold.settings.targets.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear filters</a>
        </div>
    </form>

    {{-- The grid --}}
    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm" style="color:var(--text-primary);">
            <thead>
                <tr class="text-left text-xs" style="border-bottom:1px solid var(--border); color:var(--text-muted);">
                    <th class="px-4 py-2">Agent</th>
                    @foreach($weekdays as $label)<th class="px-2 py-2 text-center">{{ $label }}</th>@endforeach
                    <th class="px-3 py-2 text-center">Week</th>
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
                    <td class="px-4 py-2">
                        <form id="{{ $fid }}" method="POST" action="{{ route('road-to-sold.settings.targets.agent', $u->id) }}" class="flex flex-wrap gap-2">
                            @csrf @method('PUT')
                            @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                            @if($status === 'cleared')
                                @if($canWrite)
                                    <button type="submit" formaction="{{ route('road-to-sold.settings.targets.restore', $u->id) }}" name="_method" value="POST"
                                            class="px-3 py-1.5 rounded-md text-xs font-semibold text-white" style="background:var(--brand-icon, #0ea5e9);">Bring back</button>
                                @endif
                            @elseif($canWrite)
                                <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-semibold text-white" style="background:var(--brand-icon, #0ea5e9);">Save</button>
                                <button type="submit" formaction="{{ route('road-to-sold.settings.targets.fill-weekdays', $u->id) }}" name="_method" value="POST"
                                        title="Copies the Monday number onto Tuesday to Friday" class="px-3 py-1.5 rounded-md text-xs" style="border:1px solid var(--border);">Monday to Friday</button>
                                <button type="submit" formaction="{{ route('road-to-sold.settings.targets.copy-agents') }}" name="_method" value="POST"
                                        onclick="return confirm('Give EVERY agent the numbers shown on this row? Their current numbers are replaced (blank boxes clear that day).');"
                                        title="Copies the boxes on this row to every agent you can see" class="px-3 py-1.5 rounded-md text-xs" style="border:1px solid var(--border);">To all agents</button>
                                @if($row['custom'])
                                    <button type="submit" formaction="{{ route('road-to-sold.settings.targets.clear', $u->id) }}" name="_method" value="DELETE"
                                            onclick="return confirm('Clear {{ addslashes($u->name) }}\'s own numbers? They go back to the agency default and you can bring them back later.');"
                                            class="px-3 py-1.5 rounded-md text-xs" style="border:1px solid var(--border);">Clear</button>
                                @endif
                            @endif
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($weekdays) + 3 }}" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                    @if($emptyFiltered) No agents match those filters. <a class="underline" href="{{ route('road-to-sold.settings.targets.index') }}">Clear the filters</a>.
                    @else No agents can use Road to Sold yet. Give agents the "Access Road to Sold" permission in Role Manager and they will appear here. @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
    <p class="text-xs" style="color:var(--text-muted);">A box with a number is that agent's own target for the day; a blank box (showing the default in grey) uses the agency default. 0 means the agent owes nothing that day.</p>
</div>
@endsection
