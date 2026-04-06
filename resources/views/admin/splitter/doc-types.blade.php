@extends('layouts.corex')

@section('corex-content')

@php $isSettings = ($context ?? 'splitter') === 'settings'; @endphp

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <div class="flex items-center justify-between">
            <div>
                <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Document Types</h2>
                <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">{{ $isSettings ? 'Manage document categories used across CoreX. Assign listing types to control which document folders appear on a property\'s drive.' : 'Manage the label types used in the PDF Pack Splitter review page.' }}</div>
            </div>
            @if($isSettings)
            <a href="{{ route('corex.settings', ['tab' => 'system']) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md no-underline transition-colors"
               style="background:rgba(255,255,255,0.12); color:#fff;"
               onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                &larr; Back to Settings
            </a>
            @else
            <a href="{{ route('tools.pdf_splitter.index') }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md no-underline transition-colors"
               style="background:rgba(255,255,255,0.12); color:#fff;"
               onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                &larr; Back to Splitter
            </a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ $errors->first() }}
        </div>
    @endif

    @php
        $storeRoute = $isSettings ? route('admin.settings.document-types.store') : route('admin.splitter.doc-types.store');
        $bulkSaveRoute = $isSettings ? route('admin.settings.document-types.bulk-save') : route('admin.splitter.doc-types.bulk-save');
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- LEFT: Add New Type --}}
        <div class="lg:col-span-1">
            <div class="rounded-md p-4 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;">Add New Type</h3>
                <form method="POST" action="{{ $storeRoute }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Label</label>
                        <input type="text" name="label" value="{{ old('label') }}" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. Title Deed">
                        @error('label')
                            <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p>
                        @enderror
                    </div>
                    <p class="text-xs" style="color:var(--text-muted);">Slug is auto-generated from the label.</p>
                    <button type="submit"
                            class="w-full px-3 py-2 text-sm font-semibold text-white rounded-md transition-colors"
                            style="background:var(--brand-button, #0ea5e9);">
                        Add Type
                    </button>
                </form>
            </div>

            {{-- Legend --}}
            <div class="mt-4 rounded-md p-4 space-y-2" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;">How It Works</h3>
                <p class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                    Assign <strong style="color:var(--text-primary);">listing types</strong> to each document type to control which file upload folders appear on a property's <strong style="color:var(--text-primary);">Drive</strong> tab.
                </p>
                <div class="space-y-1 mt-2">
                    <div class="flex items-center gap-2 text-xs" style="color:var(--text-secondary);">
                        <span class="inline-block w-2 h-2 rounded-full" style="background:#22c55e;"></span>
                        <strong style="color:var(--text-primary);">For Sale</strong> — appears on sale listings only
                    </div>
                    <div class="flex items-center gap-2 text-xs" style="color:var(--text-secondary);">
                        <span class="inline-block w-2 h-2 rounded-full" style="background:#3b82f6;"></span>
                        <strong style="color:var(--text-primary);">For Rent</strong> — appears on rental listings only
                    </div>
                    <div class="flex items-center gap-2 text-xs" style="color:var(--text-secondary);">
                        <span class="inline-block w-2 h-2 rounded-full" style="background:#a855f7;"></span>
                        <strong style="color:var(--text-primary);">Both ticked</strong> — appears on all listings
                    </div>
                    <div class="flex items-center gap-2 text-xs" style="color:var(--text-secondary);">
                        <span class="inline-block w-2 h-2 rounded-full" style="background:#94a3b8;"></span>
                        <strong style="color:var(--text-primary);">None ticked</strong> — appears on no listings
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Existing Types --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ $bulkSaveRoute }}" id="bulkForm">
                @csrf

                <div class="mb-3 flex items-center justify-between">
                    <p class="text-xs" style="color:var(--text-muted);">{{ $types->count() }} document type(s)</p>
                    <button type="submit"
                            class="px-4 py-2 text-sm font-semibold text-white rounded-md transition-colors"
                            style="background:var(--brand-button, #0ea5e9);">
                        Save All Changes
                    </button>
                </div>

                @if($types->isEmpty())
                    <div class="rounded-md p-6 text-center" style="background:var(--surface); border:1px dashed var(--border-hover);">
                        <p class="text-sm italic" style="color:var(--text-muted);">No document types yet. Add one to get started.</p>
                    </div>
                @else
                    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                        <table class="w-full text-xs text-left">
                            <thead>
                                <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                    <th class="px-3 py-2.5 w-14 text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Order</th>
                                    <th class="px-3 py-2.5 text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Label</th>
                                    <th class="px-3 py-2.5 w-20 text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Slug</th>
                                    <th class="px-3 py-2.5 text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Listing Type</th>
                                    <th class="px-3 py-2.5 w-16 text-center text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Active</th>
                                    <th class="px-3 py-2.5 w-14 text-right text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Del</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($types as $i => $t)
                                @php $assigned = $t->listing_types ?? []; @endphp
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td class="px-3 py-2">
                                        <input type="hidden" name="types[{{ $i }}][id]" value="{{ $t->id }}">
                                        <input type="number" name="types[{{ $i }}][sort_order]" value="{{ $t->sort_order }}"
                                               class="w-14 rounded-md px-2 py-1 text-xs text-center"
                                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                               min="0">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" name="types[{{ $i }}][label]" value="{{ $t->label }}"
                                               class="w-full rounded-md px-2 py-1 text-xs"
                                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs" style="color:var(--text-muted);">{{ $t->slug }}</td>
                                    <td class="px-3 py-2">
                                        <div x-data="{ open: false }" class="relative">
                                            <button type="button" @click="open = !open" @click.outside="open = false"
                                                    class="w-full flex items-center justify-between gap-1 rounded-md px-2 py-1 text-xs text-left transition-colors"
                                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary); min-width:120px;">
                                                <span class="truncate">
                                                    @if(empty($assigned) || count($assigned) === 2)
                                                        <span style="color:var(--text-muted);">All listings</span>
                                                    @elseif(in_array('sale', $assigned))
                                                        <span style="color:#22c55e;">For Sale</span>
                                                    @elseif(in_array('rental', $assigned))
                                                        <span style="color:#3b82f6;">For Rent</span>
                                                    @endif
                                                </span>
                                                <svg class="w-3 h-3 flex-shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                            <div x-show="open" x-cloak x-transition
                                                 class="absolute z-50 mt-1 w-40 rounded-md shadow-lg py-1"
                                                 style="background:var(--surface); border:1px solid var(--border); right:0;">
                                                <label class="flex items-center gap-2 px-3 py-2 cursor-pointer transition-colors text-xs hover:bg-white/5"
                                                       style="color:var(--text-secondary);">
                                                    <input type="checkbox"
                                                           name="types[{{ $i }}][listing_types][]"
                                                           value="sale"
                                                           {{ in_array('sale', $assigned) ? 'checked' : '' }}
                                                           class="rounded" style="accent-color:#22c55e;">
                                                    <span class="inline-block w-2 h-2 rounded-full" style="background:#22c55e;"></span>
                                                    For Sale
                                                </label>
                                                <label class="flex items-center gap-2 px-3 py-2 cursor-pointer transition-colors text-xs hover:bg-white/5"
                                                       style="color:var(--text-secondary);">
                                                    <input type="checkbox"
                                                           name="types[{{ $i }}][listing_types][]"
                                                           value="rental"
                                                           {{ in_array('rental', $assigned) ? 'checked' : '' }}
                                                           class="rounded" style="accent-color:#3b82f6;">
                                                    <span class="inline-block w-2 h-2 rounded-full" style="background:#3b82f6;"></span>
                                                    For Rent
                                                </label>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <select name="types[{{ $i }}][is_active]"
                                                class="rounded-md px-1 py-1 text-xs"
                                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                            <option value="1" {{ $t->is_active ? 'selected' : '' }}>Yes</option>
                                            <option value="0" {{ !$t->is_active ? 'selected' : '' }}>No</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button type="button" onclick="deleteDocType('{{ route('admin.splitter.doc-types.destroy', $t) }}', '{{ addslashes($t->label) }}')"
                                                class="text-xs font-medium transition-colors" style="color:#f87171;"
                                                onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#f87171'"
                                                title="Delete">&times;</button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
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
