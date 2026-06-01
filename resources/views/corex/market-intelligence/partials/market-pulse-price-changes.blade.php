{{-- Phase D6 — price changes (last 200).
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
<section style="margin-bottom: 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
    <div style="padding: 10px 14px; border-bottom: 1px solid var(--border);">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            Price changes · last {{ $priceChanges->count() }}
        </h2>
    </div>
    @if($priceChanges->isEmpty())
        <div style="padding: 16px; font-size: 0.8125rem; color: var(--text-muted); font-style: italic;">No price changes recorded.</div>
    @else
        <div style="overflow-x: auto; max-height: 360px; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.04em; position: sticky; top: 0;">
                        <th style="text-align: left; padding: 6px 10px;">Date</th>
                        <th style="text-align: left; padding: 6px 10px;">P24 #</th>
                        <th style="text-align: left; padding: 6px 10px;">Suburb</th>
                        <th style="text-align: right; padding: 6px 10px;">Old</th>
                        <th style="text-align: right; padding: 6px 10px;">New</th>
                        <th style="text-align: right; padding: 6px 10px;">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($priceChanges as $c)
                        @php
                            $pct = $c->old_price > 0 ? round(($c->new_price - $c->old_price) / $c->old_price * 100, 1) : 0;
                            // Neutral market metric — never red. Amber for a drop, green for a rise (UI_DESIGN_SYSTEM.md §5.3).
                            $pctColor = $pct < 0 ? 'var(--ds-amber, #f59e0b)' : ($pct > 0 ? 'var(--ds-green, #059669)' : 'var(--text-muted)');
                        @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 5px 10px; color: var(--text-muted);">{{ optional($c->change_date)->format('Y-m-d') ?? '—' }}</td>
                            <td style="padding: 5px 10px;">
                                @if($c->listing && $c->listing->p24_url)
                                    <a href="{{ $c->listing->p24_url }}" target="_blank" rel="noopener" style="color: var(--brand-button); text-decoration: none; font-family: ui-monospace, monospace; font-size: 0.6875rem;">{{ $c->listing->p24_listing_number ?? '—' }}</a>
                                @else
                                    <span style="font-family: ui-monospace, monospace; font-size: 0.6875rem; color: var(--text-secondary);">{{ $c->listing->p24_listing_number ?? '—' }}</span>
                                @endif
                            </td>
                            <td style="padding: 5px 10px;"><span data-mic-suburb="{{ $c->listing->suburb ?? '' }}" style="color: var(--text-primary);">{{ $c->listing->suburb ?? '—' }}</span></td>
                            <td style="padding: 5px 10px; text-align: right; color: var(--text-secondary);">R {{ number_format((float) $c->old_price, 0, '.', ',') }}</td>
                            <td style="padding: 5px 10px; text-align: right; color: var(--text-primary); font-weight: 500;">R {{ number_format((float) $c->new_price, 0, '.', ',') }}</td>
                            <td style="padding: 5px 10px; text-align: right; color: {{ $pctColor }}; font-weight: 600;">
                                {{ $pct >= 0 ? '+' : '' }}{{ number_format($pct, 1) }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
