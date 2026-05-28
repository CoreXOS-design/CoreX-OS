{{-- MIC Phase F — single report detail. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent);
                    color: var(--ds-green, #10b981);
                    border: 1px solid var(--ds-green, #10b981); border-radius: 4px;">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent);
                    color: var(--ds-crimson, #dc2626);
                    border: 1px solid var(--ds-crimson, #dc2626); border-radius: 4px;">
            {{ session('error') }}
        </div>
    @endif

    {{-- Report-lifecycle Phase 1+2 — archived banner with Restore CTA when
         the user has mic.restore_reports. Without the permission the agent
         still sees the banner (so they know why edits look stale) but no
         button. --}}
    @if($report->trashed())
        <div style="margin-bottom: 12px; padding: 10px 14px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-amber, #d97706) 14%, transparent);
                    color: var(--text-primary);
                    border: 1px solid var(--ds-amber, #d97706); border-radius: 4px;
                    display: flex; align-items: center; justify-content: space-between; gap: 12px;">
            <div>
                <strong style="color: var(--ds-amber, #d97706);">Archived report.</strong>
                Archived {{ $report->deleted_at?->diffForHumans() ?? 'previously' }}. Stats below are read-only until restored.
            </div>
            @permission('mic.restore_reports')
                <form method="POST" action="{{ route('market-intelligence.reports.restore', $report) }}" style="margin: 0;">
                    @csrf
                    <button type="submit"
                            style="padding: 6px 12px; font-size: 0.75rem; font-weight: 600;
                                   background: var(--ds-amber, #d97706); color: #fff;
                                   border: none; border-radius: 4px; cursor: pointer;">
                        Restore from archive
                    </button>
                </form>
            @endpermission
        </div>
    @endif

    <nav style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 12px;">
        <a href="{{ route('market-intelligence.reports.index') }}" style="color: var(--brand-button); text-decoration: none;">← All reports</a>
    </nav>

    @php
        $parseColor = match ($report->parse_status) {
            'parsed'  => '#10b981',
            'failed'  => '#dc2626',
            'parsing' => '#0ea5e9',
            default   => 'var(--text-muted)',
        };
        $spotColor = match ($report->spot_check_status) {
            'passed'  => '#10b981',
            'flagged' => '#d97706',
            'running' => '#0ea5e9',
            default   => 'var(--text-muted)',
        };
    @endphp

    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 16px; margin-bottom: 16px;">
        <h1 style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary); margin: 0 0 4px 0;">
            {{ $report->file_name }}
        </h1>
        <div style="display: flex; flex-wrap: wrap; gap: 14px; font-size: 0.8125rem; color: var(--text-secondary); margin-bottom: 14px;">
            <span><strong style="color: var(--text-primary);">Type:</strong> {{ $report->reportType?->display_name ?? '—' }}</span>
            <span><strong style="color: var(--text-primary);">Uploaded:</strong> {{ $report->created_at->format('j M Y H:i') }} by {{ $report->uploader?->name ?? '—' }}</span>
            <span><strong style="color: var(--text-primary);">Hash:</strong> <code style="font-size: 0.6875rem;">{{ \Illuminate\Support\Str::limit($report->file_hash, 16, '…') }}</code></span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; margin-bottom: 14px;">
            <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
                <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Parse</div>
                <div style="font-size: 0.875rem; font-weight: 600; color: {{ $parseColor }};">{{ ucfirst((string) $report->parse_status) }}</div>
                @if($report->parse_completed_at)
                    <div style="font-size: 0.625rem; color: var(--text-muted);">{{ $report->parse_completed_at->diffForHumans() }}</div>
                @endif
            </div>
            <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
                <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Spot check</div>
                <div style="font-size: 0.875rem; font-weight: 600; color: {{ $spotColor }};">{{ ucfirst((string) $report->spot_check_status) }}</div>
                @if(isset($report->spot_check_results['discrepancies']))
                    <div style="font-size: 0.625rem; color: var(--text-muted);">{{ $report->spot_check_results['discrepancies'] }} discrepancies</div>
                @endif
            </div>
            <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
                <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Data points</div>
                <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary);">{{ number_format($report->data_points_count) }}</div>
            </div>
            <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
                <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Parser version</div>
                <div style="font-size: 0.875rem; color: var(--text-primary);">{{ $report->parser_version ?? '—' }}</div>
            </div>
        </div>

        <div style="display: flex; gap: 8px;">
            @if($report->parse_status === 'parsed')
                <form method="POST" action="{{ route('market-intelligence.reports.spot-check', $report) }}" style="margin: 0;">
                    @csrf
                    <button type="submit"
                            style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                                   background: var(--brand-button); color: #fff;
                                   border: none; border-radius: 4px; cursor: pointer;">
                        Re-run spot check
                    </button>
                </form>
            @endif

            {{-- Report-lifecycle Phase 4 — Re-parse keeps the row + PDF,
                 clears existing data_points + comp_rows + discrepancies,
                 re-dispatches the parse job. Useful when a new parser ships
                 and an older report (often parsed via GenericFallback)
                 should be re-extracted. --}}
            <form method="POST" action="{{ route('market-intelligence.reports.reparse', $report) }}" style="margin: 0;"
                  onsubmit="return confirm('Re-parse this report? Existing data points, comp rows, and discrepancies for this report will be cleared and re-extracted from the original PDF.');">
                @csrf
                <button type="submit"
                        style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                               background: var(--surface); color: var(--text-secondary);
                               border: 1px solid var(--border); border-radius: 4px; cursor: pointer;">
                    Re-parse
                </button>
            </form>
            @if($report->discrepancies->count() > 0)
                <a href="{{ route('market-intelligence.reports.discrepancies', $report) }}"
                   style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                          background: var(--surface); color: var(--ds-amber, #d97706);
                          border: 1px solid var(--ds-amber, #d97706); border-radius: 4px; text-decoration: none;">
                    View {{ $report->discrepancies->count() }} discrepancies
                </a>
            @endif
            {{-- Archive only when not already trashed. Restore lives in the
                 archived banner above; that's the recovery path. --}}
            @if(!$report->trashed())
                <form method="POST" action="{{ route('market-intelligence.reports.destroy', $report) }}" style="margin: 0 0 0 auto;"
                      onsubmit="return confirm('Archive this report? It can be recovered from admin if needed.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                                   background: var(--surface); color: var(--text-secondary);
                                   border: 1px solid var(--border); border-radius: 4px; cursor: pointer;">
                        Archive
                    </button>
                </form>
            @endif
        </div>
    </div>

    <section style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden; margin-bottom: 16px;">
        <div style="padding: 10px 14px; border-bottom: 1px solid var(--border);">
            <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">
                Extracted data points · {{ $report->dataPoints->count() }}
            </h2>
        </div>
        @if($report->dataPoints->isEmpty())
            <div style="padding: 16px; font-size: 0.8125rem; color: var(--text-muted); font-style: italic;">
                No data points extracted. Either the parser couldn't recognise this format, or the file is image-only without OCR.
            </div>
        @else
            <div style="overflow-x: auto; max-height: 480px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                    <thead>
                        <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.04em; position: sticky; top: 0;">
                            <th style="text-align: left; padding: 6px 10px;">Metric key</th>
                            <th style="text-align: left; padding: 6px 10px;">Suburb</th>
                            <th style="text-align: right; padding: 6px 10px;">Numeric</th>
                            <th style="text-align: left; padding: 6px 10px;">Date</th>
                            <th style="text-align: left; padding: 6px 10px;">String</th>
                            <th style="text-align: left; padding: 6px 10px;">Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report->dataPoints as $dp)
                            <tr style="border-top: 1px solid var(--border);">
                                <td style="padding: 5px 10px; color: var(--text-primary);">{{ $dp->metric_key }}</td>
                                <td style="padding: 5px 10px; color: var(--text-secondary);">{{ $dp->suburb_normalised ?? '—' }}</td>
                                <td style="padding: 5px 10px; text-align: right; font-family: ui-monospace, monospace;">{{ $dp->metric_value_numeric !== null ? number_format((float) $dp->metric_value_numeric, 2) : '—' }}</td>
                                <td style="padding: 5px 10px; color: var(--text-secondary);">{{ $dp->metric_value_date ? \Carbon\Carbon::parse($dp->metric_value_date)->format('Y-m-d') : '—' }}</td>
                                <td style="padding: 5px 10px; color: var(--text-secondary); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $dp->metric_value_string }}">{{ $dp->metric_value_string ?? '—' }}</td>
                                <td style="padding: 5px 10px; color: var(--text-secondary);">{{ $dp->confidence }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if(!empty($report->raw_extracted_json))
        <section style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
            <div style="padding: 10px 14px; border-bottom: 1px solid var(--border);">
                <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">Parser debug payload</h2>
            </div>
            <pre style="margin: 0; padding: 12px; font-size: 0.6875rem; color: var(--text-secondary); overflow-x: auto; max-height: 240px; overflow-y: auto;">{{ json_encode($report->raw_extracted_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
    @endif
</div>
@endsection
