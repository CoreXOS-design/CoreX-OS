@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Document Types</h2>
            <div class="text-sm text-white/60">Manage categories for document templates.</div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('docuperfect.settings.namedFields') }}" class="text-sm text-white/70 hover:text-white">Named Fields</a>
            <a href="{{ route('docuperfect.dashboard') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- Flash messages handled by global toast system --}}

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add --}}
    <div class="ds-status-card p-4">
        <h3 class="ds-section-header mb-3">Add document type</h3>

        <form method="POST" action="{{ route('docuperfect.settings.types.store') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-7">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input name="name" required
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="e.g. Mandates">
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                <input name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="0">
            </div>

            <div class="md:col-span-2">
                <button class="w-full corex-btn-primary text-sm">Add</button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Current types</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">{{ count($types) }} total</div>
        </div>

        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @forelse($types as $type)
                <div class="p-4">
                    <form method="POST" action="{{ route('docuperfect.settings.types.update', $type->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        @method('PUT')

                        <div class="md:col-span-6">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                            <input name="name" value="{{ $type->name }}" required
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-3">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$type->sort_order }}"
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-1">
                            <button class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 text-sm font-semibold">
                                Save
                            </button>
                        </div>

                        <div class="md:col-span-2 flex items-end gap-2">
                            <span class="text-xs text-slate-400">{{ $type->templates()->count() }} template{{ $type->templates()->count() !== 1 ? 's' : '' }}</span>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('docuperfect.settings.types.destroy', $type->id) }}"
                          onsubmit="return confirm('Delete this document type? This cannot be undone.');"
                          class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs font-semibold text-red-600 hover:text-red-700" {{ $type->templates()->count() > 0 ? 'disabled title=Cannot delete — templates assigned' : '' }}>
                            Delete
                        </button>
                    </form>
                </div>
            @empty
                <div class="p-6 text-sm text-slate-500 dark:text-slate-400">
                    No document types found.
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
