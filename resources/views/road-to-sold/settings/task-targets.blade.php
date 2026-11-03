@extends('layouts.corex')

{{--
    AT-454 v2 phase 5 - Road to Sold settings -> Task targets: each agent's own target for each task on their level, over the level's.
    Spec: .ai/specs/road-to-sold.md sections 5.2, 10.4. DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Needs $tabs, $rows (paginator of arrays: agent, level, task, step, level_target, own, archived, unit), $filters, $branches, $levelNames, $track.
    Screen-text rule (Johan): every line is data or a control.
--}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $rb = \App\Services\RoadToSold\Support\Routing::base();
    $f = $filters;
    $filtered = ($f['q'] ?? '') !== '' || $f['show'] !== 'all' || ! empty($f['level']) || ! empty($f['branch']);
    $unitWord = ['hour' => 'an hour', 'day' => 'a day', 'week' => 'a week', 'month' => 'a month'];
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - task targets</h1>
        @if(\Illuminate\Support\Facades\Route::has('road-to-sold.funnel.suggestions') && auth()->user()?->hasPermission('road_to_sold.view_funnel'))
            <a href="{{ route('road-to-sold.funnel.suggestions') }}" class="corex-btn-outline corex-btn-xs">Suggested targets</a>
        @endif
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

    <form method="GET" action="{{ route($rb . 'task-targets.index') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tt-q">Search</label>
            <input id="tt-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Agent or task" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }} min-width:14rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tt-show">Show</label>
            <select id="tt-show" name="show" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }}" onchange="this.form.submit()">
                @foreach(['all' => 'Every task', 'own' => 'Their own target set', 'archived' => 'Cleared (can be restored)'] as $k => $l)<option value="{{ $k }}" @selected($f['show'] === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tt-level">Level</label>
            <select id="tt-level" name="level" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }}" onchange="this.form.submit()">
                <option value="">All levels</option>
                @foreach($levelNames as $id => $name)<option value="{{ $id }}" @selected((string) ($f['level'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach
            </select>
        </div>
        @if($branches->isNotEmpty())
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tt-branch">Branch</label>
                <select id="tt-branch" name="branch" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }}" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) ($f['branch'] ?? '') === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tt-sort">Sort</label>
            <select id="tt-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }}" onchange="this.form.submit()">
                <option value="agent" @selected($f['sort'] === 'agent')>Agent</option>
                <option value="task" @selected($f['sort'] === 'task')>Task</option>
                <option value="level" @selected($f['sort'] === 'level')>Level</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route($rb . 'task-targets.index') }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color:var(--text-muted);">
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-left">Level</th>
                    <th class="px-3 py-2 text-left">Task</th>
                    <th class="px-3 py-2 text-right">Level's target</th>
                    <th class="px-3 py-2 text-left">Their own target</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-3 py-2 font-semibold" style="color:var(--text-primary);">{{ $r['agent']->name }}<div class="font-normal" style="color:var(--text-muted);">{{ $r['agent']->branch?->name }}</div></td>
                    <td class="px-3 py-2" style="color:var(--text-primary);">{{ $r['level']->name }}</td>
                    <td class="px-3 py-2" style="color:var(--text-primary);">{{ $r['task']->label }}<div style="color:var(--text-muted);">{{ $r['step']?->label }}</div></td>
                    <td class="px-3 py-2 text-right" style="color:var(--text-primary);">{{ $r['level_target'] }} {{ $unitWord[$r['unit']] ?? '' }}</td>
                    <td class="px-3 py-2">
                        <form method="POST" action="{{ route($rb . 'task-targets.save', [$r['agent']->id, $r['task']->id]) }}" class="flex items-center gap-2">@csrf @method('PUT')
                            <label class="sr-only" for="own-{{ $r['agent']->id }}-{{ $r['task']->id }}">Their own target</label>
                            <input id="own-{{ $r['agent']->id }}-{{ $r['task']->id }}" type="number" name="target" min="0" max="{{ \App\Models\RoadToSold\RtsLevelTask::MAX_TARGET }}" value="{{ $r['own'] ?? $r['archived'] }}" placeholder="Level's" class="rounded-md px-2 py-1 text-xs w-24" style="{{ $fieldStyle }}" required>
                            <button type="submit" class="corex-btn-primary corex-btn-xs">{{ $r['own'] !== null ? 'Change' : 'Set' }}</button>
                        </form>
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($r['own'] !== null)
                            <form method="POST" action="{{ route($rb . 'task-targets.clear', [$r['agent']->id, $r['task']->id]) }}" onsubmit="return confirm('Clear their own target? The level\'s target applies again. You can restore it from the Cleared list.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs">Clear</button></form>
                        @elseif($r['archived'] !== null)
                            <form method="POST" action="{{ route($rb . 'task-targets.restore', [$r['agent']->id, $r['task']->id]) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore {{ $r['archived'] }}</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-6 py-12 text-center">
                    <div class="text-base font-semibold" style="color:var(--text-primary);">{{ $filtered ? 'Nothing matches this search.' : 'There are no targets to set yet.' }}</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted);">{{ $filtered ? 'Clear the search or change a filter to see more.' : 'An agent appears here once their level has a task with a target (Recipe tab).' }}</div>
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
@endsection
