{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded, full width) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Named Fields</h1>
                <p class="text-sm text-white/60">Define smart fields that sync values across documents in a pack.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('docuperfect.settings.types') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Document Types
                </a>
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Back
                </a>
            </div>
        </div>
    </div>

    {{-- Flash + validation messages (most success/error feedback is also surfaced
         by the global toast system; these page-level alerts are the fallback). --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="ds-section-header mb-3">Add named field</h3>

        <form method="POST" action="{{ route('docuperfect.settings.namedFields.store') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-4">
                <label for="nf-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                <input id="nf-name" name="name" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. Seller Name">
            </div>

            <div class="md:col-span-2">
                <label for="nf-type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Field type</label>
                <select id="nf-type" name="field_type"
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="text">Text</option>
                    <option value="date">Date</option>
                    <option value="selection">Selection</option>
                </select>
            </div>

            <div class="md:col-span-3">
                <label for="nf-options" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Options (comma-separated, for selection type)</label>
                <input id="nf-options" name="default_options"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. Yes, No">
            </div>

            <div class="md:col-span-2">
                <label for="nf-sort" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sort order</label>
                <input id="nf-sort" name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="0">
            </div>

            <div class="md:col-span-1">
                <button class="w-full corex-btn-primary text-sm">Add</button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Current named fields</div>
            <div class="text-xs" style="color: var(--text-muted);">{{ number_format(count($fields)) }} total</div>
        </div>

        <div>
            @forelse($fields as $field)
                <div class="p-4" style="border-top: 1px solid var(--border);">
                    <form method="POST" action="{{ route('docuperfect.settings.namedFields.update', $field->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        @method('PUT')

                        <div class="md:col-span-4">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                            <input name="name" value="{{ $field->name }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Field type</label>
                            <select name="field_type"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="text" {{ $field->field_type === 'text' ? 'selected' : '' }}>Text</option>
                                <option value="date" {{ $field->field_type === 'date' ? 'selected' : '' }}>Date</option>
                                <option value="selection" {{ $field->field_type === 'selection' ? 'selected' : '' }}>Selection</option>
                            </select>
                        </div>

                        <div class="md:col-span-3">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Options</label>
                            <input name="default_options" value="{{ is_array($field->default_options) ? implode(', ', $field->default_options) : '' }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$field->sort_order }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>

                        <div class="md:col-span-1">
                            <button class="w-full corex-btn-outline text-sm">Save</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('docuperfect.settings.namedFields.destroy', $field->id) }}"
                          onsubmit="return confirm('Archive this named field? An admin can restore it later.');"
                          class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs font-semibold transition-colors"
                                style="color: var(--ds-crimson, #c41e3a);">
                            Delete
                        </button>
                    </form>
                </div>
            @empty
                <div class="py-12 px-6 text-center" style="border-top: 1px solid var(--border);">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No named fields yet</h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first named field to sync values across documents in a pack.</p>
                    <a href="#nf-name" class="corex-btn-primary">Add named field</a>
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
