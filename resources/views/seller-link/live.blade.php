{{--
    Seller Live Link — "Live Marketing Update" (public, token-gated)
    2026-08-25 (Johan): "a page that should prove to a seller that we are
    working, and the data we provide them should show this." Every section
    answers "is my agent actually doing anything" or collapses rather than
    guess. Full spec: .ai/specs/seller-live-link.md — read that before
    changing this file; it documents WHY each figure is computed the way it
    is, not just what it shows.

    Privacy boundary unchanged: buyer/enquirer identity never appears in any
    form on this page — the controller strips it before the view ever sees
    it, not the other way round.
--}}
@php
    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';

    $agent = $link->generatedBy ?? null;

    $published  = $compliance['published'] ?? false;
    $mandateExp = $compliance['mandate_expired'] ?? true;

    // "Mandate: Active" alone tells a seller nothing (cc4) — say the date.
    $mandateExpiryWords = null;
    if (!empty($compliance['mandate_expiry'])) {
        $expiryDate = \Carbon\Carbon::parse($compliance['mandate_expiry']);
        $mandateExpiryWords = $mandateExp
            ? 'Your mandate expired on ' . $expiryDate->format('j F Y') . '.'
            : 'Your mandate runs until ' . $expiryDate->format('j F Y') . '.';
    }

    $heroImage = $property->thumbFor(
        ($property->gallery_images_json[0] ?? null)
        ?? ($property->dawn_images_json[0] ?? null)
        ?? ($property->noon_images_json[0] ?? null)
        ?? ($property->dusk_images_json[0] ?? null)
        ?? ($property->images_json[0] ?? null)
    );
    $heroImage = \App\Models\Property::publicImageUrl($heroImage);

    // Bed/bath/garage/erf — the seller's own property detail, never a buyer
    // signal, so no privacy boundary applies here.
    $propFacts = collect([
        [$property->beds, 'bed'], [$property->baths, 'bath'], [$property->garages, 'garage'],
    ])->filter(fn ($f) => !empty($f[0]));
    $erfSize = $property->erf_size_m2 ?? null;

    // SECTION: What we have done — real counts only, one narrative sentence
    // built from the SAME numbers shown as tiles (not a separate figure).
    $enquiriesReceived = collect($portalEngagement['series'] ?? [])->sum('leads')
        + collect($portalEngagement['series'] ?? [])->sum('pp_leads');
    $whatWeveDoneParts = [];
    if ($buyerDemand['total'] > 0) {
        $whatWeveDoneParts[] = $buyerDemand['total'] . ' ' . \Illuminate\Support\Str::plural('buyer', $buyerDemand['total']) . ' currently ' . ($buyerDemand['total'] === 1 ? 'matches' : 'match') . ' your home';
    }
    if (($feedbackRollup['total_viewings'] ?? 0) > 0) {
        $n = $feedbackRollup['total_viewings'];
        $whatWeveDoneParts[] = $n . ' ' . \Illuminate\Support\Str::plural('viewing', $n) . ' ' . ($n === 1 ? 'has' : 'have') . ' been held';
    }
    if ($enquiriesReceived > 0) {
        $whatWeveDoneParts[] = $enquiriesReceived . ' ' . ($enquiriesReceived === 1 ? 'enquiry has' : 'enquiries have') . ' come in';
    }
    if (!empty($portalsLive)) {
        $whatWeveDoneParts[] = 'your listing is live on ' . (count($portalsLive) > 1
            ? implode(', ', array_slice($portalsLive, 0, -1)) . ' and ' . end($portalsLive)
            : $portalsLive[0]);
    }
    $whatWeveDoneSentence = count($whatWeveDoneParts)
        ? ucfirst(implode('; ', $whatWeveDoneParts)) . '.'
        : null;

    // SECTION: Activity over time — same series the chart plots, no second query.
    $engagementSeries = $portalEngagement['series'] ?? [];
    $hasEngagementData = ($portalEngagement['has_data'] ?? false) || ($portalEngagement['pp_has_data'] ?? false);
    $hasPpEngagement = $portalEngagement['pp_has_data'] ?? false;
    $ppEngagementViews = array_sum(array_column($engagementSeries, 'pp_views'));
    $ppEngagementLeads = array_sum(array_column($engagementSeries, 'pp_leads'));

    // What buyers said — top theme(s) rendered as a short lead-in line
    // ("2 of 2 viewers mentioned Location"), from getFeedbackThemes()'s
    // structured concern_option_ids — never free-text keyword guessing.
    $feedbackThemesLines = collect($feedbackThemes)->map(fn ($t) =>
        $t['count'] . ' of ' . $t['total'] . ' ' . \Illuminate\Support\Str::plural('viewer', $t['total']) . ' mentioned ' . $t['label']
    );

    // 2026-08-25 CORRECTION (Johan) — "a seller prices their own home
    // against a number we told them was final, in writing, with their
    // agent's name on it." property_sold_records-sourced "sold" comparisons
    // were confirmed to mirror the property's own advertised price, not a
    // real transaction. Replaced with two genuinely separate sources
    // (registeredSales / underOfferSales, from the controller) and this
    // ONE shared sentence-builder — the verb ("sold" / "went under offer")
    // is the ONLY thing that differs between them, and it comes from
    // $labels (SellerLinkController::LABELS) so a rename is one line there,
    // never a hunt through this file.
    $buildComparisonSentence = function ($comparison, string $verb) use ($property) {
        if (!$comparison) return null;
        $subjFacts = trim(($property->beds ? $property->beds . ' bed' : '') . ($property->baths ? ' ' . rtrim(rtrim(number_format($property->baths, 1), '0'), '.') . ' bath' : ''));
        $compFacts = trim(($comparison['comp_beds'] ? $comparison['comp_beds'] . ' bed' : '') . ($comparison['comp_baths'] ? ' ' . rtrim(rtrim(number_format($comparison['comp_baths'], 1), '0'), '.') . ' bath' : ''));
        return 'Your ' . ($subjFacts ?: strtolower($property->property_type ?? 'property')) . ' has been on the market ' . $comparison['subject_days'] . ' ' . \Illuminate\Support\Str::plural('day', $comparison['subject_days'])
            . '; a comparable ' . ($compFacts ?: strtolower($comparison['comp_type'] ?? 'property')) . ' nearby ' . $verb . ' in ' . $comparison['comp_days'] . ' ' . \Illuminate\Support\Str::plural('day', $comparison['comp_days'])
            . ' at R' . number_format($comparison['comp_price']) . '.';
    };
    $registeredSentence = $buildComparisonSentence($registeredComparison, $labels['sold_verb']);
    $underOfferSentence = $buildComparisonSentence($underOfferComparison, $labels['under_offer_verb']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light">
    <title>{{ $property->title ?? 'Property' }} — Live Marketing Update{{ !empty($agency) && $agency->name ? ' · ' . $agency->name : '' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    {{-- Self-contained assets, no third party in the critical path — the
         Tailwind CDN fix applies here too (already ported to QA1). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f0f2f8;
            --border: rgba(0,0,0,0.07);
            --border-hover: rgba(0,0,0,0.14);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            /* Agency brand colours — injected from Company Settings */
            --brand-default: {{ $brandDefault }};
            --brand-icon: {{ $brandIcon }};
            --brand-button: {{ $brandButton }};
            --ds-green: #059669;
            --ds-amber: #f59e0b;
            --ds-crimson: #c41e3a;
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .ds-badge {
            display: inline-flex; align-items: center; gap: .3rem; white-space: nowrap;
            border-radius: 9999px; padding: 0.2rem 0.6rem;
            font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            border: 1px solid transparent;
        }
        .ds-badge-success { background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border-color: color-mix(in srgb, var(--ds-green) 28%, transparent); }
        .ds-badge-warning { background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border-color: color-mix(in srgb, var(--ds-amber) 28%, transparent); }
        .engagement-range-btn { background: var(--surface); border-color: var(--border); color: var(--text-secondary); cursor: pointer; }
        .engagement-range-btn.active { background: var(--brand-default); border-color: var(--brand-default); color: #fff; }
        .tier-chip {
            display: inline-flex; align-items: center; gap: .35rem;
            border-radius: 9999px; padding: 0.3rem 0.75rem; font-size: 0.75rem; font-weight: 600;
        }
    </style>
</head>
<body class="min-h-screen">

    {{-- Top bar --}}
    <header class="sticky top-0 z-30" style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-4xl mx-auto px-4 lg:px-6 py-3.5 flex items-center justify-between gap-3">
            @if($agency && $agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Marketing Update' }}</div>
            @endif
            <span class="ds-badge" style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">Live update</span>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 lg:px-6 py-6 space-y-6">

        {{-- Hero — unchanged --}}
        <section class="rounded-xl px-6 py-6 relative overflow-hidden"
                 style="background: linear-gradient(135deg, var(--brand-default) 0%, color-mix(in srgb, var(--brand-default) 82%, #000) 100%);">
            <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full opacity-20"
                 style="background: radial-gradient(circle, var(--brand-icon) 0%, transparent 70%);"></div>
            <div class="relative flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.15em]" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">Live marketing update</p>
                    <h1 class="text-2xl font-extrabold leading-tight text-white mt-1">{{ $property->title ?? 'Your Property' }}</h1>
                    <p class="text-sm mt-1" style="color: rgba(255,255,255,0.65);">Hi {{ $seller->first_name ?? 'there' }}, here's what's happening with your listing.</p>
                    <p class="text-[0.6875rem] mt-2" style="color: rgba(255,255,255,0.4);">Last refreshed: <span id="refresh-time">just now</span></p>
                </div>

                @if($agent)
                <div class="flex items-center gap-3 flex-shrink-0 rounded-lg px-3.5 py-2.5"
                     style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14);">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background: var(--brand-icon);">
                        {{ strtoupper(substr($agent->name, 0, 2)) }}
                    </div>
                    <div class="text-left">
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.55);">Your Agent</div>
                        <div class="text-sm font-semibold text-white leading-tight">{{ $agent->name }}</div>
                    </div>
                </div>
                @endif
            </div>
        </section>

        {{-- Property photo + facts — unchanged --}}
        <section class="surface-card overflow-hidden">
            @if($heroImage)
                <div class="relative w-full overflow-hidden" style="background: var(--surface-2); height: 200px;">
                    <img src="{{ $heroImage }}" alt="{{ $property->title ?? 'Your property' }}" loading="lazy"
                         class="absolute inset-0 w-full h-full object-cover">
                </div>
            @endif
            <div class="p-5">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="text-2xl font-extrabold" style="color: var(--brand-default);">{{ $property->formattedPrice() }}</div>
                </div>
                @if($property->suburb)
                <div class="text-sm mt-0.5" style="color: var(--text-muted);">{{ $property->suburb }}{{ $property->city ? ', ' . $property->city : '' }}</div>
                @endif
                @if($propFacts->isNotEmpty() || $erfSize)
                <div class="flex flex-wrap items-center gap-4 mt-3">
                    @foreach($propFacts as [$v, $l])
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-base font-semibold" style="color: var(--text-primary);">{{ $v }}</span>
                            <span class="text-xs" style="color: var(--text-muted);">{{ $v == 1 ? $l : \Illuminate\Support\Str::plural($l) }}</span>
                        </div>
                    @endforeach
                    @if($erfSize)
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-base font-semibold" style="color: var(--text-primary);">{{ number_format($erfSize) }}</span>
                            <span class="text-xs" style="color: var(--text-muted);">m&sup2; erf</span>
                        </div>
                    @endif
                </div>
                @endif
            </div>
        </section>

        {{-- SECTION 2 — Where your property stands. Always renders (asking
             price + status badges always exist); days-on-market only when
             listed_date is real (no fallback-chain proxy — the seller-live
             page burned that once already on "published"). --}}
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Where your property stands</h2>
            <div class="grid grid-cols-2 {{ $daysOnMarket !== null ? 'sm:grid-cols-2' : '' }} gap-4 mb-4">
                <div>
                    <div class="text-lg font-bold" style="color: var(--brand-default);">{{ $property->formattedPrice() }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Asking price</div>
                </div>
                @if($daysOnMarket !== null)
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $daysOnMarket }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Days on market</div>
                </div>
                @endif
            </div>
            <div class="flex flex-wrap gap-2 pt-3" style="border-top: 1px solid var(--border);">
                <span class="ds-badge {{ $published ? 'ds-badge-success' : 'ds-badge-warning' }}">
                    Listing: {{ $published ? 'Active' : 'Unpublished' }}
                </span>
                <span class="ds-badge {{ $mandateExp ? 'ds-badge-warning' : 'ds-badge-success' }}">
                    Mandate: {{ $mandateExp ? 'Review needed' : 'Active' }}
                </span>
            </div>
            @if($mandateExpiryWords)
                <p class="text-xs mt-2" style="color: var(--text-muted);">{{ $mandateExpiryWords }}</p>
            @endif
        </section>

        {{-- SECTION 3 — What we have done. The heart of the page: real
             counts, then one sentence built from those SAME numbers, not a
             chart the seller has to interpret alone. Whole section absent
             when every count is genuinely zero — a row of four zeroes is
             not "proof we're working", it's a worse version of no section. --}}
        @if($whatWeveDoneSentence)
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">What we have done</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">Real activity on your listing, updated live.</p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $buyerDemand['total'] }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                        {{ \Illuminate\Support\Str::plural('buyer', $buyerDemand['total']) }} matched
                    </div>
                    @if($buyerDemand['strong'] > 0 || $buyerDemand['good'] > 0)
                        <div class="text-[0.625rem] mt-0.5" style="color: var(--ds-green);">
                            {{ collect([$buyerDemand['strong'] ? $buyerDemand['strong'] . ' strong' : null, $buyerDemand['good'] ? $buyerDemand['good'] . ' good' : null])->filter()->implode(', ') }}
                        </div>
                    @endif
                </div>
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $feedbackRollup['total_viewings'] ?? 0 }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                        {{ \Illuminate\Support\Str::plural('viewing', $feedbackRollup['total_viewings'] ?? 0) }} held
                    </div>
                </div>
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $enquiriesReceived }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                        {{ $enquiriesReceived === 1 ? 'Enquiry' : 'Enquiries' }} received
                    </div>
                </div>
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ count($portalsLive) }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                        {{ \Illuminate\Support\Str::plural('portal', count($portalsLive)) }} live
                    </div>
                </div>
            </div>

            <p class="text-sm pt-3" style="color: var(--text-secondary); border-top: 1px solid var(--border);">{{ $whatWeveDoneSentence }}</p>
        </section>
        @endif

        {{-- SECTION 4 — Activity over time, with price changes marked on
             it. Same series the chart totals sum to (verified: they agree).
             Rule 2: absent entirely when there's no engagement data on
             either portal. --}}
        @if($hasEngagementData)
        <section class="surface-card p-5" id="engagement-section"
                 data-engagement-series='@json($engagementSeries)'
                 data-price-changes='@json($priceChangeEvents)'>
            <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                <div>
                    <h2 class="text-base font-bold" style="color: var(--text-primary);">Views &amp; enquiries over time</h2>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                        Property24 daily views &amp; enquiries, backfilled to ~6 months. Private Property views &amp; enquiries are only collected from when tracking was switched on for your listing — there's no history before that date.
                        @if(!empty($priceChangeEvents))
                            <span style="color: #8b5cf6;">&bull; dashed lines mark price changes.</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0" id="engagement-range-toggle">
                    <button type="button" data-range="30" class="engagement-range-btn text-[0.6875rem] font-semibold px-2 py-1 rounded-md border">30D</button>
                    <button type="button" data-range="90" class="engagement-range-btn text-[0.6875rem] font-semibold px-2 py-1 rounded-md border">90D</button>
                    <button type="button" data-range="all" class="engagement-range-btn text-[0.6875rem] font-semibold px-2 py-1 rounded-md border">6M</button>
                </div>
            </div>

            <div class="flex items-center gap-4 mt-3 mb-2 text-xs flex-wrap" style="color: var(--text-secondary);">
                <span class="inline-flex items-center gap-1">
                    <span class="inline-block w-2 h-2 rounded-full" style="background:#00d4aa;"></span>
                    Views <span class="font-semibold" id="engagement-total-views"></span>
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="inline-block w-2 h-2 rounded-full" style="background:#ef4444;"></span>
                    Enquiries <span class="font-semibold" id="engagement-total-leads"></span>
                </span>
                @if($hasPpEngagement)
                    <span class="inline-flex items-center gap-1" title="Private Property engagement since tracking was switched on for this listing — no history before that date.">
                        <span class="inline-block w-2 h-2 rounded-full" style="background:#8b5cf6;"></span>
                        Private Property views {{ number_format($ppEngagementViews) }}
                        <span style="color: var(--text-muted);">· enquiries {{ number_format($ppEngagementLeads) }}</span>
                    </span>
                @endif
                <span style="color: var(--text-muted);" class="text-[0.6875rem]" id="engagement-day-label"></span>
            </div>

            <div style="position: relative; height: 220px;">
                <canvas id="engagement-canvas"></canvas>
            </div>

            @if($priceChangeNarrative)
                <p class="text-sm mt-3 pt-3" style="color: var(--text-secondary); border-top: 1px solid var(--border);">
                    Price {{ $priceChangeNarrative['direction'] }} to R{{ number_format($priceChangeNarrative['new_price']) }} on {{ $priceChangeNarrative['date']->format('j F') }}
                    — daily views went from an average of {{ $priceChangeNarrative['before_avg'] }} the week before to {{ $priceChangeNarrative['after_avg'] }}
                    {{ $priceChangeNarrative['after_days'] >= 7 ? 'the week after' : 'in the ' . $priceChangeNarrative['after_days'] . ' ' . \Illuminate\Support\Str::plural('day', $priceChangeNarrative['after_days']) . ' since' }}.
                </p>
            @endif
        </section>
        @endif

        {{-- SECTION 5 — What buyers said. Themes line (structured concern
             data) first, then the actual seller-visible written notes.
             Absent entirely when there have been no viewings; "no feedback
             yet" stays honest when there have been viewings but no notes. --}}
        @if(($feedbackRollup['total_viewings'] ?? 0) > 0)
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">What buyers said</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">{{ $feedbackRollup['total_viewings'] }} viewing{{ $feedbackRollup['total_viewings'] === 1 ? '' : 's' }} recorded so far.</p>

            @if($feedbackThemesLines->isNotEmpty())
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach($feedbackThemesLines as $line)
                        <span class="tier-chip" style="background: color-mix(in srgb, var(--brand-icon) 14%, transparent); color: var(--brand-default);">{{ $line }}</span>
                    @endforeach
                </div>
            @endif

            @if(count($viewingFeedback) > 0)
                <div class="space-y-3">
                    @foreach($viewingFeedback as $fb)
                        <div class="p-3 rounded-lg" style="background: var(--surface-2);">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                @if($fb['outcome_label'])
                                    <span class="text-xs font-semibold" style="color: var(--brand-default);">{{ $fb['outcome_label'] }}</span>
                                @else
                                    <span></span>
                                @endif
                                @if($fb['date'])
                                    <span class="text-[0.6875rem]" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($fb['date'])->format('d M') }}</span>
                                @endif
                            </div>
                            @if($fb['notes'])
                                <p class="text-sm" style="color: var(--text-secondary);">{{ $fb['notes'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm" style="color: var(--text-secondary);">
                    No viewing feedback yet. As soon as your agent adds notes, they'll appear here.
                </p>
            @endif
        </section>
        @endif

        {{-- SECTION 3 (agent recommendations) — insights only when present. --}}
        @if($recommendations->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">What's your agent doing</h2>
            <div class="space-y-3">
                @foreach($recommendations as $rec)
                    <div class="flex items-start gap-3">
                        <span class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0" style="background: var(--brand-icon);"></span>
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $rec->seller_facing_title }}</div>
                            @if($rec->seller_facing_reasoning)
                                <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $rec->seller_facing_reasoning }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- SECTION 6 — Your competition right now. DECISION FLAG (see
             report): findComparableStock() is scoped to the subject's own
             agency — this is the agency's own comparable active stock
             nearby, the same source "Similar properties" already used, NOT
             verified cross-agency market data. Worded honestly below.
             Complex/street name only — no unit number, no agency name.
             Absent below 2 genuine comparables ("one lonely comparable is
             not a market" — Johan). --}}
        @if($activeComparables->count() >= 2)
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">Similar homes on the market near you</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">Other active listings nearby, matched on type, price band and beds.</p>
            <div class="divide-y" style="border-color: var(--border);">
                @foreach($activeComparables as $c)
                    <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span style="color: var(--text-secondary);">
                            {{ collect([$c['location'], $c['property_type'], $c['beds'] ? $c['beds'] . ' bed' : null, $c['baths'] ? $c['baths'] . ' bath' : null])->filter()->implode(' · ') }}
                        </span>
                        <span class="text-right flex-shrink-0">
                            <span class="font-semibold" style="color: var(--brand-default);">R {{ number_format($c['price']) }}</span>
                            <span class="text-xs block" style="color: var(--text-muted);">{{ $c['days_on_market'] }} {{ \Illuminate\Support\Str::plural('day', $c['days_on_market']) }} on market</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- SECTION 7 — What has actually sold near you. 2026-08-25
             CORRECTION: this used to read property_sold_records, whose
             "sold_price" was confirmed to mirror the property's own
             advertised price — not a real transaction. Never use that
             table for a "sold" claim again. Source now: the legacy `deals`
             table (Dr1), registration_date IS NOT NULL — the only source on
             this system a "sold" word may come from. Absent when there are
             no genuinely registered comparable sales. --}}
        @if($registeredSales->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">{{ $labels['sold_heading'] }}</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">{{ $labels['sold_subtitle'] }}</p>

            @if($registeredSentence)
                <p class="text-sm mb-4 p-3 rounded-lg" style="background: var(--surface-2); color: var(--text-secondary);">{{ $registeredSentence }}</p>
            @endif

            <div class="divide-y" style="border-color: var(--border);">
                @foreach($registeredSales as $s)
                    <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span style="color: var(--text-secondary);">
                            {{ collect([$s['property_type'], $s['beds'] ? $s['beds'] . ' bed' : null, $s['baths'] ? $s['baths'] . ' bath' : null])->filter()->implode(' · ') }}
                        </span>
                        <span class="text-right flex-shrink-0">
                            <span class="font-semibold" style="color: var(--brand-default);">R {{ number_format($s['price']) }}</span>
                            <span class="text-xs block" style="color: var(--text-muted);">
                                {{ \Carbon\Carbon::parse($s['registration_date'])->format('M Y') }}{{ $s['days_to_sell'] !== null ? ' · ' . $s['days_to_sell'] . ' ' . \Illuminate\Support\Str::plural('day', $s['days_to_sell']) . ' to ' . $labels['sold_verb'] : '' }}
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- SECTION 7b — What has recently gone under offer near you. NEVER
             say "sold"/"achieved" here — an accepted offer can still fall
             through. Source: deals_v2 (Dr2), actual_registration IS NULL.
             Absent when there are no genuine under-offer comparables. --}}
        @if($underOfferSales->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">{{ $labels['under_offer_heading'] }}</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">{{ $labels['under_offer_subtitle'] }}</p>

            @if($underOfferSentence)
                <p class="text-sm mb-4 p-3 rounded-lg" style="background: var(--surface-2); color: var(--text-secondary);">{{ $underOfferSentence }}</p>
            @endif

            <div class="divide-y" style="border-color: var(--border);">
                @foreach($underOfferSales as $s)
                    <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span style="color: var(--text-secondary);">
                            {{ collect([$s['property_type'], $s['beds'] ? $s['beds'] . ' bed' : null, $s['baths'] ? $s['baths'] . ' bath' : null])->filter()->implode(' · ') }}
                        </span>
                        <span class="text-right flex-shrink-0">
                            <span class="font-semibold" style="color: var(--brand-default);">R {{ number_format($s['price']) }}</span>
                            <span class="text-xs block" style="color: var(--text-muted);">
                                {{ \Carbon\Carbon::parse($s['offer_date'])->format('M Y') }}{{ $s['days_to_offer'] !== null ? ' · ' . $s['days_to_offer'] . ' ' . \Illuminate\Support\Str::plural('day', $s['days_to_offer']) . ' to offer' : '' }}
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Agent card — who to call (shared public-page component) --}}
        @include('public.shared._agent-card', ['agent' => $agent, 'agency' => $agency, 'heading' => 'Your agent'])

    </main>

    {{-- Company footer (shared public-page component) --}}
    @include('public.shared._company-footer', ['agency' => $agency])

    <script>
        // Auto-refresh indicator — reloads the live update every 60s.
        setInterval(function () {
            var el = document.getElementById('refresh-time');
            if (el) el.textContent = 'refreshing…';
            setTimeout(function () { window.location.reload(); }, 500);
        }, 60000);
    </script>

    @if($hasEngagementData)
    <script>
        // Views & enquiries chart. Calls the SAME window.NexusCharts.
        // portalEngagement() the internal Intelligence tab calls — genuine
        // reuse, not a parallel copy. Price-change markers are drawn by
        // that same shared function's inline plugin (opts.priceMarkers),
        // recomputed here on every range change since the marker's INDEX
        // into the displayed range shifts as the window shifts.
        //
        // The Vite tags above are type="module" and deferred — they only
        // run after the document has finished parsing, which is AFTER a
        // classic inline script would otherwise run. Wrapping in
        // DOMContentLoaded (which itself only fires after every deferred/
        // module script has already executed) guarantees window.NexusCharts
        // exists before this reads it, instead of racing it.
        document.addEventListener('DOMContentLoaded', function () {
            var section = document.getElementById('engagement-section');
            if (!section) return;
            var series = JSON.parse(section.getAttribute('data-engagement-series') || '[]');
            var priceChanges = JSON.parse(section.getAttribute('data-price-changes') || '[]');
            var canvas = document.getElementById('engagement-canvas');
            var totalViewsEl = document.getElementById('engagement-total-views');
            var totalLeadsEl = document.getElementById('engagement-total-leads');
            var dayLabelEl = document.getElementById('engagement-day-label');
            var chart = null;
            var range = '30';

            function filtered() {
                if (range === 'all') return series;
                var n = parseInt(range, 10);
                return series.slice(-n);
            }

            function fmt(d) {
                var dt = new Date(d + 'T00:00:00');
                return dt.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' });
            }

            function sum(rows, key) {
                return rows.reduce(function (a, r) { return a + (r[key] || 0); }, 0);
            }

            // Map each price-change date to its INDEX in the currently
            // displayed (filtered) series — the marker plugin draws at a
            // pixel position derived from that index, so it has to be
            // recomputed every time the displayed window changes.
            function markersFor(f) {
                var dateIndex = {};
                f.forEach(function (r, i) { dateIndex[r.date] = i; });
                return priceChanges
                    .filter(function (pc) { return dateIndex.hasOwnProperty(pc.date); })
                    .map(function (pc) { return { index: dateIndex[pc.date], price: pc.new_price }; });
            }

            function apply() {
                if (!window.NexusCharts) return;
                var f = filtered();
                var labels = f.map(function (r) { return fmt(r.date); });
                var views = f.map(function (r) { return r.views; });
                var leads = f.map(function (r) { return r.leads; });
                var markers = markersFor(f);

                if (!chart) {
                    chart = window.NexusCharts.portalEngagement(canvas, labels, views, leads, {
                        leadsLabel: 'Enquiries',
                        leadsAxisLabel: 'Enquiries',
                        priceMarkers: markers,
                    });
                } else {
                    chart.data.labels = labels;
                    chart.data.datasets[0].data = views;
                    chart.data.datasets[1].data = leads;
                    chart.options.plugins.priceMarkerPlugin.markers = markers;
                    chart.update();
                }

                if (totalViewsEl) totalViewsEl.textContent = sum(f, 'views');
                if (totalLeadsEl) totalLeadsEl.textContent = sum(f, 'leads');
                if (dayLabelEl) dayLabelEl.textContent = f.length ? '· ' + f.length + ' days' : '';

                document.querySelectorAll('.engagement-range-btn').forEach(function (btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-range') === range);
                });
            }

            document.querySelectorAll('.engagement-range-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    range = btn.getAttribute('data-range');
                    apply();
                });
            });

            apply();
        });
    </script>
    @endif
</body>
</html>
