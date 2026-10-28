{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-demo-preview.md --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $isPdf = $demo['kind'] === 'pdf';
    $verdict = $readiness['verdict'];
    $verdictText = ['not_ready' => 'Not ready: something must be fixed first', 'ready_with_warnings' => 'Ready, with things worth a look', 'ready' => 'Ready'][$verdict];
    $verdictColour = ['not_ready' => 'var(--ds-crimson)', 'ready_with_warnings' => 'var(--ds-amber, #d97706)', 'ready' => 'var(--ds-green)'][$verdict];
    $statusText = ['bound' => 'Linked', 'guessed' => 'Guessed', 'placeholder' => 'Placeholder', 'empty' => 'EMPTY', 'missing' => 'Not on the page'];
    $statusColour = ['bound' => 'var(--ds-green)', 'guessed' => 'var(--ds-amber, #d97706)', 'placeholder' => 'var(--ds-amber, #d97706)', 'empty' => 'var(--ds-crimson)', 'missing' => 'var(--ds-crimson)'];
@endphp
<style>
    /* Fixed-shell page: menu and header stay put, the page itself does not scroll; the document pane and the side panel scroll inside themselves. */
    .demo-fit { height: calc(100vh - 54px - 32px); display: flex; flex-direction: column; overflow: hidden; }
    .demo-main { flex: 1 1 auto; min-height: 0; display: flex; gap: 12px; }
    .demo-doc { flex: 1 1 auto; min-width: 0; overflow: auto; background: #e2e8f0; border-radius: 6px; padding: 16px 0; }
    .demo-side { flex: 0 0 25rem; min-width: 0; display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
    .demo-side-body { flex: 1 1 auto; min-height: 0; overflow: auto; padding: 12px; }
    @media (max-width: 1100px) { .demo-fit { height: auto; overflow: visible; } .demo-main { flex-direction: column; } .demo-doc { max-height: 70vh; } .demo-side { flex-basis: auto; max-height: 60vh; } }

    /* Who signs where: one colour per signer (signature lines in the page, and the per-page initials strip). */
    [data-demo-document] [data-marker-type="signature"], [data-demo-document] [data-marker-type="initial"] { outline: 2px solid #7c3aed; outline-offset: 1px; position: relative; }
    [data-demo-document] [data-marker-party^="landlord"], [data-demo-document] [data-marker-party^="seller"], [data-demo-document] [data-marker-party^="owner"], [data-demo-document] [data-marker-party^="lessor"] { outline-color: #2563eb; }
    [data-demo-document] [data-marker-party^="tenant"], [data-demo-document] [data-marker-party^="buyer"], [data-demo-document] [data-marker-party^="acquiring"], [data-demo-document] [data-marker-party^="lessee"], [data-demo-document] [data-marker-party^="purchaser"] { outline-color: #059669; }
    [data-demo-document] [data-marker-party^="agent"] { outline-color: #ea580c; }
    [data-demo-document] [data-marker-type="signature"]::after { content: "Signs: " attr(data-marker-party); position: absolute; left: 0; top: -13px; font: 600 8px/1 sans-serif; color: #334155; white-space: nowrap; }
    [data-demo-document] .initials-inline-line { outline: 2px dashed #0d9488; outline-offset: 1px; }
    /* Blanks: red = came out empty, amber = a guessed or placeholder value. */
    [data-demo-document] [data-demo-source="empty"] { outline: 2px solid #dc2626; background: #fee2e2; min-width: 40pt; display: inline-block; }
    [data-demo-document] [data-demo-source="guessed"], [data-demo-document] [data-demo-source="placeholder"] { outline: 1px dashed #d97706; background: #fffbeb; }
    .demo-sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: -1px; }
</style>
<div class="w-full demo-fit gap-3" data-demo-view data-template-id="{{ $template->id }}" x-data="{ tab: 'places' }">

    <div class="rounded-md px-6 py-3 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Demo view: {{ $template->name }}</h1>
                <p class="text-xs font-semibold" style="color: var(--ds-crimson);" data-demo-banner>DEMO — sample data. Nothing can be signed or sent from here.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.templates.readiness', $template->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Check readiness</a>
                <a href="{{ route('docuperfect.templates.index') }}" class="corex-btn-outline text-xs px-3 py-1.5">Back to templates</a>
            </div>
        </div>
    </div>

    @foreach($demo['notes'] as $note)
        <div class="rounded-md px-4 py-2 text-xs flex-shrink-0" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent); color: var(--text-primary);" data-demo-note>{{ $note }}</div>
    @endforeach

    <div class="demo-main">
        {{-- ───────── The document, drawn the way the signing link draws it ───────── --}}
        <div class="demo-doc" data-demo-doc-pane>
            @if($isPdf)
                <div class="mx-auto text-sm p-5 rounded-md" style="max-width: 36rem; background: var(--surface); color: var(--text-secondary);" data-demo-pdf>
                    This is a PDF template: it has {{ $demo['pdf_pages'] }} page(s) of scanned pages with signature zones, not typed wording with blanks, so there is nothing to fill with sample data. The signature zones are listed under "Signers and places".
                </div>
            @else
                {{-- The same document CSS and the same container shape the signing link uses (docuperfect.shared._document-body + corex-document.css). --}}
                <link href="/css/corex-document.css" rel="stylesheet">
                <link rel="stylesheet" href="{{ asset('css/docuperfect-recipient-blocks.css') }}">
                <div style="width: 210mm; max-width: 100%; margin: 0 auto;">
                    <div class="docuperfect-document-body recipient-signing-context" data-viewer-context="demo">
                        <div id="demoContainer" data-demo-document data-recipient-block-host="1">{!! $demo['html'] !!}</div>
                    </div>
                </div>
            @endif
        </div>

        {{-- ───────── The side panel ───────── --}}
        <div class="demo-side" data-demo-side>
            <div class="flex flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                @foreach(['places' => 'Signers and places', 'fields' => 'Fields', 'frame' => 'Page frame', 'readiness' => 'Readiness'] as $k => $label)
                    <button type="button" data-demo-tab="{{ $k }}" @click="tab = '{{ $k }}'" class="text-xs px-3 py-2 font-semibold" :style="tab === '{{ $k }}' ? 'border-bottom: 2px solid var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-muted);'">{{ $label }}</button>
                @endforeach
            </div>
            <div class="demo-side-body">

                {{-- Signers and places --}}
                <div x-show="tab === 'places'" data-demo-panel="places">
                    <div class="text-xs mb-2" style="color: var(--text-muted);">Colours: <span class="demo-sw" style="background:#2563eb"></span>owner / landlord / seller <span class="demo-sw" style="background:#059669"></span>tenant / buyer <span class="demo-sw" style="background:#ea580c"></span>agent <span class="demo-sw" style="background:#0d9488"></span>initials</div>
                    <div class="text-xs mb-3 p-2 rounded-md" style="border: 1px solid var(--border); color: var(--text-secondary);" data-demo-pages>Pages on the signing page: <strong data-demo-page-count>…</strong>. <span data-demo-page-spread></span></div>
                    <h2 class="text-xs font-semibold mb-1" style="color: var(--text-primary);">Declared signers</h2>
                    @if($demo['signers'] === [])
                        <p class="text-xs mb-3" style="color: var(--ds-crimson);" data-no-signers>No signers are declared on this template.</p>
                    @else
                        <table class="w-full text-xs mb-3" data-demo-signers>
                            @foreach($demo['signers'] as $s)
                                <tr style="border-top: 1px solid var(--border);" data-signer="{{ $s['label'] }}">
                                    <td class="py-1 font-semibold" style="color: var(--text-primary);">{{ $s['label'] }}</td>
                                    <td class="py-1 text-right" style="color: {{ ($s['signatures'] === 0 && ! $s['standard_block']) ? 'var(--ds-crimson)' : 'var(--text-secondary)' }};">
                                        {{ $s['signatures'] }} signature, {{ $s['initials'] }} initial place(s)@if($s['signatures'] === 0 && $s['standard_block']) · CoreX standard block signs here @elseif($s['signatures'] === 0) · NOWHERE TO SIGN @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    <h2 class="text-xs font-semibold mb-1" style="color: var(--text-primary);">Every place in the template</h2>
                    @if($demo['places'] === [])
                        <p class="text-xs" style="color: var(--text-muted);" data-empty>This template has no signature or initial places of its own.</p>
                    @else
                        <table class="w-full text-xs" data-demo-places>
                            <tr class="text-left" style="color: var(--text-muted);"><th class="py-1">Place</th><th class="py-1">Written as</th><th class="py-1">Signer</th></tr>
                            @foreach($demo['places'] as $p)
                                <tr style="border-top: 1px solid var(--border);" data-place-ok="{{ $p['ok'] ? '1' : '0' }}">
                                    <td class="py-1" style="color: var(--text-secondary);">{{ ucfirst($p['kind']) }} {{ $p['ref'] }}</td>
                                    <td class="py-1" style="color: var(--text-secondary);">{{ implode(', ', array_column($p['names'], 'written')) ?: 'nobody' }}</td>
                                    <td class="py-1 font-semibold" style="color: {{ $p['ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">
                                        @if($p['names'] === [])not a declared signer
                                        @else{{ implode(', ', array_map(fn ($n) => $n['declared'] ?? 'not a declared signer', $p['names'])) }}@endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>

                {{-- Fields --}}
                <div x-show="tab === 'fields'" x-cloak data-demo-panel="fields" x-data="{ q: '', f: 'all', show(el) { const s = el.dataset.status; const l = el.dataset.label; if (this.q !== '' && !l.includes(this.q.toLowerCase())) return false; if (this.f === 'empty') return s === 'empty' || s === 'missing'; if (this.f === 'guessed') return s === 'guessed' || s === 'placeholder'; if (this.f === 'typed') return el.dataset.typed === '1'; return true; } }">
                    @if($isPdf)
                        <p class="text-xs" style="color: var(--text-muted);" data-empty>A PDF template has no typed blanks.</p>
                    @else
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <input type="text" x-model="q" placeholder="Search by label" aria-label="Search fields by label" class="list-header-filter text-xs" style="min-width: 10rem;">
                            <select x-model="f" aria-label="Show" class="list-header-filter text-xs">
                                <option value="all">All blanks</option><option value="empty">Empty or missing</option><option value="guessed">Guessed or placeholder</option><option value="typed">Typed by hand</option>
                            </select>
                        </div>
                        <p class="text-xs mb-2" style="color: var(--text-muted);" data-demo-field-counts>{{ $demo['counts']['fields'] }} blank(s): {{ $demo['counts']['empty'] }} empty, {{ $demo['counts']['missing'] }} not on the page, {{ $demo['counts']['guessed'] }} guessed or placeholder.</p>
                        @if($demo['fields'] === [])
                            <p class="text-xs" style="color: var(--text-muted);" data-empty>This template has no blanks to fill.</p>
                        @else
                            <table class="w-full text-xs" data-demo-fields>
                                <tr class="text-left" style="color: var(--text-muted);"><th class="py-1">Blank</th><th class="py-1">Linked to</th><th class="py-1">Sample</th><th class="py-1"></th></tr>
                                @foreach($demo['fields'] as $f)
                                    <tr style="border-top: 1px solid var(--border);" data-field-row="{{ $f['var'] }}" data-status="{{ $f['status'] }}" data-label="{{ mb_strtolower($f['label']) }}" data-typed="{{ $f['binding'] === 'Typed by hand while preparing' ? '1' : '0' }}" x-show="show($el)">
                                        <td class="py-1 font-semibold" style="color: var(--text-primary);">{{ $f['label'] }}</td>
                                        <td class="py-1" style="color: var(--text-secondary);">{{ $f['binding'] }}</td>
                                        <td class="py-1" style="color: var(--text-secondary);">{{ $f['status'] === 'empty' ? '(empty)' : $f['value'] }}</td>
                                        <td class="py-1 font-semibold whitespace-nowrap" style="color: {{ $statusColour[$f['status']] ?? 'var(--text-muted)' }};">{{ $statusText[$f['status']] ?? $f['status'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    @endif
                </div>

                {{-- Page frame --}}
                <div x-show="tab === 'frame'" x-cloak data-demo-panel="frame">
                    <h2 class="text-xs font-semibold mb-1" style="color: var(--text-primary);">Header on every page</h2>
                    @forelse($demo['frame']['header'] as $line)<p class="text-xs" style="color: var(--text-secondary);" data-frame-header>{{ $line }}</p>@empty<p class="text-xs" style="color: var(--text-muted);">The document has no running header.</p>@endforelse
                    <h2 class="text-xs font-semibold mt-3 mb-1" style="color: var(--text-primary);">Footer on every page</h2>
                    @forelse($demo['frame']['footer'] as $line)<p class="text-xs" style="color: var(--text-secondary);" data-frame-footer>{{ $line }}</p>@empty<p class="text-xs" style="color: var(--text-muted);">The document has no running footer.</p>@endforelse
                    <h2 class="text-xs font-semibold mt-3 mb-1" style="color: var(--text-primary);">Agency letterhead</h2>
                    <p class="text-xs" style="color: var(--text-secondary);" data-frame-letterhead>
                        @if($demo['frame']['display'] === 'none')Switched off for this template: the document prints its own letterhead, if it has one.
                        @elseif($demo['frame']['display'] === 'all_pages')Printed above the document on every page, from the owning agency's company details.
                        @else Printed once, at the top of the first page, from the owning agency's company details.@endif
                    </p>
                </div>

                {{-- Readiness --}}
                <div x-show="tab === 'readiness'" x-cloak data-demo-panel="readiness">
                    <p class="text-sm font-bold" style="color: {{ $verdictColour }};" data-demo-verdict>{{ $verdictText }}</p>
                    @forelse($readinessChecks as $c)
                        <div class="mt-2 text-xs" style="color: var(--text-secondary);" data-demo-check="{{ $c['id'] }}"><strong style="color: var(--text-primary);">{{ $c['title'] }}</strong> — {{ $c['detail'] }}@if($c['action'] !== '')<br><em>What to do: {{ $c['action'] }}</em>@endif</div>
                    @empty
                        <p class="text-xs mt-2" style="color: var(--text-muted);">Nothing to fix or look at.</p>
                    @endforelse
                    <a href="{{ route('docuperfect.templates.readiness', $template->id) }}" class="text-xs underline mt-3 inline-block" style="color: var(--brand-icon, #0ea5e9);">Open the full readiness report</a>
                </div>
            </div>
        </div>
    </div>
</div>

@unless($isPdf)
    {{-- The signing link's own paginator (CSS + paginateDocument), run on the sample-filled document in this browser. --}}
    @include('docuperfect.signatures.partials.a4-page-styles')
    @php $demoParties = $demo['parties']; @endphp
    <script>
    (function () {
        var parties = @json($demoParties);
        function run() {
            var c = document.getElementById('demoContainer');
            var countEl = document.querySelector('[data-demo-page-count]');
            var spreadEl = document.querySelector('[data-demo-page-spread]');
            if (!c || typeof paginateDocument !== 'function') { if (countEl) countEl.textContent = 'not measured'; return; }
            try { paginateDocument(c, parties); } catch (e) { if (countEl) countEl.textContent = 'could not be measured'; return; }
            var pages = Array.prototype.slice.call(c.querySelectorAll('.corex-a4-page'));
            if (countEl) countEl.textContent = String(pages.length);
            function where(sel, skip) {
                var out = [];
                pages.forEach(function (p, i) { if (p.querySelectorAll(sel).length - (skip ? p.querySelectorAll(skip).length : 0) > 0) out.push(i + 1); });
                return out;
            }
            var sig = where('[data-marker-type="signature"]:not(.corex-page-initials)');
            var ini = where('.initials-inline-line');
            if (spreadEl) spreadEl.textContent = 'Signature places on page(s): ' + (sig.length ? sig.join(', ') : 'none') + '. Initial places in the wording on page(s): ' + (ini.length ? ini.join(', ') : 'none') + '. CoreX also adds an initials strip at the foot of every page but the last.';
        }
        if (document.readyState === 'complete') { setTimeout(run, 50); } else { window.addEventListener('load', function () { setTimeout(run, 50); }); }
    })();
    </script>
@endunless
@endsection
