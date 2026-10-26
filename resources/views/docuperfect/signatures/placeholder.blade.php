@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
@include('docuperfect.partials._dp-page-styles')
<div class="rx-page dp-page w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $title ?? 'Signatures' }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">This feature is under construction.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.rental') }}" class="corex-btn-outline text-xs shrink-0 inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Back to Rental Documents</a>
            </div>
        </div>
    </div>
    <div class="max-w-6xl space-y-5">

    <div class="ds-status-card p-8 text-center">
        <div class="text-slate-400 mb-3"><svg class="w-10 h-10 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg></div>
        <div class="text-sm text-slate-500">This page will be built in a future update.</div>
    </div>

</div>
</div>
@endsection
