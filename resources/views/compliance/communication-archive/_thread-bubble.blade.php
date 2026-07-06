{{-- AT-150 / AT-168 Part C — one chat bubble. Extracted so the initial render and
     the scroll-up loader (threadOlder) produce identical markup. $m = Communication.
     The wrapper carries id/data for in-thread search jump + highlight. --}}
@php
    $out = $m->direction === 'outbound';
    // AT-182 — the thread shows each message's NEW content: the quote-stripped display body
    // for email, or body_text as-is for WhatsApp (no quoting concept). The raw full body is
    // kept for search AND for the per-message "Show full email" affordance; "Open" (detail)
    // still renders the untouched original.
    $body = $m->display_body;
    $fullBody = $m->body_text ?: $m->body_preview;
    $quoteStripped = $m->wasQuoteStripped();
    $hasBody = filled($m->subject) || filled($body);
    $hasAttachments = $m->has_attachments && $m->attachments->isNotEmpty();
    // AT-163 — voice-note transcript affordance. A transcript is searchable text,
    // so it joins the per-bubble search index alongside the body.
    $audioAtt = $m->has_attachments
        ? $m->attachments->first(fn ($a) => is_string($a->mime) && str_starts_with($a->mime, 'audio'))
        : null;
    $hasTranscript = $m->hasTranscript();
    // Searchable text (body-field-first — the voice-note transcript is appended).
    // Search the FULL body (incl. quoted history) so in-thread search never misses content.
    $searchText = trim((string) $m->subject . ' ' . (string) $fullBody . ' ' . (string) ($hasTranscript ? $m->transcript_text : ''));
    $bubbleStyle = $out
        ? 'background:#e6f4ec; background:color-mix(in srgb, var(--ds-green,#059669) 14%, var(--surface,#ffffff)); border:1px solid #cfe8da; border-color:color-mix(in srgb, var(--ds-green,#059669) 26%, transparent); border-radius:14px 14px 4px 14px;'
        : 'background:var(--surface,#ffffff); border:1px solid var(--border,#e5e7eb); border-radius:14px 14px 14px 4px;';
