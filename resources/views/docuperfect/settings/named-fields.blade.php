@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Named Fields</h2>
            <div class="text-sm text-white/60">Define smart fields that sync values across documents in a pack.</div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('docuperfect.settings.types') }}" class="text-sm text-white/70 hover:text-white">Document Types</a>
            <a href="{{ route('docuperfect.dashboard') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
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

    {{-- Add --}}
    <div class="ds-status-card p-4">
        <h3 class="ds-section-header mb-3">Add named field</h3>

        <form method="POST" action="{{ route('docuperfect.settings.namedFields.store') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-4">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input name="name" required
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="e.g. Seller Name">
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Field type</label>
                <select name="field_type"
                        class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="text">Text</option>
                    <option value="date">Date</option>
                    <option value="selection">Selection</option>
                </select>
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Options (comma-separated, for selection type)</label>
                <input name="default_options"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="e.g. Yes, No">
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                <input name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="0">
            </div>

            <div class="md:col-span-1">
                <button class="w-full corex-btn-primary text-sm">Add</button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Current named fields</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">{{ count($fields) }} total</div>
        </div>

        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @forelse($fields as $field)
                <div class="p-4">
                    <form method="POST" action="{{ route('docuperfect.settings.namedFields.update', $field->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        @method('PUT')

                        <div class="md:col-span-4">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                            <input name="name" value="{{ $field->name }}" required
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Field type</label>
                            <select name="field_type"
                                    class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                                <option value="text" {{ $field->field_type === 'text' ? 'selected' : '' }}>Text</option>
                                <option value="date" {{ $field->field_type === 'date' ? 'selected' : '' }}>Date</option>
                                <option value="selection" {{ $field->field_type === 'selection' ? 'selected' : '' }}>Selection</option>
                            </select>
                        </div>

                        <div class="md:col-span-3">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Options</label>
                            <input name="default_options" value="{{ is_array($field->default_options) ? implode(', ', $field->default_options) : '' }}"
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$field->sort_order }}"
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-1">
                            <button class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 text-sm font-semibold">
                                Save
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('docuperfect.settings.namedFields.destroy', $field->id) }}"
                          onsubmit="return confirm('Delete this named field? This cannot be undone.');"
                          class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                    </form>
                </div>
            @empty
                <div class="p-6 text-sm text-slate-500 dark:text-slate-400">
                    No named fields defined yet.
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
