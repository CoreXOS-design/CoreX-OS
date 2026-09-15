{{-- Read-only notes fragment, fetched into the Core Matches board's popup.
     Reuses the SAME note-item partial the full contact page and buyer
     pipeline detail page use — one record, one rendering, never a
     hand-copied second block that can drift. --}}
<div class="flex items-center justify-between mb-4">
    <h3 class="text-base font-semibold" style="color:var(--text-primary);">Notes — {{ $contact->full_name }}</h3>
    <a href="{{ route('corex.contacts.show', $contact) }}?tab=notes"
       class="text-xs font-medium no-underline" style="color:var(--brand-icon,#0ea5e9);">
        Open full contact record →
    </a>
</div>

@if($notes->isEmpty())
    <p class="text-sm" style="color:var(--text-muted);">No notes yet.</p>
@else
    <div class="space-y-3">
        @foreach($notes as $note)
            @include('corex.contacts._note-item', ['note' => $note, 'readOnly' => true])
        @endforeach
    </div>
@endif
