@extends('layouts.corex')

@section('corex-content')
<div class="space-y-5" x-data="pdfReorder()">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Reorder / Delete Pages</h1>
                <p class="text-sm text-white/60">Drag thumbnails to reorder; click × to delete a page.</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h13"/><path d="M3 12h13"/><path d="M3 18h13"/><path d="m18 9 3 3-3 3"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">How it works</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Drag a thumbnail to a new position</li>
                        <li>• Click <strong>×</strong> on a thumbnail to delete that page</li>
                        <li>• Useful for dropping blank pages from scans</li>
                        <li>• Or build a custom packet order</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Reorder or delete pages</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Visual reorder — no CSV needed.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.reorder.run') }}" enctype="multipart/form-data" @submit="orderField.value = order.join(',')">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File</label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="loadPdf($event)" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <input type="hidden" name="order" x-ref="orderField">

                        <div x-show="order.length > 0" class="flex items-center justify-between mb-2">
                            <label class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Page order</label>
                            <div class="flex items-center gap-3">
                                <span class="text-xs" style="color: var(--text-muted);" x-text="order.length + ' of ' + totalPages + ' pages'"></span>
                                <button type="button" @click="reset()" class="text-xs underline" style="color: var(--text-secondary);">Reset</button>
                            </div>
                        </div>

                        <div x-show="loading" class="text-sm py-6 text-center" style="color: var(--text-muted);">Loading preview…</div>

                        <div x-show="order.length > 0" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-5 max-h-[720px] overflow-y-auto p-1">
                            <template x-for="(num, i) in order" :key="num">
                                <div class="relative rounded-md p-3 cursor-move"
                                    style="background: var(--surface-2); border: 1px solid var(--border);"
                                    draggable="true"
                                    @dragstart="dragStart($event, i)"
                                    @dragover.prevent
                                    @drop.prevent="drop($event, i)">
                                    <div class="flex items-center justify-center overflow-hidden" style="height: 280px;">
                                        <img :src="thumbs[num]" style="max-width: 100%; max-height: 100%;">
                                    </div>
                                    <div class="flex items-center justify-between mt-2">
                                        <span class="text-xs" style="color: var(--text-secondary);">
                                            <span x-text="i + 1"></span>
                                            <span style="color: var(--text-muted);" x-text="'(p' + num + ')'"></span>
                                        </span>
                                        <button type="button" @click="remove(i)" title="Delete page"
                                            class="w-6 h-6 rounded-md flex items-center justify-center text-sm"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--ds-crimson, #c41e3a);">×</button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <button type="submit" :disabled="order.length === 0" :class="order.length > 0 ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Apply &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function pdfReorder() {
    return {
        order: [],
        thumbs: {},
        totalPages: 0,
        loading: false,
        dragIdx: null,
        get orderField() { return this.$refs.orderField; },
        async loadPdf(e) {
            const file = e.target.files[0];
            this.order = []; this.thumbs = {}; this.totalPages = 0;
            if (!file) return;
            this.loading = true;
            try {
                const buf = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
                this.totalPages = pdf.numPages;
                const order = [];
                const thumbs = {};
                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const viewport = page.getViewport({ scale: 0.6 });
                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                    thumbs[i] = canvas.toDataURL('image/png');
                    order.push(i);
                }
                this.thumbs = thumbs;
                this.order = order;
            } catch (err) {
                console.error(err);
                alert('Could not read the PDF: ' + err.message);
            } finally {
                this.loading = false;
            }
        },
        dragStart(e, i) { this.dragIdx = i; e.dataTransfer.effectAllowed = 'move'; },
        drop(e, i) {
            if (this.dragIdx === null || this.dragIdx === i) return;
            const moved = this.order.splice(this.dragIdx, 1)[0];
            this.order.splice(i, 0, moved);
            this.dragIdx = null;
        },
        remove(i) { this.order.splice(i, 1); },
        reset() {
            this.order = Object.keys(this.thumbs).map(n => parseInt(n, 10));
        },
    };
}
</script>
@endsection
