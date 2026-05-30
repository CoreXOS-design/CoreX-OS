{{--
    MIC Phase D6 — Market Pulse tab.
    Folds the legacy /admin/p24 surface into the unified MIC URL.
    Spec: .ai/specs/mic-complete-spec.md §5.6.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
--}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="width: 100%;">

    <x-mic-page-header
        title="Market Pulse"
        subtitle="Live P24 firehose · {{ number_format($suburbStats->count()) }} suburbs · {{ number_format($kpis['active_listings']) }} active listings.">
        @permission('manage_p24')
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.p24.import') }}" style="margin: 0;">
                @csrf
                <button type="submit" class="corex-btn-outline text-sm"
                        style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Run import
                </button>
            </form>
        </x-slot:actions>
        @endpermission
    </x-mic-page-header>

    @include('corex.market-intelligence.partials.tabs')

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="margin-bottom: 12px;
                    background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @include('corex.market-intelligence.partials.market-pulse-kpis')
    @include('corex.market-intelligence.partials.market-pulse-suburbs')
    @include('corex.market-intelligence.partials.market-pulse-listings')
    @include('corex.market-intelligence.partials.market-pulse-price-changes')
    @include('corex.market-intelligence.partials.market-pulse-import-log')

</div>

@include('corex.market-intelligence.partials.mic-slideover')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mic-suburb]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (e.target.closest('a, button')) return;
                e.preventDefault();
                const suburb = el.dataset.micSuburb;
                if (suburb) {
                    window.dispatchEvent(new CustomEvent('mic-open-suburb', { detail: { suburb } }));
                }
            });
            el.style.cursor = 'pointer';
        });
    });
</script>
@endsection
