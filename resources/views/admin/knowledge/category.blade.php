{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')

<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.knowledge.index') }}" class="text-sm text-white/60 hover:text-white transition-colors">&larr; Back</a>
                    <h1 class="text-xl font-bold text-white leading-tight">{{ $category->name }}</h1>
                </div>
                <p class="text-sm text-white/60">{{ number_format($documents->total()) }} {{ Str::plural('document', $documents->total()) }}</p>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif

    @if($category->description)
        <p class="text-sm" style="color: var(--text-secondary);">{{ $category->description }}</p>
    @endif

    {{-- Upload form (category pre-selected) --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Upload to {{ $category->name }}</h3>
        <form action="{{ route('admin.knowledge.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="category_id" value="{{ $category->id }}">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="ds-label">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required
                           class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="Document title" value="{{ old('title') }}">
                    @error('title') <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="ds-label">File <span class="text-red-500">*</span></label>
                    <input type="file" name="file" required accept=".pdf,.docx,.doc,.txt,.md"
                           class="w-full text-sm mt-1 rounded-md px-3 py-1.5 transition-all duration-300"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                    @error('file') <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="ds-label">Version</label>
                    <input type="text" name="version"
                           class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. v2.1" value="{{ old('version') }}">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="corex-btn-primary px-4 py-2 text-sm">Upload Document</button>
                </div>
            </div>
            <div class="mt-3">
                <label class="ds-label">Description</label>
                <textarea name="description" rows="2"
                          class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                          placeholder="Optional description">{{ old('description') }}</textarea>
            </div>
            <div class="text-xs mt-3" style="color: var(--text-muted);">Accepted: PDF, DOCX, DOC, TXT, MD &mdash; Max 20MB</div>
        </form>
    </div>

    {{-- Documents Table --}}
    @if($documents->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <i class="fas fa-file-alt"></i>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No documents in this category yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Use the upload form above to add your first document.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Title</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Size</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Chunks</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ellie</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Uploaded By</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                            <tr class="transition-all duration-300">
                                <td class="px-4 py-3 text-sm font-medium" style="color: var(--text-primary);">{{ Str::limit($doc->title, 40) }}</td>
                                <td class="px-4 py-3 text-center text-xs uppercase" style="color: var(--text-secondary);">{{ $doc->file_type }}</td>
                                <td class="px-4 py-3 text-center text-xs" style="color: var(--text-secondary);">{{ $doc->file_size_formatted }}</td>
                                <td class="px-4 py-3 text-center">{!! $doc->status_badge !!}</td>
                                <td class="px-4 py-3 text-center text-sm" style="color: var(--text-secondary);">{{ $doc->chunk_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <form action="{{ route('admin.knowledge.toggleEllie', $doc->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2 py-0.5 rounded-md font-medium transition-all duration-300"
                                                style="{{ $doc->is_ellie_enabled
                                                    ? 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color: var(--brand-icon, #0ea5e9);'
                                                    : 'background: var(--surface-2); color: var(--text-muted);' }}"
                                                title="{{ $doc->is_ellie_enabled ? 'Disable Ellie' : 'Enable Ellie' }}">
                                            {{ $doc->is_ellie_enabled ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <form action="{{ route('admin.knowledge.toggleActive', $doc->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2 py-0.5 rounded-md font-medium transition-all duration-300"
                                                style="{{ $doc->is_active
                                                    ? 'background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);'
                                                    : 'background: var(--surface-2); color: var(--text-muted);' }}"
                                                title="{{ $doc->is_active ? 'Deactivate' : 'Activate' }}">
                                            {{ $doc->is_active ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->uploader->name ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $doc->created_at->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.knowledge.preview', $doc->id) }}" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--brand-icon, #0ea5e9);">Preview</a>
                                        <form action="{{ route('admin.knowledge.reprocess', $doc->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--ds-amber);">Reprocess</button>
                                        </form>
                                        <form action="{{ route('admin.knowledge.destroy', $doc->id) }}" method="POST" class="inline" x-data x-on:submit.prevent="if(confirm('Delete this document and all its chunks?')) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--ds-crimson);">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($documents->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $documents->links() }}
                </div>
            @endif
        </div>
    @endif

</div>

@endsection
