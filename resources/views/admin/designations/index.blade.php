@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Designations</h2>
        <div class="text-sm text-white/60">Manage dropdown values used on user profiles and printed documents.</div>
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
    <div class="ds-status-card p-4">
        <h3 class="ds-section-header mb-3">Add designation</h3>

        <form method="POST" action="{{ url('/admin/designations') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-6">
                <label class="block text-xs mb-1" style="color:var(--text-secondary)">Name</label>
                <input name="name" required
                       class="w-full rounded-lg border px-3 py-2 text-sm"
                       style="border-color:var(--border); background:var(--surface); color:var(--text-primary)"
                       placeholder="e.g. Property Practitioner">
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs mb-1" style="color:var(--text-secondary)">Sort order</label>
                <input name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-lg border px-3 py-2 text-sm"
                       style="border-color:var(--border); background:var(--surface); color:var(--text-primary)"
                       placeholder="e.g. 20">
            </div>

            <div class="md:col-span-2 flex items-center gap-2">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1" checked class="rounded" style="border-color:var(--border)">
                <span class="text-sm" style="color:var(--text-secondary)">Enabled</span>
            </div>

            <div class="md:col-span-1">
                <button class="w-full corex-btn-primary text-sm">
                    Add
                </button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-2xl border overflow-hidden" style="border-color:var(--border); background:var(--surface)">
        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
            <div class="text-sm font-semibold" style="color:var(--text-primary)">Current list</div>
            <div class="text-xs" style="color:var(--text-muted)">{{ count($designations ?? []) }} total</div>
        </div>

        <div class="divide-y divide-[color:var(--border)]">
            @forelse($designations as $d)
                <div class="p-4">
                    <form method="POST" action="{{ url('/admin/designations/'.$d->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf

                        <div class="md:col-span-6">
                            <label class="block text-xs mb-1" style="color:var(--text-secondary)">Name</label>
                            <input name="name" value="{{ $d->name }}" required
                                   class="w-full rounded-lg border px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                        </div>

                        <div class="md:col-span-3">
                            <label class="block text-xs mb-1" style="color:var(--text-secondary)">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$d->sort_order }}"
                                   class="w-full rounded-lg border px-3 py-2 text-sm"
                                   style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                        </div>

                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox" name="is_enabled" value="1" {{ $d->is_enabled ? 'checked' : '' }} class="rounded" style="border-color:var(--border)">
                            <span class="text-sm" style="color:var(--text-secondary)">Enabled</span>
                        </div>

                        <div class="md:col-span-1 flex gap-2 md:justify-end">
                            <button class="px-3 py-2 rounded-lg text-sm font-semibold corex-btn-primary">
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
                <div class="p-6 text-sm" style="color:var(--text-muted)">
                    No designations found.
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
