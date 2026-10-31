@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
@include('docuperfect.partials._dp-page-styles')
<div class="rx-page dp-page w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Create Document</h1>
                <p class="text-xs" style="color: var(--text-muted);">Template: {{ $template->name }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.create') }}" class="corex-btn-outline text-xs shrink-0 inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Back</a>
            </div>
        </div>
    </div>
    <div class="max-w-xl space-y-5">

    <form method="POST" action="{{ route('docuperfect.documents.store', $template->id) }}" class="ds-status-card p-6 space-y-4">
        @csrf

        <div>
            <label for="doc_name" class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Document Name</label>
            <input type="text" name="name" id="doc_name"
                   value="{{ old('name', $suggestedName) }}"
                   class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-[color:var(--brand-icon)] focus:border-[color:var(--brand-icon)]"
                   style="border-color:var(--border);background:var(--surface);color:var(--text-primary)"
                   required maxlength="255" autofocus>
            @error('name')
                <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
            @enderror
            <div class="text-xs mt-1" style="color:var(--text-faint)">You can customise the document name before creating it.</div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="corex-btn-primary text-sm px-5 py-2" style="background:#10b981;">
                <i class="fas fa-plus mr-1"></i> Create Document
            </button>
            <a href="{{ route('docuperfect.create') }}" class="text-sm hover:text-[color:var(--text-secondary)]" style="color:var(--text-muted)">Cancel</a>
        </div>
    </form>

</div>
</div>
@endsection
