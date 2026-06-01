{{--
    MIC Phase D5 — Suburb deep-dive panel body.

    Returned as an HTML fragment by GET /corex/market-intelligence/suburb/{suburb}.
    Rendered inside the slide-over container in analyse / market-pulse.

    Variables:
      $suburb       string
      $facts        array
      $narrative    string   (AI or templated)
      $fromCache    bool
      $fromFallback bool
      $generatedAt  Carbon
--}}
<div style="padding: 16px; max-width: 480px;">
    <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
        <h3 style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            {{ $suburb }}
        </h3>
        @if($fromFallback)
            <span style="font-size: 0.625rem; padding: 1px 6px; border-radius: 4px;
                         background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent);
                         color: var(--ds-amber, #d97706); font-weight: 600;">
                Fallback
            </span>
        @endif
    </div>

    <div style="padding: 10px 12px; margin-bottom: 14px; border-radius: 6px;
                background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, var(--surface));
                border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 22%, var(--border));">
        <div style="font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
                    color: var(--brand-icon, #0ea5e9); margin-bottom: 4px;">
            Ellie's read
        </div>
        <div style="font-size: 0.8125rem; color: var(--text-primary); line-height: 1.5; white-space: pre-line;">{{ $narrative }}</div>
        <div style="margin-top: 6px; font-size: 0.625rem; color: var(--text-muted);">
            {{ $fromCache ? 'cached' : 'fresh' }} · {{ $generatedAt->diffForHumans() }}
        </div>
    </div>

    {{-- Snapshot metrics --}}
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px;">
        <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Active P24 listings</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($facts['active_listings'] ?? 0) }}</div>
        </div>
        <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Active buyers</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--ds-green, #10b981);">{{ number_format($facts['active_buyers'] ?? 0) }}</div>
        </div>
        @if(!empty($facts['avg_asking']))
            <div style="padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; grid-column: span 2;">
                <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Average asking price</div>
                <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary);">R {{ number_format($facts['avg_asking'], 0, '.', ',') }}</div>
            </div>
        @endif
    </div>

    @if(!empty($facts['listing_type_breakdown']))
        <div style="margin-bottom: 14px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Listing types</div>
            @foreach($facts['listing_type_breakdown'] as $row)
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.75rem; color: var(--text-secondary);
                            border-bottom: 1px solid var(--border);">
                    <span>{{ $row['type'] }}</span>
                    <span style="font-weight: 600; color: var(--text-primary);">{{ $row['count'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if(!empty($facts['bedroom_demand']))
        <div style="margin-bottom: 14px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Buyer demand by bedrooms</div>
            @foreach($facts['bedroom_demand'] as $row)
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.75rem; color: var(--text-secondary);
                            border-bottom: 1px solid var(--border);">
                    <span>{{ $row['bedrooms'] }}-bed</span>
                    <span style="font-weight: 600; color: var(--ds-green, #10b981);">{{ $row['buyers'] }} {{ $row['buyers'] === 1 ? 'buyer' : 'buyers' }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if(empty($facts['has_historical_data']))
        <div style="padding: 8px 10px; border-radius: 4px;
                    background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, var(--surface));
                    border: 1px dashed var(--ds-amber, #f59e0b);">
            <div style="font-size: 0.6875rem; color: var(--text-primary);">
                <strong>Limited historical data.</strong> Median price + sales-count history will populate from
                <code style="font-size: 0.625rem;">market_data_points</code> in Phase F. For now the panel relies on
                live P24 + buyer signals only.
            </div>
        </div>
    @endif

    <div style="margin-top: 16px; display: flex; gap: 6px; flex-wrap: wrap;">
        <a href="{{ route('market-intelligence.work', ['suburb' => $suburb]) }}"
           style="padding: 5px 10px; font-size: 0.6875rem; font-weight: 600;
                  background: var(--brand-button); color: #fff;
                  border-radius: 4px; text-decoration: none;">
            See {{ $suburb }} in Work →
        </a>
        <a href="{{ route('market-intelligence.opportunities', ['suburb' => $suburb]) }}"
           style="padding: 5px 10px; font-size: 0.6875rem; font-weight: 600;
                  background: var(--surface); color: var(--text-primary);
                  border: 1px solid var(--border); border-radius: 4px; text-decoration: none;">
            Opportunities in {{ $suburb }} →
        </a>
    </div>
</div>
