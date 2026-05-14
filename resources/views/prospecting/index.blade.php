@extends('layouts.corex-app')

@section('corex-content')
{{--
    Build F.1 shim — the legacy /prospecting URL kept mounted for the migration
    window renders identically to pre-F.1 by re-including the body partial.
    The new /corex/market-intelligence URL uses corex/market-intelligence/index.blade.php
    which wraps the same partial inside the Work/Analyse mode toggle.

    Delete this file in F.6 once nothing internal references the legacy route name.
--}}
@include('prospecting.index_legacy_body')
@endsection
