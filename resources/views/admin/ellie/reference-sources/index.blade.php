{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')

<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Ellie Reference Sources</h1>
                <p class="text-xs" style="color: var(--text-muted);">External pages Ellie may search when CoreX's own knowledge base can't answer. Global — not per-agency.</p>
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

    {{-- How this works --}}
    <div class="rounded-md p-4 text-xs leading-relaxed" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
        Ellie never fetches a URL live during a conversation — she only searches pages already indexed here.
        Adding a source fetches and indexes it immediately; a daily job re-checks every active source for
        changes. Only the single page you paste is indexed — CoreX does not follow links on it.
    </div>

    {{-- Add Source --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Add a Reference Source</h3>
        <form action="{{ route('admin.ellie.reference-sources.store') }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="ds-label">URL <span class="text-red-500">*</span></label>
                    <input type="url" name="url" required
                           class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="https://example.co.za/prime-rate" value="{{ old('url') }}">
                    @error('url') <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="ds-label">Title (optional)</label>
                    <input type="text" name="title"
                           class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Bank X prime rate" value="{{ old('title') }}">
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="corex-btn-primary px-4 py-2 text-sm">Add &amp; Index</button>
            </div>
            <div class="text-xs mt-3" style="color: var(--text-muted);">
                Public http/https pages only. URLs that resolve to a private, internal, or reserved
                address are refused.
            </div>
        </form>
    </div>

    {{-- Sources table --}}
    <div>
        @if($sources->isEmpty())
            <div class="rounded-md p-10 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-3"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <i class="fas fa-link"></i>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No reference sources yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Add a URL above to give Ellie a narrow, approved external source.</p>
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Title / URL</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Chunks</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last Fetched</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Added By</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sources as $source)
                            <tr class="transition-all duration-300">
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ Str::limit($source->title ?: $source->url, 50) }}</div>
                                    <div class="text-xs" style="color: var(--text-muted);">{{ Str::limit($source->url, 60) }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @php
                                        $statusColor = match($source->last_fetch_status) {
                                            'ok' => 'var(--ds-green)',
                                            'error' => 'var(--ds-crimson)',
                                            default => 'var(--ds-amber)',
                                        };
                                    @endphp
                                    <span class="text-xs px-2 py-0.5 rounded-md font-medium"
                                          style="background: color-mix(in srgb, {{ $statusColor }} 15%, transparent); color: {{ $statusColor }};"
                                          @if($source->fetch_error) title="{{ $source->fetch_error }}" @endif>
                                        {{ strtoupper($source->last_fetch_status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center text-sm" style="color: var(--text-secondary);">{{ $source->chunks_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <form action="{{ route('admin.ellie.reference-sources.toggleActive', $source) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2 py-0.5 rounded-md font-medium transition-all duration-300"
                                                style="{{ $source->is_active
                                                    ? 'background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);'
                                                    : 'background: var(--surface-2); color: var(--text-muted);' }}"
                                                title="{{ $source->is_active ? 'Deactivate' : 'Activate' }}">
                                            {{ $source->is_active ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                    {{ $source->last_fetched_at ? $source->last_fetched_at->format('d M Y H:i') : 'Never' }}
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $source->addedBy->name ?? '-' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <form action="{{ route('admin.ellie.reference-sources.refresh', $source) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--ds-amber);">Refresh now</button>
                                        </form>
                                        <form action="{{ route('admin.ellie.reference-sources.destroy', $source) }}" method="POST" class="inline" x-data x-on:submit.prevent="if(confirm('Remove this reference source?')) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--ds-crimson);">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    </div>

</div>

@endsection
