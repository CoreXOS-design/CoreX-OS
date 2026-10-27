{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Create / rename a training video group. Owner-only. $group is null on create.
    Spec: .ai/specs/training-videos.md §4.2
--}}
@extends('layouts.corex')

@section('title', $group ? 'Rename group' : 'New group')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $group ? 'Rename group' : 'New group' }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    The name is the section heading on the public Training Videos page. Sections are shown A–Z.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.training-videos.groups.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                    </svg>
                    Back to Groups
                </a>
            </div>
        </div>
    </div>

    <form method="POST"
          action="{{ $group ? route('admin.training-videos.groups.update', $group) : route('admin.training-videos.groups.store') }}"
          class="max-w-3xl space-y-4">
        @csrf
        @if ($group)
            @method('PUT')
        @endif

        <div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div>
                <label for="name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Name <span class="text-red-500">*</span>
                </label>
                <input id="name" name="name" type="text" required maxlength="120"
                       value="{{ old('name', $group->name ?? '') }}"
                       placeholder="e.g. Getting started"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs">{{ $group ? 'Save' : 'Create group' }}</button>
            <a href="{{ route('admin.training-videos.groups.index') }}" class="corex-btn-outline text-xs">Cancel</a>
        </div>
    </form>

</div>
@endsection
