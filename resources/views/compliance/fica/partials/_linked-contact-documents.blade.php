{{-- AT-361 — Linked contact documents: existing docs on the contact, referenced into
     this FICA (NOT re-uploaded). Streamed via the FICA-scoped linked-documents.view route
     so the RO/CO can view them for approval. Expects $submission (with linkedDocuments).
     Optional $canManageLinks (bool) shows an Unlink control. --}}
@if($submission->linkedDocuments->isNotEmpty())
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-1 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Linked Contact Documents</h3>
    <p class="text-xs mb-3" style="color:var(--text-muted);">Existing documents on the contact, linked to this FICA (not re-uploaded).</p>
    @foreach($submission->linkedDocuments as $ldoc)
        <div class="flex items-start justify-between gap-3 py-2" style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">
            <div class="min-w-0 flex-1">
                <span class="text-xs font-semibold uppercase" style="color:var(--text-secondary);">{{ str_replace('_', ' ', $ldoc->pivot->document_type ?? 'supporting') }}@if($ldoc->documentType) · {{ $ldoc->documentType->label }}@endif</span>
                <p class="text-sm break-words" style="color:var(--text-primary);">{{ $ldoc->original_name }}</p>
                <p class="text-xs" style="color:var(--text-muted);">From the contact's document drive</p>
            </div>
            <div class="shrink-0 flex items-center gap-3">
                <a href="{{ route('compliance.fica.linked-documents.view', [$submission, $ldoc]) }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-semibold text-white transition-colors"
                   style="background:var(--brand-button,#0ea5e9);"
                   aria-label="View linked contact document">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    View
                </a>
                @if($canManageLinks ?? false)
                <form method="POST" action="{{ route('compliance.fica.linked-documents.unlink', [$submission, $ldoc]) }}" onsubmit="return confirm('Unlink this document from the FICA? The contact document itself is not deleted.');" style="display:inline;">
                    @csrf
                    <button type="submit" class="text-xs font-medium" style="color:var(--ds-crimson,#c41e3a); background:none; border:none; cursor:pointer; padding:0;">Unlink</button>
                </form>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
