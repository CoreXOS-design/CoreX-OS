{{-- AT-132 Wave 1 — safe per-thread metadata row. SAFE FIELDS ONLY: channel, date,
     message count, owning agent, attachment flag, subject (already null when the owner
     hid it). NEVER renders body / body_preview / message content.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens via var(), no emojis).
     Vars: $thread (stdClass), $isWa (bool), $accent (css colour). --}}
<div class="flex items-center justify-between gap-3">
    <div class="flex items-center gap-2 min-w-0">
        <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded"
              style="background:color-mix(in srgb, {{ $accent }} 14%, transparent); color:{{ $isWa ? '#1a9e4b' : 'var(--brand-icon, #0ea5e9)' }};">
            {{ $isWa ? 'WhatsApp' : 'Email' }}
        </span>
        @if($thread->message_count > 1)
        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
              style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
              title="Messages in this thread">{{ $thread->message_count }} messages</span>
        @endif
        <span class="text-sm font-semibold truncate" style="color:var(--text-primary);">
            @if($thread->subject_hidden)
                <span style="color:var(--text-muted);">(subject hidden by owning agent)</span>
            @else
                {{ $thread->subject ?: '(no subject)' }}
            @endif
        </span>
    </div>
    <span class="text-xs whitespace-nowrap" style="color:var(--text-muted);">{{ optional($thread->latest_at)->format('d M Y, H:i') ?? '—' }}</span>
</div>
<div class="flex items-center gap-3 mt-1 text-[11px]" style="color:var(--text-muted);">
    <span>Agent: {{ $thread->owner_name ?: 'Unassigned' }}</span>
    @if($thread->has_attachments)
    <span title="This thread has at least one attachment">Has attachment</span>
    @endif
</div>
