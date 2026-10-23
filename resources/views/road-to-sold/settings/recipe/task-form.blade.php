@extends('layouts.corex')

{{-- AT-454 phase 1 - Recipe: create / change one task. Spec: .ai/specs/road-to-sold.md section 5.1, 10.5. --}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $editing = (bool) $task;
    $type = old('type', $task->type ?? 'core');
    $evKey = old('event_key', $task->event_key ?? '');
    $filter = $task?->event_filter ?? [];
    $fFact = old('filter_fact', array_key_first($filter) ?: '');
    $fVal = old('filter_value', $fFact !== '' && isset($filter[$fFact]) ? $filter[$fFact] : ($filter ? reset($filter) : ''));
    $allFacts = collect($eventFilters)->flatMap(fn ($f) => $f)->all();
@endphp

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">{{ $editing ? 'Change task' : 'New task' }}</h1>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ $editing ? route($rb . 'recipe.tasks.update', $task->id) : route($rb . 'recipe.tasks.store') }}"
          class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);" id="task-form">
        @csrf
        @if($editing) @method('PUT') @endif

        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="label">Task name</label>
            <input id="label" name="label" value="{{ old('label', $task->label ?? '') }}" maxlength="160" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="step_id">Step</label>
                <select id="step_id" name="step_id" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    @foreach($steps as $s)<option value="{{ $s->id }}" @selected(old('step_id', $task->step_id ?? null) == $s->id)>{{ $s->label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="recurrence_unit">Repeats every</label>
                <select id="recurrence_unit" name="recurrence_unit" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    @foreach(\App\Models\RoadToSold\RtsTask::UNITS as $u => $uLabel)<option value="{{ $u }}" @selected(old('recurrence_unit', $task->recurrence_unit ?? 'day') === $u)>{{ $uLabel }}</option>@endforeach
                </select>
            </div>
        </div>

        <fieldset class="space-y-2">
            <legend class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Completed</legend>
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="radio" name="type" value="core" @checked($type === 'core')> Automatically, when CoreX records something
            </label>
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="radio" name="type" value="custom" @checked($type === 'custom')> By the agent, with a form
            </label>
        </fieldset>

        <div data-for="core" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="sm:col-span-3">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="event_key">What completes it</label>
                <select id="event_key" name="event_key" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    <option value="">Pick one</option>
                    @foreach($events as $class => $label)<option value="{{ $class }}" @selected($evKey === $class)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="filter_fact">Only when</label>
                <select id="filter_fact" name="filter_fact" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    <option value="">Always</option>
                    @foreach($allFacts as $fact => $factLabel)<option value="{{ $fact }}" @selected($fFact === $fact)>{{ $factLabel }}</option>@endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="filter_value">Is</label>
                <input id="filter_value" name="filter_value" value="{{ $fVal }}" maxlength="120" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
            </div>
        </div>

        <div data-for="custom" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="capture_kind">The agent records</label>
                <select id="capture_kind" name="capture_kind" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    @foreach(\App\Models\RoadToSold\RtsTask::CAPTURE_KINDS as $k => $kLabel)<option value="{{ $k }}" @selected(old('capture_kind', $task->capture_kind ?? 'none') === $k)>{{ $kLabel }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="evidence_required">Evidence</label>
                <select id="evidence_required" name="evidence_required" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    @foreach(\App\Models\RoadToSold\RtsTask::EVIDENCE as $k => $kLabel)<option value="{{ $k }}" @selected(old('evidence_required', $task->evidence_required ?? 'none') === $k)>{{ $kLabel }}</option>@endforeach
                </select>
            </div>
        </div>

        @if($editing)
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $task->is_active))> On
            </label>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">Save</button>
            <a href="{{ route($rb . 'recipe.index', ['section' => 'tasks']) }}" class="corex-btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('task-form');
    function sync() {
        var type = form.querySelector('input[name=type]:checked');
        type = type ? type.value : 'core';
        form.querySelectorAll('[data-for]').forEach(function (el) { el.style.display = el.getAttribute('data-for') === type ? '' : 'none'; });
    }
    form.querySelectorAll('input[name=type]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
})();
</script>
@endsection
