@extends('layouts.corex')

{{--
  AT-352 item 2 — Agent live "View document" (READ-ONLY recipient mirror).

  Content-identical, read-only view of the EXACT accumulated document the current
  recipient is signing: every prior party's baked ink is already present. Renders
  the ONE canonical artifact (CanonicalDocumentRenderer::forDisplay) exactly as the
  agent-approval review + PDF do — but with NO action bar, NO approve/reject forms,
  NO amendment actions and NO write path of any kind. Light auto-poll refreshes the
  document when a new signature lands, without the agent reloading (J3).

  This is the read-only FOUNDATION only — the future full-edit layer (live agent
  edits + enforced re-initialing) is deliberately NOT built here.
--}}

@section('content')
@php
    $templateType  = $document->template?->template_type ?? 'rentals';
    $backRoute     = route('docuperfect.esign.myDocuments');
@endphp

@include('docuperfect.signatures.partials.a4-page-styles')
<style>
/* Read-only document container — every interactive element made inert. The
   DOCUMENT render itself is governed ENTIRELY by the shared canonical-spine
   partial (a4-page-styles), so this mirror renders identically to the signing
   screen — we only make it non-interactive. */
.review-doc-container .web-sig-interactive,
.review-doc-container .corex-page-initials,
.review-doc-container [data-marker-type] {
    pointer-events: none;
    cursor: default;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 space-y-4"
     x-data="viewLiveMirror({
        stateUrl: '{{ route('docuperfect.signatures.viewLive', $document) }}?state=1',
        pageUrl:  '{{ route('docuperfect.signatures.viewLive', $document) }}',
        version:  {{ (int) $pollVersion }},
        completed: {{ (int) $pollCompleted }},
     })">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ $backRoute }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to My E-Sign Documents
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">View Document (read-only) — {{ $document->name }}</h2>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Read-only mirror banner --}}
    <div class="rounded-sm border border-sky-200 bg-sky-50 p-4">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 bg-sky-100 rounded-full flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
            </div>
            <div>
                <div class="font-semibold text-sky-800">Read-only mirror of the recipient's view</div>
                <div class="text-sm text-sky-700 mt-1">
                    @if($currentRequest)
                        This is the exact document <strong>{{ $currentRequest->signer_name }}</strong>
                        ({{ ucfirst(preg_replace('/_\d+$/', '', $currentRequest->party_role ?? 'party')) }})
                        is viewing right now, with every prior party's signatures and initials already on it.
                    @else
                        The exact accumulated document, with every party's signatures and initials on it.
                    @endif
                    You cannot edit or sign here — new marks appear automatically as parties sign.
                    <span x-show="refreshing" x-cloak class="ml-1 italic text-sky-500">updating…</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Read-only signing progress summary --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5">
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Signing Progress</div>
        <div class="space-y-1">
            @foreach($progress as $role => $p)
                @php $roleLabel = ucfirst(preg_replace('/_\d+$/', '', $role)); @endphp
                <div class="flex items-center gap-3 text-sm py-1">
                    @if($p['is_complete'])
                        <span class="text-emerald-500 text-lg">&#10003;</span>
                        <span class="text-slate-600 w-20">{{ $roleLabel }}</span>
                        <span class="text-emerald-600 font-medium">{{ $p['name'] }}</span>
                        <span class="text-slate-400 text-xs ml-auto">
                            {{ $p['signed_markers'] }}/{{ $p['total_markers'] }} markers
                            @if($p['completed_at'])
                                &mdash; {{ $p['completed_at']->format('d M H:i') }}
                            @endif
                        </span>
                    @elseif(!empty($p['is_deferred']))
                        <span class="text-amber-500 text-lg">&#9208;</span>
                        <span class="text-amber-600 w-20">{{ $roleLabel }}</span>
                        <span class="text-amber-600 font-medium">{{ $p['name'] ?: '(unknown)' }} &mdash; Deferred</span>
                    @else
                        <span class="text-slate-300 text-lg">&#128274;</span>
                        <span class="text-slate-400 w-20">{{ $roleLabel }}</span>
                        <span class="text-slate-400">{{ $p['name'] }} &mdash; waiting</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- FULL DOCUMENT (read-only) — identical render to the recipient's screen --}}
    <div class="rounded-sm border border-slate-200 bg-white p-5">
        <h4 class="font-semibold text-slate-800 mb-3">Document</h4>

        @if(!empty($isWebTemplate) && $webTemplateHtml)
            {{-- Web template: render the accumulated canonical inline (read-only) --}}
            <link href="/css/corex-document.css" rel="stylesheet">
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
            <div class="review-doc-container border border-slate-200 rounded-lg" style="background:#e2e8f0; padding:16px;">
                <div id="reviewDocContent">
                    {!! $webTemplateHtml !!}
                </div>
            </div>
            <script>
                function renderViewLiveDoc() {
                    var container = document.getElementById('reviewDocContent');
                    if (!container) return;
                    paginateDocument(container, @json($signingParties ?? []));
                    (function () {
                        var segTitles = @json($packSegmentTitles ?? []);
                        var wraps = container.querySelectorAll('.corex-document-wrapper');
                        Array.prototype.forEach.call(wraps, function (w, i) {
                            if (w.querySelector('.review-seg-title')) return;
                            var h = document.createElement('div');
                            h.className = 'review-seg-title';
                            h.textContent = segTitles[i] || ('Document ' + (i + 1));
                            h.style.cssText = 'font-weight:700;font-size:13px;color:#0f172a;background:#e2e8f0;border-bottom:2px solid #94a3b8;padding:8px 12px;margin:0 0 6px;';
                            w.insertBefore(h, w.firstChild);
                        });
                    })();
                    restoreStoredInitials(container, @json($storedInitials ?? []));
                    restoreStoredDisclosure(container, @json($disclosureAnswers ?? []));
                }
                document.addEventListener('DOMContentLoaded', renderViewLiveDoc);
            </script>
        @else
            {{-- PDF/image-based template: page images + read-only overlays --}}
            <div class="space-y-4">
                @for($pageNum = 0; $pageNum < $pageCount; $pageNum++)
                    <div class="relative border border-slate-200 rounded-lg overflow-hidden">
                        <img src="{{ $pageImages[$pageNum] ?? '' }}" alt="Page {{ $pageNum + 1 }}" class="w-full h-auto">

                        @if(empty($hasFlattened))
                            @php
                                $docFields   = $document->fields_json ?? [];
                                $pageMarkers = $allMarkers->where('page_number', $pageNum + 1);
                                $pageFields  = collect($docFields)->where('pageIndex', $pageNum);
                            @endphp

                            @foreach($pageFields as $field)
                                @php
                                    $type = $field['type'] ?? 'placeholder';
                                    $pos = $field['position'] ?? [];
                                    $size = $field['size'] ?? [];
                                    $style = $field['style'] ?? [];
                                    $x = $pos['x'] ?? 0;
                                    $y = $pos['y'] ?? 0;
                                    $w = $size['width'] ?? 0;
                                    $h = $size['height'] ?? 0;
                                    $fontSize = $style['fontSize'] ?? 12;
                                    $fontFamily = $style['fontFamily'] ?? 'Helvetica';
                                    $bold = !empty($style['bold']) ? 'font-weight:bold;' : '';
                                    $underline = !empty($style['underline']) ? 'text-decoration:underline;' : '';
                                    $solidBg = !empty($style['solidBackground']) ? 'background:white;' : '';
                                    $fieldCss = "font-size:{$fontSize}px;font-family:{$fontFamily};color:#000;{$bold}{$underline}{$solidBg}";
                                @endphp

                                @if($type === 'placeholder' && !empty(trim((string)($field['value'] ?? ''))))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                             style="{{ $fieldCss }}">{{ $field['value'] }}</div>
                                    </div>
                                @elseif($type === 'date' && !empty(trim((string)($field['value'] ?? ''))))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                             style="{{ $fieldCss }}">{{ $field['value'] }}</div>
                                    </div>
                                @elseif($type === 'selection' && !empty($field['selectedValue']))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full flex items-center px-0.5 overflow-hidden" style="{{ $fieldCss }}">
                                            <span class="bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded text-xs">{{ $field['selectedValue'] }}</span>
                                        </div>
                                    </div>
                                @elseif($type === 'condition' && !empty(trim((string)($field['text'] ?? ''))))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        <div class="w-full h-full overflow-hidden px-0.5 bg-white/85"
                                             style="{{ $fieldCss }}">{{ $field['text'] }}</div>
                                    </div>
                                @elseif($type === 'strikethrough' && !empty($field['active']))
                                    <div class="absolute pointer-events-none overflow-hidden"
                                         style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                        @if(($field['strikethroughType'] ?? 'horizontal') === 'horizontal')
                                            <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                        @else
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                                <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                            </svg>
                                        @endif
                                    </div>
                                @endif
                            @endforeach

                            @foreach($pageMarkers as $marker)
                                @php $sig = $marker->signatures->first(); @endphp
                                <div class="absolute border-2 rounded"
                                     style="left: {{ $marker->x_position }}%; top: {{ $marker->y_position }}%; width: {{ $marker->width }}%; height: {{ $marker->height }}%; z-index:10; {{ $sig ? 'border-color: #10b981;' : 'border-color: #d1d5db; border-style: dashed;' }}">
                                    @if($sig && $sig->signature_data)
                                        <img src="{{ $sig->signature_data }}" class="w-full h-full object-contain" alt="Signature">
                                    @endif
                                </div>
                            @endforeach
                        @endif

                        <div class="absolute bottom-2 right-2 bg-white/80 text-xs text-slate-500 px-2 py-0.5 rounded">
                            Page {{ $pageNum + 1 }}
                        </div>
                    </div>
                @endfor
            </div>
        @endif
    </div>

