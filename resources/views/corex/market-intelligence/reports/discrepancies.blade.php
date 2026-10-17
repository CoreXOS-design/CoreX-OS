{{-- MIC Phase F — discrepancies list. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
    @include('corex.market-intelligence.partials.tabs')

    <div class="rounded-md px-6 py-5 corex-page-banner" style="margin-bottom: 16px;">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    Discrepancies — {{ $report->file_name }}
                </h1>
                <p class="text-xs" style="margin: 2px 0 0 0; color: var(--text-muted);">
                    {{ $discrepancies->total() }} {{ $discrepancies->total() === 1 ? 'point' : 'points' }} flagged by spot-check audit. Each row shows the parser's value vs. what the audit found.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('market-intelligence.reports.show', $report) }}" class="corex-btn-outline text-xs">← Back to report</a>
            </div>
        </div>
    </div>

    @if($discrepancies->isEmpty())
        <div style="padding: 24px; text-align: center; background: var(--surface); border: 1px dashed var(--border); border-radius: 6px;">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">No discrepancies — spot check passed.</p>
        </div>
    @else
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="text-align: left; padding: 8px 12px;">Metric</th>
                        <th style="text-align: left; padding: 8px 12px;">Parsed</th>
                        <th style="text-align: left; padding: 8px 12px;">Audit found</th>
                        <th style="text-align: left; padding: 8px 12px;">Type</th>
                        <th style="text-align: left; padding: 8px 12px;">Severity</th>
                        <th style="text-align: left; padding: 8px 12px;">Resolved</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($discrepancies as $d)
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 8px 12px; color: var(--text-primary); font-family: ui-monospace, monospace; font-size: 0.75rem;">{{ $d->dataPoint?->metric_key ?? '—' }}</td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $d->parsed_value }}</td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $d->audit_value }}</td>
                            <td style="padding: 8px 12px; color: var(--text-secondary); font-size: 0.75rem;">{{ str_replace('_', ' ', $d->discrepancy_type) }}</td>
                            <td style="padding: 8px 12px;"><span class="ds-badge {{ $d->severity === 'high' ? 'ds-badge-danger' : ($d->severity === 'medium' ? 'ds-badge-warning' : 'ds-badge-muted') }}">{{ ucfirst($d->severity) }}</span></td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $d->resolved ? 'Yes' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 4px;">{{ $discrepancies->links() }}</div>
    @endif
</div>
@endsection
