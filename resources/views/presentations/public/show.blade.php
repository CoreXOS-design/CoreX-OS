{{--
    Phase 4 — public snapshot view.

    Standalone page (no app shell). Renders the locked PresentationVersion's
    data + a tracking-beacon JS block at the end of <body>.

    Section markers use data-section-id so the IntersectionObserver beacon
    can record which sections the seller actually scrolled into view.
--}}
@php
    use App\Services\Presentations\AnalysisDataService;
    $analysisData = $version && $version->computed_json
        ? (is_string($version->computed_json) ? json_decode($version->computed_json, true) : $version->computed_json)
        : (new AnalysisDataService())->compile($presentation);
    if (!is_array($analysisData)) $analysisData = [];

    $property = $presentation->property;
    $propertyAddress = $presentation->property_address ?: ($property?->address ?? 'Property');
    $suburb = $presentation->suburb ?? '';
    $askingPrice = $presentation->asking_price_inc;
    $isTeaser = $link->mode === 'teaser';

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

    @if($isTeaser)
        <div class="teaser-note">
            You're looking at a short preview. Reply to the agent to receive the full pack.
        </div>
    @endif

    {{-- ── Section 1 — Executive Summary ───────────────────────────────── --}}
    <section class="block" data-section-id="exec-summary">
        <h2>1 · Executive Summary</h2>
        <div class="kpi-grid">
            @if($askingPrice)
            <div class="kpi">
                <div class="label">Asking price</div>
                <div class="value">R {{ number_format((int) $askingPrice, 0, '.', ' ') }}</div>
            </div>
            @endif
            @if($cmaMid)
            <div class="kpi">
                <div class="label">CMA middle</div>
                <div class="value">R {{ number_format((int) $cmaMid, 0, '.', ' ') }}</div>
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
            @if($presentation->property_type)<tr><th>Type</th><td>{{ ucfirst($presentation->property_type) }}</td></tr>@endif
            @if($presentation->bedrooms)<tr><th>Bedrooms</th><td>{{ $presentation->bedrooms }}</td></tr>@endif
            @if($presentation->bathrooms)<tr><th>Bathrooms</th><td>{{ $presentation->bathrooms }}</td></tr>@endif
            @if($presentation->floor_area_m2)<tr><th>Floor area</th><td>{{ $presentation->floor_area_m2 }} m²</td></tr>@endif
            @if($presentation->erf_size_m2)<tr><th>Erf size</th><td>{{ $presentation->erf_size_m2 }} m²</td></tr>@endif
        </table>
    </section>

    @if(!$isTeaser)
        {{-- ── Section 3 — Recent Sales ──────────────────────────────────── --}}
        @if(!empty($vicinityRows))
        <section class="block" data-section-id="recent-sales">
            <h2>3 · Recent Sales in the Vicinity</h2>
            <table>
                <thead><tr><th>Address</th><th>Sale date</th><th class="num">Sale price</th><th class="num">m²</th></tr></thead>
                <tbody>
                @foreach(array_slice($vicinityRows, 0, 10) as $row)
                    <tr>
                        <td>{{ $row['address'] ?? '—' }}</td>
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
        @if($activeCount > 0 && !empty($active['rows']))
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

        {{-- ── Section 5 — Stock Absorption ──────────────────────────────── --}}
        @if(!empty($stock['has_data']))
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
