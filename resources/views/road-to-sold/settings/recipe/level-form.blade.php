@extends('layouts.corex')

{{-- AT-454 phase 1 - Recipe: create / change one level and the tasks inside it. Spec: .ai/specs/road-to-sold.md section 5.2, 8.1, 10.4. --}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $editing = (bool) $level;
@endphp

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">{{ $editing ? 'Change level ' . $level->number : 'New level' }}</h1>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ $editing ? route($rb . 'recipe.levels.update', $level->id) : route($rb . 'recipe.levels.store') }}"
          class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
        @csrf
        @if($editing) @method('PUT') <input type="hidden" name="is_active_present" value="1"> @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="name">Name</label>
                <input id="name" name="name" value="{{ old('name', $level->name ?? '') }}" maxlength="80" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="description">Description</label>
                <input id="description" name="description" value="{{ old('description', $level->description ?? '') }}" maxlength="255" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
            </div>
        </div>
        @if($editing)
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $level->is_active))> On
            </label>
        @endif

        <div class="rounded-md overflow-x-auto" style="border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-3 py-2">In this level</th><th class="px-3 py-2">Task</th><th class="px-3 py-2">Step</th>
                        <th class="px-3 py-2">Target</th><th class="px-3 py-2">Points each</th><th class="px-3 py-2">Needed for sign-off</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tasks as $t)
                    @php $row = $inLevel->get($t->id); $o = old('tasks.' . $t->id); @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-3 py-2"><input type="checkbox" name="tasks[{{ $t->id }}][in]" value="1" @checked($o !== null ? ! empty($o['in']) : ($row && ! $row->trashed()))></td>
                        <td class="px-3 py-2 font-semibold">{{ $t->label }}</td>
                        <td class="px-3 py-2">{{ $t->step_label }}</td>
                        <td class="px-3 py-2">
                            <input type="number" min="0" max="{{ $maxTarget }}" step="1" name="tasks[{{ $t->id }}][target]" value="{{ $o['target'] ?? ($row && ! $row->trashed() ? $row->target : '') }}" placeholder="0"
                                   class="w-24 rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}"> <span class="text-xs" style="color:var(--text-muted);">per {{ strtolower(\App\Models\RoadToSold\RtsTask::UNITS[$t->recurrence_unit] ?? $t->recurrence_unit) }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <input type="number" min="0" max="{{ $maxPoints }}" step="0.01" name="tasks[{{ $t->id }}][points]" value="{{ $o['points'] ?? ($row && ! $row->trashed() ? $row->points : '') }}" placeholder="0"
                                   class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                        </td>
                        <td class="px-3 py-2"><input type="checkbox" name="tasks[{{ $t->id }}][required]" value="1" @checked($o !== null ? ! empty($o['required']) : ($row && ! $row->trashed() && $row->required_for_signoff))></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center" style="color:var(--text-muted);">No tasks yet. Add one on the Tasks list first.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">Save</button>
            <a href="{{ route($rb . 'recipe.index', ['section' => 'levels']) }}" class="corex-btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
