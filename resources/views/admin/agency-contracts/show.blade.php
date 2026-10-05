{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — one platform contract (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php $badge = ['signed' => 'ds-badge-success', 'declined' => 'ds-badge-danger', 'voided' => 'ds-badge-default', 'expired' => 'ds-badge-warning', 'sent' => 'ds-badge-info', 'viewed' => 'ds-badge-info', 'draft' => 'ds-badge-default']; @endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <a href="{{ route('admin.agency-contracts.index') }}" class="text-xs underline" style="color:var(--text-muted);">← Agency Contracts</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">{{ $env->title }} — {{ $env->agency?->name }}
            <span class="ds-badge {{ $badge[$env->status] ?? 'ds-badge-default' }} ml-2 align-middle">{{ ucfirst($env->status) }}</span></h1>
        <p class="text-xs" style="color: var(--text-muted);">To {{ $env->signatory_name }} ({{ $env->signatory_role }}) · {{ $env->signatory_email }}
            @if($env->sent_at) · sent {{ $env->sent_at->format('j M Y H:i') }}@endif
            @if($env->token_expires_at && in_array($env->status, ['sent','viewed'])) · link valid until {{ $env->token_expires_at->format('j M Y') }}@endif</p>
    </div>
    @include('admin.partials.platform-flash')

    <div class="flex flex-wrap gap-3 items-start">
        @if($env->status === 'signed' && $env->sealed_pdf_path)
            <a href="{{ route('admin.agency-contracts.download', $env->id) }}" class="corex-btn-primary text-xs">Download signed PDF</a>
        @endif
        @if(in_array($env->status, ['sent','viewed','expired']))
            <form method="POST" action="{{ route('admin.agency-contracts.resend', $env->id) }}" class="flex items-center gap-2">@csrf
                <input type="number" name="expiry_days" min="1" max="90" value="14" class="ds-field" style="width:5rem;" title="Link valid for (days)">
                <button class="corex-btn-outline text-xs" onclick="return confirm('Re-send with a new link? The previous link stops working.')">Resend (new link)</button></form>
        @endif
        @if(!in_array($env->status, ['signed','voided']))
            <form method="POST" action="{{ route('admin.agency-contracts.void', $env->id) }}" class="flex items-center gap-2" onsubmit="return confirm('Void this contract? The link stops working.');">@csrf
                <input name="void_reason" required maxlength="500" placeholder="Reason for voiding" class="ds-field" style="min-width:14rem;">
                <button class="corex-btn-danger text-xs">Void</button></form>
        @endif
        @if(!$env->deleted_at && !in_array($env->status, ['sent','viewed']))
            <form method="POST" action="{{ route('admin.agency-contracts.archive', $env->id) }}" onsubmit="return confirm('Archive this contract? You can restore it.');">@csrf @method('DELETE')<button class="text-xs underline" style="color:var(--ds-crimson);">Archive</button></form>
        @elseif($env->deleted_at)
            <form method="POST" action="{{ route('admin.agency-contracts.restore', $env->id) }}">@csrf<button class="corex-btn-outline text-xs">Restore</button></form>
        @endif
        @if($env->agency_id)<a href="{{ route('admin.agency-timelines.index', ['q' => $env->agency?->name]) }}" class="text-xs underline self-center" style="color:var(--brand-icon);">Agency timeline</a>@endif
    </div>

    @if($env->status === 'signed')
        <div class="rounded-md p-4 text-sm space-y-1" style="background: var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
            <div class="font-semibold" style="color:var(--text-primary);">Signature evidence</div>
            <div>Signed by <strong>{{ $env->signed_typed_name }}</strong> on {{ $env->signed_at->format('j M Y \a\t H:i') }} from IP {{ $env->signed_ip }}.</div>
            <div class="text-xs break-all">Document fingerprint (SHA-256): {{ $env->document_hash }}</div>
        </div>
    @endif
    @if($env->status === 'declined')
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border:1px solid var(--border); color:var(--text-secondary);">Declined {{ $env->declined_at?->format('j M Y H:i') }} — “{{ $env->decline_reason }}”</div>
    @endif
    @if($env->status === 'voided')
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border:1px solid var(--border); color:var(--text-secondary);">Voided {{ $env->voided_at?->format('j M Y H:i') }} — “{{ $env->void_reason }}”</div>
    @endif

    @if($env->attachments->isNotEmpty())
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border:1px solid var(--border);">
            <div class="font-semibold mb-1" style="color:var(--text-primary);">Attachments</div>
            @foreach($env->attachments as $att)<div><a class="underline" style="color:var(--brand-icon);" href="{{ route('admin.agency-contracts.attachment', [$env->id, $att->id]) }}">{{ $att->original_name }}</a></div>@endforeach
        </div>
    @endif

    <div class="rounded-md p-6" style="background:#fff; border:1px solid var(--border); color:#111827;">
        <div class="text-xs uppercase tracking-wider mb-3" style="color:#6b7280;">The document as sent (frozen)</div>
        <div class="prose max-w-none text-sm leading-relaxed">{!! $env->body_html_snapshot !!}</div>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-semibold" style="color:var(--text-primary); border-bottom:1px solid var(--border);">History</div>
        <table class="w-full text-sm ds-table"><tbody>
        @foreach($events as $ev)
            <tr style="border-top:1px solid var(--border);">
                <td class="px-4 py-2 tabular-nums whitespace-nowrap" style="color:var(--text-muted);">{{ $ev->created_at->format('j M Y H:i') }}</td>
                <td class="px-4 py-2" style="color:var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $ev->event)) }}@if($ev->detail) — {{ $ev->detail }}@endif</td>
                <td class="px-4 py-2 text-xs" style="color:var(--text-muted);">{{ $ev->actor_user_id ? ($actors[$ev->actor_user_id] ?? '') : ($ev->ip ?? '') }}</td>
            </tr>
        @endforeach
        </tbody></table>
    </div>
</div>
@endsection
