{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" data-tour="tools-pdf-suite-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">PDF Suite</h1>
                <p class="text-sm text-white/60">Nine tools for everything you need to do with a PDF — split, compress, merge, rotate, redact, enhance and more.</p>
            </div>
            @include('layouts.partials.tour-header-launcher')
            @permission('access_settings')
                @if(\Illuminate\Support\Facades\Route::has('admin.splitter.doc-types.index'))
                <a href="{{ route('admin.splitter.doc-types.index') }}"
                   title="PDF Suite Settings"
                   aria-label="PDF Suite Settings"
                   class="inline-flex items-center justify-center rounded-md text-white transition-colors"
                   style="width:30px; height:30px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endif
            @endpermission
        </div>
    </div>

    @include('tools.pdf-suite._switcher')

    <div class="max-w-7xl mx-auto w-full">

        @php
            $tools = [
                ['route' => 'tools.pdf_splitter.index',     'title' => 'PDF Splitter',     'desc' => 'Split a multi-document PDF pack into labelled files using OCR.', 'icon' => 'split'],
                ['route' => 'tools.pdf_suite.compress',     'title' => 'Compress',         'desc' => 'Shrink file size before emailing to banks or attorneys.',         'icon' => 'compress'],
                ['route' => 'tools.pdf_suite.merge',        'title' => 'Merge',            'desc' => 'Combine multiple PDFs into one packet — OTP + FICA + ID.',        'icon' => 'merge'],
                ['route' => 'tools.pdf_suite.image-to-pdf', 'title' => 'Image to PDF',     'desc' => 'Turn JPG, PNG or HEIC photos into a clean PDF document.',         'icon' => 'image'],
                ['route' => 'tools.pdf_suite.rotate',       'title' => 'Rotate',           'desc' => 'Fix sideways scans — 90°, 180° or 270° rotation.',                'icon' => 'rotate'],
                ['route' => 'tools.pdf_suite.reorder',      'title' => 'Reorder / Delete', 'desc' => 'Drag pages into order, drop blank pages from scans.',             'icon' => 'reorder'],
                ['route' => 'tools.pdf_suite.protect',      'title' => 'Password Protect', 'desc' => 'Lock or unlock a PDF — protect commission statements.',           'icon' => 'lock'],
                ['route' => 'tools.pdf_suite.redact',       'title' => 'Redact',           'desc' => 'Black out IDs and bank details — POPIA-safe true redaction.',      'icon' => 'redact'],
                ['route' => 'tools.pdf_suite.enhance',      'title' => 'Enhance',          'desc' => 'Make a blurry scan or photo readable — de-blur, sharpen, clean up.', 'icon' => 'enhance'],
            ];

            $svgFor = function (string $key) {
                return match ($key) {
                    'split'    => '<path d="M16 3h5v5"/><path d="M4 20 21 3"/><path d="M21 16v5h-5"/><path d="m15 15 6 6"/><path d="M4 4l5 5"/>',
                    'compress' => '<path d="m21 8-4-4-4 4"/><path d="M17 4v9"/><path d="m3 16 4 4 4-4"/><path d="M7 20v-9"/>',
                    'merge'    => '<path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 10h18"/><rect x="3" y="6" width="18" height="16" rx="2"/><path d="M12 11v6"/><path d="m9 14 3 3 3-3"/>',
                    'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.81.01L6 21"/>',
                    'rotate'   => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
                    'reorder'  => '<path d="M3 6h13"/><path d="M3 12h13"/><path d="M3 18h13"/><path d="m18 9 3 3-3 3"/>',
                    'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 1 1 8 0v4"/><circle cx="12" cy="16" r="1"/>',
                    'redact'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><rect x="6" y="8" width="9" height="3" fill="currentColor"/><rect x="6" y="14" width="12" height="3" fill="currentColor"/>',
                    'enhance'  => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/>',
                    default    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
                };
            };
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5" data-tour="tools-pdf-suite-grid">
            @foreach($tools as $t)
                @if(\Illuminate\Support\Facades\Route::has($t['route']))
                <a href="{{ route($t['route']) }}"
                   @if($loop->first) data-tour="tools-pdf-suite-first-card" @endif
                   @if($t['icon'] === 'redact') data-tour="tools-pdf-suite-redact-card" @endif
                   class="group block rounded-md transition-all duration-300 hover:-translate-y-0.5"
                   style="background: var(--surface); border: 1px solid var(--border); text-decoration: none; min-height: 180px;">
                    <div class="p-6 h-full flex flex-col">
                        <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4 transition-all duration-300"
                             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                {!! $svgFor($t['icon']) !!}
                            </svg>
                        </div>
                        <h3 class="font-semibold text-base mb-1.5" style="color: var(--text-primary);">{{ $t['title'] }}</h3>
                        <p class="text-sm leading-relaxed flex-1" style="color: var(--text-secondary);">{{ $t['desc'] }}</p>
                        <div class="mt-4 inline-flex items-center gap-1.5 text-xs font-medium transition-all duration-300"
                             style="color: var(--brand-icon, #0ea5e9);">
                            Open
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="transition-transform duration-300 group-hover:translate-x-0.5">
                                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                            </svg>
                        </div>
                    </div>
                </a>
                @endif
            @endforeach
        </div>

    </div>

</div>

<style>
    .group:hover {
        box-shadow: 0 4px 20px -4px color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);
        border-color: color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, var(--border)) !important;
    }
</style>
@endsection
