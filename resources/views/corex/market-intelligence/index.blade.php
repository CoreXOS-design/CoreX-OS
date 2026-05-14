@extends('layouts.corex-app')

@section('corex-content')
{{--
    F.6 — Market Intelligence index dispatcher.

    The controller now routes ?mode=analyse straight to analyse() which
    renders corex.market-intelligence.analyse with all the Analyse-mode
    bundles. Work mode (default) renders the F.3 Work mode shell.

    Spec: build-f-market-intelligence-redesign-spec.md §3, §8, §9.
--}}

@php
    $mode = request('mode', 'work') === 'analyse' ? 'analyse' : 'work';
@endphp

@if($mode === 'analyse')
    @include('corex.market-intelligence.analyse')
@else
    @include('corex.market-intelligence.work')
@endif
@endsection
