{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 5 - suggested next targets, with the data behind each. Nothing is written until a person accepts, edits or ignores. Needs $blocks (paginator of
     [agent, rows, level]), $filters, $branches, $settings, $canWrite, $track, $viewer, $tab. --}}
@extends('layouts.corex-app')
@section('title', 'Suggested targets')

@section('corex-content')
@php
    $f = $filters;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $filtered = ($f['q'] ?? '') !== '' || ($f['task'] ?? '') !== '' || $f['show'] !== 'changes' || ! empty($f['branch']);
    $unitWord = ['day' => 'a day', 'week' => 'a week', 'month' => 'a month'];
    $shown = collect($blocks->items())->sum(fn ($b) => $b['rows']->count());
@endphp

<div class="w-full space-y-4 rts-page">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Suggested targets</h1>
                <span class="text-xs" style="color: var(--text-primary);">Learned from the last {{ $settings->suggestWindowWeeks() }} weeks, allowing {{ $settings->suggestGrowthPct() }}% growth</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Road to Sold</a>
                @if(\Illuminate\Support\Facades\Route::has('road-to-sold.settings.task-targets.index') && $viewer->hasPermission('road_to_sold.manage_targets'))
                    <a href="{{ route('road-to-sold.settings.task-targets.index') }}" class="corex-btn-outline corex-btn-xs">Task targets</a>
                @endif
            </div>
        </div>
    </div>
    @include('road-to-sold.funnel._tabs')

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('road-to-sold.funnel.suggestions') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="sg-q">Search agent</label>
            <input id="sg-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Agent name" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 12rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="sg-task">Task</label>
            <input id="sg-task" type="text" name="task" value="{{ $f['task'] }}" placeholder="Task name" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 10rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="sg-show">Show</label>
            <select id="sg-show" name="show" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="changes" @selected($f['show'] === 'changes')>Suggestions only</option>
                <option value="all" @selected($f['show'] === 'all')>Every task with data</option>
            </select>
        </div>
        @if($branches->isNotEmpty())
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="sg-branch">Branch</label>
                <select id="sg-branch" name="branch" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) ($f['branch'] ?? '') === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="sg-sort">Sort</label>
            <select id="sg-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="agent" @selected($f['sort'] === 'agent')>Agent</option>
                <option value="gap" @selected($f['sort'] === 'gap')>Biggest change first</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route('road-to-sold.funnel.suggestions', $params) }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    @forelse($blocks as $b)
        <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-3 text-xs font-bold flex items-center gap-2" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                {{ $b['agent']->name }} <span class="ds-badge ds-badge-info">{{ $b['level']?->name ?? 'No level' }}</span>
                <span class="font-normal" style="color: var(--text-muted);">{{ $b['agent']->branch?->name }}</span>
            </div>
            @if($b['rows']->isEmpty())
                <div class="px-4 py-4 text-xs" style="color: var(--text-muted);">{{ $f['show'] === 'changes' ? 'Nothing to suggest for this agent.' : 'No task with a target on this level.' }}</div>
            @else
                <table class="w-full text-xs ds-table">
                    <thead>
                        <tr style="color: var(--text-muted);">
                            <th class="px-3 py-2 text-left">Task</th>
                            <th class="px-3 py-2 text-right">Target now</th>
                            <th class="px-3 py-2 text-left">What they did</th>
                            <th class="px-3 py-2 text-right">Suggested</th>
                            <th class="px-3 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($b['rows'] as $r)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-3 py-2" style="color: var(--text-primary);"><div class="font-semibold">{{ $r['label'] }}</div><div style="color: var(--text-muted);">{{ $r['step_label'] }}</div></td>
                            <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $r['current'] }} {{ $unitWord[$r['unit']] ?? '' }}@if($r['personal'])<div style="color: var(--text-muted);">their own</div>@endif</td>
                            <td class="px-3 py-2" style="color: var(--text-primary);">
                                @if($r['suggested'] === null)
                                    <span style="color: var(--text-muted);">{{ $r['reason'] }}</span>
                                @else
                                    {{ number_format($r['total']) }} in {{ $r['weeks'] }} weeks over {{ $r['working_days'] }} working days = {{ $r['rate'] }} a working day
                                    <div style="color: var(--text-muted);">next {{ $r['unit'] === 'day' ? 'day' : $r['unit'] }} has {{ $r['next_days'] }} working {{ \Illuminate\Support\Str::plural('day', $r['next_days']) }}@if($r['capped_by']) - held to what {{ $r['capped_by'] }} asks for @endif</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-semibold" style="color: var(--text-primary);">{{ $r['suggested'] ?? '-' }}@if($r['suggested'] !== null && ! $r['change'])<div class="font-normal" style="color: var(--text-muted);">no change</div>@endif</td>
                            <td class="px-3 py-2 text-right">
                                @if($r['ignored'])
                                    <span style="color: var(--text-muted);">Ignored</span>
                                @elseif($r['suggested'] !== null && $r['change'] && $canWrite)
                                    <div class="flex items-center justify-end gap-2 flex-wrap">
                                        <form method="POST" action="{{ route('road-to-sold.funnel.suggestions.accept', $params) }}" class="flex items-center gap-2">@csrf
                                            <input type="hidden" name="agent_id" value="{{ $b['agent']->id }}"><input type="hidden" name="task_id" value="{{ $r['task_id'] }}"><input type="hidden" name="suggested" value="{{ $r['suggested'] }}">
                                            <label class="sr-only" for="tg-{{ $b['agent']->id }}-{{ $r['task_id'] }}">Target</label>
                                            <input id="tg-{{ $b['agent']->id }}-{{ $r['task_id'] }}" type="number" name="target" min="0" max="{{ \App\Models\RoadToSold\RtsLevelTask::MAX_TARGET }}" value="{{ $r['suggested'] }}" class="rounded-md px-2 py-1 text-xs w-20" style="{{ $inputStyle }}">
                                            <button type="submit" class="corex-btn-primary corex-btn-xs">Accept</button>
                                        </form>
                                        <form method="POST" action="{{ route('road-to-sold.funnel.suggestions.ignore', $params) }}">@csrf
                                            <input type="hidden" name="agent_id" value="{{ $b['agent']->id }}"><input type="hidden" name="task_id" value="{{ $r['task_id'] }}"><input type="hidden" name="suggested" value="{{ $r['suggested'] }}">
                                            <button type="submit" class="corex-btn-outline corex-btn-xs">Ignore</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <div class="rounded-md px-6 py-12 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-base font-semibold" style="color: var(--text-primary);">{{ $filtered ? 'Nothing matches this search.' : 'There are no agents to show.' }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $filtered ? 'Clear the search or change a filter to see more.' : 'Agents appear here once they are allowed into Road to Sold.' }}</div>
        </div>
    @endforelse
    {{ $blocks->links() }}
</div>
@endsection
