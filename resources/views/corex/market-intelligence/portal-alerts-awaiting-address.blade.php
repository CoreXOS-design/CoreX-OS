{{-- MIC Q4/D1 — Portal alerts awaiting an address.

     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens via var() with hex fallback).

     Lists every portal-prospecting row that can't yet appear as a map pin
     because it lacks the structured address + GPS path. Two sources:
       1. p24_listings — every row by schema (no address column on this table)
       2. prospecting_listings WHERE tracked_property_id IS NULL — ungeocoded
          Chrome captures awaiting matcher resolution
--}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1640px; margin: 0 auto; padding: 0 20px;">

    @include('corex.market-intelligence.partials.tabs')

    <div style="margin-bottom: 16px;">
        <h1 style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary); margin: 0 0 4px 0;">
            Portal alerts — awaiting address
        </h1>
        <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
            These prospecting alerts can't appear on the map yet because they don't carry a street address or GPS.
            Open the portal URL, then use the Chrome extension to capture the listing — that promotes it to a
            pin-able prospecting record.
        </p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; margin-bottom: 16px;">
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">P24 email alerts (no address)</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($totalP24Alerts) }}</div>
        </div>
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Ungeocoded Chrome captures</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($totalUngeocodedPros) }}</div>
        </div>
    </div>

    @if($alerts->isEmpty())
        <div style="padding: 24px; text-align: center; background: var(--surface); border: 1px dashed var(--border); border-radius: 6px;">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                No portal alerts awaiting an address right now. Anything new will show up here.
            </p>
        </div>
    @else
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="text-align: left; padding: 8px 12px;">Source</th>
                        <th style="text-align: left; padding: 8px 12px;">Reference</th>
                        <th style="text-align: left; padding: 8px 12px;">Suburb</th>
                        <th style="text-align: left; padding: 8px 12px;">Type</th>
                        <th style="text-align: right; padding: 8px 12px;">Beds/Baths</th>
                        <th style="text-align: right; padding: 8px 12px;">Asking</th>
                        <th style="text-align: left; padding: 8px 12px;">First seen</th>
                        <th style="text-align: left; padding: 8px 12px;">Reason</th>
                        <th style="text-align: left; padding: 8px 12px;">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($alerts as $a)
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 8px 12px; color: var(--text-secondary);">
                                {{ $a['source_label'] }}
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-secondary); font-family: monospace; font-size: 0.75rem;">
                                {{ $a['reference'] ?? '—' }}
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-primary);">
                                {{ $a['suburb'] ?? ($a['area'] ?? '—') }}
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">
                                {{ $a['property_type'] ?? '—' }}
                            </td>
                            <td style="padding: 8px 12px; text-align: right; color: var(--text-secondary);">
                                @if($a['bedrooms'] || $a['bathrooms'])
                                    {{ $a['bedrooms'] ?? '—' }} / {{ $a['bathrooms'] ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td style="padding: 8px 12px; text-align: right; color: var(--text-primary);">
                                @if($a['asking_price'])
                                    R {{ number_format((float) $a['asking_price'], 0, '.', ',') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">
                                {{ $a['first_seen_date'] ?? '—' }}
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">
                                {{ $a['reason'] }}
                            </td>
                            <td style="padding: 8px 12px;">
                                @if($a['portal_url'])
                                    <a href="{{ $a['portal_url'] }}" target="_blank" rel="noopener"
                                       style="color: var(--brand-button, #00d4aa); text-decoration: none; font-weight: 500;">
                                        Open ↗
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 4px;">{{ $alerts->links() }}</div>
    @endif

</div>
@endsection
