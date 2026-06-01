{{-- Phase D6 — P24 import log (last 50 imports).
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
<section style="margin-bottom: 24px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
    <div style="padding: 10px 14px; border-bottom: 1px solid var(--border);">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            Import log · last {{ $importLog->count() }}
        </h2>
    </div>
    @if($importLog->isEmpty())
        <div style="padding: 16px; font-size: 0.8125rem; color: var(--text-muted); font-style: italic;">No imports recorded yet.</div>
    @else
        <div style="overflow-x: auto; max-height: 300px; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.04em; position: sticky; top: 0;">
                        <th style="text-align: left; padding: 6px 10px;">When</th>
                        <th style="text-align: left; padding: 6px 10px;">Status</th>
                        <th style="text-align: right; padding: 6px 10px;">Listings</th>
                        <th style="text-align: right; padding: 6px 10px;">Price changes</th>
                        <th style="text-align: left; padding: 6px 10px;">Message</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($importLog as $log)
                        @php
                            $statusColor = match ((string) $log->status) {
                                'success' => 'var(--ds-green, #059669)',
                                'failure' => 'var(--ds-crimson, #c41e3a)',
                                default   => 'var(--text-muted)',
                            };
                        @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 5px 10px; color: var(--text-muted);">{{ $log->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td style="padding: 5px 10px; font-weight: 600; color: {{ $statusColor }};">{{ ucfirst((string) $log->status) }}</td>
                            <td style="padding: 5px 10px; text-align: right; color: var(--text-primary);">{{ $log->listings_processed ?? '—' }}</td>
                            <td style="padding: 5px 10px; text-align: right; color: var(--text-secondary);">{{ $log->price_changes_recorded ?? '—' }}</td>
                            <td style="padding: 5px 10px; color: var(--text-muted); max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $log->message }}">
                                {{ $log->message ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
