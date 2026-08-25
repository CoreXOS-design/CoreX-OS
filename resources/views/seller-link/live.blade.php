{{--
    Seller Live Link — "Live Marketing Update" (public, token-gated)
    2026-08-24 rebuild (Johan): "more visual, carry everything a seller needs
    to see — feedback, demand, activity." Ordered around the seller's own
    question order: is anyone looking → what are they saying → what's my
    agent doing → where does it sit in the market. Privacy boundary per
    .ai/audits/2026-08-24-seller-live-link-data-availability.md Part 2:
    buyer/enquirer identity never appears in any form on this page — the
    controller strips it before the view ever sees it, not the other way
    round. "Market presentation" and "Marketing activity" sections dropped
    per the same audit (0% real fill rate on live data — see Part 1).
--}}
@php
    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';

    $agent = $link->generatedBy ?? null;

    $daysListed = $compliance['days_on_market'] ?? null;
    $published  = $compliance['published'] ?? false;
    $mandateExp = $compliance['mandate_expired'] ?? true;

    $hasPortalData = $portalPerformance['has_data'] ?? false;

    // 2026-08-25 (Johan) — "the seller knows their property." A full-width
    // 16:9 hero (~500px tall) meant the entire first screen was one image —
    // worse still when (as on this listing) the file lives on live's disk,
    // not staging's, and PropertyThumbnailService::displayUrl() correctly
    // returns null: a giant grey placeholder. Capped to a fixed, modest
    // height below (a proportion, not the whole first screen) and — per the
    // same visit's "if nothing to display, remove the block" rule — the
    // WHOLE image area (not a placeholder) is now absent when there's no
    // photo at all; the price/facts panel underneath it never depended on
    // the image and stays. Still the same choke point every other property-
    // image surface uses (PropertyThumbnailService::displayUrl(), via the
    // Property model's thumbFor() wrapper) — it returns null when neither
    // the thumbnail nor the original exists on disk, so this is a REAL
    // gate, never a broken-icon or bare-alt-text fallback. Same first-image
    // resolution order as buyer-portal/_property-card.blade.php — copied,
    // not reinvented.
    $heroImage = $property->thumbFor(
        ($property->gallery_images_json[0] ?? null)
        ?? ($property->dawn_images_json[0] ?? null)
        ?? ($property->noon_images_json[0] ?? null)
        ?? ($property->dusk_images_json[0] ?? null)
        ?? ($property->images_json[0] ?? null)
    );
    $heroImage = \App\Models\Property::publicImageUrl($heroImage);

    // Bed/bath/garage/erf — the seller's own property detail, never a buyer
    // signal, so no privacy boundary applies here (unlike buyerDemand below).
    $propFacts = collect([
        [$property->beds, 'bed'], [$property->baths, 'bath'], [$property->garages, 'garage'],
    ])->filter(fn ($f) => !empty($f[0]));
    $erfSize = $property->erf_size_m2 ?? null;

    // 2026-08-25 (Johan) — "Stats should work on the basis: if nothing to
    // display, remove." Applied here to the individual Property24 views /
    // enquiries tiles (previously a "—" / "Not yet reporting" placeholder
    // pair when portal data hasn't landed yet) — a tile only exists in this
    // list when it has a real value. Days listed is always derivable (the
    // fallback chain in getComplianceStatus() never returns null in
    // practice), so it's always present.
    $demandStats = collect([
        ['value' => $daysListed !== null ? $daysListed : null, 'label' => 'Days listed'],
        ['value' => $hasPortalData ? number_format($portalPerformance['views']) : null, 'label' => 'Property24 views (30d)'],
        ['value' => $hasPortalData ? number_format($portalPerformance['enquiries']) : null, 'label' => 'Enquiries (30d)'],
    ])->filter(fn ($s) => $s['value'] !== null);

    // 2026-08-25 (Johan) — "port the graph" from the internal Intelligence
    // tab's Portal Engagement chart. Same data (getPortalEngagementSeries(),
    // called once in the controller, no second query) and the same chart
    // CONFIG (colours/axes/tooltip copied verbatim from resources/js/
    // nexus-charts.js's NexusCharts.portalEngagement() below) — this page
    // never loads the authenticated app's Vite bundle (no public page in
    // this codebase does; @vite() assumes the logged-in shell), so the
    // config is inlined rather than imported. See the report for the exact
    // line this was copied from.
    $engagementSeries = $portalEngagement['series'] ?? [];
    $hasEngagementData = ($portalEngagement['has_data'] ?? false) || ($portalEngagement['pp_has_data'] ?? false);
    $hasPpEngagement = $portalEngagement['pp_has_data'] ?? false;
    $ppEngagementViews = array_sum(array_column($engagementSeries, 'pp_views'));
    $ppEngagementLeads = array_sum(array_column($engagementSeries, 'pp_leads'));
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
    {{-- 2026-08-25 (Johan) — Tailwind's CDN JIT-compiler script (a dev tool,
         not a production one — it compiles every class in the browser on
         every load) sat render-blocking in front of first paint on every
         public page in this family. When that CDN misbehaves, the client
         gets a blank page with nothing in our own code to explain it —
         worse than a bare 404. Same fix as public/agency-properties/show
         and welcome.blade.php already use in production: our own built
         CSS/JS, no third party in the critical path. app.js also bundles
         Chart.js (via nexus-charts.js) and Alpine synchronously, so the
         Chart.js CDN <script> this page used to load conditionally is gone
         too — see the engagement-chart script below, which now calls
         window.NexusCharts.portalEngagement() directly instead of
         embedding its own copy of that config. --}}
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

        {{-- Hero --}}
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

        {{-- Property photo (proportionate, present only when a real photo exists)
             + the facts a seller recognises their own home by (always shown —
             price/suburb/bed/bath never depended on the image being there). --}}
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

        {{-- SECTION 1 — Is anyone looking at your home? (buyer demand, first, per Johan) --}}
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">Is anyone looking at your home?</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">Buyers currently registered with us whose search matches your property.</p>

            <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 mb-4">
                <div class="flex items-baseline gap-2 flex-shrink-0">
                    <span class="text-4xl font-extrabold" style="color: var(--brand-default);">{{ $buyerDemand['total'] }}</span>
                    <span class="text-sm font-medium" style="color: var(--text-secondary);">{{ \Illuminate\Support\Str::plural('buyer', $buyerDemand['total']) }} matching right now</span>
                </div>
                @if($buyerDemand['total'] > 0)
                <div class="flex flex-wrap gap-2">
                    @if($buyerDemand['strong'] > 0)
                        <span class="tier-chip" style="background: color-mix(in srgb, var(--ds-green) 14%, transparent); color: var(--ds-green);">{{ $buyerDemand['strong'] }} strong match{{ $buyerDemand['strong'] === 1 ? '' : 'es' }}</span>
                    @endif
                    @if($buyerDemand['good'] > 0)
                        <span class="tier-chip" style="background: color-mix(in srgb, var(--brand-icon) 14%, transparent); color: var(--brand-default);">{{ $buyerDemand['good'] }} good match{{ $buyerDemand['good'] === 1 ? '' : 'es' }}</span>
                    @endif
                    @if($buyerDemand['fair'] > 0)
                        <span class="tier-chip" style="background: color-mix(in srgb, var(--ds-amber) 14%, transparent); color: var(--ds-amber);">{{ $buyerDemand['fair'] }} fair match{{ $buyerDemand['fair'] === 1 ? '' : 'es' }}</span>
                    @endif
                </div>
                @endif
            </div>

            @if($buyerDemand['total'] === 0)
                <p class="text-sm mb-4" style="color: var(--text-secondary);">No buyers in our system currently match your home's price and criteria — this moves as new buyers register and as your listing is refreshed.</p>
            @endif

            @if($demandStats->isNotEmpty())
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-4" style="border-top: 1px solid var(--border);">
                @foreach($demandStats as $stat)
                <div class="text-center sm:text-left">
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $stat['value'] }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $stat['label'] }}</div>
                </div>
                @endforeach
            </div>
            @endif
        </section>

        {{-- SECTION 2 — What are they saying? (viewing feedback)
             2026-08-25 (Johan) — "Stats should work on the basis: if nothing
             to display, remove." At zero viewings this used to say "0
             viewings recorded so far" immediately followed by "No viewing
             feedback yet... will appear here" — the same nothing stated
             twice. The whole card (heading included) is now absent when
             there have been no viewings at all. When there HAVE been
             viewings but none carry feedback notes yet, the section stays —
             "N viewings recorded" is real information on its own, and "no
             feedback yet" is a genuinely different fact next to it, not a
             repeat. --}}
        @if(($feedbackRollup['total_viewings'] ?? 0) > 0)
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">What are viewers saying?</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">{{ $feedbackRollup['total_viewings'] }} viewing{{ $feedbackRollup['total_viewings'] === 1 ? '' : 's' }} recorded so far.</p>

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

        {{-- SECTION 3 — What's your agent doing? (insights only when present — hidden, not empty) --}}
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

        {{-- SECTION 3b — Views & enquiries over time. Ported from the
             internal Intelligence tab's Portal Engagement chart (Johan,
             2026-08-25) — "answers 'what is my agent actually doing' with
             evidence instead of adjectives." Seller framing: no "P24"/"PP"
             jargon (spelled out), "leads" (agent language) becomes
             "enquiries" (what a seller calls the same thing) everywhere,
             including inside the reused chart's own dataset label. Same
             honest backfill distinction the internal page's subtitle
             already makes. Rule 2: absent entirely, not an empty chart,
             when there's no engagement data on either portal at all. --}}
        @if($hasEngagementData)
        <section class="surface-card p-5" id="engagement-section" data-engagement-series='@json($engagementSeries)'>
            <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                <div>
                    <h2 class="text-base font-bold" style="color: var(--text-primary);">Views &amp; enquiries over time</h2>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                        Property24 daily views &amp; enquiries, backfilled to ~6 months. Private Property views &amp; enquiries are only collected from when tracking was switched on for your listing — there's no history before that date.
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
        </section>
        @endif

        {{-- SECTION 4 — Market position (price/value, with price-change strip folded in when present)
             2026-08-25 (Johan) — AREA AVERAGE REMOVED. His words: "many
             buyers, property is R250k below market avg yet its not sold.
             that look bad for what the agency is doing." An area average
             spans every property type in the area — comparing it to one
             2-bed apartment was never an honest comparison, and next to a
             high buyer-demand number it reads as "your agent isn't doing
             their job." Estimated Market Value stays — paired with the
             property's OWN asking price, which is the real, useful,
             explainable comparison (over/under the estimate) an agent can
             actually have a conversation about. Section gate updated to key
             off recommended_price specifically, since that's the only half
             of $marketPosition this section still reads. --}}
        @if(($marketPosition['recommended_price'] ?? null) || $priceHistory->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Where your price sits</h2>

            @if($marketPosition['recommended_price'] ?? null)
            <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                    <div class="text-lg font-bold" style="color: var(--brand-default);">R {{ number_format($marketPosition['recommended_price']) }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Estimated market value</div>
                </div>
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $property->formattedPrice() }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Your asking price</div>
                </div>
            </div>
            @endif

            @if($priceHistory->isNotEmpty())
            <div class="pt-3 space-y-1.5" style="{{ !empty($marketPosition) ? 'border-top: 1px solid var(--border);' : '' }}">
                @foreach($priceHistory as $ph)
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <span style="color: var(--text-secondary);">{{ $ph->human_summary }}</span>
                        <span style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($ph->created_at)->format('d M Y') }}</span>
                    </div>
                @endforeach
            </div>
            @endif
        </section>
        @endif

        {{-- Comparable listings --}}
        @if($comparables->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Similar properties in your area</h2>
            <div class="divide-y" style="border-color: var(--border);">
                @foreach($comparables as $comp)
                    <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span style="color: var(--text-secondary);">{{ $comp['title'] }} <span class="text-xs" style="color: var(--text-muted);">{{ $comp['suburb'] }}</span></span>
                        <span class="font-semibold flex-shrink-0" style="color: var(--brand-default);">R {{ number_format($comp['price'] ?? 0) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Listing status --}}
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Listing status</h2>
            <div class="flex flex-wrap gap-2">
                <span class="ds-badge {{ $published ? 'ds-badge-success' : 'ds-badge-warning' }}">
                    Listing: {{ $published ? 'Active' : 'Unpublished' }}
                </span>
                <span class="ds-badge {{ $mandateExp ? 'ds-badge-warning' : 'ds-badge-success' }}">
                    Mandate: {{ $mandateExp ? 'Review needed' : 'Active' }}
                </span>
            </div>
        </section>

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
        // Views & enquiries chart. 2026-08-25 — was previously its own
        // verbatim copy of the Chart.js config because this page loaded no
        // JS bundle at all; now that the Vite tags above load the real app.js
        // (which imports nexus-charts.js), this calls the SAME
        // window.NexusCharts.portalEngagement() the internal Intelligence
        // tab calls — genuine reuse, not a parallel copy. Only the label
        // wording differs (opts.leadsLabel/leadsAxisLabel), for the seller
        // framing Johan asked for; the chart config itself is untouched.
        // Toggle stays plain JS (not Alpine) — no second widget on this
        // page needs to stay in sync with the range, unlike the internal
        // tab's Alpine store.
        //
        // The Vite tags above are type="module" and deferred — they only run
        // after the document has finished parsing, which is AFTER this
        // classic inline script would otherwise run. Wrapping in
        // DOMContentLoaded (which itself only fires after every deferred/
        // module script has already executed) guarantees window.NexusCharts
        // exists before this reads it, instead of racing it.
        document.addEventListener('DOMContentLoaded', function () {
            var section = document.getElementById('engagement-section');
            if (!section) return;
            var series = JSON.parse(section.getAttribute('data-engagement-series') || '[]');
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

            function apply() {
                if (!window.NexusCharts) return;
                var f = filtered();
                var labels = f.map(function (r) { return fmt(r.date); });
                var views = f.map(function (r) { return r.views; });
                var leads = f.map(function (r) { return r.leads; });

                if (!chart) {
                    chart = window.NexusCharts.portalEngagement(canvas, labels, views, leads, {
                        leadsLabel: 'Enquiries',
                        leadsAxisLabel: 'Enquiries',
                    });
                } else {
                    chart.data.labels = labels;
                    chart.data.datasets[0].data = views;
                    chart.data.datasets[1].data = leads;
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
