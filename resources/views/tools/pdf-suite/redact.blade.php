@extends('layouts.corex')

@section('corex-content')
<x-page-header title="PDF Redact" subtitle="Black out IDs and bank details — POPIA-safe true redaction." :flush="true" />
@include('tools.pdf-suite._switcher')

<div class="p-4 lg:p-8" x-data="redactPicker()">
    <div class="max-w-5xl mx-auto">
        @include('tools.pdf-suite._alerts')
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="6" y="8" width="9" height="3" fill="currentColor"/><rect x="6" y="14" width="12" height="3" fill="currentColor"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">POPIA-safe</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• True redaction — text is destroyed, not just hidden</li>
                        <li>• Coordinates in PDF points (1pt ≈ 1/72 inch)</li>
                        <li>• Origin (0,0) is the bottom-left of the page</li>
                        <li>• A4 page is 595 × 842 points</li>
                        <li>• Add multiple rectangles per file</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Redact a PDF</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Add one or more rectangles. The page is rasterised before redaction.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.redact.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }" @submit="$refs.rectsField.value = JSON.stringify(rects)">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File</label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="hasFile = $event.target.files.length > 0" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <input type="hidden" name="rects" x-ref="rectsField">

                        <label class="block text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-secondary);">Redaction rectangles</label>
                        <div class="space-y-2 mb-3">
                            <template x-for="(r, i) in rects" :key="i">
                                <div class="flex flex-wrap gap-2 items-center text-xs p-2 rounded-md" style="background: var(--surface-2);">
                                    <span style="color: var(--text-secondary);">Page</span>
                                    <input type="number" min="1" x-model.number="r.page" class="w-14 px-2 py-1 rounded-md" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <span style="color: var(--text-secondary);">x</span>
                                    <input type="number" min="0" x-model.number="r.x" class="w-20 px-2 py-1 rounded-md" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <span style="color: var(--text-secondary);">y</span>
                                    <input type="number" min="0" x-model.number="r.y" class="w-20 px-2 py-1 rounded-md" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <span style="color: var(--text-secondary);">w</span>
                                    <input type="number" min="1" x-model.number="r.w" class="w-20 px-2 py-1 rounded-md" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <span style="color: var(--text-secondary);">h</span>
                                    <input type="number" min="1" x-model.number="r.h" class="w-20 px-2 py-1 rounded-md" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <button type="button" @click="rects.splice(i,1)" class="ml-auto px-2 py-1 rounded-md text-xs" style="background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);">Remove</button>
                                </div>
                            </template>
                        </div>
                        <button type="button" @click="rects.push({page:1,x:0,y:0,w:200,h:30})" class="corex-btn-outline text-xs">+ Add rectangle</button>
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full mt-5">Redact &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function redactPicker() { return { rects: [{page:1,x:0,y:0,w:200,h:30}] }; }
</script>
@endsection
