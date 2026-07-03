@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    @php
        // AT-137 — context-aware Back: return to the originating contact when the
        // user came from a contact record, else to the compliance archive.
        $backRoute = isset($backContact) && $backContact
            ? route('corex.contacts.show', $backContact->id)
            : route('compliance.comm-archive.index');
        $backLabel = isset($backContact) && $backContact
            ? (trim(($backContact->first_name ?? '').' '.($backContact->last_name ?? '')) ?: 'Contact')
            : 'Communication Archive';
    @endphp
    <x-page-header title="Conversation Thread" :back-route="$backRoute" :back-label="$backLabel" :flush="true" />

    {{-- AT-150 — WhatsApp-style chat thread. Direction drives alignment
         (outbound RIGHT, inbound LEFT — WhatsApp's own convention) and colour
         (outbound = tasteful CoreX green tint from --ds-green; inbound = neutral
         surface). Per-message metadata (sender, time, direction, channel tag) is
         preserved, as is the AT-148 inline audio player / pending-media chip and
         the per-thread visibility gate (enforced upstream in the controller). --}}
    <div class="p-4 lg:p-6">
        <div class="max-w-3xl mx-auto space-y-3">
            @foreach($messages as $m)
                @php
                    $out = $m->direction === 'outbound';
                    $body = $m->body_text ?: $m->body_preview;
                    $hasBody = filled($m->subject) || filled($body);
                    $hasAttachments = $m->has_attachments && $m->attachments->isNotEmpty();
                    // Outbound: green tint that adapts to the active theme surface
                    // (mixes over --surface so dark mode stays legible), with a
                    // solid fallback for browsers without color-mix. Inbound: the
                    // neutral surface bubble with the standard border.
                    $bubbleStyle = $out
                        ? 'background:#e6f4ec; background:color-mix(in srgb, var(--ds-green,#059669) 14%, var(--surface,#ffffff)); border:1px solid #cfe8da; border-color:color-mix(in srgb, var(--ds-green,#059669) 26%, transparent); border-radius:14px 14px 4px 14px;'
                        : 'background:var(--surface,#ffffff); border:1px solid var(--border,#e5e7eb); border-radius:14px 14px 14px 4px;';
                @endphp
                <div class="flex {{ $out ? 'justify-end' : 'justify-start' }}">
                    <div class="px-3.5 py-2.5 shadow-sm" style="{{ $bubbleStyle }} max-width:82%;">
                        {{-- Header: sender + channel tag --}}
                        <div class="flex items-center gap-2 mb-1 {{ $out ? 'justify-end' : 'justify-start' }}">
                            <span class="text-xs font-semibold truncate" style="color:var(--text-primary,#111827); max-width:16rem;">{{ $m->from_display }}</span>
                            <span class="ds-badge {{ $m->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }}">{{ ucfirst($m->channel) }}</span>
                        </div>

                        {{-- Body --}}
                        @if(filled($m->subject))
                            <div class="text-sm font-semibold mb-1" style="color:var(--text-primary,#111827);">{{ $m->subject }}</div>
                        @endif
                        @if(filled($body))
                            <div class="text-sm whitespace-pre-wrap break-words" style="color:var(--text-primary,#1f2937); line-height:1.55;">{{ $body }}</div>
                        @endif

                        {{-- Attachments — voice-note player (AT-148), pending chip, or generic file.
                             A media-only message renders its player here so the bubble is never blank. --}}
                        @if($hasAttachments)
                            <div class="mt-2 flex flex-col gap-2 {{ $out ? 'items-end' : 'items-start' }}">
                                @foreach($m->attachments as $att)
                                    @php
                                        $duration = $att->duration_seconds
                                            ? sprintf('%d:%02d', intdiv($att->duration_seconds, 60), $att->duration_seconds % 60)
                                            : null;
                                        $durSuffix = $duration ? ' · '.$duration : '';
                                    @endphp
                                    @php $label = $att->isAudio() ? ('Voice note'.$durSuffix) : ('Attachment: '.($att->filename ?? 'file')); @endphp
                                    @if($att->isAudio() && $att->isPlayable())
                                        <div class="flex flex-col gap-1 w-full">
                                            <span class="text-xs" style="color:var(--text-secondary,#4b5563);">{{ 'Voice note'.$durSuffix }}</span>
                                            <audio controls preload="none" style="height:36px; width:100%; max-width:260px;">
                                                <source src="{{ route('compliance.comm-archive.attachment', $att->id) }}" type="{{ $att->mime }}">
                                                Your browser cannot play this voice note.
                                            </audio>
                                        </div>
                                    @elseif($att->isPlayable())
                                        {{-- Stored non-audio (image / document) — open/download. --}}
                                        <a href="{{ route('compliance.comm-archive.attachment', $att->id) }}" target="_blank" rel="noopener"
                                           class="text-xs px-2 py-1 rounded inline-block" style="background:var(--surface-2,#f0f2f8); color:var(--text-secondary,#4b5563);">{{ $label }}</a>
                                    @elseif($att->isFailed())
                                        {{-- Terminal failure — never a silent "processing" forever; offer Retry. --}}
                                        <span class="text-xs px-2 py-1 rounded inline-flex items-center gap-2" style="background:var(--surface-2,#f0f2f8); color:var(--ds-red,#c0392b);">
                                            {{ $label }} — unavailable
                                            <form method="POST" action="{{ route('compliance.comm-archive.attachment.retry', $att->id) }}" class="inline">@csrf
                                                <button type="submit" class="underline" style="color:var(--ds-blue,#2563eb);">Retry</button>
                                            </form>
                                        </span>
                                    @else
                                        {{-- Pending — a background retry is running; allow a manual nudge. --}}
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

                        {{-- A genuinely empty message (no body, no media) never shows a blank bubble. --}}
                        @unless($hasBody || $hasAttachments)
                            <div class="text-sm italic" style="color:var(--text-muted,#9ca3af);">No message content captured</div>
                        @endunless

                        {{-- Footer: direction + timestamp --}}
                        <div class="flex items-center gap-2 mt-1.5 {{ $out ? 'justify-end' : 'justify-start' }}">
                            <span class="text-xs" style="color:var(--text-muted,#9ca3af);">{{ $out ? 'Outbound' : 'Inbound' }}</span>
                            <span class="text-xs" style="color:var(--text-muted,#9ca3af);">&middot;</span>
                            <span class="text-xs" style="color:var(--text-muted,#9ca3af);">{{ $m->occurred_at?->format('d M Y H:i') }}</span>
                        </div>
                    </div>
                </div>
            @endforeach

            @if($messages->isEmpty())
                <div class="text-center text-sm py-10" style="color:var(--text-muted,#9ca3af);">No messages in this thread.</div>
            @endif
        </div>
    </div>
</div>
@endsection
