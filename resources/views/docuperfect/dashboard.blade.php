@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">My Documents</h1>
                <p class="text-sm text-white/60">Documents you've created from templates.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('docuperfect.create') }}" class="corex-btn-primary">
                    + Create New Document
                </a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if($documents->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No documents yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Create your first document from a template.</p>
            <a href="{{ route('docuperfect.create') }}" class="corex-btn-primary">Create Document</a>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Template</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last Edited</th>
                            @if($user->hasPermission('documents.edit'))
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            @endif
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $doc->name }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $doc->template->name ?? '—' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-muted);">{{ $doc->updated_at->format('d M Y H:i') }}</td>
                            @if($user->hasPermission('documents.edit'))
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $doc->owner->name ?? '—' }}</td>
                            @endif
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('docuperfect.documents.edit', $doc->id) }}" class="text-xs font-semibold mr-3" style="color: var(--brand-icon);">Edit</a>
                                <form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document?');">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color: var(--text-muted);">Archive</button>
                                </form>
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
