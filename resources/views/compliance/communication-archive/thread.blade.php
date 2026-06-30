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

    <div class="p-4 lg:p-6">
        <div class="max-w-3xl mx-auto space-y-3">
            @foreach($messages as $m)
            <div class="bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:6px; {{ $m->direction === 'outbound' ? 'margin-left:2rem;' : 'margin-right:2rem;' }}">
                <div class="px-4 py-2 flex items-center justify-between" style="border-bottom:1px solid var(--border, #f1f5f9);">
                    <div class="text-xs" style="color:#64748b;">
                        <span class="font-semibold" style="color:var(--text-primary);">{{ $m->from_display }}</span>
                        <span class="ds-badge {{ $m->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }} ml-2">{{ ucfirst($m->channel) }}</span>
                        <span class="ml-2">{{ $m->direction === 'inbound' ? '↓ Inbound' : '↑ Outbound' }}</span>
                    </div>
                    <div class="text-xs" style="color:#94a3b8;">{{ $m->occurred_at?->format('d M Y H:i') }}</div>
                </div>
                <div class="px-4 py-3">
                    @if($m->subject)<div class="text-sm font-semibold mb-1" style="color:var(--text-primary);">{{ $m->subject }}</div>@endif
                    <div class="text-sm whitespace-pre-wrap" style="color:#334155; line-height:1.6;">{{ $m->body_text ?: $m->body_preview }}</div>
                    @if($m->has_attachments && $m->attachments->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($m->attachments as $att)
                        <span class="text-xs px-2 py-1" style="background:var(--surface-alt, #f8fafc); border-radius:6px; color:#64748b;">📎 {{ $att->filename ?? 'attachment' }}</span>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
