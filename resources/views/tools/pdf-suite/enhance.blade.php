@extends('layouts.corex')

@section('corex-content')
<div class="space-y-5">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">PDF Enhance</h1>
                <p class="text-sm text-white/60">Make a blurry scan or photo readable — de-blur, sharpen and clean up.</p>
            </div>
        </div>
    </div>

    @include('tools.pdf-suite._switcher')
    <div class="max-w-5xl mx-auto">
        @include('tools.pdf-suite._alerts')

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            {{-- Info pane --}}
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/></svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">When to use</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Blurry or low-contrast photographed IDs, FICA forms and utility bills</li>
                        <li>• Faint or soft scanned mandates that are hard to read</li>
                        <li>• Accepts a <strong>PDF</strong> or a single <strong>image</strong> (JPG / PNG / HEIC / WEBP)</li>
                        <li>• Output is always a clean, readable PDF</li>
                        <li>• <strong>Auto</strong> suits most documents; <strong>Document</strong> is best for text</li>
                    </ul>
                </div>
            </div>

            {{-- Form pane --}}
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Enhance a PDF or image</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Pick a file and an enhancement mode. We sharpen and clean it on the server.</p>
                    <form id="pdf-suite-form" method="POST" action="{{ route('tools.pdf_suite.enhance.run') }}" enctype="multipart/form-data" x-data="{ hasFile: false }">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">PDF or Image <span class="text-xs font-normal normal-case" style="color: var(--text-muted);">(max 50 MB)</span></label>
                            <input type="file" name="file" accept="application/pdf,image/*" required @change="hasFile = $event.target.files.length > 0" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div class="mb-5">
                            <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">Enhancement mode</label>
                            <select name="preset" class="w-full px-3 py-2.5 rounded-md text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                @foreach(config('pdf-suite.enhance_presets') as $key => $label)
                                    <option value="{{ $key }}" @selected($key === 'auto')>{{ ucfirst($key) }} — {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" :disabled="!hasFile" :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'" class="text-sm w-full">Enhance &amp; Download</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
