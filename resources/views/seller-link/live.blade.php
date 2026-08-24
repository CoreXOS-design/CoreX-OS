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
    <script src="https://cdn.tailwindcss.com"></script>
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

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-4" style="border-top: 1px solid var(--border);">
                <div class="text-center sm:text-left">
                    <div class="text-lg font-bold" style="color: var(--text-primary);">{{ $daysListed !== null ? $daysListed : '—' }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Days listed</div>
                </div>
                <div class="text-center sm:text-left">
                    @if($hasPortalData)
                        <div class="text-lg font-bold" style="color: var(--text-primary);">{{ number_format($portalPerformance['views']) }}</div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property24 views (30d)</div>
                    @else
                        <div class="text-lg font-bold" style="color: var(--text-muted);">—</div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Not yet reporting</div>
                    @endif
                </div>
                <div class="text-center sm:text-left">
                    @if($hasPortalData)
                        <div class="text-lg font-bold" style="color: var(--text-primary);">{{ number_format($portalPerformance['enquiries']) }}</div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Enquiries (30d)</div>
                    @else
                        <div class="text-lg font-bold" style="color: var(--text-muted);">—</div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Not yet reporting</div>
                    @endif
                </div>
            </div>
        </section>

        {{-- SECTION 2 — What are they saying? (viewing feedback, always visible, honest at zero) --}}
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">What are viewers saying?</h2>
            <p class="text-xs mb-4" style="color: var(--text-muted);">{{ $feedbackRollup['total_viewings'] ?? 0 }} viewing{{ ($feedbackRollup['total_viewings'] ?? 0) === 1 ? '' : 's' }} recorded so far.</p>

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
                    No viewing feedback yet. As soon as a viewing is held, your agent's notes and the buyer's feedback will appear here.
                </p>
            @endif
        </section>

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

        {{-- SECTION 4 — Market position (price/value, with price-change strip folded in when present) --}}
        @if(!empty($marketPosition) || $priceHistory->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Where your price sits</h2>

            @if(!empty($marketPosition))
            <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                    <div class="text-lg font-bold" style="color: var(--brand-default);">R {{ number_format($marketPosition['recommended_price'] ?? 0) }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Estimated market value</div>
                </div>
                <div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">R {{ number_format($marketPosition['area_avg_price'] ?? 0) }}</div>
                    <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Area average</div>
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
</body>
</html>
