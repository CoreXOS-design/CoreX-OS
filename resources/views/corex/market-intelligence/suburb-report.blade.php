{{-- Suburb Report — combined CMA-vs-CoreX picture (Johan, 2026-08-25, plus
     the 2026-08-25 visuals/print/upload pass). The actual report content is
     in _suburb-report-body.blade.php, shared with the print/PDF view so the
     numbers a seller is shown on screen and the page handed to them can
     never disagree. This file is just the screen's own chrome: header,
     suburb picker, upload-a-CMA action, and the print/PDF buttons. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto space-y-6">

    <x-mic-page-header
        title="{{ $data['suburb']['name'] ?? ('#' . $suburb->id) }}"
        subtitle="{{ $data['suburb']['municipality_confirmed'] ? $data['suburb']['municipality'] . ' — ' : '' }}Suburb Report — as at {{ \Illuminate\Support\Carbon::parse($data['layer_b']['as_at'] ?? now())->format('d M Y, H:i') }}">
        <x-slot:actions>
            <a href="{{ route('market-intelligence.suburb-report.print', $suburb) }}" target="_blank" class="corex-btn-outline">Print</a>
            <a href="{{ route('market-intelligence.suburb-report.pdf', $suburb) }}" class="corex-btn-primary">Download PDF</a>
        </x-slot:actions>
    </x-mic-page-header>

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex flex-col md:flex-row gap-4 md:items-end md:justify-between">
            <div class="flex-1">
                @include('corex.market-intelligence._suburb-report-picker', ['currentSuburbName' => $data['suburb']['name'] ?? null])
            </div>

            {{-- Upload a CMA for THIS suburb, right here (Johan, 2026-08-25:
                 "asking an agent to upload here, and draw a report there, is
                 a problem"). Lands back on this same suburb once parsed. --}}
            @permission('mic.upload_reports')
            <form method="POST" action="{{ route('market-intelligence.suburb-report.upload-cma', $suburb) }}" enctype="multipart/form-data"
                  class="flex items-center gap-2" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <label class="corex-btn-outline" style="cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem;">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    <span x-show="!busy">{{ $data['layer_a']['available'] ? 'Upload another CMA' : 'Upload a CMA for ' . ($data['suburb']['name'] ?? 'this suburb') }}</span>
                    <span x-show="busy" x-cloak>Uploading…</span>
                    <input type="file" name="file" accept="application/pdf" class="sr-only" onchange="this.form.requestSubmit()" required>
                </label>
            </form>
            @endpermission
        </div>
        @if(session('status'))
        <div class="mt-3 rounded-md px-3 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
        @endif
        @if(session('error'))
        <div class="mt-3 rounded-md px-3 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
        @endif
    </div>

    {{-- No CMA on file yet — the upload action IS the obvious next step,
         not buried at the bottom (Johan: "that action should be the
         obvious next step on screen, not buried"). --}}
    @if(!$data['layer_a']['available'])
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--brand-icon) 8%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
        <div class="flex-1">
            <strong>No CMA report on file for this suburb yet.</strong> Upload one above to see it alongside your own CoreX figures below.
        </div>
    </div>
    @endif

    @include('corex.market-intelligence._suburb-report-body')

</div>
@endsection
