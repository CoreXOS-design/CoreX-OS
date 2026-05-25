@extends('layouts.corex')

@section('corex-content')
<x-page-header title="Image to PDF" subtitle="Turn JPG, PNG or HEIC photos into a clean PDF." :flush="true" />
@include('tools.pdf-suite._switcher')

<div class="p-4 lg:p-8">
    <div class="max-w-5xl mx-auto">
        @include('tools.pdf-suite._alerts')
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.81.01L6 21"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">When to use</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Phone photos of signed OTPs or FICA forms</li>
                        <li>• Photographed IDs and utility bills</li>
                        <li>• Each image becomes one page</li>
                        <li>• EXIF orientation is auto-corrected</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Images to PDF</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">JPG / PNG / HEIC / WEBP supported.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.image-to-pdf.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }">
                        @csrf
                        <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">Images</label>
                        <input type="file" name="images[]" accept="image/*" multiple required @change="hasFile = $event.target.files.length > 0" class="w-full px-3 py-2.5 rounded-md text-sm mb-5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Convert &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
