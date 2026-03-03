@extends('layouts.corex')

@section('corex-content')
<style>
#pdf-splitter-root, #pdf-splitter-root * { box-sizing: border-box; }

#pdf-splitter-root {
    color: #0f172a;
}

#pdf-splitter-root .wrap {
    max-width: 680px;
    margin: 0 auto;
}

#pdf-splitter-root .field { margin-bottom: 20px; }

#pdf-splitter-root input[type="text"],
#pdf-splitter-root input[type="file"] {
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    color: #0f172a;
    background: #f8fafc;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}

#pdf-splitter-root input[type="text"]:focus {
    border-color: #0b2a4a;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.1);
}

#pdf-splitter-root .field-error {
    font-size: 0.8rem;
    color: #b91c1c;
    margin-top: 4px;
}
</style>

<div id="pdf-splitter-root">
    <div class="wrap">

        {{-- Navy header bar --}}
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">PDF Pack Splitter</h2>
                    <div class="text-sm text-white/60">Upload a pack PDF and set a base name</div>
                </div>
                @if(auth()->user()?->isEffectiveAdmin())
                <a href="{{ route('admin.splitter.doc-types.index') }}"
                   class="px-3 py-1.5 bg-white/10 text-white text-xs font-medium rounded hover:bg-white/20">
                    Manage Labels
                </a>
                @endif
            </div>
        </div>

        {{-- Status message --}}
        @if(session('status'))
            <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm font-medium">
                {{ session('status') }}
            </div>
        @endif

        {{-- Validation errors --}}
        @if($errors->any())
            <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm">
                <ul style="margin:0;padding-left:18px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
            <h3 class="ds-section-header" style="margin-bottom:1rem;">Upload PDF</h3>
            <p class="text-sm text-gray-500 mb-4">OCR runs automatically &mdash; you'll review and correct labels before the ZIP is generated.</p>

            <form method="POST"
                  action="{{ route('tools.pdf_splitter.run') }}"
                  enctype="multipart/form-data">
                @csrf

                <div class="field">
                    <label class="ds-label block mb-1">Base Name</label>
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
                    <label class="ds-label block mb-1">PDF File <span style="font-weight:400;color:#64748b;">(max 50 MB)</span></label>
                    <input type="file"
                           id="pdf"
                           name="pdf"
                           accept="application/pdf">
                    @error('pdf')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="corex-btn-primary" style="padding: 0.625rem 1.5rem;">
                    Upload PDF
                </button>
            </form>
        </div>

    </div>
</div>

@if (session('splitter_download_url'))
    <iframe src="{{ session('splitter_download_url') }}" style="display:none; width:0; height:0; border:0;"></iframe>
@endif
@endsection