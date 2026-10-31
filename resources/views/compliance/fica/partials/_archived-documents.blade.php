{{-- Removed (archived) FICA documents, with a Restore for whoever may remove them. Expects $submission and $canRestore (bool);
     draws nothing when the viewer cannot restore or nothing has been removed. --}}
@php
    $archivedDocs = ($canRestore ?? false) ? $submission->documents()->onlyTrashed()->orderByDesc('deleted_at')->get() : collect();
@endphp
@if($archivedDocs->isNotEmpty())
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);" data-fica-archived-documents>
    <h3 class="text-sm font-bold mb-1 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Removed documents</h3>
    <p class="text-xs mb-3" style="color:var(--text-muted);">Taken off this review. Restore one to put it back.</p>
    @foreach($archivedDocs as $adoc)
        <div class="flex items-center justify-between gap-3 py-2" style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">
            <div class="min-w-0 flex-1">
                <span class="text-xs font-semibold" style="color:var(--text-secondary);">{{ $adoc->document_type_label }}</span>
                <span class="text-sm break-words" style="color:var(--text-primary);">{{ $adoc->file_name }}</span>
                <span class="text-xs" style="color:var(--text-muted);">removed {{ optional($adoc->deleted_at)->format('d M Y H:i') }}</span>
            </div>
            <form method="POST" action="{{ route('compliance.fica.documents.restore', [$submission, $adoc->id]) }}" style="display:inline;">
                @csrf
                <button type="submit" class="text-xs font-medium" style="color:var(--brand-icon,#0ea5e9); background:none; border:none; cursor:pointer; padding:0;">Restore</button>
            </form>
        </div>
    @endforeach
</div>
@endif
