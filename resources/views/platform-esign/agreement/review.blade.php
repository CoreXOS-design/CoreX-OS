{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — owner review / RR countersign of the Subscription Agreement (spec §11.7). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $signers = $doc->signers->keyBy('role_key');
    $a = $signers['r1'] ?? null; $r = $signers['r2'] ?? null;
    $iniRows = \App\Models\PlatformEsign\Initial::where('document_id', $doc->id)->get()->groupBy('page_no');
    $staticIni = $countersign ? null : $iniRows->map(fn ($g) => $g->pluck('initials')->implode(' · '))->all();
    $rates = array_merge(\App\Services\PlatformEsign\Agreement\AgreementPricing::DEFAULT_RATES, (array) $ctx['rates']);
    $labels = collect(\App\Services\PlatformEsign\Agreement\AgreementFields::schema())->map(fn ($f) => $f['label'])->all();
@endphp
<div class="w-full space-y-4">
    @include('platform-esign._header', [
        'title' => $countersign ? 'Countersign — ' . $doc->title : 'Review — ' . $doc->title, 'tab' => 'documents',
        'sub' => $doc->contract_ref . ' · ' . $versionLabel . ' · ' . $doc->statusLabel(),
        'actions' => '<a href="' . route('platform-esign.documents.show', $doc->id) . '" class="corex-btn-outline">← Back to the document</a>',
    ])
    @include('platform-esign.agreement._css')
    @include('platform-esign.agreement._sheets-css')

    @if($countersign)
        <div class="topbar" style="position:static;border-radius:6px;">
            <div class="stat">Review everything the agency entered (read-only), then complete RR Technologies’ details, initial every page and sign.</div>
            <div class="sp"><span class="stat" id="pages-stat"></span><span class="stat" id="savestate"></span>
                <button type="button" id="todo-btn" class="btn ghost sm">Outstanding (<span id="todo-count">0</span>)</button></div>
        </div>
        <div id="todo" class="todo" hidden role="dialog" style="top:120px;"><div style="display:flex;justify-content:space-between;margin-bottom:.4rem;"><strong>What is still outstanding</strong><button type="button" id="todo-close" class="mini">×</button></div><div id="todo-list"></div></div>
    @else
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">Read-only view of what has been entered so far. Account numbers are masked — click one to reveal it (the reveal is recorded in the audit trail).</div>
    @endif

    <div class="agr-wrap" style="max-width:860px;">
        @include('platform-esign.agreement._sheets', ['pages' => $pages, 'total' => $total, 'versionLabel' => $versionLabel, 'interactive' => $countersign, 'staticIni' => $staticIni])

        @if($countersign)
            <div class="panel" id="final">
                <h2 style="margin:0 0 .5rem;font-size:1.05rem;color:#0b2a4a;">Countersign for RR Technologies</h2>
                <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-bottom:.7rem;">
                    <label for="ini-input" style="font-weight:600;font-size:.88rem;">RR initials</label>
                    <input id="ini-input" class="fld" style="font:inherit;border:1px solid #94a3b8;border-radius:4px;padding:.35rem .5rem;width:6.5em;text-transform:uppercase;" maxlength="5" value="{{ $defaultInitials }}" autocomplete="off">
                    <span style="font-size:.8rem;color:#64748b;">Then tap “Initial this page” on every page. Name, capacity, place, date and signature are in the signature block on Part A (page with “For RR Technologies”).</span>
                </div>
                <div id="submit-errors" style="color:#b91c1c;font-size:.85rem;margin-bottom:.6rem;"></div>
                <button type="button" id="submit-btn" class="btn" disabled>Countersign and seal</button>
            </div>
        @endif
    </div>
    <div id="toast" class="toast" hidden role="status"></div>
</div>
@php
    $cfg = [
        'mode' => $countersign ? 'rr' : 'preview', 'rev' => 0, 'total' => $total, 'done' => [], 'initials' => $countersign ? $defaultInitials : '',
        'rates' => $rates, 'variation' => (string) ($ctx['rr']['variation_amount'] ?? '0'), 'labels' => $labels, 'recipientKeys' => [],
        'urls' => ['countersign' => route('platform-esign.agreements.countersign.store', $doc->id), 'reveal' => route('platform-esign.agreements.reveal', $doc->id)],
    ];
@endphp
<script>window.AGR = {!! json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};</script>
@include('platform-esign.agreement._js')
@endsection
