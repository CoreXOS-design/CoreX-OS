{{--
    Website Performance — engagement on the agency's OWN website, counted on the
    site and pushed back to CoreX hourly (POST /api/v1/website/listings/stats).

    Sits directly below "Portal Engagement Over Time": that chart is P24 + Private
    Property, this panel is the agency's own site — the same question asked of the
    one portal CoreX previously had no numbers for at all.

    The whole panel is hidden when the agency has NEVER received website stats. An
    agency with no site on the API must not be shown a permanently empty panel; a
    screen that is always blank is a screen people learn to skip.

    Spec: .ai/specs/website-listing-stats.md §5.1
--}}
@php
    $wsSvc   = app(\App\Services\Website\WebsiteListingStatsReportService::class);
    $wsAgency = $property->agency_id ? (int) $property->agency_id : null;
    $wsShow  = $wsSvc->agencyHasStats($wsAgency);
    $ws      = $wsShow ? $wsSvc->performanceFor((int) $property->id, $wsAgency) : null;
@endphp

@if($wsShow)
<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="flex items-start justify-between mb-3 flex-wrap gap-2">
        <div>
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Website Performance</h3>
            <p class="text-[10px]" style="color: var(--text-muted);">
                Engagement on the agency&rsquo;s own website &mdash; last {{ $ws['days'] }} days. Counted on the site (bots excluded) and sent to CoreX hourly.
            </p>
        </div>
        <div class="text-right">
            @if($ws['last_received_at'])
                <div class="text-[10px]" style="color: var(--text-muted);" title="{{ $ws['last_received_at']->format('D j M Y, H:i') }}">
                    Last received {{ $ws['last_received_at']->diffForHumans() }}
                </div>
            @else
                <div class="text-[10px]" style="color: var(--ds-amber, #d97706);">Website has never sent statistics</div>
            @endif
            @if(count($ws['sites']) > 1)
                <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                    @foreach($ws['sites'] as $wsSite)
                        <span class="inline-block ml-2">{{ $wsSite['site'] }} <span class="font-semibold tabular-nums">{{ number_format($wsSite['views']) }}</span></span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if(! $ws['has_data'])
        <div class="text-xs py-8 text-center" style="color: var(--text-faint);">
            No website traffic recorded for this listing yet.
            <div class="text-[10px] mt-1" style="color: var(--text-muted);">
                The website reports each listing&rsquo;s views hourly. A listing that is not published to the website, or has only just gone live, will show nothing here.
            </div>
        </div>
    @else
        @php
            // (views, unique views, impressions, enquiries, contact clicks) over the
            // window, with the lifetime figure as subtext. Contact clicks is phone +
            // email + share — one number for "tried to make contact", because an
            // agent reads intent, not which button carried it.
            $wsContactLifetime = 0;
            foreach (\App\Models\ListingWebsiteStat::CONTACT_METRICS as $wsMetric) {
                $wsContactLifetime += (int) ($ws['lifetime'][$wsMetric] ?? 0);
            }
            $wsTiles = [
                ['label' => 'Views',          'value' => $ws['views'],          'all' => (int) ($ws['lifetime']['detail_view'] ?? 0),        'hint' => 'Detail pages rendered'],
                ['label' => 'Unique Views',   'value' => $ws['unique_views'],   'all' => (int) ($ws['lifetime']['unique_detail_view'] ?? 0), 'hint' => 'Deduplicated per visitor over a 6-hour window'],
                ['label' => 'Impressions',    'value' => $ws['impressions'],    'all' => (int) ($ws['lifetime']['impression'] ?? 0),         'hint' => 'Times it appeared as a card on a results, search or home page'],
                ['label' => 'Enquiries',      'value' => $ws['enquiries'],      'all' => (int) ($ws['lifetime']['enquiry'] ?? 0),            'hint' => 'Enquiry forms submitted for this listing'],
                ['label' => 'Contact Clicks', 'value' => $ws['contact_clicks'], 'all' => $wsContactLifetime,                                 'hint' => 'Phone, email and share clicks combined'],
            ];
        @endphp

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
            @foreach($wsTiles as $wsTile)
                <div class="rounded-md p-3 text-center" style="background: var(--surface-2); border: 1px solid var(--border);" title="{{ $wsTile['hint'] }}">
                    <div class="text-xl font-bold tabular-nums" style="color: var(--text-primary);">{{ number_format($wsTile['value']) }}</div>
                    <div class="text-[10px] uppercase tracking-wider mt-1" style="color: var(--text-muted);">{{ $wsTile['label'] }}</div>
                    <div class="text-[10px] mt-0.5 tabular-nums" style="color: var(--text-faint);">{{ number_format($wsTile['all']) }} all time</div>
                </div>
            @endforeach

            <div class="rounded-md p-3 text-center" style="background: var(--surface-2); border: 1px solid var(--border);"
                 title="Enquiries as a share of detail-page views over the last {{ $ws['days'] }} days.">
                <div class="text-xl font-bold tabular-nums" style="color: {{ $ws['conversion'] === null ? 'var(--text-muted)' : '#00d4aa' }};">
                    {{ $ws['conversion'] === null ? '—' : number_format($ws['conversion'], 1) . '%' }}
                </div>
                <div class="text-[10px] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Conversion</div>
                <div class="text-[10px] mt-0.5" style="color: var(--text-faint);">views &rarr; enquiry</div>
            </div>
        </div>

        @php
            // 30-day sparkline of detail_view. Inline SVG on purpose — a single
            // trend line does not justify a chart library on this page, and this
            // renders with no JS and no build step.
            $wsSeries = $ws['series'];
            $wsCount  = max(count($wsSeries), 1);
            $wsPeak   = max($ws['peak'], 1);
            $wsW      = 300;
            $wsH      = 40;
            $wsStep   = $wsCount > 1 ? $wsW / ($wsCount - 1) : $wsW;
            $wsPoints = [];
            foreach ($wsSeries as $wsI => $wsRow) {
                $wsX = round($wsI * $wsStep, 2);
                $wsY = round($wsH - (($wsRow['views'] / $wsPeak) * ($wsH - 4)) - 2, 2);
                $wsPoints[] = "{$wsX},{$wsY}";
            }
            $wsLine = implode(' ', $wsPoints);
            $wsArea = $wsLine !== '' ? "0,{$wsH} " . $wsLine . " {$wsW},{$wsH}" : '';
        @endphp

        <div class="rounded-md p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Daily views &mdash; last {{ $ws['days'] }} days</span>
                <span class="text-[10px] tabular-nums" style="color: var(--text-muted);">peak {{ number_format($ws['peak']) }}/day</span>
            </div>
            <svg viewBox="0 0 {{ $wsW }} {{ $wsH }}" preserveAspectRatio="none" class="w-full" style="height: 40px; display: block;"
                 role="img" aria-label="Daily website views for the last {{ $ws['days'] }} days, peaking at {{ $ws['peak'] }} views in a day">
                @if($wsArea !== '')
                    <polygon points="{{ $wsArea }}" fill="#00d4aa" fill-opacity="0.12"></polygon>
                @endif
                <polyline points="{{ $wsLine }}" fill="none" stroke="#00d4aa" stroke-width="1.5"
                          stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></polyline>
            </svg>
            <div class="flex items-center justify-between mt-1">
                <span class="text-[10px]" style="color: var(--text-faint);">{{ \Illuminate\Support\Carbon::parse($ws['from'])->format('j M') }}</span>
                <span class="text-[10px]" style="color: var(--text-faint);">{{ \Illuminate\Support\Carbon::parse($ws['to'])->format('j M') }}</span>
            </div>
        </div>
    @endif
</div>
@endif
