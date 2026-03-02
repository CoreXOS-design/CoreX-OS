@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">{{ $title ?? 'Signatures' }}</h2>
            <div class="text-sm text-white/60">This feature is under construction.</div>
        </div>
        <a href="{{ route('docuperfect.rental') }}" class="corex-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">
            Back to Rental Documents
        </a>
    </div>

    <div class="ds-status-card p-8 text-center">
        <div class="text-slate-400 text-4xl mb-3">&#9998;</div>
        <div class="text-sm text-slate-500">This page will be built in a future update.</div>
    </div>

</div>
@endsection
