{{-- Owner panel on a Subscription Agreement document (spec §11.9): link, progress, review / countersign. --}}
@php
    $a = $doc->signers->firstWhere('role_key', 'r1');
    $total = app(\App\Services\PlatformEsign\Agreement\AgreementService::class)->totalPages($doc);
    $agencyDone = \App\Models\PlatformEsign\Initial::where('signer_id', $a->id)->count();
    $link = in_array($doc->status, ['sent', 'in_progress'], true) ? route('platform-esign.agreement.show', $a->token) : null;
@endphp
<div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
        <div class="ds-section-header">Subscription Agreement · {{ $doc->contract_ref }}</div>
        <span class="text-xs" style="color: var(--text-muted);">{{ $doc->wording?->label() }}</span>
    </div>
    <div class="px-5 py-4 space-y-3 text-sm">
        @include('platform-esign.agreement._take-on-warning', ['doc' => $doc, 'lapse' => app(\App\Services\PlatformEsign\Agreement\AgreementService::class)->takeOnLapse($doc)])
        <div>Status: <strong>{{ $doc->statusLabel() }}</strong>@unless($doc->wetinkFiles()->exists()) · agency has initialled {{ $agencyDone }} of {{ $total }} pages @if($doc->form_rev > 0)· {{ $doc->form_rev }} {{ \Illuminate\Support\Str::plural('save', $doc->form_rev) }} @endif @endunless</div>
        @if($link)
            <div x-data="{ copied: false }">
                <label class="ds-label block mb-1">Signing link (send it yourself if you prefer — it is the agency’s only way in)</label>
                <div class="flex gap-2">
                    <input readonly class="ds-field w-full text-xs" value="{{ $link }}" onclick="this.select()">
                    <button type="button" class="corex-btn-outline" @click="navigator.clipboard.writeText('{{ $link }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) })" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>
            </div>
        @endif
        @if($doc->wetinkFiles()->exists())
            <div>
                <div class="ds-label mb-1">Hand-signed copy from the agency</div>
                @foreach($doc->wetinkFiles as $wf)
                    <div class="text-xs flex flex-wrap gap-x-3" style="{{ $wf->isActive() ? '' : 'color: var(--text-muted); text-decoration: line-through;' }}">
                        <a class="underline" style="color: var(--brand-icon);" href="{{ route('platform-esign.agreements.wetink', [$doc->id, $wf->id]) }}">{{ $wf->original_name }}</a>
                        <span>{{ number_format(max(1, $wf->size / 1024), 0) }} KB · {{ $wf->created_at?->format('j M H:i') }} · batch {{ $wf->batch }}@unless($wf->isActive()) · superseded {{ $wf->superseded_at?->format('j M H:i') }}@endunless</span>
                        <span class="break-all" style="color: var(--text-muted);">SHA-256 {{ substr($wf->sha256, 0, 16) }}…</span>
                    </div>
                @endforeach
            </div>
        @endif
        @if($doc->status === 'expired' && !$doc->trashed())
            <div class="rounded-md px-4 py-3 flex flex-wrap items-center gap-3" style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e;">
                <span>The agency’s link expired{{ $doc->expires_at ? ' on ' . $doc->expires_at->format('j F Y') : '' }}. Everything they entered is kept.</span>
                <form method="POST" action="{{ route('platform-esign.documents.resend', $doc->id) }}">@csrf<button class="corex-btn-primary">Re-issue — new link, fresh {{ \App\Services\PlatformEsign\Agreement\AgreementService::expiryDays() }} days</button></form>
            </div>
        @endif
        @if($doc->status === 'completed' && !$doc->trashed())
            <div class="rounded-md px-4 py-3 flex flex-wrap items-center gap-3" style="background: var(--surface-2, #f8fafc); border: 1px solid var(--border);">
                <span>{{ $doc->expires_at && $doc->expires_at->isPast() ? 'The agency’s link to the signed agreement expired on ' . $doc->expires_at->format('j F Y') . '.' : 'The agency’s own link opens the signed agreement (view and download) until ' . ($doc->expires_at?->format('j F Y') ?? '—') . '.' }} Nothing is attached to any email — it stays inside CoreX.</span>
                <form method="POST" action="{{ route('platform-esign.documents.resend', $doc->id) }}">@csrf<button class="corex-btn-outline">Re-issue the agency’s link — new link, fresh {{ \App\Services\PlatformEsign\Agreement\AgreementService::accessMonths() }} months</button></form>
            </div>
        @endif
        <div class="flex flex-wrap gap-2">
            @unless($doc->status === 'wetink_received')<a href="{{ route('platform-esign.agreements.review', $doc->id) }}" class="corex-btn-outline">Review what has been entered</a>@endunless
            @if(in_array($doc->status, ['awaiting_countersign', 'wetink_received'], true) && !$doc->trashed())
                <a href="{{ route('platform-esign.agreements.countersign', $doc->id) }}" class="corex-btn-primary">Review and countersign</a>
            @endif
        </div>
    </div>
</div>
