{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    .clause-delete-link { transition: color 150ms ease; }
    .clause-delete-link:hover { color: var(--ds-crimson, #c41e3a); }
    .clause-cancel-link { transition: color 150ms ease; }
    .clause-cancel-link:hover { color: var(--text-primary); }
    .clause-input:focus { border-color: var(--brand-button) !important; box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent); outline: none; }
</style>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    <x-list-header
        title="Clause Library"
        :form-action="route('docuperfect.clauses.index')"
        :paginator="$clauses"
        search-placeholder="Search clauses..."
    >
        <x-slot:filters>
            <select name="visibility" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All visibility</option>
                <option value="global" {{ request('visibility') === 'global' ? 'selected' : '' }}>Global</option>
                <option value="branch" {{ request('visibility') === 'branch' ? 'selected' : '' }}>Branch-specific</option>
            </select>
        </x-slot:filters>
        @if($canEdit)
        <x-slot:actions>
            <button type="button" onclick="document.getElementById('addClauseSection').classList.toggle('hidden')" class="corex-btn-primary text-sm">+ New Clause</button>
        </x-slot:actions>
        @endif
    </x-list-header>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Add Clause --}}
    @if($canEdit)
    <div id="addClauseSection" class="hidden rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Add Clause</h3>
        <form method="POST" action="{{ route('docuperfect.clauses.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                    <input name="name" required
                           class="w-full rounded-md px-3 py-2 text-sm focus:outline-none transition-all duration-300 clause-input"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Subject to Viewing">
                </div>
                <div class="flex items-center gap-4 mt-5">
                    <label class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <input type="hidden" name="is_global" value="0">
                        <input type="checkbox" name="is_global" value="1" class="rounded-md" style="border-color: var(--border);"> Global (all branches)
                    </label>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Clause Text</label>
                <textarea name="text" required rows="4"
                          class="w-full rounded-md px-3 py-2 text-sm focus:outline-none transition-all duration-300 clause-input"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                          placeholder="Enter the full clause wording..."></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch Access (if not global)</label>
                <div class="flex flex-wrap gap-3">
                    @foreach($branches as $branch)
                    <label class="flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
                        <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" class="rounded-md" style="border-color: var(--border);">
                        {{ $branch->name }}
                    </label>
                    @endforeach
                </div>
            </div>
            <button class="corex-btn-primary text-sm">Add Clause</button>
        </form>
    </div>
    @endif

    {{-- Clause List --}}
    @if($clauses->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            @if(request('search') || request('visibility'))
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No matching clauses</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">No clauses match your current search and filters.</p>
                <a href="{{ route('docuperfect.clauses.index') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No clauses yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first clause to start building the library.</p>
                @if($canEdit)
                    <button type="button" onclick="document.getElementById('addClauseSection').classList.remove('hidden')" class="corex-btn-primary text-sm">+ New Clause</button>
                @endif
            @endif
        </div>
    @else
        <div class="space-y-3">
            @foreach($clauses as $clause)
            <div class="rounded-md p-4 transition-all duration-300" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ editing: false }">
                <div x-show="!editing">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ $clause->name }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                @if($clause->is_global)
                                    <span class="ds-badge ds-badge-success">Global</span>
                                @else
                                    {{ $clause->branches->pluck('name')->join(', ') ?: 'No branches assigned' }}
                                @endif
                                @if($clause->owner)
                                    &middot; by {{ $clause->owner->name }}
                                @endif
                            </div>
                        </div>
                        @if($canEdit)
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <button @click="editing = true" class="text-xs font-medium transition-all duration-300" style="color: var(--brand-icon);">Edit</button>
                            <form method="POST" action="{{ route('docuperfect.clauses.copy', $clause->id) }}" class="inline">
                                @csrf
                                <button class="text-xs font-medium transition-all duration-300" style="color: var(--brand-icon);">Copy</button>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.clauses.destroy', $clause->id) }}" class="inline" onsubmit="return confirm('Delete this clause?');">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-medium clause-delete-link" style="color: var(--text-muted);">Delete</button>
                            </form>
                        </div>
                        @endif
                    </div>
                    <div class="mt-2 text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ Str::limit($clause->text, 300) }}</div>
                </div>

                @if($canEdit)
                <div x-show="editing" x-cloak>
                    <form method="POST" action="{{ route('docuperfect.clauses.update', $clause->id) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                                <input name="name" value="{{ $clause->name }}" required
                                       class="w-full rounded-md px-3 py-2 text-sm focus:outline-none transition-all duration-300 clause-input"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <div class="flex items-center gap-4 mt-5">
                                <label class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                                    <input type="hidden" name="is_global" value="0">
                                    <input type="checkbox" name="is_global" value="1" {{ $clause->is_global ? 'checked' : '' }} class="rounded-md" style="border-color: var(--border);"> Global
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Clause Text</label>
                            <textarea name="text" required rows="4"
                                      class="w-full rounded-md px-3 py-2 text-sm focus:outline-none transition-all duration-300 clause-input"
                                      style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ $clause->text }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch Access</label>
                            <div class="flex flex-wrap gap-3">
                                @foreach($branches as $branch)
                                <label class="flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" {{ $clause->branches->contains('id', $branch->id) ? 'checked' : '' }} class="rounded-md" style="border-color: var(--border);">
                                    {{ $branch->name }}
                                </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <button class="corex-btn-primary text-sm">Save</button>
                            <button type="button" @click="editing = false" class="text-sm clause-cancel-link" style="color: var(--text-muted);">Cancel</button>
                        </div>
                    </form>
                </div>
                @endif
            </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $clauses->links() }}
        </div>
    @endif

</div>
@endsection
