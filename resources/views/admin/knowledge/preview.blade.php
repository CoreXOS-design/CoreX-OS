{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')

<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ $document->category ? route('admin.knowledge.category', $document->category->id) : route('admin.knowledge.index') }}" class="text-sm text-white/60 hover:text-white transition-colors">&larr; Back</a>
                    <h1 class="text-xl font-bold text-white leading-tight">Document Preview</h1>
                </div>
                <p class="text-sm text-white/60">{{ $document->title }}</p>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    {{-- Document Info Card --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Document Information</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <div class="ds-label">File</div>
                <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ $document->file_name }}</div>
            </div>
            <div>
                <div class="ds-label">Type</div>
                <div class="text-sm font-medium uppercase mt-1" style="color: var(--text-primary);">{{ $document->file_type }}</div>
            </div>
            <div>
                <div class="ds-label">Size</div>
                <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ $document->file_size_formatted }}</div>
            </div>
            <div>
                <div class="ds-label">Category</div>
                <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ $document->category->name ?? '-' }}</div>
            </div>
            <div>
                <div class="ds-label">Status</div>
                <div class="mt-1">{!! $document->status_badge !!}</div>
            </div>
            <div>
                <div class="ds-label">Chunks</div>
                <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ number_format($document->chunk_count) }}</div>
            </div>
            <div>
                <div class="ds-label">Uploaded By</div>
                <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ $document->uploader->name ?? '-' }}</div>
            </div>
            <div>
                <div class="ds-label">Date</div>
                <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ $document->created_at->format('d M Y H:i') }}</div>
            </div>
        </div>

        @if($document->description)
            <div class="mt-4 pt-4" style="border-top: 1px solid var(--border);">
                <div class="ds-label">Description</div>
                <div class="text-sm mt-1" style="color: var(--text-secondary);">{{ $document->description }}</div>
            </div>
        @endif

        @if($document->status === 'error' && $document->error_message)
            <div class="mt-4 pt-4" style="border-top: 1px solid var(--border);">
                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                     style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
                    <i class="fas fa-triangle-exclamation mt-0.5" style="color: var(--ds-crimson);"></i>
                    <div><strong>Processing error.</strong> {{ $document->error_message }}</div>
                </div>
            </div>
        @endif
    </div>

    {{-- Chunks List --}}
    <div>
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Document Chunks ({{ number_format($document->chunks->count()) }})</h3>

        @if($document->chunks->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No chunks extracted</h3>
                <p class="text-sm" style="color: var(--text-muted);">This document has not produced any searchable text segments.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($document->chunks as $chunk)
                    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold" style="color: var(--text-primary);">Chunk {{ $chunk->chunk_index }}</span>
                                @if($chunk->section_title)
                                    <span class="text-xs px-2 py-0.5 rounded-md"
                                          style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">{{ Str::limit($chunk->section_title, 60) }}</span>
                                @endif
                                @if($chunk->page_number)
                                    <span class="text-xs" style="color: var(--text-muted);">Page {{ $chunk->page_number }}</span>
                                @endif
                            </div>
                            <div class="text-xs" style="color: var(--text-muted);">
                                {{ number_format($chunk->word_count) }} words &middot; {{ number_format($chunk->char_count) }} chars
                            </div>
                        </div>
                        <div class="rounded-md p-3 text-xs font-mono whitespace-pre-wrap max-h-48 overflow-y-auto"
                             style="background: var(--surface-2); color: var(--text-secondary); line-height: 1.4;">{{ $chunk->content }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>

@endsection
