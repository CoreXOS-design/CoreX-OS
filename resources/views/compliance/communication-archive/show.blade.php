@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    @php
        // AT-137 — context-aware Back (return to the originating contact when present).
        $backRoute = isset($backContact) && $backContact
            ? route('corex.contacts.show', $backContact->id)
            : route('compliance.comm-archive.index');
        $backLabel = isset($backContact) && $backContact
            ? (trim(($backContact->first_name ?? '').' '.($backContact->last_name ?? '')) ?: 'Contact')
            : 'Communication Archive';
    @endphp
    <x-page-header title="Communication" :back-route="$backRoute" :back-label="$backLabel" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-3xl mx-auto bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
            <div class="px-5 py-4" style="border-bottom:1px solid var(--border, #f1f5f9);">
                <div class="flex items-center gap-2 mb-1">
                    <span class="ds-badge {{ $communication->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }}">{{ ucfirst($communication->channel) }}</span>
                    <span class="text-xs" style="color:#64748b;">{{ $communication->direction === 'inbound' ? '↓ Inbound' : '↑ Outbound' }}</span>
                    <span class="text-xs ml-auto" style="color:#94a3b8;">{{ $communication->occurred_at?->format('d M Y H:i') }}</span>
                </div>
                <h2 class="text-base font-bold" style="color:var(--text-primary);">{{ $communication->subject ?: '(no subject)' }}</h2>
                <div class="text-xs mt-1" style="color:#64748b;">From: {{ $communication->from_display }}</div>
            </div>
            <div class="px-5 py-4 text-sm whitespace-pre-wrap" style="color:#334155; line-height:1.7;">{{ $communication->body_text ?: $communication->body_preview }}</div>

            @if($communication->attachments->isNotEmpty())
            <div class="px-5 py-3" style="border-top:1px solid var(--border, #f1f5f9);">
                <h4 class="text-xs font-bold uppercase mb-2" style="color:#94a3b8;">Attachments</h4>
                <div class="flex flex-col gap-2">
                    @foreach($communication->attachments as $att)
                        @php
                            $duration = $att->duration_seconds
                                ? sprintf('%d:%02d', intdiv($att->duration_seconds, 60), $att->duration_seconds % 60)
                                : null;
                        @endphp
                        @if($att->isAudio() && $att->isPlayable())
                            {{-- AT-148 — inline voice-note player (authenticated route) --}}
                            <div class="flex items-center gap-2">
                                <span class="text-xs" style="color:#64748b;">🎙️ Voice note{{ $duration ? ' · '.$duration : '' }} · {{ number_format($att->size_bytes / 1024, 1) }} KB</span>
                                <audio controls preload="none" style="height:34px; max-width:280px;">
                                    <source src="{{ route('compliance.comm-archive.attachment', $att->id) }}" type="{{ $att->mime }}">
                                    Your browser cannot play this voice note.
                                </audio>
                            </div>
                        @elseif($att->isAudio())
                            <span class="text-xs px-2 py-1 inline-block" style="background:var(--surface-alt, #f8fafc); border-radius:6px; color:#94a3b8;">🎙️ Voice note — processing{{ $duration ? ' · '.$duration : '' }}</span>
                        @else
                            <span class="text-xs px-2 py-1 inline-block" style="background:var(--surface-alt, #f8fafc); border-radius:6px; color:#64748b;">📎 {{ $att->filename ?? 'attachment' }} ({{ number_format($att->size_bytes / 1024, 1) }} KB)</span>
                        @endif
                    @endforeach
                </div>
            </div>
            @endif

            <div class="px-5 py-3 text-xs" style="border-top:1px solid var(--border, #f1f5f9); color:#94a3b8;">
                Ref: COMM-{{ str_pad($communication->id, 8, '0', STR_PAD_LEFT) }} · captured {{ $communication->captured_at?->format('d M Y H:i') }}
                @if($communication->thread_key) · <a href="{{ route('compliance.comm-archive.thread', $communication->thread_key) }}" style="color:var(--brand-icon);">view thread</a> @endif
            </div>
        </div>
    </div>
</div>
@endsection
