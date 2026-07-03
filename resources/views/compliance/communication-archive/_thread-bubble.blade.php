{{-- AT-150 / AT-168 Part C — one chat bubble. Extracted so the initial render and
     the scroll-up loader (threadOlder) produce identical markup. $m = Communication.
     The wrapper carries id/data for in-thread search jump + highlight. --}}
@php
    $out = $m->direction === 'outbound';
    $body = $m->body_text ?: $m->body_preview;
    $hasBody = filled($m->subject) || filled($body);
    $hasAttachments = $m->has_attachments && $m->attachments->isNotEmpty();
    // Searchable text (body-field-first — future voice-note transcripts append here).
    $searchText = trim((string) $m->subject . ' ' . (string) $body);
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
