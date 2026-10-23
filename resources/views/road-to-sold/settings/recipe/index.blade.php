@extends('layouts.corex')

{{--
    AT-454 phase 1 - Road to Sold settings -> Recipe (steps, tasks, levels).
    Spec: .ai/specs/road-to-sold.md section 5, 10.4, 13.1.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    No Alpine on this page: every row is a plain form; the confirm prompts are inline onclick handlers.
    Screen-text rule (Johan): every line is data or a control.
--}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $f = $filters ?? [];
    $statusChoices = ['live' => 'Not archived', 'on' => 'On', 'off' => 'Switched off', 'archived' => 'Archived', 'all' => 'Everything'];
    $tabLink = fn (string $key) => route($rb . 'recipe.index', ['section' => $key]);
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - recipe</h1>
        {{-- The rules for WHEN CoreX credits an activity by itself (calendar events, captured contacts, deals ...) are still set on the old Setup screen's Auto tab. --}}
        @if(auth()->user()?->hasPermission('manage_activity_mappings') && \Illuminate\Support\Facades\Route::has('admin.daily-activities.setup'))
            <a href="{{ route('admin.daily-activities.setup', ['tab' => 'auto']) }}" class="corex-btn-outline corex-btn-xs">Automatic credit rules</a>
        @endif
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

    @if(! $started)
        {{-- Nothing is written until the button is pressed: this is what the standard recipe would contain. --}}
        <div class="rounded-md p-4 flex flex-wrap items-center gap-4" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">You have not started your recipe yet. This is the standard one.</div>
            <form method="POST" action="{{ route($rb . 'recipe.start') }}" class="ml-auto">
                @csrf
                <button type="submit" class="corex-btn-primary">Start with the standard recipe</button>
            </form>
        </div>

        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-4 py-2">Step</th><th class="px-3 py-2">Group</th><th class="px-3 py-2">Tasks</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($previewSteps as $key => [$group, $label])
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-2 font-semibold">{{ $label }}</td>
                        <td class="px-3 py-2">{{ $groupLabels[$group] }}</td>
                        <td class="px-3 py-2">
                            {{ collect($previewTasks)->where('step', $key)->pluck('label')->implode(', ') ?: '-' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="flex flex-wrap gap-2 items-center">
            @foreach(['tasks' => 'Tasks', 'steps' => 'Steps', 'levels' => 'Levels'] as $key => $label)
                <a href="{{ $tabLink($key) }}" class="px-3 py-1.5 rounded-md text-sm font-semibold"
                   style="{{ $section === $key ? 'background:var(--brand-icon, #0ea5e9); color:#fff;' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);' }}">{{ $label }}</a>
            @endforeach
            @if($section === 'tasks')
                <a href="{{ route($rb . 'recipe.tasks.create') }}" class="corex-btn-primary ml-auto">New task</a>
            @elseif($section === 'levels')
                <a href="{{ route($rb . 'recipe.levels.create') }}" class="corex-btn-primary ml-auto">New level</a>
            @endif
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route($rb . 'recipe.index') }}" class="props-filter-panel rounded-md p-4 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end"
              style="background:var(--surface); border:1px solid var(--border);">
            <input type="hidden" name="section" value="{{ $section }}">
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="q">Search</label>
                <input class="list-header-filter {{ ! empty($f['q']) ? 'is-active' : '' }} w-full" id="q" name="q" value="{{ $f['q'] }}" type="search"
                       placeholder="{{ $section === 'tasks' ? 'Task or step name' : ($section === 'steps' ? 'Step name' : 'Level or task name') }}">
            </div>
            @if($section === 'tasks')
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="step">Step</label>
                    <select class="list-header-filter {{ $f['stepId'] ? 'is-active' : '' }} w-full" id="step" name="step">
                        <option value="">All steps</option>
                        @foreach($steps as $s)<option value="{{ $s->id }}" @selected($f['stepId'] == $s->id)>{{ $s->label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="type">Kind</label>
                    <select class="list-header-filter {{ $f['type'] !== 'all' ? 'is-active' : '' }} w-full" id="type" name="type">
                        <option value="all">Automatic and captured</option>
                        <option value="core" @selected($f['type'] === 'core')>Automatic</option>
                        <option value="custom" @selected($f['type'] === 'custom')>Captured</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="unit">Repeats</label>
                    <select class="list-header-filter {{ $f['unit'] !== 'all' ? 'is-active' : '' }} w-full" id="unit" name="unit">
                        <option value="all">Any</option>
                        @foreach(\App\Models\RoadToSold\RtsTask::UNITS as $u => $uLabel)<option value="{{ $u }}" @selected($f['unit'] === $u)>{{ $uLabel }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="status">Show</label>
                <select class="list-header-filter {{ $f['status'] !== 'live' ? 'is-active' : '' }} w-full" id="status" name="status">
                    @foreach($statusChoices as $v => $l)<option value="{{ $v }}" @selected($f['status'] === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="sort">Sort by</label>
                <select class="list-header-filter w-full" id="sort" name="sort">
                    @php
                        $sortChoices = match($section) {
                            'steps'  => ['position' => 'Position', 'label' => 'Name (A-Z)'],
                            'levels' => ['number' => 'Level number', 'name' => 'Name (A-Z)'],
                            default  => ['step' => 'Step, then order', 'label' => 'Name (A-Z)', 'unit' => 'How often', 'type' => 'Kind'],
                        };
                    @endphp
                    @foreach($sortChoices as $v => $l)<option value="{{ $v }}" @selected($f['sort'] === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="sm:col-span-6 flex items-center gap-3">
                <button type="submit" class="corex-btn-primary">Apply</button>
                <a href="{{ route($rb . 'recipe.index', ['section' => $section]) }}" class="corex-btn-outline">Clear</a>
            </div>
        </form>

        @if($section === 'tasks')
            <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="color:var(--text-primary);">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                            <th class="px-4 py-2">Task</th><th class="px-3 py-2">Step</th><th class="px-3 py-2">Completed by</th>
                            <th class="px-3 py-2">Repeats</th><th class="px-3 py-2">In levels</th><th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $t)
                        @php $levels = $levelsByTask[$t->id] ?? []; @endphp
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-2">
                                <div class="font-semibold">{{ $t->label }}</div>
                                <div class="text-xs" style="color:var(--text-muted);">
                                    {{ $t->trashed() ? 'archived' : ($t->is_active ? 'on' : 'switched off') }}
                                </div>
                            </td>
                            <td class="px-3 py-2">{{ $t->step_label }}</td>
                            <td class="px-3 py-2">
                                @if($t->isCore()){{ \App\Services\RoadToSold\RecipeCatalogue::eventLabel($t->event_key) }}@if($t->event_filter) ({{ collect($t->event_filter)->map(fn ($v, $k) => "$k = $v")->implode(', ') }})@endif
                                @else The agent, with a form @endif
                            </td>
                            <td class="px-3 py-2">{{ \App\Models\RoadToSold\RtsTask::UNITS[$t->recurrence_unit] ?? $t->recurrence_unit }}</td>
                            <td class="px-3 py-2">{{ $levels ? implode(', ', $levels) : 'None yet' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    @if($t->trashed())
                                        <form method="POST" action="{{ route($rb . 'recipe.tasks.restore', $t->id) }}">@csrf <button type="submit" class="corex-btn-primary">Bring back</button></form>
                                    @else
                                        <a href="{{ route($rb . 'recipe.tasks.edit', $t->id) }}" class="corex-btn-outline">Edit</a>
                                        <form method="POST" action="{{ route($rb . 'recipe.tasks.archive', $t->id) }}">@csrf @method('DELETE')
                                            <button type="submit" class="corex-btn-outline" onclick="return confirm('Archive this task? Completions already recorded are kept, and you can bring the task back.');">Archive</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                            @if($emptyFiltered) No tasks match those filters. <a class="underline" href="{{ route($rb . 'recipe.index', ['section' => 'tasks']) }}">Clear the filters</a>.
                            @else No tasks yet. Use "New task" to add one. @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @elseif($section === 'steps')
            <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="color:var(--text-primary);">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                            <th class="px-4 py-2">Name</th><th class="px-3 py-2">Group</th><th class="px-3 py-2">Position</th>
                            <th class="px-3 py-2">On</th><th class="px-3 py-2 text-right">Tasks</th><th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $s)
                        @php $fid = 'rts-step-' . $s->id; $gone = $s->trashed(); @endphp
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-2"><input form="{{ $fid }}" name="label" value="{{ old('label', $s->label) }}" maxlength="120" @disabled($gone) class="w-56 rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}"></td>
                            <td class="px-3 py-2">{{ $groupLabels[$s->group] ?? $s->group }}</td>
                            <td class="px-3 py-2"><input form="{{ $fid }}" type="number" min="1" max="99" name="position" value="{{ $s->position }}" @disabled($gone) class="w-20 rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}"></td>
                            <td class="px-3 py-2"><input form="{{ $fid }}" type="checkbox" name="is_enabled" value="1" @checked($s->is_enabled) @disabled($gone)></td>
                            <td class="px-3 py-2 text-right">{{ $s->tasks_count }}</td>
                            <td class="px-4 py-2">
                                <form id="{{ $fid }}" method="POST" action="{{ route($rb . 'recipe.steps.update', $s->id) }}" class="flex flex-wrap gap-2">
                                    @csrf @method('PUT')
                                    @if($gone)
                                        <button type="submit" formaction="{{ route($rb . 'recipe.steps.restore', $s->id) }}" name="_method" value="POST" class="corex-btn-primary">Bring back</button>
                                    @else
                                        <button type="submit" class="corex-btn-primary">Save</button>
                                        <button type="submit" formaction="{{ route($rb . 'recipe.steps.archive', $s->id) }}" name="_method" value="DELETE" class="corex-btn-outline"
                                                onclick="return confirm('Archive this step? Its tasks stop completing while it is archived, and you can bring it back.');">Archive</button>
                                    @endif
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                            @if($emptyFiltered) No steps match those filters. <a class="underline" href="{{ route($rb . 'recipe.index', ['section' => 'steps']) }}">Clear the filters</a>.
                            @else No steps. @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="color:var(--text-primary);">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                            <th class="px-4 py-2">Level</th><th class="px-3 py-2">Description</th><th class="px-3 py-2 text-right">Tasks</th><th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $l)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-2">
                                <div class="font-semibold">{{ $l->number }}. {{ $l->name }}</div>
                                <div class="text-xs" style="color:var(--text-muted);">{{ $l->trashed() ? 'archived' : ($l->is_active ? 'on' : 'switched off') }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $l->description ?: '-' }}</td>
                            <td class="px-3 py-2 text-right">{{ $l->level_tasks_count }}</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    @if($l->trashed())
                                        <form method="POST" action="{{ route($rb . 'recipe.levels.restore', $l->id) }}">@csrf <button type="submit" class="corex-btn-primary">Bring back</button></form>
                                    @else
                                        <a href="{{ route($rb . 'recipe.levels.edit', $l->id) }}" class="corex-btn-outline">Edit</a>
                                        <form method="POST" action="{{ route($rb . 'recipe.levels.archive', $l->id) }}">@csrf @method('DELETE')
                                            <button type="submit" class="corex-btn-outline" onclick="return confirm('Archive this level? You can bring it back.');">Archive</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                            @if($emptyFiltered) No levels match those filters. <a class="underline" href="{{ route($rb . 'recipe.index', ['section' => 'levels']) }}">Clear the filters</a>.
                            @else No levels yet. Use "New level" to add one. @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif
        {{ $rows->links() }}
    @endif
</div>
@endsection
