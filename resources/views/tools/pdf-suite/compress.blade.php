@extends('layouts.corex')

@section('corex-content')
<x-page-header title="PDF Compress" subtitle="Reduce file size before emailing to banks or attorneys." :flush="true" />
@include('tools.pdf-suite._switcher')

<div class="p-4 lg:p-8">
    <div class="max-w-5xl mx-auto">
        @include('tools.pdf-suite._alerts')

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            {{-- Info pane --}}
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 8-4-4-4 4"/><path d="M17 4v9"/><path d="m3 16 4 4 4-4"/><path d="M7 20v-9"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">When to use</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Bank bond applications often reject &gt;10MB attachments</li>
                        <li>• Email scanned FICA bundles without splitting</li>
                        <li>• <strong>Ebook</strong> is the recommended preset for most documents</li>
                        <li>• Use <strong>Printer</strong> only when print-quality matters</li>
                    </ul>
                </div>
            </div>

            {{-- Form pane --}}
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Compress a PDF</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Pick a file and quality level. We use Ghostscript on the server.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.compress.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF File <span class="text-xs font-normal normal-case" style="color: var(--text-muted);">(max 50 MB)</span></label>
                            <input type="file" name="pdf" accept="application/pdf" required @change="hasFile = $event.target.files.length > 0" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div class="mb-5">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">Quality</label>
                            <select name="quality" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                @foreach(config('pdf-suite.compress_presets') as $key => $label)
                                    <option value="{{ $key }}" @selected($key === 'ebook')>{{ ucfirst($key) }} — {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Compress &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
