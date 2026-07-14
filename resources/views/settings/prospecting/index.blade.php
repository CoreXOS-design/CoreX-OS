@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('corex.settings') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Settings</a>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">Prospecting Setup</h1>
                <p class="text-sm text-white/60">Towns, property types, bedroom segments and price bands for your agency's prospecting.</p>
            </div>
            {{-- AT-246 — Regions door retired from nav; region assignment lives on
                 the single P24 Suburb Mappings surface. --}}
        </div>
    </div>

    @include('settings.prospecting._panel', [
        'activeTab'         => $activeTab,
        'towns'             => $towns,
        'propertyTypes'     => $propertyTypes,
        'bedroomSegments'   => $bedroomSegments,
        'priceBandsSale'    => $priceBandsSale,
        'priceBandsRental'  => $priceBandsRental,
        'suggestionRegions' => $suggestionRegions ?? [],
        'unmappedSuburbs'   => $unmappedSuburbs ?? collect(),
        'buyerMatchTier'    => $buyerMatchTier ?? null,
        'context'           => 'page',
    ])

</div>
@endsection
