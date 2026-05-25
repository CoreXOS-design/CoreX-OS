@extends('layouts.corex')

@section('corex-content')
<x-page-header title="PDF Merge" subtitle="Combine multiple PDFs into one packet." :flush="true" />
@include('tools.pdf-suite._switcher')

<div class="p-4 lg:p-8">
    <div class="max-w-5xl mx-auto">
        @include('tools.pdf-suite._alerts')
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M12 11v6"/><path d="m9 14 3 3 3-3"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">When to use</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Build offer packets — OTP + FICA + proof of funds + ID</li>
                        <li>• Combine bank statements into one attachment</li>
                        <li>• Files merge in the order you select them</li>
                        <li>• Hold <kbd>Ctrl</kbd> / <kbd>Cmd</kbd> to multi-select</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Merge multiple PDFs</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Select 2 or more PDFs.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.merge.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }">
                        @csrf
                        <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF Files</label>
                        <input type="file" name="pdfs[]" accept="application/pdf" multiple required @change="hasFile = $event.target.files.length >= 2" class="w-full px-3 py-2.5 rounded-md text-sm mb-5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Merge &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
