@extends('layouts.nexus')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Clause Library</h2>
            <div class="text-sm text-white/60">Reusable conditional clauses for document templates.</div>
        </div>
        @if($canEdit)
        <button type="button" onclick="document.getElementById('addClauseSection').classList.toggle('hidden')" class="nexus-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">
            + New Clause
        </button>
        @endif
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add Clause --}}
    @if($canEdit)
    <div id="addClauseSection" class="ds-status-card p-4 hidden">
        <h3 class="ds-section-header mb-3">Add Clause</h3>
        <form method="POST" action="{{ route('docuperfect.clauses.store') }}" class="space-y-3">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-600 mb-1">Name</label>
                    <input name="name" required class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="e.g. Subject to Viewing">
                </div>
                <div class="flex items-center gap-4 mt-5">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="is_global" value="0">
                        <input type="checkbox" name="is_global" value="1" class="rounded border-slate-300"> Global (all branches)
                    </label>
                </div>
            </div>
            <div>
                <label class="block text-xs text-slate-600 mb-1">Clause Text</label>
                <textarea name="text" required rows="4" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm" placeholder="Enter the full clause wording..."></textarea>
            </div>
            <div>
                <label class="block text-xs text-slate-600 mb-1">Branch Access (if not global)</label>
                <div class="flex flex-wrap gap-3">
                    @foreach($branches as $branch)
                    <label class="flex items-center gap-1 text-sm text-slate-700">
                        <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" class="rounded border-slate-300">
                        {{ $branch->name }}
                    </label>
                    @endforeach
                </div>
            </div>
            <button class="nexus-btn-primary text-sm">Add Clause</button>
        </form>
    </div>
    @endif

    {{-- Clause List --}}
    @if($clauses->isEmpty())
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">No clauses yet.</div>
        </div>
    @else
        <div class="space-y-3">
            @foreach($clauses as $clause)
            <div class="ds-status-card p-4" x-data="{ editing: false }">
                <div x-show="!editing">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-slate-900 text-sm">{{ $clause->name }}</div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                @if($clause->is_global)
                                    <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                                @else
                                    {{ $clause->branches->pluck('name')->join(', ') ?: 'No branches assigned' }}
                                @endif
                                @if($clause->owner)
                                    &middot; by {{ $clause->owner->name }}
                                @endif
                            </div>
                        </div>
                        @if($canEdit)
                        <div class="flex items-center gap-2">
                            <button @click="editing = true" class="ds-link text-xs">Edit</button>
                            <form method="POST" action="{{ route('docuperfect.clauses.copy', $clause->id) }}" class="inline">
                                @csrf
                                <button class="ds-link text-xs">Copy</button>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.clauses.destroy', $clause->id) }}" class="inline" onsubmit="return confirm('Delete this clause?');">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs text-slate-400 hover:text-red-600">Delete</button>
                            </form>
                        </div>
                        @endif
                    </div>
                    <div class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ Str::limit($clause->text, 300) }}</div>
                </div>

                @if($canEdit)
                <div x-show="editing" x-cloak>
                    <form method="POST" action="{{ route('docuperfect.clauses.update', $clause->id) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-slate-600 mb-1">Name</label>
                                <input name="name" value="{{ $clause->name }}" required class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                            </div>
                            <div class="flex items-center gap-4 mt-5">
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="hidden" name="is_global" value="0">
                                    <input type="checkbox" name="is_global" value="1" {{ $clause->is_global ? 'checked' : '' }} class="rounded border-slate-300"> Global
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-600 mb-1">Clause Text</label>
                            <textarea name="text" required rows="4" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">{{ $clause->text }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-600 mb-1">Branch Access</label>
                            <div class="flex flex-wrap gap-3">
                                @foreach($branches as $branch)
                                <label class="flex items-center gap-1 text-sm text-slate-700">
                                    <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" {{ $clause->branches->contains('id', $branch->id) ? 'checked' : '' }} class="rounded border-slate-300">
                                    {{ $branch->name }}
                                </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button class="nexus-btn-primary text-sm">Save</button>
                            <button type="button" @click="editing = false" class="text-sm text-slate-500 hover:text-slate-700">Cancel</button>
                        </div>
                    </form>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    @endif

</div>
@endsection
