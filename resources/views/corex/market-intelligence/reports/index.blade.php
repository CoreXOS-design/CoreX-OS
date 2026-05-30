{{-- MIC Phase F — Reports index. Spec §8.1. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="width: 100%;">

    <x-mic-page-header
        title="Market reports"
        subtitle="Upload CMAs, Lightstone reports, and other market intelligence. Parsed data feeds Property Intelligence + the Strategic Brief.">
        <x-slot:actions>
            {{-- Report-lifecycle Phase 2 — Show archived toggle. Widens the
                 query to ->withTrashed() so admins can find and restore
                 soft-deleted reports. Default view hides archived rows. --}}
            @if($showArchived)
                <a href="{{ route('market-intelligence.reports.index') }}"
                   class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Hide archived
                </a>
            @else
                <a href="{{ route('market-intelligence.reports.index', ['archived' => 1]) }}"
                   class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Show archived ({{ number_format($stats['archived'] ?? 0) }})
                </a>
            @endif
            <a href="{{ route('market-intelligence.reports.create') }}" class="corex-btn-primary text-sm">
                Upload a report
            </a>
        </x-slot:actions>
    </x-mic-page-header>

    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent);
                    color: var(--ds-green, #10b981);
                    border: 1px solid var(--ds-green, #10b981); border-radius: 4px;">
            {{ session('status') }}
        </div>
    @endif

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
                        <tr style="border-top: 1px solid var(--border); {{ $r->trashed() ? 'opacity: 0.6;' : '' }}">
                            <td style="padding: 8px 12px;">
                                <a href="{{ route('market-intelligence.reports.show', $r) }}" style="color: var(--brand-button); text-decoration: none;">
                                    {{ $r->file_name }}
                                </a>
                                @if($r->trashed())
                                    <span style="margin-left: 6px; padding: 1px 6px; font-size: 0.625rem; font-weight: 600;
                                                 color: var(--ds-amber, #d97706);
                                                 background: color-mix(in srgb, var(--ds-amber, #d97706) 14%, transparent);
                                                 border-radius: 8px; vertical-align: middle;">Archived</span>
                                @endif
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
