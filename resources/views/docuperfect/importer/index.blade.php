@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Import Document Template</h1>
                <p class="text-sm text-white/60">Upload a Word (.docx) or text-based PDF. CoreX detects markers and fields and converts it to an editable web template automatically.</p>
            </div>
        </div>
    </div>

    <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border);">

        @if(session('success'))
            <div class="rounded-md px-4 py-3 mb-4 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-md px-4 py-3 mb-4 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
                <span>{{ session('error') }}</span>
            </div>
        @endif

        {{-- CDS Engine Import — the one true import path (ES-6.5: Path A retired) --}}
        <form method="POST" action="{{ route('docuperfect.import.cds') }}" enctype="multipart/form-data"
              x-data="{ cdsFile: '', cdsSubmitting: false }"
              @submit="cdsSubmitting = true">
            @csrf
            <p class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Import with CoreX Document Engine</p>
            <p class="text-xs mb-3" style="color: var(--text-muted);">
                Uses the CDS parser for structured document import with field detection.
                Accepts Word (.docx) and text-based PDF.
            </p>
            @error('document')
                <p class="text-xs mb-3" style="color: var(--danger, #c0392b);">{{ $message }}</p>
            @enderror
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <input type="file" name="document" accept=".docx,.pdf" class="hidden" x-ref="cdsFileInput"
                           @change="cdsFile = $event.target.files[0]?.name || ''">
                    <div @click="$refs.cdsFileInput.click()"
                         class="cursor-pointer border border-dashed rounded-md px-4 py-2 text-center text-sm transition-colors"
                         style="border-color: var(--border); color: var(--text-secondary);">
                        <span x-text="cdsFile || 'Choose .docx or .pdf file...'"></span>
                    </div>
                </div>
                <button type="submit" :disabled="!cdsFile || cdsSubmitting"
                        class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-text="cdsSubmitting ? 'Importing...' : 'Import CDS'"></span>
                </button>
            </div>

            {{-- Marker guide --}}
            <div class="mt-3 rounded-md p-4"
                 style="background: var(--surface-2); border: 1px solid var(--border);">
                <h4 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color: var(--text-secondary);">
                    Preparing your document
                </h4>
                <p class="text-xs mb-3" style="color: var(--text-muted);">
                    Before uploading, replace fill-in areas in your Word document with these markers:
                </p>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold"
                              style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">@@@@</code>
                        <span style="color: var(--text-secondary);">Input field &mdash; where data needs to be filled in</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold"
                              style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber);">%%%%</code>
                        <span style="color: var(--text-secondary);">Signature block &mdash; where parties sign</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold"
                              style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">####</code>
                        <span style="color: var(--text-secondary);">Initial block &mdash; where parties initial</span>
                    </div>

                    {{-- ES-9 / ES-6 — insertable-block marker family --}}
                    <div class="mt-3 pt-3 rounded-md p-3"
                         style="border-top: 1px solid var(--border);
                                background: color-mix(in srgb, #92400e 5%, transparent);">
                        <p class="text-[11px] font-semibold uppercase tracking-wider mb-2"
                           style="color: #92400e;">
                            Insertable blocks (<span class="font-mono">~~~~</span>)
                        </p>
                        <div class="space-y-1.5">
                            <div class="flex items-start gap-2 text-xs">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, #92400e 12%, transparent); color: #92400e;">~~~~OTHER_CONDITIONS~~~~</code>
                            </div>
                            <p class="text-[11px] pl-1" style="color: var(--text-secondary);">
                                Other Conditions &mdash; numbered list for additional clauses (max 1 per template)
                            </p>

                            <div class="flex items-start gap-2 text-xs pt-1">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, #92400e 12%, transparent); color: #92400e;">~~~~INCLUDED_ITEMS~~~~</code>
                            </div>
                            <p class="text-[11px] pl-1" style="color: var(--text-secondary);">
                                Included items &mdash; fixtures, fittings, items in sale
                            </p>

                            <div class="flex items-start gap-2 text-xs pt-1">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, #92400e 12%, transparent); color: #92400e;">~~~~EXCLUDED_ITEMS~~~~</code>
                            </div>
                            <p class="text-[11px] pl-1" style="color: var(--text-secondary);">
                                Excluded items &mdash; what's NOT part of the sale
                            </p>

                            <div class="flex items-start gap-2 text-xs pt-1">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, #92400e 12%, transparent); color: #92400e;">~~~~CUSTOM:&lt;label&gt;~~~~</code>
                            </div>
                            <p class="text-[11px] pl-1" style="color: var(--text-secondary);">
                                Custom-named block &mdash; e.g. <code class="font-mono">~~~~CUSTOM:Outstanding Repairs~~~~</code>
                            </p>
                        </div>
                    </div>
                </div>
                <p class="text-xs mt-3" style="color: var(--text-muted);">
                    The system auto-detects fields from surrounding text. You can also tag fields manually after import.
                </p>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    <strong>Tip:</strong> Insertable blocks let recipients add conditions during signing.
                    The first three markers stay locked to their template positions;
                    <span class="font-mono">~~~~</span> markers become live insertable areas.
                </p>
            </div>
        </form>
    </div>
</div>

@endsection
