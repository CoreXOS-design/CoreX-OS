@extends('layouts.corex')

@section('corex-content')
<x-page-header title="PDF Rotate" subtitle="Fix sideways scans — apply the same rotation to every page." :flush="true" />
@include('tools.pdf-suite._switcher')

<div class="p-4 lg:p-8">
    <div class="max-w-5xl mx-auto">
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
                        <li>• Need per-page rotation? Use <strong>Reorder</strong> instead</li>
                    </ul>
                </div>
            </div>
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Rotate every page</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Same rotation applied across the entire document.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.rotate.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File</label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="hasFile = $event.target.files.length > 0" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">Rotation</label>
                        <select name="angle" class="w-full px-3 py-2.5 rounded-md text-sm mb-5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="90">90° clockwise</option>
                            <option value="180">180°</option>
                            <option value="270">270° (90° counter-clockwise)</option>
                        </select>
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Rotate &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
