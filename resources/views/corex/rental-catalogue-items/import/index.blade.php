@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14.19 — bulk-load a price list: download
    template, upload CSV/XLSX, dry-run preview on the next screen. Nothing
    is written to the catalogue until that preview screen's Confirm button.
--}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Import Parts &amp; Labour Catalogue</h1>
                <p class="text-xs" style="color: var(--text-muted);">Load a price list from a file. Nothing is saved until you confirm on the preview screen.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('corex.rental-catalogue-items.index') }}" class="corex-btn-outline text-xs">&larr; Back to catalogue</a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm max-w-3xl" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="rounded-md p-6 space-y-3 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rmnt-import-template">
        <p class="text-sm font-medium" style="color: var(--text-primary);">1. Download the template, fill in your items, then upload it below.</p>
        <a href="{{ route('corex.rental-catalogue-items.import.template') }}" class="corex-btn-outline text-sm inline-flex">Download template</a>
    </div>

    <form method="POST" action="{{ route('corex.rental-catalogue-items.import.upload') }}" enctype="multipart/form-data" class="rounded-md p-6 space-y-5 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <div data-tour="rmnt-import-file">
            <label class="text-sm font-medium" style="color: var(--text-primary);">2. Upload your filled-in file</label>
            <input type="file" name="file" accept=".csv,.xlsx,.txt" required class="prop-input mt-2">
            <p class="text-xs mt-1" style="color: var(--text-muted);">CSV or XLSX, up to 50MB.</p>
        </div>

        <div data-tour="rmnt-import-dupes">
            <label class="text-sm font-medium" style="color: var(--text-primary);">3. If a code in your file already exists in your catalogue</label>
            <div class="mt-2 space-y-2 text-sm" style="color: var(--text-primary);">
                <label class="flex items-center gap-2">
                    <input type="radio" name="on_duplicate" value="update" required>
                    Update the existing item with the file's values
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="on_duplicate" value="skip" required>
                    Skip it — leave the existing item unchanged
                </label>
            </div>
        </div>

        <div class="pt-4" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary text-sm" data-tour="rmnt-import-preview">Preview import</button>
        </div>
        <p class="text-xs" style="color: var(--text-muted);">Nothing is added or changed yet — the next screen shows exactly what will happen, row by row, before anything is saved.</p>
    </form>
</div>
@endsection
