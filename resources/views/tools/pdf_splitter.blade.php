{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<style>
#pdf-splitter-root, #pdf-splitter-root * { box-sizing: border-box; }

#pdf-splitter-root {
    color: var(--text-primary);
}

#pdf-splitter-root .wrap {
    max-width: 680px;
    margin: 0 auto;
}

#pdf-splitter-root .field { margin-bottom: 1.25rem; }

/* Labels */
#pdf-splitter-root label {
    display: block;
    color: var(--text-secondary);
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.05em;
    margin-bottom: 6px;
}

/* Inputs */
#pdf-splitter-root input[type="text"],
#pdf-splitter-root input[type="file"] {
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.875rem;
    color: var(--text-primary);
    background: var(--surface);
    outline: none;
    transition: border-color 300ms, box-shadow 300ms;
}

#pdf-splitter-root input[type="text"]:focus,
#pdf-splitter-root input[type="file"]:focus {
    border-color: var(--brand-button, #0ea5e9);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-button, #0ea5e9) 15%, transparent);
}

#pdf-splitter-root .field-error {
    font-size: 0.75rem;
    color: var(--ds-crimson, #c41e3a);
    margin-top: 6px;
}

/* Card */
#pdf-splitter-root .upload-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 1.5rem;
    border-left: 3px solid var(--brand-icon, #0ea5e9);
    transition: box-shadow 300ms;
}

#pdf-splitter-root .upload-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

#pdf-splitter-root .upload-card h3 {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 0.5rem 0;
}

#pdf-splitter-root .upload-card .subtitle {
    font-size: 0.8125rem;
    color: var(--text-secondary);
    margin-bottom: 1.25rem;
}

/* Alert boxes */
#pdf-splitter-root .alert-success {
    padding: 0.75rem 1rem;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
    color: var(--text-primary);
    margin-bottom: 1.25rem;
}

#pdf-splitter-root .alert-error {
    padding: 0.75rem 1rem;
    border-radius: 6px;
    font-size: 0.875rem;
    background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
    color: var(--text-primary);
    margin-bottom: 1.25rem;
}

#pdf-splitter-root .alert-error ul {
    margin: 0;
    padding-left: 18px;
}

/* File input hint */
#pdf-splitter-root .label-hint {
    font-weight: 400;
    color: var(--text-muted);
    text-transform: none;
    letter-spacing: normal;
    font-size: 0.6875rem;
}
</style>

<div class="space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">PDF Pack Splitter</h1>
                <p class="text-sm text-white/60">OCR-driven splitting of multi-document PDF packs into labelled files.</p>
            </div>
        </div>
    </div>

    @include('tools.pdf-suite._switcher')

<div id="pdf-splitter-root">
    <div class="wrap">

        {{-- Status message --}}
        @if(session('status'))
            <div class="alert-success">
                {{ session('status') }}
            </div>
        @endif

        {{-- AT-105 — FICA verification(s) kicked off from the split pack. One
             line per distinct contact (many-to-many → multiple parties). --}}
        @if(session('splitter_fica_results'))
            <div class="alert-success" style="border-left: 3px solid #8b5cf6;">
                <div style="font-weight:600; margin-bottom:4px;">Wet-ink FICA verification{{ count(session('splitter_fica_results')) === 1 ? '' : 's' }} from this pack:</div>
                <ul style="margin:0; padding-left:18px;">
                    @foreach(session('splitter_fica_results') as $f)
                        <li>
                            <strong>{{ $f['contact'] ?? 'the contact' }}</strong>
                            @if(!empty($f['reused']))
                                — already had a verification in progress; opened that one (no duplicate).
                            @else
                                — started with {{ $f['slots'] ?? 0 }} document{{ ($f['slots'] ?? 0) === 1 ? '' : 's' }} attached.
                            @endif
                            <a href="{{ $f['url'] }}" style="text-decoration: underline; font-weight: 600;">Open to finish &rarr;</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @elseif(session('splitter_fica_note'))
            <div class="alert-error">
                {{ session('splitter_fica_note') }}
            </div>
        @endif

        {{-- Validation errors --}}
        @if($errors->any())
            <div class="alert-error">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="upload-card">
            <h3>Upload PDF</h3>
            <p class="subtitle">OCR runs automatically &mdash; you'll review and correct labels before the ZIP is generated.</p>

            <form id="pdf-upload-form"
                  method="POST"
                  action="{{ route('tools.pdf_splitter.run') }}"
                  enctype="multipart/form-data"
                  x-data="{ hasFile: false }">
                @csrf

                <div class="field">
                    <label for="base_name">Base Name</label>
                    <input type="text"
                           id="base_name"
                           name="base_name"
                           value="{{ old('base_name') }}"
                           maxlength="120"
                           placeholder="e.g. OceanView_Pack">
                    @error('base_name')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label for="pdf">PDF File <span class="label-hint">(max 50 MB)</span></label>
                    <input type="file"
                           id="pdf"
                           name="pdf"
                           accept="application/pdf"
                           @change="hasFile = $event.target.files.length > 0">
                    @error('pdf')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" :disabled="!hasFile"
                        :class="hasFile ? 'corex-btn-primary' : 'opacity-50 cursor-not-allowed corex-btn-primary'"
                        class="text-sm w-full">Upload &amp; Split</button>
            </form>
        </div>

    </div>
</div>

</div>{{-- /space-y-5 --}}

@if (session('splitter_download_url'))
    <iframe src="{{ session('splitter_download_url') }}" style="display:none; width:0; height:0; border:0;"></iframe>
@endif
@endsection
