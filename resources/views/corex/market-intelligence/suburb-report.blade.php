{{--
    Suburb report screen. Renders SuburbReportDataService::build().
    Only figures with a defensible source render — a section with nothing
    behind it is omitted entirely, never shown as an empty state.
    Days-on-market reuses the exact formula the property header already
    uses — see MarketIntelligenceController::suburbReport().
--}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1000px; margin: 0 auto; padding: 0 20px;">

    <div style="margin-bottom: 16px; padding: 0 0 14px 0; border-bottom: 1px solid var(--border);">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
            Suburb Report — {{ $suburbName }}
        </h1>
        @if($municipality)
            <div class="text-xs" style="margin-top: 2px; color: var(--text-muted);">
                {{ $municipality }}{{ $municipalityConfirmed ? '' : ' (municipality not confirmed)' }}
            </div>
        @endif
    </div>

    @if($stockCount > 0)
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 class="text-sm font-bold" style="color: var(--text-primary); margin-bottom: 10px;">
            Stock on market — {{ $stockCount }} active listing{{ $stockCount === 1 ? '' : 's' }}
        </h2>
        <div style="display: flex; flex-direction: column; gap: 6px;">
            @foreach($stockListings as $l)
                <div style="display: flex; justify-content: space-between; align-items: baseline; padding: 6px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; font-size: 0.8125rem;">
                    <span style="color: var(--text-secondary);">{{ $l['label'] }}</span>
                    <span>
                        @if($l['days_on_market'] !== null)
                            <span class="text-xs" style="color: var(--text-muted); margin-right: 8px;">{{ $l['days_on_market'] }} days on market</span>
                        @endif
                        <span style="color: var(--text-primary); font-weight: 600;">R {{ number_format($l['price']) }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    @if($expiringCounts['90'] > 0)
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 class="text-sm font-bold" style="color: var(--text-primary); margin-bottom: 10px;">
            Mandates expiring
        </h2>
        <div style="display: flex; gap: 24px; flex-wrap: wrap;">
            <div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">{{ $expiringCounts['30'] }}</div>
                <div class="text-xs" style="color: var(--text-muted);">within 30 days</div>
            </div>
            <div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">{{ $expiringCounts['60'] }}</div>
                <div class="text-xs" style="color: var(--text-muted);">within 60 days</div>
            </div>
            <div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">{{ $expiringCounts['90'] }}</div>
                <div class="text-xs" style="color: var(--text-muted);">within 90 days</div>
            </div>
        </div>
    </section>
    @endif

    @if($priceReductionCount > 0)
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 class="text-sm font-bold" style="color: var(--text-primary);">
            Price reductions — {{ $priceReductionCount }}
        </h2>
        <div class="text-xs" style="margin-top: 2px; color: var(--text-muted);">
            Distinct price changes recorded for this suburb.
        </div>
    </section>
    @endif

    @if($achievedSalesCount > 0)
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 class="text-sm font-bold" style="color: var(--text-primary);">
            Achieved sales — {{ $achievedSalesCount }}
        </h2>
        <div class="text-xs" style="margin-top: 2px; color: var(--text-muted);">
            Sourced from the deal register (Dr2).
        </div>
    </section>
    @endif

    @if($buyersSpecific > 0 || $buyersIncluding > 0)
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 class="text-sm font-bold" style="color: var(--text-primary); margin-bottom: 10px;">
            Buyer demand
        </h2>
        <div style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 12px;">
            <div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">{{ $buyersSpecific }}</div>
                <div class="text-xs" style="color: var(--text-muted);">buyers wanting this suburb specifically</div>
            </div>
            <div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">{{ $buyersIncluding }}</div>
                <div class="text-xs" style="color: var(--text-muted);">buyers including this suburb among broader criteria</div>
            </div>
        </div>
        @if(count($priceBands) > 0)
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                @foreach($priceBands as $band => $count)
                    <div style="padding: 6px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; font-size: 0.75rem;">
                        <span style="color: var(--text-secondary);">{{ $band }}:</span>
                        <span style="color: var(--text-primary); font-weight: 600;">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
    @endif

    @if($layerAAvailable)
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 class="text-sm font-bold" style="color: var(--text-primary);">Historical sales (CMA reports)</h2>
        <pre style="font-size: 0.75rem; color: var(--text-secondary); white-space: pre-wrap;">{{ json_encode($layerA['years'] ?? [], JSON_PRETTY_PRINT) }}</pre>
    </section>
    @endif

    @if(!$stockCount && !$expiringCounts['90'] && !$priceReductionCount && !$achievedSalesCount && !$buyersSpecific && !$buyersIncluding && !$layerAAvailable)
    <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
        No data on file for {{ $suburbName }} yet.
    </div>
    @endif

</div>
@endsection