</div>

<script>
/* Light auto-poll — a new signature appears without the agent reloading (J3).
   Polls a read-only JSON fingerprint; when the canonical version or completed
   count changes, refetches THIS page, swaps the document body, and re-renders.
   Falls back to a full reload on any error. No write path. */
function viewLiveMirror(cfg) {
    return {
        version: cfg.version,
        completed: cfg.completed,
        refreshing: false,
        _timer: null,
        init() {
            this._timer = setInterval(() => this.check(), 10000);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.check();
            });
        },
        async check() {
            if (this.refreshing) return;
            try {
                const res = await fetch(cfg.stateUrl, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const s = await res.json();
                if (s.version !== this.version || s.completed !== this.completed) {
                    this.version = s.version;
                    this.completed = s.completed;
                    await this.refresh();
                }
            } catch (e) { /* transient — try again next tick */ }
        },
        async refresh() {
            this.refreshing = true;
            try {
                const res = await fetch(cfg.pageUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) { window.location.reload(); return; }
                const html = await res.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const fresh = doc.getElementById('reviewDocContent');
                const current = document.getElementById('reviewDocContent');
                if (fresh && current && typeof renderViewLiveDoc === 'function') {
                    current.innerHTML = fresh.innerHTML;
                    renderViewLiveDoc();
                } else {
                    window.location.reload();
                }
            } catch (e) {
                window.location.reload();
            } finally {
                this.refreshing = false;
            }
        },
    };
}
</script>
@endsection
