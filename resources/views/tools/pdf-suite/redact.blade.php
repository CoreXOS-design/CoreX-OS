@extends('layouts.corex')

@section('corex-content')
<div class="space-y-5" x-data="pdfRedact()">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">PDF Redact</h1>
                <p class="text-sm text-white/60">Click-drag to draw black-out boxes — POPIA-safe true redaction.</p>
            </div>
        </div>
    </div>

    @include('tools.pdf-suite._switcher')
    @include('tools.pdf-suite._pdfjs')

    <div>
    <div class="max-w-7xl mx-auto">
        @include('tools.pdf-suite._alerts')
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="6" y="8" width="9" height="3" fill="currentColor"/><rect x="6" y="14" width="12" height="3" fill="currentColor"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">POPIA-safe</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Click and drag on any page to draw a black-out box</li>
                        <li>• Click an existing box to remove it</li>
                        <li>• True redaction — text is destroyed, not just hidden</li>
                        <li>• Pages are rasterised before redaction</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Redact a PDF</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Draw rectangles on the preview to mark what to redact.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.redact.run') }}" enctype="multipart/form-data" @submit="rectsField.value = JSON.stringify(serializeRects())">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File</label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="loadPdf($event)" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <input type="hidden" name="rects" x-ref="rectsField">

                        <div x-show="pages.length > 0" class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Pages</span>
                            <div class="flex items-center gap-3">
                                <span class="text-xs" style="color: var(--text-muted);" x-text="totalRects() + ' rectangle(s)'"></span>
                                <button type="button" @click="clearAll()" class="text-xs underline" style="color: var(--text-secondary);">Clear all</button>
                            </div>
                        </div>

                        <div x-show="loading" class="text-sm py-6 text-center" style="color: var(--text-muted);">Loading preview…</div>

                        <div x-show="pages.length > 0" class="space-y-4 mb-5 max-h-[900px] overflow-y-auto p-1">
                            <template x-for="(p, i) in pages" :key="p.num">
                                <div>
                                    <div class="text-xs mb-1.5" style="color: var(--text-secondary);" x-text="'Page ' + p.num"></div>
                                    <div class="relative inline-block w-full" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
                                        <img :src="p.dataUrl" :data-page="p.num"
                                            @mousedown.prevent="startDraw($event, p.num)"
                                            @mousemove="moveDraw($event, p.num)"
                                            @mouseup="endDraw($event, p.num)"
                                            @mouseleave="cancelDraw()"
                                            class="block w-full select-none" style="cursor: crosshair;">
                                        <template x-for="(r, ri) in rectsByPage[p.num] || []" :key="ri">
                                            <div @click="removeRect(p.num, ri)"
                                                class="absolute cursor-pointer"
                                                :style="rectStyle(p.num, r)"></div>
                                        </template>
                                        <div x-show="drawing && drawing.page === p.num"
                                            class="absolute pointer-events-none"
                                            :style="drawing && drawing.page === p.num ? rectStyle(p.num, drawing) : ''"></div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <button type="submit" :disabled="pages.length === 0 || totalRects() === 0" :class="(pages.length > 0 && totalRects() > 0) ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Redact &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function pdfRedact() {
    return {
        // pages: [{num, dataUrl, pdfWidth, pdfHeight}]  — pdfWidth/pdfHeight in PDF points
        pages: [],
        rectsByPage: {},   // {pageNum: [{x,y,w,h}]}  — coords in fraction of image (0..1)
        loading: false,
        drawing: null,     // {page, x, y, w, h, startClientX, startClientY}
        get rectsField() { return this.$refs.rectsField; },
        async loadPdf(e) {
            const file = e.target.files[0];
            this.pages = []; this.rectsByPage = {}; this.drawing = null;
            if (!file) return;
            this.loading = true;
            try {
                const buf = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
                const out = [];
                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const baseViewport = page.getViewport({ scale: 1 });
                    const viewport = page.getViewport({ scale: 1.2 });
                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                    out.push({
                        num: i,
                        dataUrl: canvas.toDataURL('image/png'),
                        pdfWidth: baseViewport.width,    // PDF points
                        pdfHeight: baseViewport.height,
                    });
                }
                this.pages = out;
            } catch (err) {
                console.error(err);
                alert('Could not read the PDF: ' + err.message);
            } finally {
                this.loading = false;
            }
        },
        relCoords(e) {
            const img = e.target.closest('div.relative').querySelector('img');
            const rect = img.getBoundingClientRect();
            return {
                x: Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)),
                y: Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height)),
            };
        },
        startDraw(e, pageNum) {
            const p = this.relCoords(e);
            this.drawing = { page: pageNum, x: p.x, y: p.y, w: 0, h: 0 };
        },
        moveDraw(e, pageNum) {
            if (!this.drawing || this.drawing.page !== pageNum) return;
            const p = this.relCoords(e);
            this.drawing.w = p.x - this.drawing.x;
            this.drawing.h = p.y - this.drawing.y;
        },
        endDraw(e, pageNum) {
            if (!this.drawing || this.drawing.page !== pageNum) return;
            // Normalise negative width/height
            let { x, y, w, h } = this.drawing;
            if (w < 0) { x += w; w = -w; }
            if (h < 0) { y += h; h = -h; }
            if (w > 0.01 && h > 0.01) {
                if (!this.rectsByPage[pageNum]) this.rectsByPage[pageNum] = [];
                this.rectsByPage[pageNum].push({ x, y, w, h });
            }
            this.drawing = null;
        },
        cancelDraw() { this.drawing = null; },
        removeRect(pageNum, idx) {
            if (!this.rectsByPage[pageNum]) return;
            this.rectsByPage[pageNum].splice(idx, 1);
        },
        clearAll() { this.rectsByPage = {}; },
        totalRects() {
            return Object.values(this.rectsByPage).reduce((a, b) => a + b.length, 0);
        },
        rectStyle(pageNum, r) {
            let { x, y, w, h } = r;
            if (w < 0) { x += w; w = -w; }
            if (h < 0) { y += h; h = -h; }
            return `left: ${x * 100}%; top: ${y * 100}%; width: ${w * 100}%; height: ${h * 100}%; background: rgba(0,0,0,0.85); border: 1px solid #fff;`;
        },
        serializeRects() {
            // Send fractional coords (0..1) with TOP-LEFT origin — controller multiplies by image px.
            const out = [];
            this.pages.forEach(p => {
                const arr = this.rectsByPage[p.num] || [];
                arr.forEach(r => {
                    let { x, y, w, h } = r;
                    if (w < 0) { x += w; w = -w; }
                    if (h < 0) { y += h; h = -h; }
                    out.push({ page: p.num, x: x, y: y, w: w, h: h });
                });
            });
            return out;
        },
    };
}
</script>
@endsection
