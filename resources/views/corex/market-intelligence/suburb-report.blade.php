{{-- Suburb Report — combined CMA-vs-CoreX picture (Johan, 2026-08-25, plus
     the 2026-08-25 visuals/print/upload pass). The actual report content is
     in _suburb-report-body.blade.php, shared with the print/PDF view so the
     numbers a seller is shown on screen and the page handed to them can
     never disagree. This file is just the screen's own chrome: header,
     suburb picker, upload-a-CMA action, and the print/PDF buttons. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" style="width: 100%;">

    <x-mic-page-header
        title="{{ $data['suburb']['name'] ?? ('#' . $suburb->id) }}"
        subtitle="{{ $data['suburb']['municipality_confirmed'] ? $data['suburb']['municipality'] . ' — ' : '' }}Suburb Report — as at {{ \Illuminate\Support\Carbon::parse($data['layer_b']['as_at'] ?? now())->format('d M Y, H:i') }}">
        <x-slot:actions>
            <a href="{{ route('market-intelligence.suburb-report.print', $suburb) }}?{{ $sectionsQuery }}" target="_blank" class="corex-btn-outline">Print</a>
            <a href="{{ route('market-intelligence.suburb-report.pdf', $suburb) }}?{{ $sectionsQuery }}" class="corex-btn-primary">Download PDF</a>
        </x-slot:actions>
    </x-mic-page-header>

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @include('corex.market-intelligence._suburb-report-picker', ['currentSuburbName' => $data['suburb']['name'] ?? null])
    </div>

    {{-- Per-section controls — Johan, 2026-08-25: "the tick should be per
         section... an agent putting a report in front of a seller must be
         able to leave out the agency's own thin stock while still showing
         the market picture." A global convenience pair sets every section
         at once; the per-section row is what actually matters and is what
         gets submitted (GET form, so it's a normal shareable/printable URL
         — no JS round-trip needed for it to take effect). --}}
    <details class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" open>
        <summary class="text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">What to include in this report</summary>
        <form method="GET" action="{{ route('market-intelligence.suburb-report', $suburb) }}" class="mt-3 space-y-3" x-data="{
            all(field, val) {
                this.$el.querySelectorAll('input[data-field=' + field + ']').forEach(el => el.checked = val);
            }
        }">
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="self-center" style="color: var(--text-secondary);">Set all sections:</span>
                <button type="button" class="corex-btn-outline" style="padding:0.25rem 0.6rem;" @click="all('agency', true)">Show all agency sides</button>
                <button type="button" class="corex-btn-outline" style="padding:0.25rem 0.6rem;" @click="all('agency', false)">Hide all agency sides</button>
                <button type="button" class="corex-btn-outline" style="padding:0.25rem 0.6rem;" @click="all('market', true)">Show all market sides</button>
                <button type="button" class="corex-btn-outline" style="padding:0.25rem 0.6rem;" @click="all('market', false)">Hide all market sides</button>
            </div>
            <div class="overflow-x-auto">
                <table class="text-sm ds-table" style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="color: var(--text-secondary); text-align:left;">
                            <th class="py-1 pr-3">Section</th>
                            <th class="py-1 px-3">Show section</th>
                            <th class="py-1 px-3">Agency side</th>
                            <th class="py-1 px-3">Market side</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach([
                            'stock' => 'Stock on market',
                            'sales' => 'Sales',
                            'sold_under_offer' => 'Sold & under offer',
                            'price_reductions' => 'Price reduction activity',
                        ] as $key => $label)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="py-2 pr-3" style="color: var(--text-primary);">{{ $label }}</td>
                            <td class="py-2 px-3"><input type="checkbox" name="sections[{{ $key }}][show]" value="1" data-field="show" {{ $sections[$key]['show'] ? 'checked' : '' }}></td>
                            <td class="py-2 px-3"><input type="checkbox" name="sections[{{ $key }}][agency]" value="1" data-field="agency" {{ $sections[$key]['agency'] ? 'checked' : '' }}></td>
                            <td class="py-2 px-3"><input type="checkbox" name="sections[{{ $key }}][market]" value="1" data-field="market" {{ $sections[$key]['market'] ? 'checked' : '' }}></td>
                        </tr>
                        @endforeach
                        @foreach([
                            'cma_reports' => 'CMA reports on file',
                            'buyer_demand' => 'Buyer demand vs stock',
                        ] as $key => $label)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="py-2 pr-3" style="color: var(--text-primary);">{{ $label }}</td>
                            <td class="py-2 px-3"><input type="checkbox" name="sections[{{ $key }}][show]" value="1" {{ $sections[$key]['show'] ? 'checked' : '' }}></td>
                            <td class="py-2 px-3" style="color: var(--text-secondary);">—</td>
                            <td class="py-2 px-3" style="color: var(--text-secondary);">—</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="submit" class="corex-btn-primary" style="padding:0.4rem 1rem;">Apply</button>
        </form>
    </details>

    {{-- Upload a CMA for THIS suburb — ALWAYS here, whether a CMA already
         exists or not (Johan, 2026-08-25: "he wants to be able to add a CMA
         to any suburb at any time, not only when the suburb is empty...
         every suburb should be able to take another CMA"). Its own
         full-width row, sitting directly above the CMA section, so it reads
         as a permanent action tied to that section — not a small button that
         could be missed next to the picker. Lands back on this same suburb
         once parsed. --}}
    @permission('mic.upload_reports')
    <div class="rounded-md p-4 flex flex-wrap items-center justify-between gap-3"
         style="background: color-mix(in srgb, var(--brand-icon) 6%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon) 22%, transparent);">
        <div class="text-sm" style="color: var(--text-primary);">
            @if($data['layer_a']['available'])
                <strong>Have another CMA for {{ $data['suburb']['name'] ?? 'this suburb' }}?</strong> CoreX's own data is thin with one agency — every CMA on file helps.
            @else
                <strong>No CMA report on file for {{ $data['suburb']['name'] ?? 'this suburb' }} yet.</strong> Upload one to see it alongside your own CoreX figures below.
            @endif
        </div>
        <form method="POST" action="{{ route('market-intelligence.suburb-report.upload-cma', $suburb) }}" enctype="multipart/form-data"
              x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <label class="corex-btn-primary" style="cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem;">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                <span x-show="!busy">{{ $data['layer_a']['available'] ? 'Upload another CMA' : 'Upload a CMA for ' . ($data['suburb']['name'] ?? 'this suburb') }}</span>
                <span x-show="busy" x-cloak>Uploading…</span>
                <input type="file" name="file" accept="application/pdf" class="sr-only" onchange="this.form.requestSubmit()" required>
            </label>
        </form>
    </div>
    @endpermission
    @if(session('status'))
    <div class="rounded-md px-3 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if(session('error'))
    <div class="rounded-md px-3 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif

    @include('corex.market-intelligence._suburb-report-body')

</div>
@endsection
