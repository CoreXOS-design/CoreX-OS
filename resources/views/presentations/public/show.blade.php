{{--
    Phase 4 — public snapshot view.

    Standalone page (no app shell). Renders the locked PresentationVersion's
    data + a tracking-beacon JS block at the end of <body>.

    Section markers use data-section-id so the IntersectionObserver beacon
    can record which sections the seller actually scrolled into view.
--}}
@php
    use App\Services\Presentations\AnalysisDataService;

    // Build 5 — data source priority:
    //   1. version.snapshot_payload  (Build 5+: frozen at publish)
    //   2. version.computed_json     (legacy pre-Build-5 snapshot)
    //   3. live AnalysisDataService::compile() (fallback ONLY — surfaces a
    //      banner so it's never silently the live path)
    $snapshotPayload = $version?->snapshot_payload;
    $usedLiveFallback = false;
    if (is_array($snapshotPayload) && !empty($snapshotPayload)) {
        $analysisData = $snapshotPayload;
    } elseif ($version && $version->computed_json) {
        $analysisData = is_string($version->computed_json)
            ? json_decode($version->computed_json, true)
            : $version->computed_json;
    } else {
        $usedLiveFallback = true;
        \Illuminate\Support\Facades\Log::warning('[PRES-WARN] public/show used live fallback — snapshot_payload missing', [
            'version_id' => $version?->id,
            'token'      => $link->token ?? null,
        ]);
        $analysisData = (new AnalysisDataService())->compile($presentation, $version);
    }
    if (!is_array($analysisData)) $analysisData = [];

    $property = $presentation->property;
    $propertyAddress = $presentation->property_address ?: ($property?->address ?? 'Property');
    $suburb = $presentation->suburb ?? '';
    $askingPrice = $presentation->asking_price_inc;
    $isTeaser = $link->mode === 'teaser';

    // Build 5 — freshness window calc. Snapshot age is in days from
    // snapshot_taken_at. Agency-configurable threshold (default 90).
    // Under 30d: no banner. 30-89d: small footnote. 90+ (or threshold):
    // full CTA panel. If snapshot_taken_at is null (legacy), treat as
    // not-stale — we don't have a publish anchor to age against.
    $snapshotAt    = $version?->snapshot_taken_at;
    $agency        = $version?->presentation?->agency_id
        ? \App\Models\Agency::find($version->presentation->agency_id)
        : ($link->agency_id ? \App\Models\Agency::find($link->agency_id) : null);
    $freshnessDays = (int) ($agency->presentations_freshness_days ?? 90);
    // Explicit timestamp delta — Carbon's diffInHours sign semantics shifted
    // across versions (see StalenessCalculator for the same workaround).
    $snapshotAgeDays = $snapshotAt
        ? max(0, (int) floor((now()->getTimestamp() - $snapshotAt->getTimestamp()) / 86400))
        : null;
    $showCta       = $snapshotAgeDays !== null && $snapshotAgeDays >= $freshnessDays;
    $showFootnote  = $snapshotAgeDays !== null && $snapshotAgeDays >= 30 && $snapshotAgeDays < $freshnessDays;

    // Build 5 — has the seller already requested a revision for this link?
    // RefreshRequestService dedups, but we want the button copy to swap
    // immediately so the seller doesn't tap twice in confusion.
    $revisionAlreadyRequested = $link
        ? \App\Models\PresentationRefreshRequest::where('snapshot_link_id', $link->id)
            ->whereIn('status', [
                \App\Models\PresentationRefreshRequest::STATUS_PENDING,
                \App\Models\PresentationRefreshRequest::STATUS_ACKNOWLEDGED,
            ])
            ->latest('created_at')
            ->first()
        : null;
    $agentName = $link?->creator?->name ?? 'your agent';

    $cma = $analysisData['cma_valuation'] ?? [];
    $cmaLower = $cma['cma_lower']  ?? null;
    $cmaMid   = $cma['cma_middle'] ?? null;
    $cmaUpper = $cma['cma_upper']  ?? null;

    $soldStats = $analysisData['comparable_sales']['vicinity'] ?? [];
    $vicinityRows = $soldStats['rows'] ?? [];

    $active = $analysisData['active_competition'] ?? [];
    $activeCount = $active['count'] ?? 0;

    $stock = $analysisData['stock_absorption'] ?? [];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Presentation — {{ $propertyAddress }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f6fb; --surface: #ffffff; --border: #e2e8f0;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --brand: #00d4aa; --brand-dark: #00b594;
            --ds-blue: #3b82f6; --ds-amber: #d97706; --ds-green: #16a34a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: 'Figtree', system-ui, sans-serif;
            background: var(--bg); color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--brand-dark); text-decoration: none; }
        .container { max-width: 880px; margin: 0 auto; padding: 32px 20px; }
        header.hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff; padding: 36px 20px; text-align: center;
        }
        header.hero h1 { margin: 0; font-size: 1.625rem; font-weight: 700; }
        header.hero .sub { opacity: .8; margin-top: 4px; font-size: 0.875rem; }
        header.hero .badge { display:inline-block;margin-top:10px;padding:4px 10px;background:rgba(0,212,170,.18);color:#5eead4;border:1px solid rgba(0,212,170,.4);border-radius:999px;font-size:0.6875rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase; }

        /* Phase 7 — staleness banner */
        .staleness-banner {
            display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border-radius:8px;
            margin-bottom:16px; font-size:0.875rem; line-height:1.45;
        }
        .staleness-banner.aging { background:#fef3c7; border:1px solid #fde68a; color:#92400e; }
        .staleness-banner.stale { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; }
        .staleness-banner .sb-icon { flex-shrink:0; font-size:1.1rem; line-height:1; padding-top:1px; }
        .staleness-banner .sb-body { flex:1; }
        .staleness-banner .sb-body strong { display:block; margin-bottom:2px; }
        .staleness-banner a.sb-cta {
            display:inline-block; margin-top:6px; padding:6px 12px; background:#0f172a; color:#fff;
            border-radius:5px; font-weight:600; font-size:0.8125rem;
        }
        .staleness-banner.aging a.sb-cta { background:#92400e; }
        .staleness-banner.stale a.sb-cta { background:#991b1b; }

        /* Build 5 — freshness CTA panel + footnote */
        .freshness-cta-panel { margin-bottom:16px; padding:18px 20px; background:linear-gradient(135deg, #ecfeff 0%, #f0fdfa 100%); border:1px solid #5eead4; border-radius:10px; }
        .freshness-cta-panel .fcp-headline { font-size:0.9375rem; font-weight:700; color:#0f172a; margin-bottom:6px; }
        .freshness-cta-panel .fcp-copy { font-size:0.875rem; color:var(--text-secondary); margin:0 0 12px 0; line-height:1.55; }
        .freshness-cta-panel .fcp-button { display:inline-block; padding:9px 18px; background:#0b2a4a; color:#fff; border:0; border-radius:6px; font-size:0.875rem; font-weight:600; cursor:pointer; transition:opacity 200ms; }
        .freshness-cta-panel .fcp-button:hover { opacity:0.9; }
        .freshness-cta-panel .fcp-button:disabled { opacity:0.5; cursor:not-allowed; }
        .freshness-cta-panel .fcp-already { font-size:0.8125rem; color:#0f766e; padding:8px 12px; background:#ccfbf1; border-radius:5px; }
        .freshness-cta-panel .fcp-confirm { font-size:0.8125rem; color:#065f46; padding:8px 12px; background:#d1fae5; border-radius:5px; margin-top:8px; }
        .freshness-cta-panel .fcp-confirm[hidden] { display:none; }
        .freshness-cta-panel .fcp-error { font-size:0.8125rem; color:#991b1b; padding:8px 12px; background:#fee2e2; border-radius:5px; margin-top:8px; }
        .freshness-cta-panel .fcp-error[hidden] { display:none; }
        .freshness-footnote { margin-bottom:14px; font-size:0.75rem; color:var(--text-muted); text-align:center; }
        .staleness-banner a.sb-cta:hover { opacity:0.9; }

        section.block {
            background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
            padding: 20px 22px; margin-bottom: 16px;
        }
        section.block h2 {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--brand-dark); margin: 0 0 12px 0;
        }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .kpi { padding: 10px 12px; background: #f8fafc; border-radius: 6px; }
        .kpi .label { font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
        .kpi .value { font-size: 1.0625rem; font-weight: 700; color: var(--text-primary); margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        th { text-align: left; padding: 8px 6px; font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; border-bottom: 1px solid var(--border); }
        td { padding: 8px 6px; border-bottom: 1px solid var(--border); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }

        footer {
            text-align: center; padding: 24px 20px; color: var(--text-muted); font-size: 0.75rem;
        }
        .teaser-note {
            background: #fffbeb; border: 1px solid #fcd34d; color: #92400e;
            padding: 10px 14px; border-radius: 6px; font-size: 0.8125rem; margin-bottom: 16px;
        }
    </style>
</head>
<body>

<header class="hero">
    <h1>{{ $propertyAddress }}</h1>
    <div class="sub">{{ $suburb }}</div>
    @if($isTeaser)
        <span class="badge">Preview</span>
    @endif
</header>

<div class="container">

    {{-- Build 5 — live-fallback warning. Only fires when snapshot_payload
         was missing and we had to recompile live. Defensive: shouldn't
         appear in normal operation post-Build-5. --}}
    @if($usedLiveFallback)
        <div class="staleness-banner stale">
            <span class="sb-icon">&#9888;</span>
            <div class="sb-body">
                <strong>Snapshot unavailable</strong>
                This presentation is showing current data because no published snapshot was found. Please ask the agent for a refreshed analysis.
            </div>
        </div>
    @endif

    {{-- Build 5 — snapshot-age CTA. Independent of Phase 7's banner: Phase 7
         tracks how long since the LINK was shared; this one tracks how long
         since the data was SNAPSHOTTED. Under 30 days: nothing. 30 to
         (freshness-1): grey footnote. Freshness window or older: full CTA. --}}
    @if($showCta)
        <div class="freshness-cta-panel" id="freshness-cta" data-token="{{ $link->token }}">
            <div class="fcp-body">
                <div class="fcp-headline">
                    This presentation was prepared {{ $snapshotAgeDays }} days ago.
                </div>
                <p class="fcp-copy">
                    Property market data may have changed since then.
                    Would you like {{ $agentName }} to send you a revised analysis?
                </p>
                <div id="freshness-cta-actions">
                    @if($revisionAlreadyRequested)
                        <div class="fcp-already" id="fcp-already-msg">
                            Already requested {{ $revisionAlreadyRequested->created_at->diffForHumans() }} —
                            {{ $agentName }} has been notified.
                        </div>
                    @else
                        <button type="button" id="btn-request-revision" class="fcp-button">
                            Yes, request revised analysis &rarr;
                        </button>
                    @endif
                    <div id="fcp-confirm" class="fcp-confirm" hidden>
                        Got it — {{ $agentName }} has been notified and will be in touch shortly.
                    </div>
                    <div id="fcp-error" class="fcp-error" hidden></div>
                </div>
            </div>
        </div>
    @elseif($showFootnote)
        <div class="freshness-footnote">
            Prepared {{ $snapshotAt->format('j F Y') }} · {{ $snapshotAgeDays }} days ago.
        </div>
    @endif

    {{-- Phase 7 — data-may-be-dated banner (aging | stale) --}}
    @php
        $sState = $stalenessState ?? null;
        $sBanner = $stalenessBanner ?? null;
        $sCls = $sState && $sState->showsBanner()
            ? ($sState === \App\Support\Presentations\StalenessState::Stale ? 'stale' : 'aging')
            : null;
    @endphp
    @if($sCls && $sBanner)
        <div class="staleness-banner {{ $sCls }}">
            <span class="sb-icon">{!! $sCls === 'stale' ? '&#9888;' : '&#8987;' !!}</span>
            <div class="sb-body">
                <strong>{{ $sState->label() }}</strong>
                {{ $sBanner }}
                <div>
                    <a class="sb-cta" href="{{ route('presentation.public.refresh-form', $link->token) }}">Request refreshed presentation</a>
                </div>
            </div>
        </div>
    @endif

    @if($isTeaser)
        <div class="teaser-note">
            You're looking at a short preview. Reply to the agent to receive the full pack.
        </div>
    @endif

    {{-- ── Section 1 — Executive Summary ───────────────────────────────── --}}
    <section class="block" data-section-id="exec-summary">
        <h2>1 · Executive Summary</h2>
        {{-- Phase 3 — AI-generated narrative lives on the version snapshot. --}}
        @if(!empty($version->ai_summary_text))
            <div style="font-size:0.9375rem;line-height:1.65;color:var(--text-primary);margin-bottom:16px;white-space:pre-wrap;">{{ $version->ai_summary_text }}</div>
        @endif
        <div class="kpi-grid">
            @if($askingPrice)
            <div class="kpi">
                <div class="label">Asking price</div>
                <div class="value">R {{ number_format((int) $askingPrice, 0, '.', ' ') }}</div>
            </div>
            @endif
            @if($cmaMid)
            <div class="kpi">
                <div class="label">CMA middle{!! !empty($cma['condition_applied']) ? ' <span style="color:#00b594;font-weight:600;">(adjusted)</span>' : '' !!}</div>
                <div class="value">R {{ number_format((int) $cmaMid, 0, '.', ' ') }}</div>
                @if(!empty($cma['condition_applied']))
                    {{-- Build 3 — defensibility footer on the public/seller view.
                         The seller sees the headline is condition-adjusted, not
                         opaque. Mirrors the PDF footnote. --}}
                    <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:4px;line-height:1.4;">
                        Reflects {{ $cma['condition_label'] ?? '' }} condition
                        ({{ ((float)$cma['condition_pct'] >= 0 ? '+' : '') . (float)$cma['condition_pct'] }}%).
                        Baseline: R {{ number_format((int) ($cma['cma_middle_baseline'] ?? 0), 0, '.', ' ') }}.
                    </div>
                @endif
            </div>
            @endif
            @if($cmaLower && $cmaUpper)
            <div class="kpi">
                <div class="label">CMA range</div>
                <div class="value" style="font-size:0.875rem;">R {{ number_format((int) $cmaLower, 0, '.', ' ') }} – R {{ number_format((int) $cmaUpper, 0, '.', ' ') }}</div>
            </div>
            @endif
            @if(!empty($soldStats['count']))
            <div class="kpi">
                <div class="label">Recent sales</div>
                <div class="value">{{ $soldStats['count'] }} in vicinity</div>
            </div>
            @endif
            @if($activeCount)
            <div class="kpi">
                <div class="label">Active listings</div>
                <div class="value">{{ $activeCount }} competing</div>
            </div>
            @endif
        </div>
    </section>

    {{-- ── Section 2 — Property Snapshot ───────────────────────────────── --}}
    <section class="block" data-section-id="property">
        <h2>2 · The Property</h2>
        <table>
            <tr><th>Address</th><td>{{ $propertyAddress }}</td></tr>
            <tr><th>Suburb</th><td>{{ $suburb }}</td></tr>
            @if($presentation->property_type)<tr><th>Type</th><td>{{ \Illuminate\Support\Str::humanType($presentation->property_type) }}</td></tr>@endif
            @if($presentation->bedrooms)<tr><th>Bedrooms</th><td>{{ $presentation->bedrooms }}</td></tr>@endif
            @if($presentation->bathrooms)<tr><th>Bathrooms</th><td>{{ $presentation->bathrooms }}</td></tr>@endif
            @if($presentation->floor_area_m2)<tr><th>Floor area</th><td>{{ $presentation->floor_area_m2 }} m²</td></tr>@endif
            @if($presentation->erf_size_m2)<tr><th>Erf size</th><td>{{ $presentation->erf_size_m2 }} m²</td></tr>@endif
        </table>
    </section>

    @if(!$isTeaser)
        {{-- Build 4 — section toggles. If no version (legacy share), default ON. --}}
        @php
            $secOn = fn(string $k) => !$version || $version->isSectionEnabled($k);
        @endphp
        {{-- ── Section 3 — Recent Sales ──────────────────────────────────── --}}
        @if($secOn('recent_sales') && !empty($vicinityRows))
        <section class="block" data-section-id="recent-sales">
            <h2>3 · Recent Sales in the Vicinity</h2>
            <table>
                <thead><tr><th>Address</th><th>Sale date</th><th class="num">Sale price</th><th class="num">m²</th></tr></thead>
                <tbody>
                @foreach(array_slice($vicinityRows, 0, 10) as $row)
                    <tr>
                        <td>
                            {{ $row['address'] ?? '—' }}
                            @if(!empty($row['hfc_sold']))
                                <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:rgba(0,181,148,0.12);color:#00b594;border-radius:99px;font-size:0.625rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;"
                                      title="Home Finders Coastal sold this property">
                                    HFC sold this
                                </span>
                            @endif
                        </td>
                        <td>{{ $row['sale_date'] ?? '—' }}</td>
                        <td class="num">{{ isset($row['sale_price']) ? 'R ' . number_format((int) $row['sale_price'], 0, '.', ' ') : '—' }}</td>
                        <td class="num">{{ $row['extent_m2'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
        @endif

        {{-- ── Section 4 — Active Competition ────────────────────────────── --}}
        @if($secOn('active_competition') && $activeCount > 0 && !empty($active['rows']))
        <section class="block" data-section-id="active-competition">
            <h2>4 · Active Competition</h2>
            <table>
                <thead><tr><th>Address</th><th class="num">List price</th><th class="num">Days on market</th></tr></thead>
                <tbody>
                @foreach(array_slice($active['rows'], 0, 8) as $row)
                    <tr>
                        <td>{{ $row['address'] ?? '—' }}</td>
                        <td class="num">{{ isset($row['list_price']) ? 'R ' . number_format((int) $row['list_price'], 0, '.', ' ') : '—' }}</td>
                        <td class="num">{{ $row['days_on_market'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
        @endif

        {{-- ── Section 5 — Stock Absorption (toggle: inflow_absorption) ──── --}}
        @if($secOn('inflow_absorption') && !empty($stock['has_data']))
        <section class="block" data-section-id="absorption">
            <h2>5 · Stock Absorption</h2>
            <div class="kpi-grid">
                <div class="kpi"><div class="label">Active</div><div class="value">{{ $stock['total_active_stock'] }}</div></div>
                <div class="kpi"><div class="label">Annual sales</div><div class="value">{{ $stock['annual_sales'] }}</div></div>
                <div class="kpi"><div class="label">Months of supply</div><div class="value">{{ number_format($stock['months_of_supply'] ?? 0, 1) }}</div></div>
                @if(isset($stock['absorption_label']))<div class="kpi"><div class="label">Trend</div><div class="value" style="font-size:0.875rem;">{{ $stock['absorption_label'] }}</div></div>@endif
            </div>
        </section>
        @endif
    @endif

    <footer>
        Shared by {{ $link->creator?->name ?? 'your agent' }} ·
        Prepared {{ optional($version?->created_at)->format('j F Y') }} ·
        <a href="{{ route('presentation.public.refresh-form', $link->token) }}">Request a refreshed version</a>
    </footer>
</div>

{{-- ── Build 5 — freshness CTA handler ─────────────────────────────── --}}
<script>
(function () {
    'use strict';
    const btn = document.getElementById('btn-request-revision');
    if (!btn) return;
    const REQUEST_URL = @json($showCta ? route('presentation.public.request-revision', $link->token) : '');
    const confirmEl   = document.getElementById('fcp-confirm');
    const errorEl     = document.getElementById('fcp-error');
    const actionsEl   = document.getElementById('freshness-cta-actions');

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        errorEl.hidden = true;
        try {
            const r = await fetch(REQUEST_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({}),
                credentials: 'same-origin',
            });
            const d = await r.json().catch(() => null);
            if (r.ok && d && d.ok) {
                btn.hidden = true;
                if (d.already_requested) {
                    // Replace the button with the "already requested" copy.
                    const already = document.createElement('div');
                    already.className = 'fcp-already';
                    already.textContent = 'Already requested ' + (d.requested_ago || 'recently') + ' — ' +
                        (d.agent_name || 'your agent') + ' has been notified.';
                    actionsEl.insertBefore(already, btn);
                } else {
                    confirmEl.hidden = false;
                }
            } else if (r.status === 429 && d && d.message) {
                errorEl.textContent = d.message;
                errorEl.hidden = false;
                btn.disabled = false;
            } else {
                errorEl.textContent = 'Could not send your request — please try again, or use the longer form.';
                errorEl.hidden = false;
                btn.disabled = false;
            }
        } catch (e) {
            errorEl.textContent = 'Network error. Please check your connection and try again.';
            errorEl.hidden = false;
            btn.disabled = false;
        }
    });
})();
</script>

{{-- ── Tracking beacon ─────────────────────────────────────────────────── --}}
<script>
(function () {
    'use strict';
    const TOKEN = @json($link->token);
    const TRACK_URL = @json(route('presentation.public.track', $link->token));
    const startedAt = Date.now();
    let maxScrollPct = 0;
    const seenSections = new Set();

    function clientFingerprint() {
        try {
            return [
                navigator.userAgent,
                navigator.language || '',
                screen.width + 'x' + screen.height + 'x' + (screen.colorDepth || ''),
                new Date().getTimezoneOffset(),
                navigator.hardwareConcurrency || '',
                navigator.platform || '',
            ].join('|');
        } catch (e) { return ''; }
    }
    const CLIENT_FP = clientFingerprint();

    function scrollPct() {
        const doc = document.documentElement;
        const visible = window.innerHeight + window.scrollY;
        const total   = Math.max(doc.scrollHeight, doc.offsetHeight, 1);
        return Math.min(100, Math.round((visible / total) * 100));
    }

    window.addEventListener('scroll', () => {
        const p = scrollPct();
        if (p > maxScrollPct) maxScrollPct = p;
    }, { passive: true });

    // IntersectionObserver — flag each section as "seen" when ≥30% in view.
    const sectionEls = document.querySelectorAll('[data-section-id]');
    if ('IntersectionObserver' in window && sectionEls.length) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.intersectionRatio >= 0.3) {
                    seenSections.add(e.target.dataset.sectionId);
                }
            });
        }, { threshold: [0.3] });
        sectionEls.forEach(el => io.observe(el));
    }

    function buildBody() {
        return {
            duration_seconds:   Math.floor((Date.now() - startedAt) / 1000),
            scroll_depth_pct:   maxScrollPct,
            sections_viewed:    Array.from(seenSections),
            client_fingerprint: CLIENT_FP,
        };
    }

    function postBeacon(useSendBeacon) {
        const body = JSON.stringify(buildBody());
        if (useSendBeacon && navigator.sendBeacon) {
            navigator.sendBeacon(TRACK_URL, new Blob([body], { type: 'application/json' }));
            return;
        }
        fetch(TRACK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: body, credentials: 'same-origin', keepalive: true,
        }).catch(() => {}); // beacon must never surface to user.
    }

    // Every 15s.
    setInterval(() => postBeacon(false), 15000);

    // On hide / unload — sendBeacon variant for reliability.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') postBeacon(true);
    });
    window.addEventListener('beforeunload', () => postBeacon(true));
})();
</script>

</body>
</html>
