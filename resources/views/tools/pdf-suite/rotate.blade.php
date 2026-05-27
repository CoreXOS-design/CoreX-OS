@extends('layouts.corex')

@section('corex-content')
<div class="space-y-5" x-data="pdfRotate()">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">PDF Rotate</h1>
                <p class="text-sm text-white/60">Rotate the whole document or individual pages.</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">When to use</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Sideways phone-scanned IDs</li>
                        <li>• Upside-down utility bills</li>
                        <li>• Misoriented mandate scans</li>
                        <li>• Click <strong>↻</strong> on a thumbnail to rotate just that page</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Rotate pages</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Use the quick-rotate-all buttons, or rotate individual pages from their thumbnails.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.rotate.run') }}" enctype="multipart/form-data" @submit="rotationsField.value = JSON.stringify(rotations)">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File</label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="loadPdf($event)" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <input type="hidden" name="rotations" x-ref="rotationsField" value="{}">

                        <div x-show="pages.length > 0" class="mb-4">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Rotate all pages</label>
                                <span class="text-xs" style="color: var(--text-muted);" x-text="pages.length + ' pages'"></span>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" @click="rotateAll(90)" class="corex-btn-outline text-xs flex-1">+90°</button>
                                <button type="button" @click="rotateAll(180)" class="corex-btn-outline text-xs flex-1">+180°</button>
                                <button type="button" @click="rotateAll(270)" class="corex-btn-outline text-xs flex-1">−90°</button>
                                <button type="button" @click="resetAll()" class="corex-btn-outline text-xs flex-1">Reset</button>
                            </div>
                        </div>

                        <div x-show="loading" class="text-sm py-6 text-center" style="color: var(--text-muted);">Loading preview…</div>

                        <div x-show="pages.length > 0" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-5 max-h-[720px] overflow-y-auto p-1">
                            <template x-for="(p, idx) in pages" :key="p.num">
                                <div class="relative rounded-md p-3 group" style="background: var(--surface-2); border: 1px solid var(--border);">
                                    <div class="flex items-center justify-center overflow-hidden" style="height: 280px;">
                                        <img :src="p.dataUrl" :style="'transform: rotate(' + (rotations[p.num] || 0) + 'deg); max-width: 100%; max-height: 100%; transition: transform 200ms;'">
                                    </div>
                                    <div class="flex items-center justify-between mt-2">
                                        <span class="text-xs" style="color: var(--text-secondary);" x-text="'Page ' + p.num"></span>
                                        <button type="button" @click="rotatePage(p.num)" title="Rotate 90°"
                                            class="w-7 h-7 rounded-md flex items-center justify-center text-sm"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">↻</button>
                                    </div>
                                    <div x-show="rotations[p.num]" class="absolute top-1 right-1 text-[10px] px-1.5 py-0.5 rounded"
                                        style="background: var(--brand-icon, #0ea5e9); color: white;"
                                        x-text="(rotations[p.num] || 0) + '°'"></div>
                                </div>
                            </template>
                        </div>

                        <button type="submit" :disabled="pages.length === 0" :class="pages.length > 0 ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Rotate &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function pdfRotate() {
    return {
        pages: [],
        rotations: {},
        loading: false,
        get rotationsField() { return this.$refs.rotationsField; },
        async loadPdf(e) {
            const file = e.target.files[0];
            this.pages = [];
            this.rotations = {};
            if (!file) return;
            this.loading = true;
            try {
                const buf = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
                const out = [];
                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const viewport = page.getViewport({ scale: 0.6 });
                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                    out.push({ num: i, dataUrl: canvas.toDataURL('image/png') });
                }
                this.pages = out;
            } catch (err) {
                console.error(err);
                alert('Could not read the PDF: ' + err.message);
            } finally {
                this.loading = false;
            }
        },
        rotatePage(num) {
            const cur = this.rotations[num] || 0;
            const next = (cur + 90) % 360;
            if (next === 0) { delete this.rotations[num]; } else { this.rotations[num] = next; }
        },
        rotateAll(angle) {
            this.pages.forEach(p => { this.rotations[p.num] = angle; });
        },
        resetAll() { this.rotations = {}; },
    };
}
</script>
@endsection
