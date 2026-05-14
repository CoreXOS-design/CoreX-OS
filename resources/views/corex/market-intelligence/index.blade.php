@extends('layouts.corex-app')

@section('corex-content')
{{--
    F.2 — Market Intelligence index dispatcher.

    Routes the request to the right body based on the ?mode= param. The mode
    toggle and admin in-stock toggle moved into _top-bar (rendered inside
    work.blade.php), so this dispatcher is now minimal.

    Spec: build-f-market-intelligence-redesign-spec.md §3, §8, §9.
--}}

@php
    $mode = request('mode', 'work') === 'analyse' ? 'analyse' : 'work';
@endphp

@if($mode === 'analyse')
    @include('corex.market-intelligence._top-bar')
    <div class="rounded-md py-16 px-6 text-center"
         style="background: var(--surface); border: 1px solid var(--border); border-top: none; color: var(--text-secondary); margin: 0 16px;">
        <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary);">
            Analyse mode — coming in F.6
        </h2>
        <p class="text-sm max-w-xl mx-auto">
            Ellie's strategic brief, demand-supply matrix, opportunity pockets,
            market velocity and competitive landscape ship in F.6.
        </p>
        <a href="{{ route('market-intelligence.index', array_merge(request()->except(['mode']), ['mode' => 'work'])) }}"
           class="inline-block mt-4 text-xs font-semibold no-underline"
           style="color: var(--brand-icon);">
            ← Back to Work mode
        </a>
    </div>
@else
    @include('corex.market-intelligence.work')
@endif
@endsection
