@extends('layouts.corex-app')

@section('corex-content')
{{--
    Build F.1 — Market Intelligence shim.

    Renders the legacy Work-mode body verbatim via the prospecting/index_legacy_body
    partial. The mode toggle (Work | Analyse) is wired through the ?mode= query
    string; both arms render the same legacy body for now. F.2 onwards reshapes
    the Work arm; F.6 ships the Analyse arm body.

    The "Show in-stock too" admin toggle is gated on prospecting_setup.manage
    and writes ?include_in_stock=1 to the URL. The controller's applyInStockFilter()
    reads this flag.

    Spec: .ai/specs/build-f-market-intelligence-redesign-spec.md §3, §7, §17 (F.1).
--}}

@php
    $mode = request('mode', 'work') === 'analyse' ? 'analyse' : 'work';
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;
    $includeInStock = (bool) request()->boolean('include_in_stock');

    // Preserve all existing query params when flipping the mode toggle.
    $workQuery    = array_merge(request()->except(['mode']), ['mode' => 'work']);
    $analyseQuery = array_merge(request()->except(['mode']), ['mode' => 'analyse']);
@endphp

<div class="max-w-7xl mx-auto px-4 pt-4">
    {{-- Mode toggle bar --}}
    <div class="flex items-center justify-between gap-3 mb-4 pb-3" style="border-bottom: 1px solid var(--border);">
        <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            <a href="{{ route('market-intelligence.index', $workQuery) }}"
               class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider no-underline"
               style="{{ $mode === 'work' ? 'background: var(--brand-default); color: #fff;' : 'background: var(--surface); color: var(--text-secondary);' }}">
                Work
            </a>
            <a href="{{ route('market-intelligence.index', $analyseQuery) }}"
               class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider no-underline"
               style="{{ $mode === 'analyse' ? 'background: var(--brand-default); color: #fff;' : 'background: var(--surface); color: var(--text-secondary);' }}">
                Analyse
            </a>
        </div>

        @if($isManager)
        {{-- Admin in-stock audit toggle. Default off ⇒ matched_property_id IS NULL applied. --}}
        <label class="inline-flex items-center gap-2 text-xs cursor-pointer"
               style="color: var(--text-secondary);"
               title="Audit-only: include listings already promoted to agency stock">
            <input type="checkbox"
                   {{ $includeInStock ? 'checked' : '' }}
                   onchange="(function(cb){
                       const url = new URL(window.location.href);
                       if (cb.checked) { url.searchParams.set('include_in_stock','1'); }
                       else { url.searchParams.delete('include_in_stock'); }
                       window.location.href = url.toString();
                   })(this)">
            Show in-stock listings too (audit)
        </label>
        @endif
    </div>

    @if($mode === 'analyse')
        {{-- F.1 placeholder. F.6 replaces this body with Ellie strategic brief, demand-supply
             matrix, opportunity pockets, market velocity and competitive landscape. --}}
        <div class="rounded-md py-16 px-6 text-center"
             style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
            <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary);">
                Analyse mode — coming in F.6
            </h2>
            <p class="text-sm max-w-xl mx-auto">
                Ellie's strategic brief, demand-supply matrix, opportunity pockets,
                market velocity and competitive landscape ship in F.6.
            </p>
            <a href="{{ route('market-intelligence.index', $workQuery) }}"
               class="inline-block mt-4 text-xs font-semibold no-underline"
               style="color: var(--brand-icon);">
                ← Back to Work mode
            </a>
        </div>
    @else
        @include('prospecting.index_legacy_body')
    @endif
</div>
@endsection
