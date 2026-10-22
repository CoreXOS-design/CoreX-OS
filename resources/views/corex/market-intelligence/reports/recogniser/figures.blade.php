{{-- Report recogniser — "View figures": what was read from one report, beside the PDF it was read from. CoreX owner roles only.
     Spec: .ai/specs/mic-complete-spec.md §8.9 ("View figures and Approve layout"). Read-only, no Alpine. --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $money = fn ($v) => $v === null ? '—' : 'R ' . number_format((float) $v, 0, '.', ' ');
    $int   = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ');
    $th = 'text-align: left; padding: 6px 10px;';
    $thr = 'text-align: right; padding: 6px 10px;';
    $td = 'padding: 5px 10px; color: var(--text-secondary);';
    $tdr = 'padding: 5px 10px; text-align: right; font-family: ui-monospace, monospace; color: var(--text-primary);';
    $has = fn (string $col) => $comps->contains(fn ($c) => $c->{$col} !== null && $c->{$col} !== '');
@endphp
<div style="width: 100%;">

    <x-mic-page-header
        title="Figures read from this report"
        :subtitle="$report->file_name . ($report->read_publisher ? ' · ' . $report->read_publisher : '')">
        <x-slot:actions>
            <a href="{{ route('market-intelligence.reports.recogniser.queue', ['focus' => $report->id]) }}" class="corex-btn-outline text-xs">← Back to the queue</a>
            @if($layout && !$layout->trashed() && $layout->status === 'learned')
                <form method="POST" action="{{ route('market-intelligence.reports.recogniser.layouts.approve', $layout->id) }}" style="display: inline-block;"
                      onsubmit="return confirm('Approve this layout? Its queued reports are re-run on it and their agents are told.');">
                    @csrf <button type="submit" class="corex-btn-primary text-xs">Approve layout</button>
                </form>
            @endif
        </x-slot:actions>
    </x-mic-page-header>

    @include('corex.market-intelligence.partials.tabs')

    <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; color: var(--text-secondary);">
        {{ $report->readBadge() ?? 'Not read' }}
        @if($report->ai_read_confidence !== null) · confidence {{ number_format((float) $report->ai_read_confidence, 2) }} @endif
        · {{ number_format($report->data_points_count) }} figures
        · {{ $agencyName ?? 'Agency #' . $report->agency_id }}
        · uploaded {{ $report->created_at?->format('j M Y H:i') }}
        <br>
        @if($layout && !$layout->trashed())
            Layout: {{ $layout->name }} — {{ $layout->status === 'approved' ? 'approved' : 'saved, waiting for approval' }}. Check the figures on the right against the PDF on the left, then approve.
        @elseif($layout)
            Layout: {{ $layout->name }} — retired.
        @else
            No layout was saved from this read{{ $report->ai_read_note ? ' — ' . $report->ai_read_note : '' }}
        @endif
    </div>

    <div style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr); gap: 12px; align-items: start;">

        {{-- The PDF it was read from --}}
        <section style="position: sticky; top: 8px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
            <div style="padding: 8px 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">The report (PDF)</h2>
                <a href="{{ route('market-intelligence.reports.recogniser.download', $report->id) }}" class="corex-btn-outline text-xs">Download</a>
            </div>
            <iframe src="{{ route('market-intelligence.reports.recogniser.pdf', $report->id) }}#view=FitH" title="{{ $report->file_name }}"
                    style="width: 100%; height: 78vh; border: 0; background: var(--surface-2);"></iframe>
        </section>

        {{-- What was read --}}
        <div style="min-width: 0;">
            <section style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden; margin-bottom: 12px;">
                <div style="padding: 8px 12px; border-bottom: 1px solid var(--border);">
                    <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">Comparable sales and listings · {{ $comps->count() }}</h2>
                </div>
                @if($comps->isEmpty())
                    <div style="padding: 14px; font-size: 0.8125rem; color: var(--text-muted); font-style: italic;">No sale rows were read from this report.</div>
                @else
                    <div style="overflow: auto; max-height: 70vh;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                            <thead>
                                <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.04em; position: sticky; top: 0;">
                                    <th style="{{ $thr }}">#</th>
                                    <th style="{{ $th }}">Kind</th>
                                    <th style="{{ $th }}">Address</th>
                                    @if($has('scheme_name'))<th style="{{ $th }}">Scheme</th>@endif
                                    @if($has('property_type'))<th style="{{ $th }}">Type</th>@endif
                                    @if($has('extent_m2'))<th style="{{ $thr }}">Extent m²</th>@endif
                                    @if($has('sale_date'))<th style="{{ $th }}">Sale date</th>@endif
                                    @if($has('sale_price'))<th style="{{ $thr }}">Sale price</th>@endif
                                    @if($has('list_price'))<th style="{{ $thr }}">List price</th>@endif
                                    @if($has('days_on_market'))<th style="{{ $thr }}">Days on market</th>@endif
                                    @if($has('r_per_m2'))<th style="{{ $thr }}">R/m²</th>@endif
                                    @if($has('municipal_valuation'))<th style="{{ $thr }}">Municipal value</th>@endif
                                    @if($has('distance_to_subject_m'))<th style="{{ $thr }}">Distance m</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comps as $i => $c)
                                    <tr style="border-top: 1px solid var(--border);">
                                        <td style="{{ $tdr }}">{{ $i + 1 }}</td>
                                        <td style="{{ $td }}">{{ $c->row_type }}</td>
                                        <td style="{{ $td }} color: var(--text-primary);">{{ $c->address ?? '—' }}</td>
                                        @if($has('scheme_name'))<td style="{{ $td }}">{{ $c->scheme_name ?? '—' }}{{ $c->section_number ? ' / ' . $c->section_number : '' }}</td>@endif
                                        @if($has('property_type'))<td style="{{ $td }}">{{ $c->property_type ?? '—' }}</td>@endif
                                        @if($has('extent_m2'))<td style="{{ $tdr }}">{{ $int($c->extent_m2) }}</td>@endif
                                        @if($has('sale_date'))<td style="{{ $td }}">{{ $c->sale_date ? \Carbon\Carbon::parse($c->sale_date)->format('j M Y') : '—' }}</td>@endif
                                        @if($has('sale_price'))<td style="{{ $tdr }}">{{ $money($c->sale_price) }}</td>@endif
                                        @if($has('list_price'))<td style="{{ $tdr }}">{{ $money($c->list_price) }}</td>@endif
                                        @if($has('days_on_market'))<td style="{{ $tdr }}">{{ $int($c->days_on_market) }}</td>@endif
                                        @if($has('r_per_m2'))<td style="{{ $tdr }}">{{ $money($c->r_per_m2) }}</td>@endif
                                        @if($has('municipal_valuation'))<td style="{{ $tdr }}">{{ $money($c->municipal_valuation) }}</td>@endif
                                        @if($has('distance_to_subject_m'))<td style="{{ $tdr }}">{{ $int($c->distance_to_subject_m) }}</td>@endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
                <div style="padding: 8px 12px; border-bottom: 1px solid var(--border);">
                    <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">Other figures read · {{ $points->count() }}</h2>
                </div>
                @if($points->isEmpty())
                    <div style="padding: 14px; font-size: 0.8125rem; color: var(--text-muted); font-style: italic;">No other figures (value ranges, suburb statistics) were read from this report.</div>
                @else
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                        <thead>
                            <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.04em;">
                                <th style="{{ $th }}">Figure</th><th style="{{ $th }}">Suburb</th><th style="{{ $th }}">For</th><th style="{{ $thr }}">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($points as $p)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td style="{{ $td }} color: var(--text-primary);">{{ str_replace('_', ' ', $p->metric_key) }}</td>
                                    <td style="{{ $td }}">{{ $p->suburb_normalised ?? '—' }}</td>
                                    <td style="{{ $td }}">{{ $p->metric_date ? \Carbon\Carbon::parse($p->metric_date)->format('Y') : '—' }}</td>
                                    <td style="{{ $tdr }}">{{ $p->metric_value_numeric !== null ? number_format((float) $p->metric_value_numeric, 2, '.', ' ') : ($p->metric_value_string ?? '—') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
