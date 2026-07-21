{{-- Phase D6 — recent listings (last 200).
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
<section style="margin-bottom: 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
    <div style="padding: 10px 14px; border-bottom: 1px solid var(--border);">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            Recent listings · last {{ $recentListings->count() }}
        </h2>
    </div>
    @if($recentListings->isEmpty())
        <div style="padding: 16px; font-size: 0.8125rem; color: var(--text-muted); font-style: italic;">No recent listings.</div>
    @else
        <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.04em; position: sticky; top: 0;">
                        <th style="text-align: left; padding: 6px 10px;">First seen</th>
                        <th style="text-align: left; padding: 6px 10px;">P24 #</th>
                        <th style="text-align: left; padding: 6px 10px;">Suburb</th>
                        <th style="text-align: left; padding: 6px 10px;">Type</th>
                        <th style="text-align: right; padding: 6px 10px;">Asking</th>
                        <th style="text-align: right; padding: 6px 10px;">Bed / Bath</th>
                        <th style="text-align: left; padding: 6px 10px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentListings as $l)
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 5px 10px; color: var(--text-muted);">{{ optional($l->first_seen_date)->format('Y-m-d') ?? '—' }}</td>
                            <td style="padding: 5px 10px;">
                                @if($l->p24_url)
                                    <a href="{{ $l->p24_url }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="color: var(--brand-button); text-decoration: none; font-family: ui-monospace, monospace; font-size: 0.6875rem;">{{ $l->p24_listing_number }}</a>
                                @else
                                    <span style="font-family: ui-monospace, monospace; font-size: 0.6875rem; color: var(--text-secondary);">{{ $l->p24_listing_number }}</span>
                                @endif
                            </td>
                            <td style="padding: 5px 10px;"><span data-mic-suburb="{{ $l->suburb }}" style="color: var(--text-primary);">{{ $l->suburb ?: '—' }}</span></td>
                            <td style="padding: 5px 10px; color: var(--text-secondary);">{{ $l->property_type ?: '—' }}</td>
                            <td style="padding: 5px 10px; text-align: right; color: var(--text-primary); font-weight: 500;">R {{ number_format((float) $l->asking_price, 0, '.', ',') }}</td>
                            <td style="padding: 5px 10px; text-align: right; color: var(--text-secondary);">{{ $l->bedrooms ?? '—' }} / {{ $l->bathrooms ?? '—' }}</td>
                            <td style="padding: 5px 10px; color: var(--text-secondary); font-size: 0.6875rem;">{{ ucfirst((string) $l->listing_status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
