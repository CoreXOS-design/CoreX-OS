@extends('layouts.corex')

@section('corex-content')
<x-page-header title="Reorder / Delete Pages" subtitle="Rearrange or drop pages from a PDF." :flush="true" />
@include('tools.pdf-suite._switcher')

<div class="p-4 lg:p-8">
    <div class="max-w-5xl mx-auto">
        @include('tools.pdf-suite._alerts')
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h13"/><path d="M3 12h13"/><path d="M3 18h13"/><path d="m18 9 3 3-3 3"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">How it works</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• List the page numbers in the order you want them</li>
                        <li>• Omit any page to delete it from the output</li>
                        <li>• Example: <code style="color: var(--text-primary);">3,1,4</code> = page 3 first, drops page 2</li>
                        <li>• Useful for dropping blank pages from scans</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Reorder or delete pages</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Comma-separated page numbers (1-based).</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.reorder.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File</label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="hasFile = $event.target.files.length > 0" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">New page order (CSV)</label>
                        <input type="text" name="order" required placeholder="e.g. 1,2,4,5" class="w-full px-3 py-2.5 rounded-md text-sm mb-5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Apply &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
