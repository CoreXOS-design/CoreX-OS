{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Image Converter</h1>
                <p class="text-sm text-white/60">Convert HEIC, JPG, PNG, WEBP, BMP, TIFF or GIF photos into any other image format.</p>
            </div>
        </div>
    </div>

    <div class="max-w-5xl mx-auto w-full">
        @include('tools.pdf-suite._alerts')

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            {{-- Left: when to use --}}
            <div class="lg:col-span-2">
                <div class="rounded-md p-6 h-full" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-center w-12 h-12 rounded-md mb-4"
                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m21 16-4-4-4 4"/><path d="M17 20V12"/>
                            <path d="M3 8l4 4 4-4"/><path d="M7 4v8"/>
                        </svg>
                    </div>
                    <h3 class="font-semibold text-base mb-2" style="color: var(--text-primary);">When to use</h3>
                    <ul class="text-sm space-y-2" style="color: var(--text-secondary);">
                        <li>• Convert iPhone HEIC photos to PNG or JPG</li>
                        <li>• Strip transparency for email attachments (JPG)</li>
                        <li>• Re-encode TIFF or BMP scans to web-friendly PNG</li>
                        <li>• EXIF orientation is auto-corrected</li>
                        <li>• Multiple files are returned as a ZIP</li>
                    </ul>
                </div>
            </div>

            {{-- Right: convert form --}}
            <div class="lg:col-span-3">
                <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <h3 class="font-semibold text-base mb-1" style="color: var(--text-primary);">Convert images</h3>
                    <p class="text-sm mb-5" style="color: var(--text-secondary);">Up to 50 files, 50 MB each.</p>

                    <form method="POST" action="{{ route('tools.image_converter.run') }}" enctype="multipart/form-data"
                          x-data="imageConverterForm()" @submit.prevent="submit($event)">
                        @csrf

                        <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">Images</label>
                        <input type="file" name="images[]" accept="image/*,.heic,.heif"
                               multiple required
                               x-ref="fileInput"
                               @change="onFileChange($event)"
                               :disabled="busy"
                               class="w-full px-3 py-2.5 rounded-md text-sm mb-5"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">

                        <label class="block text-xs font-semibold uppercase tracking-wide mb-1.5" style="color: var(--text-secondary);">Output format</label>
                        <select name="format" required x-ref="formatSelect" :disabled="busy"
                                class="w-full px-3 py-2.5 rounded-md text-sm mb-5"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="png" selected>PNG — lossless, supports transparency</option>
                            <option value="jpg">JPG — smaller, no transparency</option>
                            <option value="webp">WEBP — modern, compact</option>
                        </select>

                        {{-- Progress bar --}}
                        <div x-show="busy" x-cloak class="mb-5">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);" x-text="phaseLabel"></span>
                                <span class="text-xs font-mono" style="color: var(--text-secondary);" x-text="phase === 'upload' ? pct + '%' : ''"></span>
                            </div>
                            <div class="w-full h-2 rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <div class="h-full transition-all duration-200"
                                     :class="phase === 'convert' ? 'image-converter-indeterminate' : ''"
                                     :style="phase === 'upload' ? ('width: ' + pct + '%; background: var(--brand-icon, #0ea5e9);') : 'background: var(--brand-icon, #0ea5e9);'"></div>
                            </div>
                        </div>

                        {{-- Inline error --}}
                        <div x-show="errorMsg" x-cloak class="mb-5 px-3 py-2 rounded-md text-sm"
                             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent); color: var(--text-primary);"
                             x-text="errorMsg"></div>

                        <button type="submit"
                                :disabled="!hasFile || busy"
                                :class="(!hasFile || busy) ? 'opacity-50 cursor-not-allowed corex-btn-primary' : 'corex-btn-primary'"
                                class="text-sm w-full">
                            <span x-show="!busy">Convert &amp; Download</span>
                            <span x-show="busy" x-cloak x-text="phase === 'upload' ? 'Uploading…' : 'Converting…'"></span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
    @keyframes image-converter-indet {
        0%   { transform: translateX(-100%); }
        100% { transform: translateX(400%); }
    }
    .image-converter-indeterminate {
        width: 25% !important;
        animation: image-converter-indet 1.2s ease-in-out infinite;
    }
    [x-cloak] { display: none !important; }
</style>

<script>
function imageConverterForm() {
    return {
        hasFile: false,
        busy: false,
        phase: 'upload',          // 'upload' | 'convert'
        pct: 0,
        errorMsg: '',
        get phaseLabel() { return this.phase === 'upload' ? 'Uploading' : 'Converting'; },

        onFileChange(e) {
            this.hasFile = e.target.files.length > 0;
            this.errorMsg = '';
        },

        submit(e) {
            if (!this.hasFile || this.busy) return;
            this.errorMsg = '';
            this.busy = true;
            this.phase = 'upload';
            this.pct = 0;

            const form = e.target;
            const fd = new FormData(form);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);
            xhr.responseType = 'blob';
            xhr.setRequestHeader('Accept', 'application/octet-stream, application/zip, image/*, text/html');

            xhr.upload.onprogress = (ev) => {
                if (ev.lengthComputable) {
                    this.pct = Math.min(99, Math.round((ev.loaded / ev.total) * 100));
                }
            };
            xhr.upload.onload = () => {
                this.phase = 'convert';
                this.pct = 100;
            };
            xhr.onerror = () => {
                this.busy = false;
                this.errorMsg = 'Network error during upload.';
            };
            xhr.onload = () => {
                this.busy = false;
                if (xhr.status >= 200 && xhr.status < 300) {
                    const blob = xhr.response;
                    const cd = xhr.getResponseHeader('Content-Disposition') || '';
                    const m = cd.match(/filename\*?="?([^;"]+)"?/i);
                    const filename = m ? decodeURIComponent(m[1].replace(/^UTF-8''/i, '')) : 'converted';
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url; a.download = filename;
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                } else {
                    // Try to parse Laravel validation/back-with-errors HTML
                    const reader = new FileReader();
                    reader.onload = () => {
                        const text = String(reader.result || '');
                        const match = text.match(/<li>([^<]+)<\/li>/);
                        this.errorMsg = match ? match[1] : ('Conversion failed (HTTP ' + xhr.status + ').');
                    };
                    reader.readAsText(xhr.response);
                }
            };
            xhr.send(fd);
        },
    };
}
</script>
@endsection
