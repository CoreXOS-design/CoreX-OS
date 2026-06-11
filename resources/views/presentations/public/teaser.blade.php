{{--
    Phase 5 — teaser presentation view.

    Lead-capture gate sits BELOW the visible teaser content. Visible content
    is determined by the agency's teaser_default_show_* toggles, passed in
    as $teaserVisibility. Locked sections render as visible placeholders
    with a lock icon + "unlock with your details" CTA that scrolls to the
    form — the seller should clearly SEE what they're missing.
--}}
@php
    use App\Services\Presentations\AnalysisDataService;

    $analysisData = $version && $version->computed_json
        ? (is_string($version->computed_json) ? json_decode($version->computed_json, true) : $version->computed_json)
        : (new AnalysisDataService())->compile($presentation);
    if (!is_array($analysisData)) $analysisData = [];

    $property        = $presentation->property;
    $propertyAddress = $presentation->property_address ?: ($property?->address ?? 'Property');
    $suburb          = $presentation->suburb ?? '';
    $agency          = \App\Models\Agency::find($link->agency_id);
    $agencyName      = $agency?->name ?? 'Your agent';
    $agentName       = $link->creator?->name ?? 'your agent';
    $agentFirstName  = explode(' ', (string) $agentName)[0] ?? 'your agent';

    // Suburb stats (Section 2 of the full presentation).
    $fields = $presentation->fields->keyBy('field_key');
    $suburbYear  = $fields->get('suburb.latest_year')?->final_value;
    $suburbMed   = $fields->get('suburb.latest_median_price')?->final_value;
    $suburbSales = $fields->get('suburb.latest_sales_count')?->final_value;

    // CMA range (asking price band).
    $cma = $analysisData['cma_valuation'] ?? [];
    $cmaLower = $cma['cma_lower'] ?? null;
    $cmaUpper = $cma['cma_upper'] ?? null;

    // Active competition count (number only — addresses + prices locked).
    // Build 8 — read the canonical scored set from competitor_stock so
    // the teaser shows the same count as the PDF tile and full
    // presentation. Falls back to legacy active_competition.count for
    // pre-Build-8 snapshots that don't carry the canonical block.
    $active = $analysisData['active_competition'] ?? [];
    $compStock = $analysisData['competitor_stock'] ?? [];
    $activeCount = isset($compStock['competing_count'])
        ? (int) $compStock['competing_count']
        : (int) ($active['count'] ?? 0);

    // Holding cost summary (if visible). Build 8 — read the canonical
    // monthly_total from $analysisData['holding_cost'] (sourced via
    // HoldingCostEstimator::monthlyTotalFor inside compileHoldingCost),
    // so the teaser shows the same total as the PDF tile and the AI
    // summary. Pre-fix the teaser hardcoded five columns (no bond, no
    // garden/pool/security), under-counting freehold properties.
    $holdingMonthlyTotal = (int) ($analysisData['holding_cost']['monthly_total'] ?? 0);
    $holdingCost = $holdingMonthlyTotal > 0 ? $holdingMonthlyTotal : null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $propertyAddress }} — Market Analysis</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus+jakarta+sans:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f6fb; --surface: #ffffff; --border: #e2e8f0;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --brand: #00d4aa; --brand-dark: #00b594; --brand-text: #0f172a;
            --navy-1: #0f172a; --navy-2: #1e293b;
            --lock-bg: #f1f5f9; --lock-border: #cbd5e1;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--bg); color: var(--text-primary); -webkit-font-smoothing: antialiased; }
        a { color: var(--brand-dark); text-decoration: none; }

        header.hero {
            background: linear-gradient(135deg, var(--navy-1) 0%, var(--navy-2) 100%);
            color: #fff; padding: 42px 20px; text-align: center;
        }
        header.hero h1 { margin: 0; font-size: 1.5rem; font-weight: 700; letter-spacing: -0.01em; }
        header.hero .sub { opacity: .8; margin-top: 6px; font-size: 0.875rem; }
        header.hero .agency-name {
            display: inline-block; margin-top: 12px;
            font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
            color: #5eead4; padding: 4px 12px; background: rgba(0, 212, 170, 0.12);
            border: 1px solid rgba(0, 212, 170, 0.4); border-radius: 999px;
        }

        .wrap {
            max-width: 1100px; margin: 0 auto; padding: 28px 20px 80px;
            display: grid; grid-template-columns: 1fr 360px; gap: 24px;
            align-items: start;
        }
        @media (max-width: 880px) {
            .wrap { grid-template-columns: 1fr; }
            .sticky-form { position: static !important; }
        }

        section.block {
            background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
            padding: 22px 24px; margin-bottom: 16px;
        }
        section.block h2 { font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--brand-dark); margin: 0 0 14px 0; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .kpi { padding: 12px 14px; background: #f8fafc; border-radius: 8px; }
        .kpi .label { font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
        .kpi .value { font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-top: 4px; }

        .locked {
            position: relative; background: var(--lock-bg); border: 1px dashed var(--lock-border);
            border-radius: 10px; padding: 28px 24px; margin-bottom: 16px;
        }
        .locked .lock-row {
            display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
        }
        .locked .lock-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; background: #fff; color: var(--text-secondary);
            border: 1px solid var(--lock-border); border-radius: 50%; flex-shrink: 0;
        }
        .locked .lock-title { font-size: 0.875rem; font-weight: 700; color: var(--text-primary); }
        .locked .lock-blurb { font-size: 0.8125rem; color: var(--text-secondary); line-height: 1.5; margin: 6px 0 12px 0; }
        .locked .lock-cta {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px;
            background: var(--brand-dark); color: #fff; border: 0; border-radius: 6px;
            font-size: 0.8125rem; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        .locked .lock-cta:hover { background: #009e80; }

        /* Lead capture form */
        .sticky-form { position: sticky; top: 18px; }
        .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
            padding: 24px; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06); }
        .form-card h3 { margin: 0 0 6px 0; font-size: 1.0625rem; font-weight: 700; color: var(--text-primary); }
        .form-card .form-sub { font-size: 0.8125rem; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.5; }
        .form-card label.field-label { display: block; font-size: 0.6875rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary); margin-bottom: 4px; }
        .form-card .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .form-card input[type=text], .form-card input[type=email], .form-card input[type=tel], .form-card textarea {
            width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
            font-size: 0.875rem; font-family: inherit; margin-bottom: 12px;
        }
        .form-card input:focus, .form-card textarea:focus { outline: 2px solid var(--brand); outline-offset: 1px; border-color: var(--brand-dark); }
        .form-card textarea { min-height: 64px; resize: vertical; }
        .form-card .radio-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .form-card .radio-row { display: flex; align-items: center; gap: 6px; font-size: 0.8125rem; cursor: pointer; }
        .form-card .checkbox-row { display: flex; align-items: flex-start; gap: 8px; font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 14px; line-height: 1.5; }
        .form-card button[type=submit] {
            width: 100%; padding: 12px 16px; background: var(--brand-dark); color: #fff;
            border: 0; border-radius: 6px; font-size: 0.9375rem; font-weight: 700; cursor: pointer;
            font-family: inherit;
        }
        .form-card button[type=submit]:hover:not(:disabled) { background: #009e80; }
        .form-card button[type=submit]:disabled { opacity: 0.6; cursor: wait; }
        .form-card .privacy { font-size: 0.6875rem; color: var(--text-muted); margin-top: 10px; line-height: 1.4; text-align: center; }
        .form-card .form-error { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b;
            padding: 8px 10px; border-radius: 6px; font-size: 0.8125rem; margin-bottom: 12px; display: none; }

        /* Honeypot — visually hidden, kept from form submissions. */
        .hp-field { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

        footer { text-align: center; padding: 20px 16px; color: var(--text-muted); font-size: 0.75rem; }
    </style>
</head>
<body>

<header class="hero">
    <h1>Market Analysis for {{ $propertyAddress }}</h1>
    <div class="sub">{{ $suburb }}</div>
    <span class="agency-name">Prepared by {{ $agencyName }} · {{ $agentFirstName }}</span>
</header>

<div class="wrap">
    {{-- Phase 7 — data-may-be-dated banner (aging | stale) --}}
    @php
        $sState = $stalenessState ?? null;
        $sBanner = $stalenessBanner ?? null;
        $sCls = $sState && $sState->showsBanner()
            ? ($sState === \App\Support\Presentations\StalenessState::Stale ? 'stale' : 'aging')
            : null;
    @endphp
    @if($sCls && $sBanner)
        <div style="display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border-radius:8px; margin-bottom:16px; font-size:0.875rem; line-height:1.45;
            background:{{ $sCls === 'stale' ? '#fee2e2' : '#fef3c7' }};
            border:1px solid {{ $sCls === 'stale' ? '#fecaca' : '#fde68a' }};
            color:{{ $sCls === 'stale' ? '#991b1b' : '#92400e' }};">
            <span style="flex-shrink:0; font-size:1.1rem; line-height:1; padding-top:1px;">{!! $sCls === 'stale' ? '&#9888;' : '&#8987;' !!}</span>
            <div style="flex:1;">
                <strong style="display:block; margin-bottom:2px;">{{ $sState->label() }}</strong>
                {{ $sBanner }}
                <div>
                    <a href="{{ route('presentation.public.refresh-form', $link->token) }}"
                       style="display:inline-block; margin-top:6px; padding:6px 12px; background:{{ $sCls === 'stale' ? '#991b1b' : '#92400e' }}; color:#fff; border-radius:5px; font-weight:600; font-size:0.8125rem; text-decoration:none;">
                        Request refreshed presentation
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Visible teaser content (gated by agency toggles) ────────────── --}}
    <div>

        {{-- Quick area context (always shown — it's the hook) --}}
        <section class="block" data-section-id="area-summary">
            <h2>Your Area at a Glance</h2>
            <p style="font-size: 0.9375rem; color: var(--text-secondary); line-height: 1.6; margin: 0 0 14px 0;">
                We've put together a current market analysis for <strong>{{ $propertyAddress }}</strong>.
                Below is a quick view of what's happening in {{ $suburb }}.
                The full report — including comparable sales, exact pricing recommendations, and projected timing — unlocks when you share your details.
            </p>
        </section>

        {{-- Suburb stats (toggle: teaser_default_show_suburb_stats) --}}
        @if($teaserVisibility['suburb_stats'] && ($suburbMed || $suburbSales))
        <section class="block" data-section-id="suburb-stats">
            <h2>{{ $suburb }} — Recent Activity</h2>
            <div class="kpi-grid">
                @if($suburbMed)
                <div class="kpi">
                    <div class="label">Median sale price{{ $suburbYear ? ' (' . $suburbYear . ')' : '' }}</div>
                    <div class="value">R {{ number_format((int) $suburbMed, 0, '.', ' ') }}</div>
                </div>
                @endif
                @if($suburbSales)
                <div class="kpi">
                    <div class="label">Sales in the year</div>
                    <div class="value">{{ $suburbSales }}</div>
                </div>
                @endif
                @if($activeCount > 0)
                <div class="kpi">
                    <div class="label">Currently for sale</div>
                    <div class="value">{{ $activeCount }} competing</div>
                </div>
                @endif
            </div>
        </section>
        @else
        <div class="locked" data-section-id="suburb-stats-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">{{ $suburb }} sales activity</div>
            </div>
            <div class="lock-blurb">Median price, sales volume, and competing listings in your area.</div>
            <a class="lock-cta" href="#lead-form">Get my full report →</a>
        </div>
        @endif

        {{-- Asking range (toggle: teaser_default_show_asking_range) --}}
        @if($teaserVisibility['asking_range'] && $cmaLower && $cmaUpper)
        <section class="block" data-section-id="asking-range">
            <h2>Suggested Asking Range for Your Address</h2>
            <div class="kpi-grid">
                <div class="kpi">
                    <div class="label">Lower</div>
                    <div class="value">R {{ number_format((int) $cmaLower, 0, '.', ' ') }}</div>
                </div>
                <div class="kpi">
                    <div class="label">Upper</div>
                    <div class="value">R {{ number_format((int) $cmaUpper, 0, '.', ' ') }}</div>
                </div>
            </div>
            <p style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 12px;">
                This is a high-level range. The exact recommendation (which depends on your finishes, timing, and the current competitive set) is in your full report.
            </p>
        </section>
        @else
        <div class="locked" data-section-id="asking-range-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">Suggested asking range</div>
            </div>
            <div class="lock-blurb">A specific lower / middle / upper recommendation built from local comparable sales.</div>
            <a class="lock-cta" href="#lead-form">Get my full report →</a>
        </div>
        @endif

        {{-- Phase 3 — AI Market Summary stays locked in teaser. --}}
        <div class="locked" data-section-id="ai-summary-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">AI Market Summary</div>
            </div>
            <div class="lock-blurb">
                A data-grounded narrative analysis of your property's market position, what the numbers mean for your timing, and the pricing conversation your agent recommends.
            </div>
            <a class="lock-cta" href="#lead-form">Unlock to see →</a>
        </div>

        {{-- Locked: comparable sales --}}
        <div class="locked" data-section-id="comparables-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">Comparable Sales Analysis</div>
            </div>
            <div class="lock-blurb">
                The exact properties that sold near you in the last 12 months — addresses, prices, sale dates, and how they compare to yours.
            </div>
            <a class="lock-cta" href="#lead-form">Unlock to see →</a>
        </div>

        {{-- Locked: pricing strategy --}}
        <div class="locked" data-section-id="pricing-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">Pricing Strategy</div>
            </div>
            <div class="lock-blurb">
                Scenario analysis: net proceeds at three different asking prices, expected time on market for each, and the recommended position.
            </div>
            <a class="lock-cta" href="#lead-form">Unlock to see →</a>
        </div>

        {{-- Locked OR visible: holding cost --}}
        @if($teaserVisibility['holding_cost_summary'] && $holdingCost)
        <section class="block" data-section-id="holding-cost">
            <h2>The Cost of Waiting</h2>
            <p style="font-size: 0.9375rem; color: var(--text-secondary); line-height: 1.6; margin: 0;">
                Carrying this property costs roughly
                <strong style="color: var(--text-primary);">R {{ number_format($holdingCost, 0, '.', ' ') }}</strong>
                per month while it sits unsold (rates, levies, insurance, opportunity cost on your equity).
            </p>
        </section>
        @else
        <div class="locked" data-section-id="holding-cost-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">Holding Cost Analysis</div>
            </div>
            <div class="lock-blurb">
                What it costs you to hold this property each month it's on the market — and what that means for pricing it sharply.
            </div>
            <a class="lock-cta" href="#lead-form">Unlock to see →</a>
        </div>
        @endif

        {{-- Locked: market position --}}
        @if(!$teaserVisibility['market_position'])
        <div class="locked" data-section-id="market-position-locked">
            <div class="lock-row">
                <div class="lock-icon">🔒</div>
                <div class="lock-title">Where Your Property Sits in the Market</div>
            </div>
            <div class="lock-blurb">
                Your home's price percentile vs. the competition — are you priced for speed or for top dollar?
            </div>
            <a class="lock-cta" href="#lead-form">Unlock to see →</a>
        </div>
        @endif

    </div>

    {{-- ── Lead capture form (sticky on desktop) ──────────────────────── --}}
    <aside class="sticky-form" id="lead-form">
        <div class="form-card">
            <h3>Get your complete property analysis</h3>
            <div class="form-sub">Free, no obligation. We'll email your full report and one of our agents will be in touch.</div>

            <div id="lead-form-error" class="form-error"></div>

            <form id="capture-form" novalidate>
                @csrf

                <div class="field-row">
                    <div>
                        <label class="field-label" for="first_name">First name *</label>
                        <input type="text" id="first_name" name="first_name" required maxlength="100">
                    </div>
                    <div>
                        <label class="field-label" for="last_name">Last name *</label>
                        <input type="text" id="last_name" name="last_name" required maxlength="100">
                    </div>
                </div>

                <label class="field-label" for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="200" placeholder="you@example.com">

                <label class="field-label" for="phone">Mobile</label>
                <input type="tel" id="phone" name="phone" maxlength="30" placeholder="082 123 4567">
                <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:-8px;margin-bottom:12px;">Provide email or mobile (one is required).</div>

                <label class="field-label">Are you the owner?</label>
                <div class="radio-group">
                    <label class="radio-row"><input type="radio" name="relationship" value="owner" required> Yes, I own this property</label>
                    <label class="radio-row"><input type="radio" name="relationship" value="considering_selling"> Thinking of selling soon</label>
                    <label class="radio-row"><input type="radio" name="relationship" value="agent"> I'm in real estate</label>
                    <label class="radio-row"><input type="radio" name="relationship" value="researcher"> Researching the area</label>
                    <label class="radio-row"><input type="radio" name="relationship" value="other"> Other</label>
                </div>

                <label class="field-label">When might you sell?</label>
                <div class="radio-group">
                    <label class="radio-row"><input type="radio" name="intent" value="sell_now" required> In the next 3 months</label>
                    <label class="radio-row"><input type="radio" name="intent" value="sell_soon"> Within a year</label>
                    <label class="radio-row"><input type="radio" name="intent" value="just_curious"> Just researching</label>
                    <label class="radio-row"><input type="radio" name="intent" value="other"> Other</label>
                </div>

                <label class="field-label" for="notes">Anything else? (optional)</label>
                <textarea id="notes" name="notes" maxlength="2000" placeholder="Special requirements, timeline, questions..."></textarea>

                {{-- Phase 9a hardening — POPIA-aligned: opt-in is now an
                     active choice, no longer pre-ticked. The "By submitting…"
                     note above the button captures lawful basis for the
                     property-specific contact; this checkbox is for ongoing
                     marketing-channel consent only. --}}
                <label class="checkbox-row">
                    <input type="checkbox" name="consent_marketing" value="1">
                    <span>I'd also like to receive future market updates from this agency.</span>
                </label>

                {{-- Honeypot — bots fill, humans don't see. --}}
                <div class="hp-field" aria-hidden="true">
                    <label>Company name (leave blank)</label>
                    <input type="text" name="company_name" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit">Unlock my full report →</button>

                <div class="privacy">
                    Your details stay private and are only used to contact you about this property.
                </div>
            </form>
        </div>
    </aside>
</div>

<footer>
    Shared by {{ $agentName }} · {{ $agencyName }}
</footer>

<script>
(function () {
    'use strict';
    const form = document.getElementById('capture-form');
    const errorBox = document.getElementById('lead-form-error');
    const submitBtn = form.querySelector('button[type=submit]');
    const CAPTURE_URL = @json(route('presentation.public.capture-lead', $link->token));
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        errorBox.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending…';

        const data = new FormData(form);

        try {
            const resp = await fetch(CAPTURE_URL, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: data,
                credentials: 'same-origin',
            });
            const json = await resp.json().catch(() => ({}));
            if (resp.ok && json.ok) {
                // Reload — server-side session is now marked, page renders in full mode.
                window.location.href = json.redirect || window.location.href;
                return;
            }
            const msg = json.error
                || (json.errors ? Object.values(json.errors).flat()[0] : null)
                || 'Could not submit — please check your details and try again.';
            showError(msg);
        } catch (err) {
            showError('Network error — please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Unlock my full report →';
        }
    });
})();
</script>

@include('public.partials.privacy-footer')
</body>
</html>
