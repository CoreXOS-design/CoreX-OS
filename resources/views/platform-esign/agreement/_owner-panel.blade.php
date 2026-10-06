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
        <div>Status: <strong>{{ $doc->statusLabel() }}</strong> · agency has initialled {{ $agencyDone }} of {{ $total }} pages @if($doc->form_rev > 0)· {{ $doc->form_rev }} {{ \Illuminate\Support\Str::plural('save', $doc->form_rev) }} @endif</div>
        @if($link)
            <div x-data="{ copied: false }">
                <label class="ds-label block mb-1">Signing link (send it yourself if you prefer — it is the agency’s only way in)</label>
                <div class="flex gap-2">
                    <input readonly class="ds-field w-full text-xs" value="{{ $link }}" onclick="this.select()">
                    <button type="button" class="corex-btn-outline" @click="navigator.clipboard.writeText('{{ $link }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) })" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>
            </div>
        @endif
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('platform-esign.agreements.review', $doc->id) }}" class="corex-btn-outline">Review what has been entered</a>
            @if(in_array($doc->status, ['awaiting_countersign', 'wetink_received'], true) && !$doc->trashed())
                <a href="{{ route('platform-esign.agreements.countersign', $doc->id) }}" class="corex-btn-primary">Review and countersign</a>
            @endif
        </div>
    </div>
</div>
