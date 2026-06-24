@extends('layouts.corex')

@section('corex-content')

@php
    $docTypeLabels = $documentTypes->pluck('label', 'key')->toArray();
@endphp

{{-- Page Header (Pattern A — branded) --}}
<div class="rounded-md px-6 py-5 mb-6" style="background: var(--brand-default, #0b2a4a);">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div data-tour="docs-intro">
            <h1 class="text-xl font-bold text-white leading-tight">Document Library</h1>
            <p class="text-sm text-white/60">Upload, browse and manage shared documents.</p>
        </div>
        <div class="flex items-center gap-2">
            @include('layouts.partials.tour-header-launcher')
        </div>
        @if($presentation)
            <div class="flex items-center gap-2">
                <a href="{{ $returnUrl ?? route('presentations.show', $presentation) . '#documents' }}"
                   class="corex-btn-outline">
                    Back to Presentation #{{ $presentation->id }}
                </a>
            </div>
        @endif
    </div>
</div>

{{-- Attach toolbar --}}
@if($presentation)
<div class="rounded-md px-4 py-3 text-sm flex items-start gap-3 mb-4"
     style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
            color: var(--text-primary);">
    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--brand-icon);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div class="flex-1">
        <strong>Attaching to: {{ $presentation->title }} (#{{ $presentation->id }}).</strong>
        <span style="color: var(--text-secondary);">Select documents below and click "Attach Selected" to link them to this presentation.</span>
    </div>
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    {{-- LEFT: Upload + Filters --}}
    <div class="lg:col-span-1 space-y-4">

        {{-- Upload --}}
        <div id="upload-library" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Upload to Library</h2>
            <form method="POST" action="{{ route('documents.library.upload') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Document Type</label>
                    <select name="doc_type" class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" required>
                        <option value="" disabled selected>Select type...</option>
                        @foreach($docTypeLabels as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('doc_type')
                        <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title (optional)</label>
                    <input type="text" name="title" value="{{ old('title') }}"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="Descriptive title...">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">File</label>
                    <input type="file" name="file"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);"
                           required>
                    @error('file')
                        <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="corex-btn-primary w-full">
                    Upload
                </button>
            </form>
        </div>

        {{-- Filters --}}
        <div class="rounded-md p-4" data-tour="docs-filter" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Filter</h2>
            <form method="GET" action="{{ route('documents.library.index') }}" class="space-y-3">
                @if($presentationId)
                    <input type="hidden" name="presentation_id" value="{{ $presentationId }}">
                @endif
                @if($returnUrl)
                    <input type="hidden" name="return" value="{{ $returnUrl }}">
                @endif

                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                    <input type="text" name="q" value="{{ request('q') }}"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="Name or title...">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Doc Type</label>
                    <select name="doc_type" class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">All types</option>
                        @foreach($docTypes as $dt)
                            <option value="{{ $dt }}" {{ request('doc_type') === $dt ? 'selected' : '' }}>
                                {{ $docTypeLabels[$dt] ?? $dt }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Uploaded By</label>
                    <select name="user_id" class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">All users</option>
                        @foreach($uploaders as $u)
                            <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>
                                {{ $u->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="corex-btn-outline w-full">
                    Apply Filters
                </button>
                <a href="{{ route('documents.library.index', array_filter(['presentation_id' => $presentationId, 'return' => $returnUrl])) }}"
                   class="block text-center text-xs transition-all duration-300 hover:underline" style="color: var(--text-muted);">Clear Filters</a>
            </form>
        </div>

        {{-- Manage Document Types (collapsible) --}}
        <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ open: false }">
            <button @click="open = !open" type="button"
                    class="w-full flex items-center justify-between p-4 text-left transition-all duration-300">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Manage Document Types</h2>
                <svg class="w-4 h-4 transition-transform duration-300" :class="{ 'rotate-180': open }"
                     style="color: var(--text-muted);"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="open" x-cloak class="px-4 pb-4">
                <div class="space-y-2 mb-3">
                    @foreach($documentTypes as $dt)
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('documents.library.types.update', $dt) }}" class="flex-1 flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <input type="text" name="label" value="{{ $dt->label }}"
                                       class="flex-1 rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <button type="submit" class="text-xs font-medium transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);">Save</button>
                            </form>
                            <form method="POST" action="{{ route('documents.library.types.destroy', $dt) }}"
                                  onsubmit="return confirm('Delete type \'{{ $dt->label }}\'?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium transition-all duration-300 hover:opacity-80" style="color: var(--ds-crimson);" title="Delete">&times;</button>
                            </form>
                        </div>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('documents.library.types.store') }}" class="flex items-center gap-2">
                    @csrf
                    <input type="text" name="label" placeholder="New type name..." required
                           class="flex-1 rounded-md px-3 py-2 text-sm transition-all duration-300 focus:outline-none"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <button type="submit" class="corex-btn-primary">
                        Add
                    </button>
                </form>
                @error('label')
                    <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- RIGHT: Document list --}}
    <div class="lg:col-span-3">
        @if($presentation)
        <form method="POST" action="{{ route('documents.library.attach') }}" id="attachForm">
            @csrf
            <input type="hidden" name="presentation_id" value="{{ $presentationId }}">
            <input type="hidden" name="return" value="{{ $returnUrl ?? route('presentations.show', $presentation) . '#documents' }}">

            <div class="mb-3 flex items-center justify-between">
                <p class="text-sm" style="color: var(--text-secondary);">Showing {{ number_format($items->count()) }} of {{ number_format($items->total()) }} document{{ $items->total() === 1 ? '' : 's' }}</p>
                <button type="submit"
                        class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed"
                        id="attachBtn" disabled>
                    Attach Selected
                </button>
            </div>
        @else
            <div class="mb-3">
                <p class="text-sm" style="color: var(--text-secondary);">Showing {{ number_format($items->count()) }} of {{ number_format($items->total()) }} document{{ $items->total() === 1 ? '' : 's' }}</p>
            </div>
        @endif

        @if($items->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No documents yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Upload your first document using the form on the left to get started.</p>
                <a href="#upload-library" class="corex-btn-primary">Upload Document</a>
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2);">
                                @if($presentation)
                                    <th class="px-4 py-2.5 w-8">
                                        <input type="checkbox" id="selectAll" class="rounded">
                                    </th>
                                @endif
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Uploaded By</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Size</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                                @php
                                    $isAttached = in_array($item->id, $attachedIds);
                                @endphp
                                <tr class="transition-colors" style="border-top: 1px solid var(--border); @if($isAttached) background: color-mix(in srgb, var(--brand-icon) 6%, transparent); @endif">
                                    @if($presentation)
                                        <td class="px-4 py-3">
                                            @if($isAttached)
                                                <span class="text-sm" style="color: var(--brand-icon);" title="Already attached">&#10003;</span>
                                            @else
                                                <input type="checkbox" name="item_ids[]" value="{{ $item->id }}"
                                                       class="item-checkbox rounded" form="attachForm">
                                            @endif
                                        </td>
                                    @endif
                                    <td class="px-4 py-3 font-medium max-w-[240px] truncate" style="color: var(--text-primary);" title="{{ $item->original_name }}">
                                        {{ $item->title ?? $item->original_name }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="ds-badge ds-badge-info">
                                            {{ $docTypeLabels[$item->doc_type] ?? $item->doc_type }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $item->uploader->name ?? 'Unknown' }}</td>
                                    <td class="px-4 py-3" style="color: var(--text-muted);">{{ $item->created_at->format('d M Y') }}</td>
                                    <td class="px-4 py-3" style="color: var(--text-muted);">
                                        @if($item->bytes > 0)
                                            {{ number_format($item->bytes / 1024, 0) }} KB
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('documents.library.download', $item) }}"
                                           class="text-xs font-semibold transition-colors hover:underline"
                                           style="color: var(--brand-icon);">
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                {{ $items->links() }}
            </div>
        @endif

        @if($presentation)
        </form>
        @endif
    </div>
</div>

@if($presentation)
<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const attachBtn = document.getElementById('attachBtn');
    const selectAll = document.getElementById('selectAll');

    function updateBtn() {
        const checked = document.querySelectorAll('.item-checkbox:checked').length;
        attachBtn.disabled = checked === 0;
        attachBtn.textContent = checked > 0 ? 'Attach Selected (' + checked + ')' : 'Attach Selected';
    }

    checkboxes.forEach(function(cb) { cb.addEventListener('change', updateBtn); });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateBtn();
        });
    }
});
</script>
@endif

@endsection
