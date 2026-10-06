{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — RR countersigns a hand-signed Subscription Agreement (spec §11.8). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $labels = collect(\App\Services\PlatformEsign\Agreement\AgreementFields::schema())->map(fn ($f) => $f['label'])->all();
    $active = $files->filter(fn ($f) => $f->isActive());
@endphp
<div class="w-full space-y-4">
    @include('platform-esign._header', [
        'title' => 'Countersign a hand-signed copy — ' . $doc->title, 'tab' => 'documents',
        'sub' => $doc->contract_ref . ' · ' . $versionLabel . ' · ' . $doc->statusLabel(),
        'actions' => '<a href="' . route('platform-esign.documents.show', $doc->id) . '" class="corex-btn-outline">← Back to the document</a>',
    ])
    @include('platform-esign.agreement._css')
    @include('platform-esign.agreement._sheets-css')

    <div class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="ds-section-header">Signed copy received from the agency</div>
        @foreach($files as $f)
            <div class="text-sm flex flex-wrap gap-x-3" style="{{ $f->isActive() ? '' : 'color: var(--text-muted); text-decoration: line-through;' }}">
                <a class="underline" style="color: var(--brand-icon);" href="{{ route('platform-esign.agreements.wetink', [$doc->id, $f->id]) }}" target="_blank">{{ $f->original_name }}</a>
                <span>{{ number_format(max(1, $f->size / 1024), 0) }} KB · {{ $f->created_at?->format('j M Y H:i') }}@unless($f->isActive()) · superseded @endunless</span>
                <span class="text-xs break-all" style="color: var(--text-muted);">SHA-256 {{ $f->sha256 }}</span>
            </div>
        @endforeach
        <p class="text-xs" style="color: var(--text-muted);">Open each file and check every page is signed and initialled. When you countersign, a countersignature and attestation page is sealed with the fingerprint of each file received; the agency’s hand-signed copy plus that page is the signed agreement.</p>
    </div>

    <div class="agr rounded-md p-5 space-y-3" id="final" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="ds-section-header">Countersign for {{ \App\Services\PlatformEsign\Agreement\AgreementCompany::for($doc)->legalName() }}</div>
        <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="ds-label block mb-1" for="fld-rr_name">Name</label><input class="ds-field w-full" id="fld-rr_name" data-field="rr_name" data-required="1" maxlength="255" value="{{ $rr['rr_name'] ?? '' }}"></div>
            <div><label class="ds-label block mb-1" for="fld-rr_capacity">Capacity</label><input class="ds-field w-full" id="fld-rr_capacity" data-field="rr_capacity" data-required="1" maxlength="255" value="{{ $rr['rr_capacity'] ?? '' }}"></div>
            <div><label class="ds-label block mb-1" for="fld-rr_place">Place</label><input class="ds-field w-full" id="fld-rr_place" data-field="rr_place" data-required="1" maxlength="255" value="{{ $rr['rr_place'] ?? '' }}"></div>
            <div><label class="ds-label block mb-1" for="fld-rr_date">Date</label><input type="date" class="ds-field w-full" id="fld-rr_date" data-field="rr_date" data-required="1" value="{{ $rr['rr_date'] ?? '' }}"></div>
        </div>
        <div>
            <div class="ds-label mb-1">Signature</div>
            <div class="sigpad" data-sig="sigR" data-field="sigR" data-required="1"><canvas></canvas>
                <div class="sigtools"><button type="button" class="mini" data-sig-clear>Clear</button><button type="button" class="mini" data-sig-type>Type it instead</button></div>
                <input type="hidden" name="sigR" value=""></div>
        </div>
        <div id="submit-errors" style="color:#b91c1c;font-size:.85rem;"></div>
        <div class="flex items-center gap-3"><button type="button" id="submit-btn" class="btn" disabled>Countersign and seal</button><span id="savestate" class="text-xs" style="color: var(--text-muted);"></span></div>
    </div>
    <div id="toast" class="toast" hidden role="status"></div>
</div>
@php
    $cfg = ['mode' => 'rr', 'rev' => 0, 'total' => 0, 'done' => [], 'initials' => '', 'rates' => \App\Services\PlatformEsign\Agreement\AgreementPricing::DEFAULT_RATES, 'variation' => '0', 'labels' => $labels, 'recipientKeys' => [],
        'urls' => ['countersign' => route('platform-esign.agreements.countersign.store', $doc->id)], 'signerName' => (string) ($rr['rr_name'] ?? '')];
@endphp
<script>window.AGR = {!! json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};</script>
@include('platform-esign.agreement._js')
@endsection
