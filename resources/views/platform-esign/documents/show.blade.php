{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign document detail (AT-447, spec §3A). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $tone = ['draft' => 'default', 'sent' => 'info', 'in_progress' => 'orange', 'completed' => 'success', 'declined' => 'default', 'voided' => 'default', 'expired' => 'default'];
    $actions = '<a href="' . route('platform-esign.documents.index') . '" class="corex-btn-outline">← Documents</a>';
    if ($doc->sealed_pdf_path) { $actions .= '<a href="' . route('platform-esign.documents.download', $doc->id) . '" class="corex-btn-primary">Download signed PDF</a>'; }
@endphp
<div class="w-full space-y-5">
    @include('platform-esign._header', ['title' => $doc->title, 'tab' => 'documents', 'sub' => ($doc->agency?->name ?? 'No agency') . ' · contract #' . $doc->id, 'actions' => $actions])

    <div class="grid lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2 space-y-5">
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                    <div class="ds-section-header">Signers</div>
                    <span class="ds-badge ds-badge-{{ $tone[$doc->status] ?? 'default' }}">{{ \App\Models\PlatformEsign\Document::STATUSES[$doc->status] ?? $doc->status }}@if($doc->trashed()) · archived @endif</span>
                </div>
                @foreach($doc->signers as $s)
                    <div class="px-5 py-4" style="border-top: 1px solid var(--border);">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ $s->name }} <span class="font-normal text-xs" style="color: var(--text-muted);">· {{ $s->role_label }}</span></div>
                                <div class="text-xs" style="color: var(--text-muted);">{{ $s->email }}@if($s->id_number) · ID {{ $s->id_number }}@endif</div>
                            </div>
                            <span class="ds-badge ds-badge-{{ ['signed' => 'success', 'declined' => 'default', 'viewed' => 'info', 'sent' => 'info'][$s->status] ?? 'muted' }}">{{ ucfirst($s->status === 'pending' ? 'waiting' : $s->status) }}</span>
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            @if($s->signed_at)Signed {{ $s->signed_at->format('j M Y H:i') }} from {{ $s->signed_ip }}
                            @elseif($s->first_viewed_at)Opened {{ $s->first_viewed_at->format('j M Y H:i') }}
                            @elseif($s->invited_at)Emailed {{ $s->invited_at->format('j M Y H:i') }}, not opened yet
                            @else Not emailed yet @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">History</div></div>
                @foreach($doc->events->sortByDesc('id') as $e)
                    <div class="px-5 py-2.5 text-xs flex gap-3" style="border-top: 1px solid var(--border);">
                        <span class="whitespace-nowrap tabular-nums" style="color: var(--text-muted);">{{ $e->created_at?->format('j M H:i') }}</span>
                        <span style="color: var(--text-primary);"><strong>{{ ucfirst(str_replace('_', ' ', $e->event)) }}</strong>@if($e->detail) — {{ $e->detail }}@endif @if($e->actor)<span style="color: var(--text-muted);">· {{ $e->actor->name }}</span>@endif</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="space-y-5">
            <div class="rounded-md p-5 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="ds-section-header mb-2">Details</div>
                <div class="flex justify-between"><span style="color: var(--text-muted);">Template</span><span>{{ $doc->template?->name ?? '—' }} v{{ $doc->template_version }}</span></div>
                <div class="flex justify-between"><span style="color: var(--text-muted);">Order</span><span>{{ $doc->sequential ? 'In sequence' : 'Anyone first' }}</span></div>
                <div class="flex justify-between"><span style="color: var(--text-muted);">Sent</span><span>{{ $doc->sent_at?->format('j M Y') }}</span></div>
                <div class="flex justify-between"><span style="color: var(--text-muted);">Link expires</span><span>{{ $doc->expires_at?->format('j M Y') ?? '—' }}</span></div>
                @if($doc->completed_at)<div class="flex justify-between"><span style="color: var(--text-muted);">Signed</span><span>{{ $doc->completed_at->format('j M Y H:i') }}</span></div>@endif
                @if($doc->document_hash)<div class="text-xs break-all pt-1" style="color: var(--text-muted);">SHA-256 {{ $doc->document_hash }}</div>@endif
                @if($doc->decline_reason)<div class="text-xs pt-1" style="color: var(--ds-crimson);">Declined: {{ $doc->decline_reason }}</div>@endif
                @if($doc->void_reason)<div class="text-xs pt-1" style="color: var(--text-muted);">Voided: {{ $doc->void_reason }}</div>@endif
                @if($doc->attachments->isNotEmpty())
                    <div class="pt-2 text-xs"><div class="font-semibold mb-1">Attachments</div>@foreach($doc->attachments as $a)<div><a class="underline" style="color: var(--brand-icon);" href="{{ route('platform-esign.documents.attachment', [$doc->id, $a->id]) }}">{{ $a->original_name }}</a></div>@endforeach</div>
                @endif
                @if($doc->agency && \App\Models\Platform\AgencyTimeline::where('agency_id', $doc->agency_id)->exists())
                    <div class="pt-2 text-xs"><a class="underline" style="color: var(--brand-icon);" href="{{ route('admin.agency-timelines.show', \App\Models\Platform\AgencyTimeline::where('agency_id', $doc->agency_id)->value('id')) }}">Open this agency's timeline</a></div>
                @endif
            </div>

            @if(!$doc->trashed())
            <div class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ voiding: false }">
                <div class="ds-section-header">Actions</div>
                @if(in_array($doc->status, ['sent', 'in_progress', 'expired'], true))
                    <form method="POST" action="{{ route('platform-esign.documents.resend', $doc->id) }}">@csrf<button class="corex-btn-outline w-full">Resend (new link, fresh expiry)</button></form>
                @endif
                @if($doc->status === 'completed' && !$doc->sealed_pdf_path)
                    <form method="POST" action="{{ route('platform-esign.documents.reseal', $doc->id) }}">@csrf<button class="corex-btn-primary w-full">Rebuild the signed PDF</button></form>
                @endif
                @if(!in_array($doc->status, ['completed', 'voided'], true))
                    <button type="button" class="corex-btn-outline w-full" style="color: var(--ds-crimson);" @click="voiding = !voiding">Void this document</button>
                    <form method="POST" action="{{ route('platform-esign.documents.void', $doc->id) }}" x-show="voiding" x-cloak class="space-y-2">@csrf
                        <textarea name="reason" required maxlength="490" rows="2" class="ds-field w-full" placeholder="Why is it being voided?"></textarea>
                        <button class="corex-btn-primary w-full" onclick="return confirm('Void this document? Every signing link stops working.');">Confirm void</button>
                    </form>
                @endif
                @unless($doc->isOpen())
                    <form method="POST" action="{{ route('platform-esign.documents.archive', $doc->id) }}" onsubmit="return confirm('Archive this document? You can restore it.');">@csrf @method('DELETE')<button class="corex-btn-outline w-full">Archive</button></form>
                @endunless
            </div>
            @else
                <form method="POST" action="{{ route('platform-esign.documents.restore', $doc->id) }}">@csrf<button class="corex-btn-primary w-full">Restore this document</button></form>
            @endif
        </div>
    </div>
</div>
@endsection
