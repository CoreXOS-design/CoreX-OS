@extends('layouts.corex')

@section('corex-content')
<style>
/* Remove main's padding so the sticky bar can truly touch the top */
#appScroll { padding: 0 !important; }

#pdf-splitter-root, #pdf-splitter-root * { box-sizing: border-box; }

#pdf-splitter-root {
    color: var(--text-primary);
}

#pdf-splitter-root .wrap {
    max-width: 680px;
    margin: 0 auto;
    padding: 0 1.5rem;
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

<x-page-header title="PDF Pack Splitter" :flush="true">
    <x-slot:actions>
        @permission('calculators.manage')
        <a href="{{ route('admin.splitter.doc-types.index') }}" class="corex-btn-outline text-xs">Manage Labels</a>
        @endpermission
        <button type="submit" form="pdf-upload-form" class="corex-btn-primary text-sm">Upload &amp; Split</button>
    </x-slot:actions>
</x-page-header>

<div class="p-4 lg:p-6">
<div id="pdf-splitter-root">
    <div class="wrap">

        {{-- Status message --}}
        @if(session('status'))
            <div class="alert-success">
                {{ session('status') }}
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
                  enctype="multipart/form-data">
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
                           accept="application/pdf">
                    @error('pdf')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>
            </form>
        </div>

    </div>
</div>
</div>{{-- /p-4 lg:p-6 --}}

@if (session('splitter_download_url'))
    <iframe src="{{ session('splitter_download_url') }}" style="display:none; width:0; height:0; border:0;"></iframe>
@endif
@endsection
