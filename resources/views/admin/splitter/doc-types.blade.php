{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')

@php $isSettings = ($context ?? 'splitter') === 'settings'; @endphp

<div class="w-full space-y-6">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Types</h1>
                <p class="text-sm text-white/60">{{ $isSettings ? 'Manage document categories used across CoreX. Assign listing types to control which document folders appear on a property\'s drive.' : 'Manage the label types used in the PDF Pack Splitter review page.' }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if($isSettings)
                    <a href="{{ route('corex.settings', ['tab' => 'system']) }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">&larr; Back to Settings</a>
                @else
                    <a href="{{ route('tools.pdf_splitter.index') }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">&larr; Back to Splitter</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    @php
        $storeRoute = $isSettings ? route('admin.settings.document-types.store') : route('admin.splitter.doc-types.store');
        $bulkSaveRoute = $isSettings ? route('admin.settings.document-types.bulk-save') : route('admin.splitter.doc-types.bulk-save');
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- LEFT: Add New Type --}}
        <div class="lg:col-span-1 space-y-4">
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Add New Type</h3>
                <form method="POST" action="{{ $storeRoute }}" class="space-y-3">
                    @csrf
                    <div>
                        <label for="dt-label" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Label <span style="color: var(--ds-crimson);">*</span></label>
                        <input id="dt-label" type="text" name="label" value="{{ old('label') }}" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                               placeholder="e.g. Title Deed">
                        @error('label')
                            <p class="mt-1 text-xs" style="color: var(--ds-crimson);">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">Slug is auto-generated from the label.</p>
                    </div>
                    <button type="submit" class="corex-btn-primary w-full">Add Type</button>
                </form>
            </div>

            {{-- Legend --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">How It Works</h3>
                <p class="text-sm leading-relaxed mb-3" style="color: var(--text-secondary);">
                    Assign <strong style="color: var(--text-primary);">listing types</strong> to each document type to control which file upload folders appear on a property's <strong style="color: var(--text-primary);">Drive</strong> tab.
                </p>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-success">For Sale</span>
                        <span>appears on sale listings only</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-info">For Rent</span>
                        <span>appears on rental listings only</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-default">Both</span>
                        <span>appears on all listings</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <span class="ds-badge ds-badge-default">None</span>
                        <span>appears on no listings</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Existing Types --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ $bulkSaveRoute }}" id="bulkForm">
                @csrf

                <div class="mb-3 flex items-center justify-between">
                    <p class="text-sm" style="color: var(--text-muted);">Showing {{ number_format($types->count()) }} document {{ \Illuminate\Support\Str::plural('type', $types->count()) }}</p>
                    <button type="submit" class="corex-btn-primary">Save All Changes</button>
                </div>

                @if($types->isEmpty())
                    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No document types yet</h3>
                        <p class="text-sm" style="color: var(--text-muted);">Add your first document type using the form on the left.</p>
                    </div>
                @else
                    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm ds-table">
                                <thead>
                                    <tr style="background: var(--surface-2);">
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted);">Order</th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Label</th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Slug</th>
                                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Listing Type</th>
                                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Active</th>
                                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted);">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($types as $i => $t)
                                    @php $assigned = $t->listing_types ?? []; @endphp
                                    <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                                        <td class="px-4 py-3">
                                            <input type="hidden" name="types[{{ $i }}][id]" value="{{ $t->id }}">
                                            <input type="number" name="types[{{ $i }}][sort_order]" value="{{ $t->sort_order }}"
                                                   class="w-16 rounded-md px-2 py-1 text-sm text-center"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                   min="0">
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="text" name="types[{{ $i }}][label]" value="{{ $t->label }}"
                                                   class="w-full rounded-md px-2 py-1 text-sm"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">{{ $t->slug }}</td>
                                        <td class="px-4 py-3">
                                            <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                                <button type="button" @click="open = !open"
                                                        class="w-full flex items-center justify-between gap-1 rounded-md px-2 py-1.5 text-sm text-left transition-colors"
                                                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary); min-width: 140px;">
                                                    <span class="truncate">
                                                        @if(empty($assigned) || count($assigned) === 2)
                                                            <span class="ds-badge ds-badge-default">All listings</span>
                                                        @elseif(in_array('sale', $assigned))
                                                            <span class="ds-badge ds-badge-success">For Sale</span>
                                                        @elseif(in_array('rental', $assigned))
                                                            <span class="ds-badge ds-badge-info">For Rent</span>
                                                        @endif
                                                    </span>
                                                    <svg class="w-3 h-3 flex-shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color: var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </button>
                                                <div x-show="open" x-cloak x-transition
                                                     class="absolute z-50 mt-1 w-44 rounded-md py-1"
                                                     style="background: var(--surface); border: 1px solid var(--border); right: 0; box-shadow: 0 8px 24px rgba(0,0,0,0.4);">
                                                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer text-sm transition-colors hover:bg-[var(--surface-2)]"
                                                           style="color: var(--text-secondary);">
                                                        <input type="checkbox"
                                                               name="types[{{ $i }}][listing_types][]"
                                                               value="sale"
                                                               {{ in_array('sale', $assigned) ? 'checked' : '' }}
                                                               class="rounded" style="accent-color: var(--ds-green);">
                                                        <span>For Sale</span>
                                                    </label>
                                                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer text-sm transition-colors hover:bg-[var(--surface-2)]"
                                                           style="color: var(--text-secondary);">
                                                        <input type="checkbox"
                                                               name="types[{{ $i }}][listing_types][]"
                                                               value="rental"
                                                               {{ in_array('rental', $assigned) ? 'checked' : '' }}
                                                               class="rounded" style="accent-color: var(--brand-icon);">
                                                        <span>For Rent</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <select name="types[{{ $i }}][is_active]"
                                                    class="rounded-md px-2 py-1 text-sm"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="1" {{ $t->is_active ? 'selected' : '' }}>Yes</option>
                                                <option value="0" {{ !$t->is_active ? 'selected' : '' }}>No</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <button type="button" onclick="deleteDocType('{{ route('admin.splitter.doc-types.destroy', $t) }}', '{{ addslashes($t->label) }}')"
                                                    class="text-xs font-semibold transition-colors"
                                                    style="color: var(--ds-crimson);"
                                                    title="Delete">Delete</button>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </form>
        </div>
    </div>

</div>

{{-- Hidden delete form --}}
<form id="deleteDocTypeForm" method="POST" action="" style="display:none;">
    @csrf
    @method('DELETE')
</form>

<script>
function deleteDocType(url, label) {
    if (!confirm('Delete \'' + label + '\'?')) return;
    var form = document.getElementById('deleteDocTypeForm');
    form.action = url;
    form.submit();
}
</script>

@endsection
