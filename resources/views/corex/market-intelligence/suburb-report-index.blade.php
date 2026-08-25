{{-- Suburb Report — landing/picker page. UI_DESIGN_SYSTEM.md Pattern A header. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto space-y-6">

    <x-mic-page-header
        title="Suburb Report"
        subtitle="Your own CoreX stock and sales, alongside any imported CMA figures for that suburb." />

    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Pick a suburb to see its report</h3>
        <p class="text-sm mb-5" style="color: var(--text-muted);">Stock on market, price reductions, sold and under-offer activity, buyer demand — and any CMA reports on file for that suburb, shown side by side.</p>
        <div class="mx-auto flex justify-center">
            @include('corex.market-intelligence._suburb-report-picker')
        </div>
    </div>

</div>
@endsection
