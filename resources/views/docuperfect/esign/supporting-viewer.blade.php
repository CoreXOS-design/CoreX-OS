{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full max-w-5xl mx-auto space-y-4">

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('docuperfect.esign.myDocuments') }}" class="text-xs font-medium hover:underline" style="color: var(--text-muted);">&larr; Back to My E-Sign Documents</a>
            <h1 class="text-xl font-bold mt-1" style="color: var(--text-primary);">{{ $signerName }}&rsquo;s uploaded documents</h1>
            <p class="text-sm mt-0.5" style="color: var(--text-muted);">
                {{ $versions->count() }} document{{ $versions->count() === 1 ? '' : 's' }} attached while signing
                <strong>{{ $document->name ?? 'Untitled' }}</strong>. Scroll to review each one before sending the batch to the splitter.
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('signatures.supporting.downloadAll', ['document' => $document->id, 'request' => $signingRequest->id]) }}"
               class="corex-btn-outline text-sm">Download all</a>
            <form method="POST" action="{{ route('signatures.supporting.processBatch', ['document' => $document->id, 'request' => $signingRequest->id]) }}">
                @csrf
                <button type="submit" class="corex-btn-primary text-sm" title="Hand the whole batch off to the document splitter (coming soon)">Send to splitter</button>
            </form>
        </div>
    </div>

    {{-- Files inherit the signature document's name until the splitter names them (expected). --}}
    <div class="rounded-md px-4 py-2 text-xs" style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent); color: var(--text-secondary); border: 1px solid var(--border);">
        These files keep the signature document&rsquo;s name for now &mdash; the splitter is where each one gets its own name. This viewer is so you can see exactly what was sent.
    </div>

    {{-- Each doc rendered full-width, stacked & scrollable (like the FICA document viewer) --}}
    @foreach($versions as $i => $version)
        @php $ext = strtolower($version->file_type ?? ''); @endphp
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-2.5 flex items-center justify-between" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">
                    Document {{ $i + 1 }} of {{ $versions->count() }}
                    <span class="text-xs font-normal ml-2" style="color: var(--text-muted);">{{ strtoupper($ext) }} &middot; uploaded {{ $version->uploaded_at?->format('d M Y H:i') }}</span>
                </div>
                <a href="{{ route('signatures.supporting.stream', ['document' => $document->id, 'version' => $version->id]) }}" target="_blank"
                   class="text-xs font-semibold hover:underline" style="color: var(--brand-icon);">Open in new tab</a>
            </div>
            <div style="background: #f1f5f9;">
                @php $src = route('signatures.supporting.stream', ['document' => $document->id, 'version' => $version->id]); @endphp
                @if(in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                    <img src="{{ $src }}" alt="Document {{ $i + 1 }}" style="width: 100%; display: block;">
                @elseif($ext === 'pdf')
                    <iframe src="{{ $src }}" title="Document {{ $i + 1 }}" style="width: 100%; height: 85vh; border: none; display: block;"></iframe>
                @else
                    <div class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                        This file type ({{ strtoupper($ext) ?: 'file' }}) can&rsquo;t be previewed in the browser.
                        <a href="{{ $src }}" target="_blank" class="font-semibold hover:underline" style="color: var(--brand-icon);">Open / download it</a> to view.
                    </div>
                @endif
            </div>
        </div>
    @endforeach

</div>
@endsection
