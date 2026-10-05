@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14.19 — bulk-load a price list: download
    template, upload CSV/XLSX, dry-run preview on the next screen. Nothing
    is written to the catalogue until that preview screen's Confirm button.
--}}

@section('content')
<div class="p-6 space-y-4 max-w-2xl">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Import Parts &amp; Labour Catalogue</h1>
        <a href="{{ route('corex.rental-catalogue-items.index') }}" class="text-xs underline" style="color: var(--text-muted);">&larr; Back to catalogue</a>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
        <p class="text-sm">1. Download the template, fill in your items, then upload it below.</p>
        <a href="{{ route('corex.rental-catalogue-items.import.template') }}" class="corex-btn-outline text-xs inline-block">Download template</a>
    </div>

    <form method="POST" action="{{ route('corex.rental-catalogue-items.import.upload') }}" enctype="multipart/form-data" class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <div>
            <label class="text-sm font-medium">2. Upload your filled-in file</label><br>
            <input type="file" name="file" accept=".csv,.xlsx,.txt" required class="text-xs mt-2">
            <p class="text-xs mt-1" style="color: var(--text-muted);">CSV or XLSX, up to 50MB.</p>
        </div>

        <div>
            <label class="text-sm font-medium">3. If a code in your file already exists in your catalogue</label>
            <div class="mt-2 space-y-1 text-sm">
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

        <button type="submit" class="corex-btn-primary text-sm">Preview import</button>
        <p class="text-xs" style="color: var(--text-muted);">Nothing is added or changed yet — the next screen shows exactly what will happen, row by row, before anything is saved.</p>
    </form>
</div>
@endsection
