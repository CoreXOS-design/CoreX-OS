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
    // SS identity — prefer the frozen complex+unit display address
    // ("Unit 17, Brock Manor, Margate") computed in compileSubjectProperty.
    // Falls back to the flat street address.
    $propertyAddress = ($analysisData['subject_property']['display_address'] ?? null)
        ?: ($presentation->property_address ?: ($property?->address ?? 'Property'));
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

    // Build 6 — hero image. Priority: property primary image → satellite
    // tile via OpenStreetMap when GPS exists → gradient fallback.
    $heroImageUrl = null;
    if ($property) {
        $gal = $property->gallery_images_json ?? [];
        $dawn = $property->dawn_images_json ?? [];
        $img = $gal[0] ?? ($dawn[0] ?? null);
        if (is_string($img) && $img !== '') {
            $heroImageUrl = $img;
        }
    }
    // Build 6 — agent details for the footer card.
    $agentUser = $link?->creator;
    $agentEmail = $agentUser?->email;
    $agentPhone = $agentUser?->cell ?? $agentUser?->phone;
    $agentDesignation = $agentUser?->designation ?? 'Property Practitioner';
    $agentFfc = $agentUser?->ffc_number ?? null;
    $agencyName = $agentUser?->agency?->name ?? null;
    // Build 6 — clean SA phone for WhatsApp deep link.
    $waPhone = $agentPhone ? preg_replace('/\D+/', '', $agentPhone) : null;
    if ($waPhone && str_starts_with($waPhone, '0')) {
        $waPhone = '27' . preg_replace('/^0+/', '', $waPhone);
    }
    $sellerName = $presentation->seller_name ?? null;

    $cma = $analysisData['cma_valuation'] ?? [];
    $cmaLower = $cma['cma_lower']  ?? null;
    $cmaMid   = $cma['cma_middle'] ?? null;
    $cmaUpper = $cma['cma_upper']  ?? null;

    $soldStats = $analysisData['comparable_sales']['vicinity'] ?? [];
    $vicinityRows = $soldStats['rows'] ?? [];

    // SS — sectional / same-complex sales as their own group. The agency
    // toggle is baked into the compiled groups (suppressed → folded into
    // vicinity), so the dedicated section shows purely on presence. Heading
    // uses the subject complex name when known.
    $complexStats   = $analysisData['comparable_sales']['complex'] ?? [];
    $complexRows    = $complexStats['rows'] ?? [];
    $subjectComplexName = $analysisData['subject_property']['complex_name'] ?? null;
    $showComplexSales = !empty($complexRows);

    // Aggregate market-activity (monthly bars chart) reflects ALL sold evidence
    // — vicinity + complex — so a sectional subject (whose comps live in the
    // complex group) still gets a populated activity chart, not an empty one.
    $marketActivityRows  = array_merge($vicinityRows, $complexRows);
    $marketActivityCount = ($soldStats['count'] ?? count($vicinityRows)) + ($complexStats['count'] ?? count($complexRows));

    $active = $analysisData['active_competition'] ?? [];
    // Build 8 — seller-facing competition count reads the canonical
    // competitor_stock (scored Active Competition curated by the agent
    // on the review screen). Legacy active_competition.count becomes
    // a fallback for pre-Build-8 snapshots that don't yet carry the
    // canonical block. $active['rows'] still drives the audit grid
    // below the headline.
    $compStock = $analysisData['competitor_stock'] ?? [];
    $competingCount = isset($compStock['competing_count'])
        ? (int) $compStock['competing_count']
        : (int) ($active['count'] ?? 0);
    $activeCount = $competingCount;  // back-compat — anywhere downstream still reading it
    $activeRowsForAudit = $active['rows'] ?? [];

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
        /* ── Build 6 visual upgrade. SSOT-compliant: tokens from
              .ai/specs/UI_DESIGN_SYSTEM.md §1 (Figtree, rounded-md 6px,
              navy + sky-blue brand defaults, --ds-* semantic colours).
              No serif accent. No 2-3px corners. No raw hex where a token
              fits — fallback hexes match the spec's :root defaults. ── */
        :root {
            /* §1.1 Background layers */
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f0f2f8;
            /* §1.2 Borders */
            --border: rgba(0,0,0,0.07);
            --border-hover: rgba(0,0,0,0.14);
            /* §1.3 Text */
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            /* §1.4 Brand — defaults from the spec; runtime per-agency
                  inject is absent on auth-less public pages so the
                  defaults are the real surface. */
            --brand-default: #0b2a4a;
            --brand-icon: #0ea5e9;
            --brand-button: #0ea5e9;
            /* §1.5 Semantic */
            --ds-green: #059669;
            --ds-amber: #f59e0b;
            --ds-crimson: #c41e3a;
            --ds-navy: #0b2a4a;
            /* CoreX teal — agency-internal token Phase 4 introduced */
            --hfc-teal: #00d4aa;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Figtree', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        a { color: var(--brand-button); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .container { max-width: 920px; margin: 0 auto; padding: 28px 20px; }

        /* ── HERO ── property image / map fallback, no centered banner */
        .hero-shell {
            position: relative;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            overflow: hidden;
        }
        .hero-image {
            width: 100%;
            aspect-ratio: 21/9;
            background: linear-gradient(135deg, var(--brand-default, #0b2a4a) 0%, color-mix(in srgb, var(--brand-default, #0b2a4a) 70%, var(--hfc-teal)) 100%);
            background-size: cover;
            background-position: center;
            display: block;
        }
        .hero-meta {
            max-width: 920px;
            margin: 0 auto;
            padding: 22px 20px 18px;
        }
        .hero-caption {
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .hero-title {
            font-size: 1.625rem;
            line-height: 1.2;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            letter-spacing: -0.01em;
        }
        .hero-prepared-for {
            margin-top: 8px;
            font-size: 0.8125rem;
            color: var(--text-secondary);
            font-style: italic;
        }
        @media (max-width: 768px) {
            .hero-image { aspect-ratio: 1/1; }
            .hero-title { font-size: 1.375rem; }
            .container { padding: 20px 16px; }
        }

        /* ── Notices (live-fallback / freshness / staleness / teaser) ── */
        .notice {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 6px;
            margin-bottom: 14px;
            font-size: 0.8125rem;
            line-height: 1.5;
        }
        .notice strong { display: block; margin-bottom: 2px; }
        .notice .nb-icon { flex-shrink: 0; font-size: 1.05rem; line-height: 1; }
        .notice-warning {
            background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
            color: var(--text-primary);
        }
        .notice-warning .nb-icon { color: var(--ds-amber); }
        .notice-danger {
            background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
            color: var(--text-primary);
        }
        .notice-danger .nb-icon { color: var(--ds-crimson); }
        .notice .sb-cta {
            display: inline-block;
            margin-top: 6px;
            padding: 6px 14px;
            background: var(--brand-default, #0b2a4a);
            color: #fff;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8125rem;
        }

        /* ── Freshness CTA panel — Build 5 surface ── */
        .freshness-cta-panel {
            margin-bottom: 16px;
            padding: 22px 24px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-left: 4px solid var(--hfc-teal);
            border-radius: 6px;
        }
        .fcp-headline { font-size: 0.9375rem; font-weight: 700; color: var(--text-primary); margin-bottom: 6px; }
        .fcp-copy { font-size: 0.8125rem; color: var(--text-secondary); margin: 0 0 14px 0; line-height: 1.55; }
        .fcp-button {
            display: inline-block;
            padding: 10px 20px;
            background: var(--brand-default, #0b2a4a);
            color: #fff;
            border: 0;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: opacity 200ms;
            min-height: 44px;
        }
        .fcp-button:hover { opacity: 0.9; }
        .fcp-button:disabled { opacity: 0.5; cursor: not-allowed; }
        .fcp-already, .fcp-confirm {
            font-size: 0.8125rem;
            padding: 10px 14px;
            border-radius: 6px;
        }
        .fcp-already {
            color: var(--ds-green);
            background: color-mix(in srgb, var(--ds-green) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-green) 25%, transparent);
        }
        .fcp-confirm {
            color: var(--ds-green);
            background: color-mix(in srgb, var(--ds-green) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-green) 25%, transparent);
            margin-top: 10px;
        }
        .fcp-confirm[hidden] { display: none; }
        .fcp-error {
            font-size: 0.8125rem;
            padding: 10px 14px;
            border-radius: 6px;
            color: var(--ds-crimson);
            background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);
            margin-top: 10px;
        }
        .fcp-error[hidden] { display: none; }
        .freshness-footnote {
            margin-bottom: 14px;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: center;
        }

        /* ── Beat divider — full-width section break with display title ── */
        .beat-divider {
            margin: 36px 0 24px;
            padding: 28px 0 20px;
            border-top: 1px solid var(--border);
        }
        .beat-eyebrow {
            font-size: 0.6875rem;
            color: var(--brand-button);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .beat-title {
            font-size: 1.625rem;
            font-weight: 700;
            line-height: 1.2;
            color: var(--text-primary);
            margin: 0 0 6px 0;
            letter-spacing: -0.01em;
        }
        .beat-sub {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin: 0;
        }
        @media (max-width: 768px) {
            .beat-title { font-size: 1.375rem; }
            .beat-divider { margin: 24px 0 18px; }
        }

        /* ── Block card ── */
        section.block {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 20px 22px;
            margin-bottom: 14px;
        }
        section.block h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 14px 0;
            letter-spacing: -0.01em;
        }
        section.block .block-eyebrow {
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            margin-bottom: 4px;
        }
        section.block .block-caption {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 12px;
            line-height: 1.5;
        }

        /* ── KPI / fact grid ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
        }
        .kpi {
            padding: 12px 14px;
            background: var(--surface-2);
            border-radius: 6px;
        }
        .kpi .label {
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .kpi .value {
            font-size: 1.0625rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-top: 4px;
            line-height: 1.2;
        }

        /* ── Two-column facts (property + location) ── */
        .facts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        @media (max-width: 768px) {
            .facts-grid { grid-template-columns: 1fr; gap: 16px; }
        }
        .facts-col h3 {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: var(--text-muted);
            margin: 0 0 10px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        .facts-row {
            display: grid;
            grid-template-columns: 110px 1fr;
            gap: 8px;
            padding: 6px 0;
            font-size: 0.8125rem;
        }
        .facts-row .lbl { color: var(--text-muted); font-weight: 500; }
        .facts-row .val { color: var(--text-primary); }
        .facts-row .val.missing { color: var(--text-muted); }

        /* ── Tables ── */
        table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        thead th { background: var(--surface-2); }
        th { text-align: left; padding: 9px 10px; font-size: 0.6875rem; color: var(--text-muted);
             text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        td { padding: 10px 10px; border-bottom: 1px solid var(--border); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }

        /* ── BUILD 6 — Holding cost callout ── */
        .holding-callout {
            text-align: center;
            padding: 28px 22px;
        }
        .holding-callout .hc-eyebrow {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        .holding-callout .hc-number {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.05;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .holding-callout .hc-sub {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-top: 8px;
        }
        .holding-callout .hc-disclosure {
            margin-top: 14px;
            font-size: 0.75rem;
            color: var(--brand-button);
            cursor: pointer;
            background: none;
            border: 0;
            font-family: inherit;
            text-decoration: underline;
        }
        .holding-breakdown {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            text-align: left;
        }
        .holding-breakdown[hidden] { display: none; }
        @media (max-width: 768px) {
            .holding-callout .hc-number { font-size: 1.75rem; }
        }

        /* ── BUILD 6 — CMA gauge ── */
        .cma-gauge-wrap { padding: 14px 8px 8px; }
        .cma-gauge {
            position: relative;
            height: 56px;
            margin: 0 12px;
        }
        .cma-gauge svg { width: 100%; height: 100%; display: block; overflow: visible; }
        .cma-gauge-labels {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-top: 6px;
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .cma-gauge-labels .lbl-mid { text-align: center; }
        .cma-gauge-labels .lbl-up  { text-align: right; }
        .cma-gauge-values {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-top: 2px;
            font-size: 0.875rem;
            color: var(--text-primary);
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .cma-gauge-values .v-mid { text-align: center; font-size: 1rem; }
        .cma-gauge-values .v-up  { text-align: right; }
        .cma-asking-row {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8125rem;
        }
        .cma-asking-row .ar-lbl { color: var(--text-muted); }
        .cma-asking-row .ar-val { color: var(--hfc-teal); font-weight: 700; }
        .cma-condition-line {
            margin-top: 10px;
            padding: 10px 14px;
            background: var(--surface-2);
            border-radius: 6px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* ── BUILD 6 — Active competition cards ── */
        .comp-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        @media (max-width: 768px) {
            .comp-grid { grid-template-columns: 1fr; }
        }
        .comp-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .comp-card .cc-photo {
            aspect-ratio: 16/10;
            background: linear-gradient(135deg, color-mix(in srgb, var(--brand-default, #0b2a4a) 80%, transparent), color-mix(in srgb, var(--hfc-teal) 25%, transparent));
            background-size: cover;
            background-position: center;
            position: relative;
        }
        .comp-card .cc-photo .cc-placeholder-addr {
            position: absolute; left: 12px; bottom: 8px; right: 12px;
            color: #fff;
            font-size: 0.75rem;
            font-weight: 600;
            text-shadow: 0 1px 4px rgba(0,0,0,0.4);
        }
        .comp-card .cc-body { padding: 12px 14px; }
        .comp-card .cc-price {
            font-size: 1.0625rem;
            font-weight: 700;
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
        }
        .comp-card .cc-addr {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .comp-card .cc-meta {
            margin-top: 8px;
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ── Suburb trend chart container ── */
        .chart-container {
            position: relative;
            width: 100%;
            height: 240px;
        }
        @media (max-width: 768px) {
            .chart-container { height: 200px; }
        }

        /* ── Footer / agent card ── */
        .agent-footer {
            margin-top: 32px;
            padding: 24px 22px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        .agent-footer .af-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 18px;
            align-items: center;
        }
        @media (max-width: 768px) {
            .agent-footer .af-grid { grid-template-columns: 1fr; }
        }
        .agent-footer .af-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        .agent-footer .af-agency {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }
        .agent-footer .af-ppra {
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-top: 6px;
        }
        .agent-footer .af-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .agent-footer .af-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 14px;
            border-radius: 6px;
            background: var(--surface-2);
            color: var(--text-primary);
            font-size: 0.8125rem;
            font-weight: 600;
            min-height: 44px;
            border: 1px solid var(--border);
            transition: background 200ms;
        }
        .agent-footer .af-action:hover { background: color-mix(in srgb, var(--brand-button) 12%, var(--surface-2)); text-decoration: none; }
        .agent-footer .af-action.is-primary {
            background: var(--brand-default, #0b2a4a);
            color: #fff;
            border-color: var(--brand-default, #0b2a4a);
        }
        .agent-footer .af-action.is-primary:hover { background: color-mix(in srgb, var(--brand-default, #0b2a4a) 88%, #fff); }
        .meta-strip {
            margin-top: 18px;
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
        }

        /* ── Teaser note ── */
        .teaser-note {
            background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
            color: var(--text-primary);
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 0.8125rem;
            margin-bottom: 14px;
        }

        /* ── @media print — strip interactive chrome, ensure page-break
              behaviour for the HTML-as-PDF flow used today ── */
        @media print {
            .fcp-button, .agent-footer .af-action, .holding-callout .hc-disclosure { display: none !important; }
            .freshness-cta-panel, .notice { box-shadow: none !important; }
            section.block, .agent-footer { page-break-inside: avoid; }
            .beat-divider { page-break-before: always; }
            body { background: #fff !important; }
            .hero-image { background-attachment: scroll !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

{{-- Build 6 — hero: property image first, gradient fallback otherwise. --}}
<header class="hero-shell" data-section-id="hero">
    <div class="hero-image"
         @if($heroImageUrl) style="background-image: url('{{ $heroImageUrl }}');" @endif
         role="img"
         aria-label="{{ $propertyAddress }}"></div>
    <div class="hero-meta">
        <div class="hero-caption">
            {{ $suburb ?: 'Market Analysis' }}{{ $presentation->property_type ? ' · ' . \Illuminate\Support\Str::humanType($presentation->property_type) : '' }}
        </div>
        <h1 class="hero-title">{{ $propertyAddress }}</h1>
        @if($sellerName)
            <div class="hero-prepared-for">Market analysis prepared for {{ $sellerName }}</div>
        @endif
    </div>
</header>

<div class="container">

    {{-- Build 5 — live-fallback warning. Only fires when snapshot_payload
         was missing and we had to recompile live. Defensive: shouldn't
         appear in normal operation post-Build-5. --}}
    @if($usedLiveFallback)
        <div class="notice notice-danger">
            <span class="nb-icon">&#9888;</span>
            <div>
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
        <div class="notice {{ $sCls === 'stale' ? 'notice-danger' : 'notice-warning' }}">
            <span class="nb-icon">{!! $sCls === 'stale' ? '&#9888;' : '&#8987;' !!}</span>
            <div>
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

    {{-- Build 6 — Subject Snapshot (two-column facts).
         Replaces the prior "1 · Executive Summary" KPI grid + "2 · The
         Property" table. The valuation KPIs move into Beat 3 below
         (CMA gauge); page-1 stays free of data per the build prompt. --}}
    <section class="block" data-section-id="subject-snapshot">
        <div class="block-eyebrow">Subject Snapshot</div>
        <h2>What we're working with</h2>
        @if(!empty($version->ai_summary_text))
            {{-- Phase 3 — AI-generated narrative lives on the version snapshot. --}}
            <div style="font-size:0.875rem;line-height:1.65;color:var(--text-primary);margin-bottom:16px;white-space:pre-wrap;">{{ $version->ai_summary_text }}</div>
        @endif

        <div class="facts-grid">
            <div class="facts-col">
                <h3>Property</h3>
                <div class="facts-row">
                    <span class="lbl">Type</span>
                    <span class="val {{ $presentation->property_type ? '' : 'missing' }}">{{ $presentation->property_type ? \Illuminate\Support\Str::humanType($presentation->property_type) : '—' }}</span>
                </div>
                <div class="facts-row">
                    <span class="lbl">Bedrooms</span>
                    <span class="val {{ $presentation->bedrooms ? '' : 'missing' }}">{{ $presentation->bedrooms ?: '—' }}</span>
                </div>
                <div class="facts-row">
                    <span class="lbl">Bathrooms</span>
                    <span class="val {{ $presentation->bathrooms ? '' : 'missing' }}">{{ $presentation->bathrooms ?: '—' }}</span>
                </div>
                <div class="facts-row">
                    <span class="lbl">Floor area</span>
                    <span class="val {{ $presentation->floor_area_m2 ? '' : 'missing' }}">{{ $presentation->floor_area_m2 ? number_format($presentation->floor_area_m2) . ' m²' : '—' }}</span>
                </div>
                <div class="facts-row">
                    <span class="lbl">Erf size</span>
                    <span class="val {{ $presentation->erf_size_m2 ? '' : 'missing' }}">{{ $presentation->erf_size_m2 ? number_format($presentation->erf_size_m2) . ' m²' : '—' }}</span>
                </div>
            </div>
            <div class="facts-col">
                <h3>Location</h3>
                <div class="facts-row">
                    <span class="lbl">Address</span>
                    <span class="val">{{ $propertyAddress }}</span>
                </div>
                <div class="facts-row">
                    <span class="lbl">Suburb</span>
                    <span class="val {{ $suburb ? '' : 'missing' }}">{{ $suburb ?: '—' }}</span>
                </div>
                @if(!empty($property->latitude) && !empty($property->longitude))
                    <div class="facts-row">
                        <span class="lbl">GPS</span>
                        <span class="val">{{ number_format($property->latitude, 5) }}, {{ number_format($property->longitude, 5) }}</span>
                    </div>
                @endif
                @php
                    $subjectFacts = $analysisData['subject_property'] ?? [];
                    $munVal = $subjectFacts['municipal_value'] ?? null;
                    $munYear = $subjectFacts['municipal_year'] ?? null;
                @endphp
                <div class="facts-row">
                    <span class="lbl">Municipal value{{ $munYear ? ' (' . e($munYear) . ')' : '' }}</span>
                    <span class="val {{ $munVal ? '' : 'missing' }}">{{ $munVal ? 'R ' . number_format($munVal, 0, '.', ' ') : '—' }}</span>
                </div>
            </div>
        </div>
    </section>

    @if(!$isTeaser)
        {{-- Build 4 — section toggles. If no version (legacy share), default ON. --}}
        @php
            $secOn = fn(string $k) => !$version || $version->isSectionEnabled($k);
        @endphp

        {{-- ─────────── BEAT 1 — "What's happening here" ─────────── --}}
        @if($secOn('market_overview') || $secOn('recent_sales'))
        <div class="beat-divider" data-section-id="beat-1">
            <div class="beat-eyebrow">Beat 1</div>
            <h2 class="beat-title">What's happening here</h2>
            <p class="beat-sub">The suburb's recent activity and the comparable sales that anchor the analysis.</p>
        </div>
        @endif

        {{-- Suburb market overview chart — Chart.js renders client-side.
             Build 6 — replaces the old kpi-grid for stock-absorption. --}}
        @if($secOn('market_overview') && !empty($marketActivityCount))
        <section class="block" data-section-id="market-overview">
            <div class="block-eyebrow">Market Overview</div>
            <h2>{{ $suburb ?: 'Suburb' }} — recent sales activity</h2>
            <div class="chart-container">
                <canvas id="suburb-trend-chart" aria-label="Recent sales over time"></canvas>
            </div>
            <p class="block-caption">
                Each bar shows the number of recorded sales per month in the vicinity. Sample of the {{ $marketActivityCount }} most recent.
            </p>
        </section>
        @endif

        {{-- Recent vicinity sales (kept as a table — best representation
             for tabular data; redesigned with new typography tokens). --}}
        @if($secOn('recent_sales') && !empty($vicinityRows))
        <section class="block" data-section-id="recent-sales">
            <div class="block-eyebrow">Comparable Sales</div>
            <h2>Recent sales in the vicinity</h2>
            <table>
                <thead><tr><th>Address</th><th>Sale date</th><th class="num">Sale price</th><th class="num">m²</th></tr></thead>
                <tbody>
                @foreach(array_slice($vicinityRows, 0, 10) as $row)
                    <tr>
                        <td>
                            {{ $row['address'] ?? '—' }}
                            @if(!empty($row['hfc_sold']))
                                <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:color-mix(in srgb, var(--hfc-teal) 14%, transparent);color:var(--hfc-teal);border-radius:6px;font-size:0.625rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;">
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
            <p class="block-caption">Top {{ min(10, count($vicinityRows)) }} of {{ $soldStats['count'] ?? count($vicinityRows) }} comparable sales in the vicinity.</p>
        </section>
        @endif

        {{-- SS — Recent sales in the complex. Sectional / same-scheme sales as
             their own section: same building, body corporate, levies and
             position make these the strongest comps for a sectional unit.
             Shown when present AND the agency hasn't suppressed it. --}}
        @if($secOn('recent_sales') && $showComplexSales)
        <section class="block" data-section-id="complex-sales">
            <div class="block-eyebrow">Comparable Sales</div>
            {{-- Title-case the scheme name for display only ("PUMULA" → "Pumula"); underlying data unchanged. --}}
            <h2>{{ ($subjectComplexName !== null && trim((string) $subjectComplexName) !== '') ? 'Recent sales in ' . \Illuminate\Support\Str::title(trim((string) $subjectComplexName)) : 'Recent sales in the complex' }}</h2>
            <table>
                <thead><tr><th>Unit / Section</th><th>Sale date</th><th class="num">Sale price</th><th class="num">m²</th></tr></thead>
                <tbody>
                @foreach(array_slice($complexRows, 0, 10) as $row)
                    <tr>
                        <td>
                            {{ $row['address'] ?? '—' }}
                            @if(!empty($row['hfc_sold']))
                                <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:color-mix(in srgb, var(--hfc-teal) 14%, transparent);color:var(--hfc-teal);border-radius:6px;font-size:0.625rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;">
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
            <p class="block-caption">Top {{ min(10, count($complexRows)) }} of {{ $complexStats['count'] ?? count($complexRows) }} sales within the complex.</p>
        </section>
        @endif

        {{-- ─────────── BEAT 2 — "What you're up against" ─────────── --}}
        @if($secOn('active_competition') || $secOn('inflow_absorption'))
        <div class="beat-divider" data-section-id="beat-2">
            <div class="beat-eyebrow">Beat 2</div>
            <h2 class="beat-title">What you're up against</h2>
            <p class="beat-sub">The active listings competing for the same buyer pool, and how quickly the suburb absorbs new stock.</p>
        </div>
        @endif

        {{-- Active competition — card grid. Photo where available,
             gradient fallback otherwise. NEVER a broken image.
             Build 8 — headline count is the canonical scored set
             (competitor_stock.competing_count). The audit grid below
             continues to show recent + portal-sourced rows from
             active_competition; the framing label after the headline
             makes the two layers explicit. --}}
        @if($secOn('active_competition') && ($competingCount > 0 || !empty($activeRowsForAudit)))
        <section class="block" data-section-id="active-competition">
            <div class="block-eyebrow">Active Competition</div>
            <h2>{{ $competingCount }} live listing{{ $competingCount === 1 ? '' : 's' }} competing in this market</h2>
            @if(!empty($activeRowsForAudit))
            <p class="block-eyebrow" style="margin-top:8px;">Recent &amp; portal-sourced listings on record</p>
            <div class="comp-grid">
                @foreach(array_slice($activeRowsForAudit, 0, 9) as $row)
                    @php
                        $rowImg = $row['primary_image'] ?? ($row['photo_url'] ?? null);
                    @endphp
                    <div class="comp-card">
                        <div class="cc-photo" @if($rowImg) style="background-image: url('{{ $rowImg }}');" @endif>
                            @if(!$rowImg)
                                <div class="cc-placeholder-addr">{{ $row['address'] ?? '—' }}</div>
                            @endif
                        </div>
                        <div class="cc-body">
                            <div class="cc-price">
                                {{ isset($row['list_price']) ? 'R ' . number_format((int) $row['list_price'], 0, '.', ' ') : '—' }}
                            </div>
                            <div class="cc-addr">{{ $row['address'] ?? '—' }}</div>
                            <div class="cc-meta">
                                @if(!empty($row['property_type']))<span>{{ \Illuminate\Support\Str::humanType($row['property_type']) }}</span>@endif
                                @if(!empty($row['extent_m2']))<span>{{ number_format((int)$row['extent_m2']) }} m²</span>@endif
                                @if(!empty($row['days_on_market']))<span>{{ $row['days_on_market'] }} d on market</span>@endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="block-caption">
                Showing {{ min(9, count($activeRowsForAudit)) }} of {{ count($activeRowsForAudit) }}
                recent &amp; portal-sourced listings on record.
            </p>
            @endif
        </section>
        @endif

        {{-- Stock absorption — kept as a KPI strip, restyled. --}}
        @if($secOn('inflow_absorption') && !empty($stock['has_data']))
        <section class="block" data-section-id="absorption">
            <div class="block-eyebrow">Stock Absorption</div>
            <h2>How quickly the suburb sells through stock</h2>
            <div class="kpi-grid">
                <div class="kpi">
                    <div class="label">Active listings</div>
                    <div class="value">{{ number_format($stock['total_active_stock']) }}</div>
                </div>
                <div class="kpi">
                    <div class="label">Annual sales</div>
                    <div class="value">{{ number_format($stock['annual_sales']) }}</div>
                </div>
                <div class="kpi">
                    <div class="label">Months of supply</div>
                    <div class="value">{{ number_format($stock['months_of_supply'] ?? 0, 1) }}</div>
                </div>
                @if(isset($stock['absorption_label']))
                <div class="kpi">
                    <div class="label">Trend</div>
                    <div class="value" style="font-size:0.9375rem;">{{ $stock['absorption_label'] }}</div>
                </div>
                @endif
            </div>
            <p class="block-caption">
                Months of supply = active listings ÷ (annual sales / 12). Lower = faster market.
            </p>
        </section>
        @endif

        {{-- ─────────── BEAT 3 — "What it means for you" ─────────── --}}
        @if($secOn('cma_analysis') || $secOn('pricing_strategy') || $secOn('holding_cost'))
        <div class="beat-divider" data-section-id="beat-3">
            <div class="beat-eyebrow">Beat 3</div>
            <h2 class="beat-title">What it means for you</h2>
            <p class="beat-sub">The recommended price band, the condition adjustment, and the cost of staying on market.</p>
        </div>
        @endif

        {{-- CMA Valuation — Build 6 horizontal gauge.
             SVG-rendered so it's print-safe and needs no JS. The asking
             price tick uses the existing teal token. --}}
        @if($secOn('cma_analysis') && ($cmaLower || $cmaMid || $cmaUpper))
        <section class="block" data-section-id="cma-valuation">
            <div class="block-eyebrow">CMA Valuation</div>
            <h2>Where your asking price sits in the recommended band</h2>
            @php
                $gLow = (int) ($cmaLower ?? $cmaMid ?? 0);
                $gMid = (int) ($cmaMid   ?? 0);
                $gUp  = (int) ($cmaUpper ?? $cmaMid ?? 0);
                $gRange = max(1, $gUp - $gLow);
                $midPctX = $gRange > 0 ? max(0, min(100, (($gMid - $gLow) / $gRange) * 100)) : 50;
                $askingPct = ($askingPrice && $gRange > 0)
                    ? max(0, min(100, (($askingPrice - $gLow) / $gRange) * 100))
                    : null;
            @endphp
            <div class="cma-gauge-wrap">
                <div class="cma-gauge" role="img" aria-label="CMA gauge — Lower {{ $gLow }}, Middle {{ $gMid }}, Upper {{ $gUp }}, Asking {{ $askingPrice }}">
                    <svg viewBox="0 0 100 14" preserveAspectRatio="none">
                        {{-- Track --}}
                        <rect x="0" y="6" width="100" height="2" fill="var(--surface-2)" rx="1"/>
                        {{-- Lower → middle (soft) --}}
                        <rect x="0" y="6" width="{{ $midPctX }}" height="2" fill="color-mix(in srgb, var(--brand-default, #0b2a4a) 30%, transparent)" rx="1"/>
                        {{-- Middle → upper (stronger) --}}
                        <rect x="{{ $midPctX }}" y="6" width="{{ 100 - $midPctX }}" height="2" fill="color-mix(in srgb, var(--brand-default, #0b2a4a) 55%, transparent)" rx="1"/>
                        {{-- Middle marker --}}
                        <rect x="{{ $midPctX - 0.4 }}" y="2" width="0.8" height="10" fill="var(--brand-default, #0b2a4a)" rx="0.2"/>
                        @if($askingPct !== null)
                            {{-- Asking-price tick (teal) --}}
                            <rect x="{{ $askingPct - 0.3 }}" y="0" width="0.6" height="14" fill="var(--hfc-teal)" rx="0.2"/>
                        @endif
                    </svg>
                </div>
                <div class="cma-gauge-labels">
                    <span class="lbl-low">Lower</span>
                    <span class="lbl-mid">Middle</span>
                    <span class="lbl-up">Upper</span>
                </div>
                <div class="cma-gauge-values">
                    <span class="v-low">{{ $cmaLower ? 'R ' . number_format($cmaLower, 0, '.', ' ') : '—' }}</span>
                    <span class="v-mid">{{ $cmaMid   ? 'R ' . number_format($cmaMid,   0, '.', ' ') : '—' }}</span>
                    <span class="v-up">{{  $cmaUpper ? 'R ' . number_format($cmaUpper, 0, '.', ' ') : '—' }}</span>
                </div>
                @if($askingPrice)
                <div class="cma-asking-row">
                    <span class="ar-lbl">Your asking price</span>
                    <span class="ar-val">R {{ number_format($askingPrice, 0, '.', ' ') }}</span>
                </div>
                @endif
                @if(!empty($cma['condition_applied']))
                <div class="cma-condition-line">
                    Reflects {{ $cma['condition_label'] ?? '' }} condition
                    ({{ ((float)$cma['condition_pct'] >= 0 ? '+' : '') . (float)$cma['condition_pct'] }}%).
                    Baseline middle: R {{ number_format((int) ($cma['cma_middle_baseline'] ?? 0), 0, '.', ' ') }}.
                </div>
                @endif
            </div>
            <p class="block-caption">
                Teal mark = your asking price. Dark mark = independent middle band. Lower / upper are the bookend extremes from comparable sales.
            </p>
        </section>
        @endif

        {{-- Holding cost callout — Build 6 single bold number, expandable. --}}
        @php
            $holding = $analysisData['holding_cost'] ?? null;
            $monthlyTotal = $holding['monthly_total'] ?? null;
            $projected12m = $holding['projected_12m'] ?? null;
            $holdingBreakdown = $holding['breakdown'] ?? [];
        @endphp
        @if($secOn('holding_cost') && is_numeric($monthlyTotal) && $monthlyTotal > 0)
        <section class="block" data-section-id="holding-cost">
            <div class="block-eyebrow">Holding Cost</div>
            <div class="holding-callout">
                <div class="hc-eyebrow">Every month at this asking price costs</div>
                <p class="hc-number">R {{ number_format($monthlyTotal, 0, '.', ' ') }}</p>
                @if($projected12m && $askingPrice && $askingPrice > 0)
                    <div class="hc-sub">
                        Twelve months of holding = R {{ number_format($projected12m, 0, '.', ' ') }} —
                        {{ number_format($projected12m / $askingPrice * 100, 1) }}% of your asking price.
                    </div>
                @endif
                @if(!empty($holdingBreakdown))
                <button type="button" class="hc-disclosure" id="hc-toggle" aria-expanded="false" aria-controls="hc-breakdown">
                    Show breakdown
                </button>
                <div class="holding-breakdown" id="hc-breakdown" hidden>
                    <table>
                        <thead><tr><th>Item</th><th class="num">Monthly</th></tr></thead>
                        <tbody>
                        @foreach($holdingBreakdown as $label => $value)
                            @if(is_numeric($value) && $value > 0)
                            <tr>
                                <td>{{ ucwords(str_replace('_', ' ', (string) $label)) }}</td>
                                <td class="num">R {{ number_format((float) $value, 0, '.', ' ') }}</td>
                            </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </section>
        @endif
    @endif

    {{-- Build 6 — agent footer card on every page. --}}
    <div class="agent-footer" data-section-id="agent-footer">
        <div class="af-grid">
            <div>
                <div class="af-name">{{ $agentName }}</div>
                @if($agencyName)<div class="af-agency">{{ $agencyName }}</div>@endif
                <div class="af-ppra">{{ $agentDesignation }}{{ $agentFfc ? ' · FFC ' . $agentFfc : '' }}</div>
            </div>
            <div class="af-actions">
                @if($waPhone)
                    <a class="af-action is-primary" href="https://wa.me/{{ $waPhone }}" target="_blank" rel="noopener">WhatsApp</a>
                @endif
                @if($agentEmail)
                    <a class="af-action" href="mailto:{{ $agentEmail }}">Email</a>
                @endif
                @if($agentPhone)
                    <a class="af-action" href="tel:{{ preg_replace('/\s+/', '', $agentPhone) }}">Call</a>
                @endif
            </div>
        </div>
    </div>

    <div class="meta-strip">
        Prepared {{ optional($version?->snapshot_taken_at ?? $version?->created_at)->format('j F Y') }}
        @if($version?->id) · v{{ $version->id }}@endif
        · <a href="{{ route('presentation.public.refresh-form', $link->token) }}" style="color: var(--text-muted); text-decoration: underline;">Request refresh</a>
    </div>
</div>

{{-- Build 6 — Chart.js for the suburb-trend chart. Loaded via CDN
     because the public seller view has no Vite bundle. Defer-loaded so
     it doesn't block paint. SSOT-token-styled (navy primary, teal
     highlight, no decorative colours). --}}
@if(!empty($marketActivityRows ?? []) && (!isset($version) || !$version || $version->isSectionEnabled('market_overview')))
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" defer></script>
<script>
window.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('suburb-trend-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    // Build month buckets from vicinity rows. We bucket by YYYY-MM and
    // count occurrences — a small histogram of suburb activity over time.
    const rows = @json($marketActivityRows ?? []);
    const buckets = {};
    rows.forEach(r => {
        const d = (r.sale_date || '').toString().substring(0, 7);
        if (!d) return;
        buckets[d] = (buckets[d] || 0) + 1;
    });
    const labels = Object.keys(buckets).sort();
    const data   = labels.map(l => buckets[l]);
    if (labels.length === 0) return;

    // SSOT brand tokens read via CSS — fall back to hex defaults.
    const navy = getComputedStyle(document.documentElement).getPropertyValue('--brand-default').trim() || '#0b2a4a';
    const teal = getComputedStyle(document.documentElement).getPropertyValue('--hfc-teal').trim() || '#00d4aa';

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Sales',
                data,
                backgroundColor: labels.map((_, i) => i === labels.length - 1 ? teal : navy),
                borderRadius: 4,
                barPercentage: 0.7,
                categoryPercentage: 0.85,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#ffffff',
                    borderColor: navy,
                    borderWidth: 1,
                    titleColor: '#111827',
                    bodyColor: '#111827',
                    padding: 8,
                    titleFont: { size: 11, weight: '600', family: 'Figtree' },
                    bodyFont:  { size: 11, family: 'Figtree' },
                    cornerRadius: 6,
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#9ca3af', font: { size: 10, family: 'Figtree' } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)', borderDash: [2, 4] },
                    ticks: { color: '#9ca3af', precision: 0, font: { size: 10, family: 'Figtree' } },
                },
            },
        },
    });
});
</script>
@endif

{{-- Build 6 — holding cost expandable breakdown toggle. --}}
<script>
(function () {
    const btn = document.getElementById('hc-toggle');
    const box = document.getElementById('hc-breakdown');
    if (!btn || !box) return;
    btn.addEventListener('click', () => {
        const open = !box.hidden;
        box.hidden = open;
        btn.textContent = open ? 'Show breakdown' : 'Hide breakdown';
        btn.setAttribute('aria-expanded', String(!open));
    });
})();
</script>

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
