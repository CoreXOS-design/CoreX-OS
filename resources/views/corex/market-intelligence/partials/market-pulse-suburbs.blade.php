{{-- Phase D6 — listings by suburb. Each row clickable -> suburb deep-dive panel.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
<section style="margin-bottom: 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
    <div style="display: flex; align-items: baseline; justify-content: space-between; padding: 10px 14px;
                border-bottom: 1px solid var(--border);">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            Listings by suburb · {{ $suburbStats->count() }} suburbs
        </h2>
        <span style="font-size: 0.6875rem; color: var(--text-muted);">Click a suburb for the deep-dive panel</span>
    </div>
    @if($suburbStats->isEmpty())
        <div style="padding: 20px; font-size: 0.875rem; color: var(--text-muted); font-style: italic;">
            No P24 listings yet — run an import to populate.
        </div>
    @else
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="text-align: left; padding: 6px 12px;">Suburb</th>
                        <th style="text-align: right; padding: 6px 12px;">Listings</th>
                        <th style="text-align: right; padding: 6px 12px;">New this month</th>
                        <th style="text-align: right; padding: 6px 12px;">Min</th>
                        <th style="text-align: right; padding: 6px 12px;">Avg</th>
                        <th style="text-align: right; padding: 6px 12px;">Max</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($suburbStats as $row)
                        <tr data-mic-suburb="{{ $row->suburb }}"
                            style="border-top: 1px solid var(--border); transition: background 100ms;"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background='var(--surface)'">
                            <td style="padding: 6px 12px; color: var(--text-primary); font-weight: 500;">{{ $row->suburb }}</td>
                            <td style="padding: 6px 12px; text-align: right; font-weight: 600;">{{ number_format($row->listing_count) }}</td>
                            <td style="padding: 6px 12px; text-align: right; color: {{ $row->new_this_month > 0 ? 'var(--ds-green, #059669)' : 'var(--text-muted)' }};">
                                {{ $row->new_this_month > 0 ? '+' . $row->new_this_month : '—' }}
                            </td>
                            <td style="padding: 6px 12px; text-align: right; color: var(--text-secondary); font-size: 0.75rem;">R {{ number_format((float) $row->min_price, 0, '.', ',') }}</td>
                            <td style="padding: 6px 12px; text-align: right; color: var(--text-primary);">R {{ number_format((float) $row->avg_price, 0, '.', ',') }}</td>
                            <td style="padding: 6px 12px; text-align: right; color: var(--text-secondary); font-size: 0.75rem;">R {{ number_format((float) $row->max_price, 0, '.', ',') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
