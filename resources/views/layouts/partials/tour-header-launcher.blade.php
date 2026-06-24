{{-- ════════════════════════════════════════════════════════════════════════
     COREX TOUR — shared page-header launcher slot (AT-41).

     Johan + Andre's decision: the "?" tour launcher lives in each page's
     HEADER. This is the single shared partial that page headers @include.

     It renders the launcher slot ONLY when TourRegistry resolves a tour for
     the current route — so a tour-less page never shows a dead "?" button, and
     there is exactly ONE source of truth (the registry) for whether the
     launcher appears. The tour engine (layouts/partials/tour-engine.blade.php)
     finds #tour-launcher-slot and relocates the real "?" button into it; if a
     page forgets to include this partial, the engine degrades gracefully to a
     floating button.

     Usage — drop inside a page header's action group:
       @include('layouts.partials.tour-header-launcher')
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__hdrTour = \App\Support\Tours\TourRegistry::forRoute(
        \Illuminate\Support\Facades\Route::currentRouteName()
    );
@endphp
@if($__hdrTour && \App\Support\Tours\TourRegistry::visibleTo($__hdrTour, auth()->user()))
    {{-- Empty slot; the tour engine appends the "?" button here on init(). --}}
    <div id="tour-launcher-slot"
         data-tour-header-slot
         style="flex-shrink:0; display:flex; align-items:center;"></div>
@endif
@endauth