@endphp
<div class="flex {{ $out ? 'justify-end' : 'justify-start' }} cx-msg"
     id="msg-{{ $m->id }}" data-mid="{{ $m->id }}"
     data-at="{{ optional($m->occurred_at)->format('Y-m-d') }}"
     data-search="{{ \Illuminate\Support\Str::lower($searchText) }}">
    <div class="px-3.5 py-2.5 shadow-sm cx-bubble" style="{{ $bubbleStyle }} max-width:82%; transition:box-shadow .3s, background .3s;">
        <div class="flex items-center gap-2 mb-1 {{ $out ? 'justify-end' : 'justify-start' }}">
            <span class="text-xs font-semibold truncate" style="color:var(--text-primary,#111827); max-width:16rem;">{{ $m->from_display }}</span>
            <span class="ds-badge {{ $m->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }}">{{ ucfirst($m->channel) }}</span>
        </div>

        @if(filled($m->subject))
            <div class="text-sm font-semibold mb-1 cx-msg-text" style="color:var(--text-primary,#111827);">{{ $m->subject }}</div>
        @endif
        @if(filled($body))
            <div class="text-sm whitespace-pre-wrap break-words cx-msg-text" style="color:var(--text-primary,#1f2937); line-height:1.55;">{{ $body }}</div>
            @if($quoteStripped)
                {{-- AT-182 — quoted reply history was hidden for readability; reveal the full email on demand. --}}
                <div x-data="{ full: false }" class="mt-1.5">
                    <button type="button" @click="full = !full" class="text-xs font-semibold inline-flex items-center gap-1" style="color:var(--brand-icon,#0ea5e9);">
                        <span x-text="full ? 'Hide quoted history' : 'Show full email'"></span>
                    </button>
                    <div x-show="full" x-cloak class="text-sm whitespace-pre-wrap break-words cx-msg-text mt-1.5 pt-2"
                         style="color:var(--text-secondary,#4b5563); border-top:1px dashed var(--border,#e5e7eb); line-height:1.55;">{{ $fullBody }}</div>
                </div>
            @endif
        @endif

        @if($hasAttachments)
            <div class="mt-2 flex flex-col gap-2 {{ $out ? 'items-end' : 'items-start' }}">
                @foreach($m->attachments as $att)
                    @php
                        $duration = $att->duration_seconds
                            ? sprintf('%d:%02d', intdiv($att->duration_seconds, 60), $att->duration_seconds % 60)
                            : null;
                        $durSuffix = $duration ? ' · '.$duration : '';
                        $label = $att->isAudio() ? ('Voice note'.$durSuffix) : ('Attachment: '.($att->filename ?? 'file'));
                    @endphp
                    @if($att->isAudio() && $att->isPlayable())
                        <div class="flex flex-col gap-1 w-full">
                            <span class="text-xs" style="color:var(--text-secondary,#4b5563);">{{ 'Voice note'.$durSuffix }}</span>
                            <audio controls preload="none" style="height:36px; width:100%; max-width:260px;">
                                <source src="{{ route('compliance.comm-archive.attachment', $att->id) }}" type="{{ $att->mime }}">
                                Your browser cannot play this voice note.
                            </audio>
                        </div>
                    @elseif($att->isPlayable())
                        <a href="{{ route('compliance.comm-archive.attachment', $att->id) }}" target="_blank" rel="noopener"
                           class="text-xs px-2 py-1 rounded inline-block" style="background:var(--surface-2,#f0f2f8); color:var(--text-secondary,#4b5563);">{{ $label }}</a>
                    @elseif($att->isFailed())
                        <span class="text-xs px-2 py-1 rounded inline-flex items-center gap-2" style="background:var(--surface-2,#f0f2f8); color:var(--ds-red,#c0392b);">
                            {{ $label }} — unavailable
                            <form method="POST" action="{{ route('compliance.comm-archive.attachment.retry', $att->id) }}" class="inline">@csrf
                                <button type="submit" class="underline" style="color:var(--ds-blue,#2563eb);">Retry</button>
                            </form>
                        </span>
                    @else
                        <span class="text-xs px-2 py-1 rounded inline-flex items-center gap-2" style="background:var(--surface-2,#f0f2f8); color:var(--text-muted,#9ca3af);">
                            {{ ($att->isAudio() ? 'Voice note — processing'.$durSuffix : $label.' — processing') }}
                            <form method="POST" action="{{ route('compliance.comm-archive.attachment.retry', $att->id) }}" class="inline">@csrf
                                <button type="submit" class="underline" style="color:var(--text-muted,#9ca3af);">Retry now</button>
                            </form>
                        </span>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- AT-163 — voice-note transcription: view the transcript, or transcribe on
             demand. The transcript inherits this message's visibility gate (the
             thread only renders visible messages) and consent gate (a withheld note
             has no stored audio, so no affordance shows). --}}
        @if($audioAtt)
            <div class="mt-2" x-data="{ open: {{ $hasTranscript ? 'false' : 'false' }} }">
                @if($hasTranscript)
                    <button type="button" @click="open = !open" class="text-xs font-semibold inline-flex items-center gap-1" style="color:var(--brand-icon, #0ea5e9);">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
                        <span x-text="open ? 'Hide transcription' : 'View transcription'"></span>
                        @if($m->transcript_lang)<span style="color:var(--text-muted,#9ca3af);">· {{ strtoupper($m->transcript_lang) }}</span>@endif
                    </button>
                    <div x-show="open" x-cloak class="mt-1.5 text-sm whitespace-pre-wrap break-words cx-msg-text p-2 rounded"
                         style="color:var(--text-secondary,#374151); background:var(--surface-2,#f0f2f8); line-height:1.5;">{{ $m->transcript_text }}</div>
                @elseif(in_array($m->transcript_status, ['pending','processing'], true))
                    <span class="text-xs inline-flex items-center gap-1" style="color:var(--text-muted,#9ca3af);">
                        <svg class="w-3.5 h-3.5 animate-pulse" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5"/></svg>
                        Transcribing…
                    </span>
                @else
                    {{-- not transcribed (null) or terminally failed → offer Transcribe now --}}
                    <form method="POST" action="{{ route('compliance.comm-archive.transcribe', $m->id) }}" class="inline">
                        @csrf
                        <button type="submit" class="text-xs font-semibold inline-flex items-center gap-1" style="color:var(--brand-icon, #0ea5e9);">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z"/></svg>
                            {{ $m->transcript_status === 'failed' ? 'Retry transcription' : 'Transcribe now' }}
                        </button>
                    </form>
                    @if($m->transcript_status === 'failed')
                        <span class="text-xs ml-1" style="color:var(--text-muted,#9ca3af);">· last attempt failed</span>
                    @endif
                @endif
            </div>
        @endif

        @unless($hasBody || $hasAttachments)
            <div class="text-sm italic" style="color:var(--text-muted,#9ca3af);">No message content captured</div>
        @endunless

        <div class="flex items-center gap-2 mt-1.5 {{ $out ? 'justify-end' : 'justify-start' }}">
            <span class="text-xs" style="color:var(--text-muted,#9ca3af);">{{ $out ? 'Outbound' : 'Inbound' }}</span>
            <span class="text-xs" style="color:var(--text-muted,#9ca3af);">&middot;</span>
            <span class="text-xs" style="color:var(--text-muted,#9ca3af);">{{ $m->occurred_at?->format('d M Y H:i') }}</span>
        </div>
    </div>
</div>
