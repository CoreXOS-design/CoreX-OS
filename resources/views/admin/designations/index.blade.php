@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Designations</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Manage dropdown values used on user profiles and printed documents.
            </p>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-4">
        <div class="flex items-center justify-between gap-4 mb-3">
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Add designation</div>
        </div>

        <form method="POST" action="{{ url('/admin/designations') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-6">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input name="name" required
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="e.g. Property Practitioner">
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                <input name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="e.g. 20">
            </div>

            <div class="md:col-span-2 flex items-center gap-2">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1" checked class="rounded border-slate-300 dark:border-slate-700">
                <span class="text-sm text-slate-700 dark:text-slate-200">Enabled</span>
            </div>

            <div class="md:col-span-1">
                <button class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 text-sm font-semibold">
                    Add
                </button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Current list</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">{{ count($designations ?? []) }} total</div>
        </div>

        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @forelse($designations as $d)
                <div class="p-4">
                    <form method="POST" action="{{ url('/admin/designations/'.$d->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf

                        <div class="md:col-span-6">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                            <input name="name" value="{{ $d->name }}" required
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-3">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$d->sort_order }}"
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox" name="is_enabled" value="1" {{ $d->is_enabled ? 'checked' : '' }} class="rounded border-slate-300 dark:border-slate-700">
                            <span class="text-sm text-slate-700 dark:text-slate-200">Enabled</span>
                        </div>

                        <div class="md:col-span-1 flex gap-2 md:justify-end">
                            <button class="px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 text-sm font-semibold">
                                Save
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="{{ url('/admin/designations/'.$d->id.'/delete') }}"
                          onsubmit="return confirm('Delete this designation? This cannot be undone.');"
                          class="mt-2">
                        @csrf
                        <button class="text-xs font-semibold text-red-600 hover:text-red-700">
                            Delete
                        </button>
                    </form>
                </div>
            @empty
                <div class="p-6 text-sm text-slate-500 dark:text-slate-400">
                    No designations found.
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
