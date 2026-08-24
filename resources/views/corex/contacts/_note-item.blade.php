{{-- Shared note-item partial (2026-08-20) — used by both contacts/show.blade.php
     and command-center/buyers/detail.blade.php's Notes tab. Same $note row,
     same markup, on purpose: Johan's constraint is that a note written on
     either screen is the SAME record, so the two screens must render it
     identically rather than maintain two hand-copied blocks that can drift. --}}
<div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-2 flex-shrink-0">
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                 style="background:var(--brand-default, #0b2a4a);">
                {{ strtoupper(substr($note->user?->name ?? '?', 0, 1)) }}
            </div>
            <div>
                <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $note->user?->name ?? 'Unknown' }}</div>
                <div class="text-xs" style="color:var(--text-muted);">{{ $note->created_at->format('d M Y H:i') }} · {{ $note->created_at->diffForHumans() }}</div>
            </div>
        </div>
        <form method="POST" action="{{ route('corex.contacts.notes.destroy', [$note->contact_id, $note]) }}"
              onsubmit="return confirm('Delete this note?');">
            @csrf @method('DELETE')
            <button type="submit" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-crimson);">Delete</button>
        </form>
    </div>
    @if($note->type)
        <span class="inline-block mt-2 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
              style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color: var(--brand-icon, #0ea5e9);">
            {{ $note->type }}
        </span>
    @endif
    @if(trim((string) $note->body) !== '')
        <div class="mt-3 text-sm whitespace-pre-line" style="color:var(--text-primary);">{{ $note->body }}</div>
    @endif
</div>
