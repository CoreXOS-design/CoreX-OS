{{-- MIC Phase F — Reports index. Spec §8.1. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1640px; margin: 0 auto; padding: 0 20px;">

    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent);
                    color: var(--ds-green, #10b981);
                    border: 1px solid var(--ds-green, #10b981); border-radius: 4px;">
            {{ session('status') }}
        </div>
    @endif

    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px;">
        <div>
            <h1 style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary); margin: 0 0 4px 0;">Market reports</h1>
            <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
                Upload CMAs, Lightstone reports, and other market intelligence. Parsed data lands in market_data_points and feeds Property Intelligence + Strategic Brief.
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="{{ route('market-intelligence.reports.bulk-import') }}"
               style="padding: 8px 14px; font-size: 0.8125rem; font-weight: 500;
                      color: var(--text-secondary); background: var(--surface);
                      border: 1px solid var(--border); border-radius: 4px;
                      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2"/>
                    <path d="M14 2v4a2 2 0 0 0 2 2h4"/>
                    <rect x="2" y="2" width="12" height="14" rx="1"/>
                </svg>
                Bulk Import
            </a>
            <a href="{{ route('market-intelligence.reports.create') }}"
               style="padding: 8px 14px; font-size: 0.8125rem; font-weight: 500;
                      background: var(--brand-button); color: #fff;
                      border-radius: 4px; text-decoration: none;">
                Upload a report →
            </a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-bottom: 16px;">
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Total uploaded</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($stats['total']) }}</div>
        </div>
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Parsed</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--ds-green, #10b981);">{{ number_format($stats['parsed']) }}</div>
        </div>
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Pending</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($stats['pending']) }}</div>
        </div>
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Flagged</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--ds-amber, #d97706);">{{ number_format($stats['flagged']) }}</div>
        </div>
    </div>

    @if($reports->isEmpty())
        <div style="padding: 24px; text-align: center; background: var(--surface); border: 1px dashed var(--border); border-radius: 6px;">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0 0 12px 0;">No reports uploaded yet.</p>
            <a href="{{ route('market-intelligence.reports.create') }}"
               style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                      background: var(--brand-button); color: #fff;
                      border-radius: 4px; text-decoration: none;">Upload your first report</a>
        </div>
    @else
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="text-align: left; padding: 8px 12px;">Filename</th>
                        <th style="text-align: left; padding: 8px 12px;">Type</th>
                        <th style="text-align: left; padding: 8px 12px;">Parse</th>
                        <th style="text-align: left; padding: 8px 12px;">Spot check</th>
                        <th style="text-align: right; padding: 8px 12px;">Points</th>
                        <th style="text-align: right; padding: 8px 12px;">Discrepancies</th>
                        <th style="text-align: left; padding: 8px 12px;">Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $r)
                        @php
                            $parseColor = match ($r->parse_status) {
                                'parsed'  => '#10b981',
                                'failed'  => '#dc2626',
                                'parsing' => '#0ea5e9',
                                default   => 'var(--text-muted)',
                            };
                            $spotColor = match ($r->spot_check_status) {
                                'passed'  => '#10b981',
                                'flagged' => '#d97706',
                                'running' => '#0ea5e9',
                                default   => 'var(--text-muted)',
                            };
                        @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 8px 12px;">
                                <a href="{{ route('market-intelligence.reports.show', $r) }}" style="color: var(--brand-button); text-decoration: none;">
                                    {{ $r->file_name }}
                                </a>
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $r->reportType?->display_name ?? '—' }}</td>
                            <td style="padding: 8px 12px; color: {{ $parseColor }}; font-weight: 600;">{{ ucfirst((string) $r->parse_status) }}</td>
                            <td style="padding: 8px 12px; color: {{ $spotColor }}; font-weight: 600;">{{ ucfirst((string) $r->spot_check_status) }}</td>
                            <td style="padding: 8px 12px; text-align: right; color: var(--text-primary);">{{ number_format($r->data_points_count) }}</td>
                            <td style="padding: 8px 12px; text-align: right; color: var(--text-primary);">
                                @if($r->discrepancies_count > 0)
                                    <a href="{{ route('market-intelligence.reports.discrepancies', $r) }}" style="color: var(--ds-amber, #d97706);">{{ $r->discrepancies_count }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">{{ $r->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 4px;">{{ $reports->links() }}</div>
    @endif

    @permission('mic.view_ai_costs')
        <div style="margin-top: 16px;">
            <a href="{{ route('market-intelligence.reports.parser-dashboard') }}"
               style="font-size: 0.75rem; color: var(--text-secondary); text-decoration: none;">
                View parser accuracy dashboard →
            </a>
        </div>
    @endpermission

</div>
@endsection
