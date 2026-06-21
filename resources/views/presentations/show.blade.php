@extends('layouts.corex')

@push('head')
<meta name="hfc-presentation-id" content="{{ $presentation->id }}">
<meta name="hfc-presentation-title" content="{{ $presentation->title ?? '' }}">
{{-- Zero out <main> padding so sticky bar pins flush with no gap --}}
<style>#appScroll { padding: 0 !important; }</style>
@endpush

@section('corex-content')

@php
    $statusClasses = match($presentation->status) {
        'presented' => 'bg-emerald-50 text-[#00d4aa]',
        'locked'    => 'pres-badge-success',
        default     => 'bg-slate-100 text-slate-500',
    };
    $lastSummary = $latestSnapshot ? $latestSnapshot->getOutputSummaryArray() : null;
@endphp

{{-- Sticky action bar — no wrapper, no negative margins, <main> padding zeroed --}}
<div class="sticky top-0 z-40 bg-white border-b border-gray-200 shadow-sm">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-3">
                <a href="{{ route('presentations.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    All Presentations
                </a>
            </div>
            <div class="flex-1 text-center truncate mx-4">
                <h2 class="text-sm font-semibold text-gray-700 truncate">{{ $presentation->title }}</h2>
            </div>
            <div class="flex items-center gap-2">
                {{-- Edit Property link removed — property data has ONE source
                     (the property page itself). No manual edit path here. --}}
                <a href="{{ route('presentations.analysis', [$presentation, 'refresh' => 1]) }}" class="px-3 py-1.5 text-sm font-medium text-white rounded-lg" style="background:#00d4aa;color:#0f172a;font-weight:600;">
                    {{ $latestSnapshot ? 'Re-run Analysis' : 'Run Analysis' }}
                </a>
            </div>
        </div>
    </div>
</div>

<div class="pres-page p-4 lg:p-6">

{{-- Navy header bar --}}
<div style="background:#0f172a;" class="rounded-2xl px-6 py-4 mb-8">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div>
            <div class="flex items-center gap-3 mb-1.5">
                <h2 class="text-xl font-bold text-white leading-tight">{{ $presentation->title }}</h2>
                <span class="pres-badge {{ $statusClasses }}" style="border:1px solid rgba(255,255,255,0.2);">
                    {{ ucfirst($presentation->status) }}
                </span>
            </div>
            <p class="text-sm text-white/70 font-medium">{{ $presentation->property_address ?? 'No address set' }}</p>

            {{-- Property details row --}}
            @php
                // Build 1 — Str::humanType is the single source for
                // property-type display. The legacy $propTypeLabels map
                // is retained as a fine-grained override (e.g. "land" →
                // "Vacant Land" rather than "Land") but falls through to
                // humanType for unknown values.
                $propTypeLabels = [
                    'house' => 'House', 'townhouse' => 'Townhouse', 'apartment' => 'Apartment/Flat',
                    'duplex' => 'Duplex', 'vacant_land' => 'Vacant Land', 'farm' => 'Farm',
                    'unit' => 'Unit/Apartment', 'land' => 'Vacant Land', 'other' => 'Other',
                ];
                $propDetails = array_filter([
                    $presentation->suburb,
                    $presentation->property_type ? ($propTypeLabels[$presentation->property_type] ?? \Illuminate\Support\Str::humanType($presentation->property_type)) : null,
                    $presentation->bedrooms ? $presentation->bedrooms . ' bed' : null,
                    $presentation->bathrooms ? $presentation->bathrooms . ' bath' : null,
                    $presentation->garages_parking ? $presentation->garages_parking . ' garage' : null,
                    $presentation->erf_size_m2 ? number_format($presentation->erf_size_m2) . ' m² erf' : null,
                    $presentation->floor_area_m2 ? $presentation->floor_area_m2 . ' m² floor' : null,
                    $presentation->asking_price_inc ? 'R ' . number_format($presentation->asking_price_inc, 0, '.', ' ') : null,
                ]);
            @endphp
            @if(!empty($propDetails))
                <p class="text-xs text-white/40 mt-1">{{ implode(' · ', $propDetails) }}</p>
            @endif

            @if($presentation->seller_name)
                <p class="text-xs text-white/40 mt-0.5">Seller: {{ $presentation->seller_name }}</p>
            @endif
            <p class="text-xs text-white/40 mt-0.5">Created {{ $presentation->created_at->format('Y-m-d') }}</p>
        </div>
        <a href="{{ route('presentations.index') }}"
           class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; All Presentations
        </a>
    </div>
</div>

{{-- Flash messages handled by global toast system --}}

{{-- ACTION BUTTONS --}}
<div class="ds-status-card mb-8">
    <div class="flex flex-wrap items-center gap-3 px-5 py-3.5">
        @if($latestSnapshot)
            <a href="{{ route('presentations.analysis', $presentation) }}"
               class="corex-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                View Analysis
            </a>
        @endif
        <a href="{{ route('presentations.analysis', [$presentation, 'refresh' => 1]) }}"
           class="{{ $latestSnapshot ? 'corex-btn-outline' : 'corex-btn-primary' }}">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
            {{ $latestSnapshot ? 'Re-run Analysis' : 'Run Analysis' }}
        </a>
        @if(config('features.pricing_simulator_v1'))
            <a href="{{ route('presentations.pricing-simulator', $presentation) }}"
               class="corex-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
                Pricing Simulator
            </a>
            <a href="{{ route('presentations.seller-live', $presentation) }}"
               class="corex-btn-primary" style="background:#1a1a1a;border:1px solid #444;color:#f0f0f0;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5" /></svg>
                Seller Live Test
            </a>
        @endif
        @php
            // PDF readiness gate — the auto-presentation flow guarantees
            // data capture + auto-generates a default-tone Executive
            // Summary at generate time, so under normal conditions
            // ai_summary_text is populated before the agent reaches this
            // screen. The gate only blocks when (a) Analysis hasn't been
            // run yet OR (b) the AI was unreachable at generate time and
            // the agent hasn't regenerated from the Exec Summary panel.
            // Holding Cost does NOT gate — it's auto-filled by the Tier
            // 0/1/2 chain and agent overrides happen inline.
            $hasAiSummary = $latestVersion?->ai_summary_text;
            $pdfReady     = $latestVersion && $hasAiSummary;
            $pdfBlockMsg  = !$latestVersion
                ? 'Run Analysis first to produce a compiled snapshot'
                : (!$hasAiSummary ? 'Generate the Executive Summary to enable the PDF' : '');
        @endphp
        @if(config('features.presentation_blueprint'))
            <form method="POST" action="{{ route('presentations.compile', $presentation) }}" class="inline">
                @csrf
                <button type="submit"
                        class="corex-btn-primary" style="{{ $pdfReady ? '' : 'opacity:0.5;cursor:not-allowed;' }}"
                        {{ $pdfReady ? '' : 'disabled title="' . e($pdfBlockMsg) . '"' }}>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    Compile Pack
                </button>
            </form>
        @endif
        @if(config('features.presentation_pdf_v1') && isset($latestVersion) && $latestVersion)
            @if($pdfReady)
                <a href="{{ route('presentations.versions.pdf', [$presentation, $latestVersion]) }}"
                   class="corex-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Download PDF (v{{ $latestVersion->id }})
                </a>
                <a href="{{ route('presentations.versions.complete-pack', [$presentation, $latestVersion]) }}"
                   class="corex-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                    Complete Pack (ZIP)
                </a>
            @else
                {{-- Disabled state for Download PDF + Complete Pack — share
                     the same readiness boolean + tooltip as Compile Pack so
                     the agent sees consistent messaging across all three. --}}
                <span class="corex-btn-primary" style="opacity:0.5;cursor:not-allowed;"
                      title="{{ $pdfBlockMsg }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Download PDF (v{{ $latestVersion->id }})
                </span>
                <span class="corex-btn-primary" style="opacity:0.5;cursor:not-allowed;"
                      title="{{ $pdfBlockMsg }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                    Complete Pack (ZIP)
                </span>
            @endif
        @endif
        {{-- Edit Details button removed — property data has ONE source (the
             property page itself). Changes there flow through regenerate;
             no manual override on the presentation screen. --}}
    </div>
</div>

{{-- Error flash handled by global toast system --}}

{{-- ── PHASE 8: OUTCOME PANEL ──────────────────────────────────────────── --}}
@include('presentations.partials._outcome-panel', ['presentation' => $presentation])

{{-- ── PHASE 7: REFRESH REQUESTS (open + recent) ───────────────────────── --}}
@php
    $openRefreshRequests = \App\Models\PresentationRefreshRequest::where('presentation_id', $presentation->id)
        ->whereIn('status', [
            \App\Models\PresentationRefreshRequest::STATUS_PENDING,
            \App\Models\PresentationRefreshRequest::STATUS_ACKNOWLEDGED,
        ])
        ->orderByDesc('created_at')
        ->limit(5)
        ->get();
@endphp
@if($openRefreshRequests->isNotEmpty())
<div class="ds-status-card mb-4" style="border-left:3px solid #f59e0b;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
        <div style="flex:1;">
            <h2 class="ds-section-header" style="margin-bottom:6px;color:#92400e;">
                {{ $openRefreshRequests->count() }} open refresh {{ \Illuminate\Support\Str::plural('request', $openRefreshRequests->count()) }}
            </h2>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">
                @foreach($openRefreshRequests as $rr)
                    <div style="padding:6px 0;border-top:{{ $loop->first ? '0' : '1px solid var(--border)' }};">
                        <strong>{{ $rr->requester_name }}</strong>
                        <span style="color:var(--text-muted);font-size:0.75rem;">· {{ $rr->created_at?->diffForHumans() }}</span>
                        @if($rr->message)
                            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">{{ \Illuminate\Support\Str::limit($rr->message, 120) }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        <a href="{{ route('corex.presentations.refresh-requests.index') }}"
           style="white-space:nowrap;font-size:0.75rem;color:#92400e;text-decoration:none;font-weight:600;padding:6px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:4px;">
            Open inbox →
        </a>
    </div>
</div>
@endif

{{-- ── PHASE 4: SHARE LINKS ──────────────────────────────────────────────── --}}
@php
    $shareLinkService = app(\App\Services\Presentations\SnapshotLinkService::class);
    $shareLinks       = $shareLinkService->listForPresentation($presentation);
    $contactsForLink  = $presentation->property
        ? $presentation->property->contacts()->select('contacts.id', 'first_name', 'last_name', 'email', 'phone')
            ->withPivot('role')->get()
        : collect();
    $sendDefaults = [
        'channel' => auth()->user()->last_presentation_send_channel ?: 'email',
        'mode'    => auth()->user()->last_presentation_send_mode    ?: 'full',
    ];
    $shareLinkSummary = $shareLinkService->engagementSummary($presentation);
@endphp
<div class="ds-status-card mb-8" id="share-links">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="ds-section-header" style="margin-bottom:0">Share Links</h2>
            <p class="text-xs" style="color: var(--text-muted); margin: 2px 0 0 0;">
                Tokenised public links for sellers. Static snapshots — locked to the version when the link was created.
            </p>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" onclick="document.getElementById('send-presentation-modal').style.display='flex';window.__corexSendInit && window.__corexSendInit();"
                    class="corex-btn-primary">
                📧 Send to Recipient
            </button>
            <button type="button" onclick="document.getElementById('share-link-modal').style.display='flex'"
                    class="corex-btn-outline">
                🔗 Generate Link Only
            </button>
        </div>
    </div>

    @if($shareLinks->isEmpty())
        <div style="padding: 18px; text-align: center; background: var(--surface-2); border: 1px dashed var(--border); border-radius: 6px; color: var(--text-muted); font-size: 0.875rem;">
            No links created yet. Click <strong style="color: var(--text-primary);">Send to Recipient</strong> or <strong style="color: var(--text-primary);">Generate Link Only</strong> to create one.
        </div>
    @else
        @if($shareLinkSummary['total_views'] > 0)
        <div style="margin-bottom: 12px; padding: 10px 12px; background: color-mix(in srgb, var(--ds-green, #16a34a) 8%, transparent); border-left: 3px solid var(--ds-green, #16a34a); border-radius: 4px; font-size: 0.8125rem;">
            <strong>Recent activity:</strong>
            Sellers opened the presentation
            <strong>{{ $shareLinkSummary['total_views'] }}</strong> {{ \Illuminate\Support\Str::plural('time', $shareLinkSummary['total_views']) }}
            @if($shareLinkSummary['last_viewed_at'])
                · most recent {{ $shareLinkSummary['last_viewed_at']->diffForHumans() }}
            @endif
            @if($shareLinkSummary['avg_duration_seconds'])
                · avg {{ floor($shareLinkSummary['avg_duration_seconds'] / 60) }}m {{ $shareLinkSummary['avg_duration_seconds'] % 60 }}s on page
            @endif
            @if($shareLinkSummary['any_flagged'])
                · <span style="color: var(--ds-amber, #d97706); font-weight: 600;">⚠ at least one flagged access</span>
            @endif
        </div>
        @endif

        <div style="overflow:auto;">
        <table style="width:100%;font-size:0.8125rem;">
            <thead>
                <tr style="text-align:left;color:var(--text-muted);font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;border-bottom:1px solid var(--border);">
                    <th style="padding:8px 6px;">Recipient</th>
                    <th style="padding:8px 6px;">Mode</th>
                    <th style="padding:8px 6px;">Created</th>
                    <th style="padding:8px 6px;">Expires</th>
                    <th style="padding:8px 6px;text-align:center;">Views</th>
                    <th style="padding:8px 6px;text-align:center;">Leads</th>
                    <th style="padding:8px 6px;">First viewed</th>
                    <th style="padding:8px 6px;">URL</th>
                    <th style="padding:8px 6px;text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach($shareLinks as $sl)
                @php
                    $expDays = $sl->expires_at ? now()->diffInDays($sl->expires_at, false) : null;
                    $expBadgeClass = $expDays === null ? '' : ($expDays < 0 ? 'ds-badge-danger' : ($expDays <= 7 ? 'ds-badge-warning' : 'ds-badge-success'));
                @endphp
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 6px;">
                        @if($sl->recipientContact)
                            {{ trim(($sl->recipientContact->first_name ?? '') . ' ' . ($sl->recipientContact->last_name ?? '')) ?: 'Contact #' . $sl->recipientContact->id }}
                        @elseif($sl->recipient_label)
                            {{ $sl->recipient_label }}
                        @else
                            <span style="color: var(--text-muted);">Untargeted</span>
                        @endif
                        @if($sl->flagged_at)
                            <span class="ds-badge ds-badge-warning" title="{{ $sl->flagged_reason }}" style="margin-left:6px;">⚠ Flagged</span>
                        @endif
                    </td>
                    <td style="padding:8px 6px;">
                        @if($sl->mode === 'teaser')
                            <span class="ds-badge ds-badge-info">Teaser</span>
                        @else
                            <span class="ds-badge" style="background:var(--surface-2);color:var(--text-secondary);">Full</span>
                        @endif
                    </td>
                    <td style="padding:8px 6px;color:var(--text-muted);font-size:0.75rem;">{{ $sl->created_at->diffForHumans() }}</td>
                    <td style="padding:8px 6px;">
                        @if($expDays !== null)
                            <span class="ds-badge {{ $expBadgeClass }}">
                                {{ $expDays < 0 ? 'Expired ' . abs((int) $expDays) . 'd ago' : 'in ' . (int) $expDays . 'd' }}
                            </span>
                        @endif
                    </td>
                    <td style="padding:8px 6px;text-align:center;font-variant-numeric:tabular-nums;">{{ $sl->view_count }}</td>
                    <td style="padding:8px 6px;text-align:center;font-variant-numeric:tabular-nums;">
                        @if($sl->mode === 'teaser')
                            @if(($sl->teaser_leads_count ?? 0) > 0)
                                <a href="{{ route('presentations.teaser-leads', $presentation) }}" style="color:var(--brand-button);font-weight:600;">{{ $sl->teaser_leads_count }}</a>
                            @else
                                <span style="color:var(--text-muted);">—</span>
                            @endif
                        @else
                            <span style="color:var(--text-muted);">n/a</span>
                        @endif
                    </td>
                    <td style="padding:8px 6px;color:var(--text-muted);font-size:0.75rem;">
                        {{ $sl->first_viewed_at ? $sl->first_viewed_at->diffForHumans() : 'Not yet viewed' }}
                    </td>
                    <td style="padding:8px 6px;">
                        <button type="button" class="corex-btn-outline corex-btn-xs"
                                data-share-url="{{ route('presentation.public.show', $sl->token) }}"
                                onclick="navigator.clipboard.writeText(this.dataset.shareUrl).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy URL',1500);})">
                            Copy URL
                        </button>
                    </td>
                    <td style="padding:8px 6px;text-align:right;white-space:nowrap;">
                        <form method="POST" action="{{ route('presentations.snapshot-links.extend', [$presentation, $sl]) }}" style="display:inline;">
                            @csrf
                            <input type="hidden" name="days" value="7">
                            <button type="submit" class="corex-btn-outline corex-btn-xs">+7d</button>
                        </form>
                        <form method="POST" action="{{ route('presentations.snapshot-links.revoke', [$presentation, $sl]) }}" style="display:inline;"
                              onsubmit="return confirm('Revoke this link? The seller will no longer be able to view it.');">
                            @csrf
                            <button type="submit" class="corex-btn-outline corex-btn-xs" style="color: var(--ds-red, #dc2626);">Revoke</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

{{-- Generate Share Link modal --}}
<div id="share-link-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.4);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--surface);border-radius:8px;max-width:480px;width:100%;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div class="flex items-center justify-between mb-3">
            <h3 style="font-size:1rem;font-weight:600;margin:0;">Generate share link</h3>
            <button type="button" onclick="document.getElementById('share-link-modal').style.display='none'"
                    style="background:none;border:0;font-size:1.25rem;color:var(--text-muted);cursor:pointer;">×</button>
        </div>
        <form method="POST" action="{{ route('presentations.snapshot-links.store', $presentation) }}">
            @csrf

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Mode</label>
                <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;padding:4px 0;">
                    <input type="radio" name="mode" value="full" checked>
                    <span>Full presentation</span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;padding:4px 0;">
                    <input type="radio" name="mode" value="teaser">
                    <span>Teaser (lead-capture mode)</span>
                </label>
                <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:2px;line-height:1.4;">
                    Recipient sees suburb context but must submit their details to unlock the full report. Use for cold prospects.
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label for="recipient_contact_id" style="display:block;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Recipient (optional)</label>
                <select name="recipient_contact_id" id="recipient_contact_id" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;background:var(--surface);font-size:0.875rem;">
                    <option value="">— Untargeted —</option>
                    @foreach($contactsForLink as $c)
                        <option value="{{ $c->id }}">{{ trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ('Contact #' . $c->id) }}</option>
                    @endforeach
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label for="recipient_label" style="display:block;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Or free-text label</label>
                <input type="text" name="recipient_label" id="recipient_label" maxlength="200" placeholder="e.g. Seller WhatsApp" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;background:var(--surface);font-size:0.875rem;">
            </div>

            <div style="margin-bottom:16px;">
                <label for="expires_days" style="display:block;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Expires in (days)</label>
                <input type="number" name="expires_days" id="expires_days" min="1" max="365"
                       value="{{ optional(\App\Models\Agency::find($presentation->agency_id))->snapshot_link_default_expiry_days ?? 21 }}"
                       style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;background:var(--surface);font-size:0.875rem;">
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('share-link-modal').style.display='none'" class="corex-btn-outline">Cancel</button>
                <button type="submit" class="corex-btn-primary">Generate</button>
            </div>
        </form>
    </div>
</div>

{{-- Documents — relocated to sit next to Share Links (was previously
     grouped with the removed Property Links + Portal Captures sections). --}}
{{-- DOCUMENT UPLOAD --}}
<div class="ds-status-card mb-8" id="documents">
    <h2 class="ds-section-header mb-3">Documents</h2>
    <div>

    @php
        $docTypeLabels = [
            'suburb_stats'   => 'Suburb Report',
            'vicinity_sales' => 'Vicinity Sales Report',
            'cma'            => 'CMA Evaluation Report',
            'market_article' => 'Market Article',
            'other'          => 'Other',
        ];
        $docTypeIcons = [
            'suburb_stats'   => '📊',
            'vicinity_sales' => '📍',
            'cma'            => '📋',
            'market_article' => '📰',
            'other'          => '📄',
            'unknown'        => '❓',
            'application/pdf' => '📄',
        ];

        // Upload status summary
        $uploadsByType = $presentation->uploads->groupBy('type');
        $requiredTypes = ['suburb_stats', 'vicinity_sales', 'cma'];
        $presentTypes = $uploadsByType->keys()->intersect($requiredTypes)->toArray();
        $missingTypes = array_diff($requiredTypes, $presentTypes);
        $totalUploads = $presentation->uploads->count();
    @endphp

    {{-- Upload status summary --}}
    @if($totalUploads > 0)
        <div class="mb-4 px-3 py-2 rounded-lg {{ empty($missingTypes) ? 'bg-emerald-50' : 'bg-slate-50' }}">
            <div class="flex items-center gap-2 text-xs">
                @if(empty($missingTypes))
                    <span class="text-[#00d4aa] font-semibold">Documents: {{ count($presentTypes) }}/3 uploaded ✓</span>
                @else
                    <span class="text-slate-600 font-semibold">Documents: {{ count($presentTypes) }}/3</span>
                    <span class="text-slate-400">— missing:
                        {{ implode(', ', array_map(fn($t) => $docTypeLabels[$t] ?? $t, $missingTypes)) }}
                    </span>
                @endif
            </div>
        </div>
    @endif

    @if($presentation->uploads->isEmpty())
        <p class="text-xs text-slate-400 italic mb-3">No documents uploaded yet.</p>
    @else
        <ul class="space-y-3 mb-4 text-xs text-slate-600">
            @foreach($presentation->uploads as $upload)
                <li class="pres-doc-row">
                    {{-- Row 1: File header --}}
                    @php
                        $uIcon = $docTypeIcons[$upload->type] ?? '📄';
                        $uTypeLabel = $docTypeLabels[$upload->type] ?? $upload->type;
                        $uIsKnownType = in_array($upload->type, ['suburb_stats', 'vicinity_sales', 'cma', 'market_article', 'other']);
                        $uExtStatus = $upload->extraction_status ?? 'pending';
                        $uExtBadge = match($uExtStatus) {
                            'ok'     => 'bg-emerald-50 text-[#00d4aa]',
                            'failed' => 'bg-red-50 text-red-600',
                            default  => 'bg-amber-50 text-amber-600',
                        };
                        $uExtLabel = match($uExtStatus) {
                            'ok'     => '✅ Extracted',
                            'failed' => '❌ Failed',
                            default  => '⏳ Processing',
                        };
                    @endphp
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0 flex-wrap">
                            <span class="text-lg shrink-0 leading-none">{{ $uIcon }}</span>
                            <div class="min-w-0">
                                <span class="font-semibold text-slate-700">{{ $uTypeLabel }}</span>
                                <span class="text-slate-400 ml-1 truncate">{{ $upload->original_filename ?? basename($upload->file_path) }}</span>
                            </div>

                            <span class="pres-badge {{ $uExtBadge }}">
                                {{ $uExtLabel }}
                            </span>

                            <form method="POST"
                                  action="{{ route('presentations.uploads.re-extract', [$presentation, $upload]) }}"
                                  class="inline">
                                @csrf
                                <button type="submit"
                                        class="inline-block px-1 py-0.5 text-xs text-[#00d4aa] hover:text-[#0f172a]"
                                        title="Re-run extraction">&#x27F3;</button>
                            </form>

                            <form method="POST"
                                  action="{{ route('presentations.uploads.destroy', [$presentation, $upload]) }}"
                                  class="inline"
                                  onsubmit="return confirm('Delete this document? Extracted data will be removed.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-block px-1 py-0.5 text-xs text-red-400 hover:text-red-600"
                                        title="Delete document">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                </button>
                            </form>

                            @if($upload->isOverridden())
                                <span class="pres-badge pres-badge-warn">
                                    Override
                                </span>
                            @endif
                        </div>
                        @if(!$uIsKnownType || $upload->type === 'other')
                            {{-- Unknown/other type: show prominent type selector --}}
                            <form method="POST"
                                  action="{{ route('presentations.uploads.update-type', [$presentation, $upload]) }}"
                                  class="flex items-center gap-1.5 shrink-0">
                                @csrf
                                @method('PATCH')
                                <select name="type" class="pres-select text-xs border-amber-300">
                                    <option value="" disabled>Select type...</option>
                                    @foreach($docTypeLabels as $val => $label)
                                        <option value="{{ $val }}" {{ $upload->type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit"
                                        class="text-xs text-[#00d4aa] hover:text-[#0f172a] font-semibold">Save</button>
                            </form>
                        @else
                            {{-- Known type: small "Change type" toggle --}}
                            <details class="shrink-0">
                                <summary class="text-[11px] text-slate-400 cursor-pointer hover:text-[#00d4aa]">Change type</summary>
                                <form method="POST"
                                      action="{{ route('presentations.uploads.update-type', [$presentation, $upload]) }}"
                                      class="flex items-center gap-1.5 mt-1">
                                    @csrf
                                    @method('PATCH')
                                    <select name="type" class="pres-select text-xs">
                                        @foreach($docTypeLabels as $val => $label)
                                            <option value="{{ $val }}" {{ $upload->type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="text-xs text-[#00d4aa] hover:text-[#0f172a] font-semibold">Save</button>
                                </form>
                            </details>
                        @endif
                    </div>

                    {{-- Row 2: Extraction summary (doc_extract_v1 enhanced) --}}
                    @php
                        $uVerified  = $upload->getVerifiedData();
                        $uAgg       = $uVerified['aggregates'] ?? [];
                        $uCounts    = $uVerified['parsed_counts'] ?? [];
                        $uFields    = $uVerified['fields'] ?? [];
                        $hasDocExtract = !empty($uFields) && ($uVerified['extracted_version'] ?? '') === 'doc_extract_v1';
                    @endphp

                    @if($hasDocExtract && $upload->type === 'cma')
                        {{-- ── CMA Evaluation Summary Card ── --}}
                        <div class="mt-2 bg-emerald-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-1">
                            <div class="font-semibold text-[#0f172a]">CMA Evaluation Summary</div>
                            @if(isset($uFields['cma.lower_range']) || isset($uFields['cma.middle_range']) || isset($uFields['cma.upper_range']))
                                <div>
                                    <span class="text-gray-500">Price Range:</span>
                                    @if(isset($uFields['cma.lower_range'])) R{{ number_format((int)$uFields['cma.lower_range']) }} @endif
                                    @if(isset($uFields['cma.middle_range'])) &ndash; <span class="font-medium">R{{ number_format((int)$uFields['cma.middle_range']) }}</span> @endif
                                    @if(isset($uFields['cma.upper_range'])) &ndash; R{{ number_format((int)$uFields['cma.upper_range']) }} @endif
                                </div>
                                <div class="text-[10px] text-gray-400 -mt-0.5">Lower &ndash; Middle &ndash; Upper</div>
                            @endif
                            @if(isset($uFields['municipal.total_value']))
                                <div>
                                    <span class="text-gray-500">Municipal:</span>
                                    R{{ number_format((int)$uFields['municipal.total_value']) }}
                                    @if(isset($uFields['municipal.valuation_year']))
                                        <span class="text-gray-400">({{ $uFields['municipal.valuation_year'] }})</span>
                                    @endif
                                </div>
                            @endif
                            @if(isset($uFields['subject.address']))
                                <div>{{ $uFields['subject.address'] }}@if(isset($uFields['subject.suburb'])), {{ $uFields['subject.suburb'] }}@endif</div>
                            @endif
                            @php
                                $subjectParts = [];
                                if (isset($uFields['subject.erf'])) $subjectParts[] = 'Erf ' . $uFields['subject.erf'];
                                if (isset($uFields['subject.extent_m2'])) $subjectParts[] = number_format((int)$uFields['subject.extent_m2']) . ' m²';
                            @endphp
                            @if(!empty($subjectParts))
                                <div class="text-gray-500">{{ implode(' | ', $subjectParts) }}</div>
                            @endif
                            @if(isset($uFields['subject.purchase_price']))
                                <div class="text-gray-500">
                                    Purchased{{ isset($uFields['subject.purchase_date']) ? ': ' . $uFields['subject.purchase_date'] : '' }}
                                    for R{{ number_format((int)$uFields['subject.purchase_price']) }}
                                    @if(isset($uFields['subject.indexed_value']))
                                        | Indexed: R{{ number_format((int)$uFields['subject.indexed_value']) }}
                                    @endif
                                    @if(isset($uFields['subject.cagr']))
                                        | CAGR: {{ $uFields['subject.cagr'] }}%
                                    @endif
                                </div>
                            @endif
                        </div>

                    @elseif($hasDocExtract && $upload->type === 'suburb_stats')
                        {{-- ── Suburb Sales Summary Card ── --}}
                        <div class="mt-2 bg-emerald-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-1">
                            <div class="font-semibold text-[#0f172a]">
                                Suburb Sales Summary
                                @if(isset($uFields['suburb.latest_year']))
                                    <span class="font-normal text-gray-400">({{ $uFields['suburb.latest_year'] }})</span>
                                @endif
                            </div>
                            @if(isset($uFields['suburb.latest_median_price']))
                                <div>
                                    <span class="text-gray-500">Median:</span>
                                    <span class="font-medium">R{{ number_format((int)$uFields['suburb.latest_median_price']) }}</span>
                                    @if(isset($uFields['suburb.latest_sales_count']))
                                        | <span class="text-gray-500">Sales:</span> {{ $uFields['suburb.latest_sales_count'] }}
                                    @endif
                                </div>
                            @endif
                            @if(isset($uFields['suburb.latest_low']) && isset($uFields['suburb.latest_high']))
                                <div>
                                    <span class="text-gray-500">Range:</span>
                                    R{{ number_format((int)$uFields['suburb.latest_low']) }}
                                    &ndash; R{{ number_format((int)$uFields['suburb.latest_high']) }}
                                </div>
                            @endif
                        </div>

                    @elseif($hasDocExtract && $upload->type === 'vicinity_sales')
                        {{-- ── Vicinity Sales Summary Card ── --}}
                        <div class="mt-2 bg-emerald-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-1">
                            <div class="font-semibold text-[#0f172a]">Vicinity Sales Summary</div>
                            @if(isset($uFields['vicinity.lower_range']) || isset($uFields['vicinity.middle_range']) || isset($uFields['vicinity.upper_range']))
                                <div>
                                    <span class="text-gray-500">Price Range:</span>
                                    @if(isset($uFields['vicinity.lower_range'])) R{{ number_format((int)$uFields['vicinity.lower_range']) }} @endif
                                    @if(isset($uFields['vicinity.middle_range'])) &ndash; <span class="font-medium">R{{ number_format((int)$uFields['vicinity.middle_range']) }}</span> @endif
                                    @if(isset($uFields['vicinity.upper_range'])) &ndash; R{{ number_format((int)$uFields['vicinity.upper_range']) }} @endif
                                </div>
                                <div class="text-[10px] text-gray-400 -mt-0.5">Lower &ndash; Middle &ndash; Upper</div>
                            @endif
                            @php
                                $vicParts = [];
                                if (isset($uFields['vicinity.average_price'])) $vicParts[] = 'Avg: R' . number_format((int)$uFields['vicinity.average_price']);
                                if (isset($uFields['vicinity.avg_price_per_m2'])) $vicParts[] = 'Avg R/m²: R' . number_format((int)$uFields['vicinity.avg_price_per_m2']);
                                if (isset($uFields['vicinity.comps_count'])) $vicParts[] = 'Comps: ' . $uFields['vicinity.comps_count'];
                            @endphp
                            @if(!empty($vicParts))
                                <div>{{ implode(' | ', $vicParts) }}</div>
                            @endif
                        </div>

                    @elseif($uVerified && ($upload->type === 'suburb_stats') && !empty($uAgg))
                        {{-- Suburb Stats compact summary (legacy) --}}
                        @php
                            $uParts = [];
                            if (!empty($uAgg['active_listings_count'])) $uParts[] = 'Active: ' . $uAgg['active_listings_count'];
                            if (!empty($uAgg['median_price'])) $uParts[] = 'Median: R' . number_format($uAgg['median_price'], 0);
                            if (!empty($uAgg['average_price'])) $uParts[] = 'Avg: R' . number_format($uAgg['average_price'], 0);
                            if (!empty($uAgg['dom_p50'])) $uParts[] = 'DOM: ' . $uAgg['dom_p50'];
                            if (!empty($uAgg['months_of_inventory'])) $uParts[] = 'MOI: ' . $uAgg['months_of_inventory'];
                            if (!empty($uCounts['active_listings'])) $uParts[] = 'Rows: ' . $uCounts['active_listings'];
                        @endphp
                        <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                            {{ implode(' | ', $uParts) }}
                        </div>
                    @elseif($uVerified && ($upload->type === 'vicinity_sales') && !empty($uAgg))
                        {{-- Vicinity Sales compact summary (legacy) --}}
                        @php
                            $uParts = [];
                            if (!empty($uAgg['sold_count'])) $uParts[] = 'Sold: ' . $uAgg['sold_count'];
                            if (!empty($uAgg['median_price'])) $uParts[] = 'Median: R' . number_format($uAgg['median_price'], 0);
                            if (!empty($uAgg['average_price'])) $uParts[] = 'Avg: R' . number_format($uAgg['average_price'], 0);
                            if (!empty($uAgg['dom_p50'])) $uParts[] = 'DOM: ' . $uAgg['dom_p50'];
                            if (!empty($uAgg['price_range_low']) && !empty($uAgg['price_range_high'])) {
                                $uParts[] = 'Range: R' . number_format($uAgg['price_range_low'], 0) . '–R' . number_format($uAgg['price_range_high'], 0);
                            }
                            if (!empty($uCounts['sold_comps'])) $uParts[] = 'Rows: ' . $uCounts['sold_comps'];
                        @endphp
                        <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                            {{ implode(' | ', $uParts) }}
                        </div>
                    @elseif($uVerified && ($upload->type === 'cma') && !empty($uVerified['suggested_band']))
                        {{-- CMA compact summary (legacy) --}}
                        @php
                            $band = $uVerified['suggested_band'];
                        @endphp
                        <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                            Band: R{{ number_format($band['low'], 0) }} – R{{ number_format($band['high'], 0) }}
                            @if(!empty($uVerified['notes']))
                                @foreach($uVerified['notes'] as $note)
                                    | {{ str_replace('suggested_value:', 'Suggested: R', $note) }}
                                @endforeach
                            @endif
                        </div>
                    @elseif($uVerified && !empty($uCounts))
                        {{-- Fallback: show parsed counts --}}
                        <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                            @foreach($uCounts as $pcKey => $pcVal)
                                <span>
                                    <span class="text-gray-400">{{ str_replace('_', ' ', $pcKey) }}:</span>
                                    {{ $pcVal }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @if($uExtStatus === 'failed')
                        <div class="mt-1.5 bg-red-50 border border-red-200 rounded px-2 py-1.5 text-xs text-red-700">
                            No data extracted — {{ $upload->extraction_error ?? 'check PDF format' }}
                        </div>
                    @endif

                    {{-- Override audit info --}}
                    @if($upload->isOverridden())
                        <p class="mt-1 text-xs text-slate-500">
                            Overridden {{ $upload->override_at ? $upload->override_at->format('Y-m-d H:i') : '' }}
                            @if($upload->override_by_user_id)
                                by user #{{ $upload->override_by_user_id }}
                            @endif
                        </p>
                    @endif

                    {{-- Expand: details + diagnostics + override form --}}
                        <details class="mt-1.5">
                            <summary class="text-xs text-[#00d4aa] cursor-pointer hover:underline">
                                {{ $upload->isOverridden() ? 'Edit override' : 'Details' }}
                            </summary>
                            <div class="mt-2 space-y-2">

                                {{-- Extracted fields table (agent-friendly, no JSON) --}}
                                @if($hasDocExtract)
                                    <div class="bg-white border border-gray-100 rounded p-2">
                                        <p class="text-xs font-medium text-gray-500 mb-1">Extracted Fields <span class="text-gray-300">({{ $uVerified['extracted_version'] ?? '' }})</span></p>
                                        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                                            @foreach($uFields as $fk => $fv)
                                                <span class="text-gray-400">{{ $fk }}</span>
                                                <span class="text-gray-700">
                                                    @if(is_numeric($fv) && (int)$fv >= 10000)
                                                        R{{ number_format((int)$fv) }}
                                                    @else
                                                        {{ $fv }}
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Diagnostics (admin, collapsed) --}}
                                <details class="text-xs">
                                    <summary class="text-gray-400 cursor-pointer hover:underline">Diagnostics</summary>
                                    <div class="mt-1 space-y-1">
                                        @if($upload->extraction_json)
                                            <div class="bg-gray-50 rounded p-2 font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                                <pre>{{ json_encode($upload->extraction_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif
                                        @if($upload->text_extracted)
                                            <div class="bg-gray-50 rounded p-2 font-mono text-gray-500 overflow-x-auto max-h-24 overflow-y-auto">
                                                <pre>{{ Illuminate\Support\Str::limit($upload->text_extracted, 500) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                </details>

                                {{-- Override form --}}
                                <form method="POST"
                                      action="{{ route('presentations.uploads.override', [$presentation, $upload]) }}"
                                      class="border border-slate-200 rounded p-2 bg-slate-50">
                                    @csrf
                                    @method('PATCH')
                                    <p class="text-xs font-medium text-slate-600 mb-1.5">Override values</p>
                                    @php
                                        $uOverrideSource = $upload->override_json ?? [];
                                        $uAggPrefill = $uVerified['aggregates'] ?? [];
                                        $uOverride = !empty($uOverrideSource) ? $uOverrideSource : $uAggPrefill;
                                        $uFieldDefs = match($upload->type) {
                                            'suburb_stats' => [
                                                'active_listings_count' => 'Active listings',
                                                'median_price' => 'Median price',
                                                'average_price' => 'Average price',
                                                'dom_p50' => 'DOM p50',
                                                'months_of_inventory' => 'Months of inventory',
                                            ],
                                            'vicinity_sales' => [
                                                'sold_count' => 'Sold count',
                                                'median_price' => 'Median price',
                                                'average_price' => 'Average price',
                                                'dom_p50' => 'DOM p50',
                                            ],
                                            'cma' => [
                                                'suggested_price_low' => 'Price low',
                                                'suggested_price_high' => 'Price high',
                                                'comps_count' => 'Comps count',
                                            ],
                                            default => [
                                                'notes' => 'Notes',
                                            ],
                                        };
                                    @endphp
                                    <div class="grid grid-cols-2 gap-1.5">
                                        @foreach($uFieldDefs as $fKey => $fLabel)
                                            <div>
                                                <label class="block text-xs text-gray-400">{{ $fLabel }}</label>
                                                <input type="text" name="override_data[{{ $fKey }}]"
                                                       placeholder="{{ $fLabel }}"
                                                       value="{{ $uOverride[$fKey] ?? '' }}"
                                                       class="w-full border border-gray-200 rounded px-2 py-1 text-xs">
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="flex gap-2 mt-1.5">
                                        <button type="submit"
                                                class="px-2 py-1 text-white text-xs rounded" style="background:var(--pres-brand)">
                                            Save Override
                                        </button>
                                    </div>
                                </form>
                                @if($upload->isOverridden())
                                    <form method="POST"
                                          action="{{ route('presentations.uploads.override.clear', [$presentation, $upload]) }}"
                                          class="mt-1">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="px-2 py-1 text-xs text-gray-500 hover:text-red-600"
                                                onclick="return confirm('Clear this override?')">
                                            Clear Override
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </details>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- AT-22 R3 — MIC is the single source. Suburb stats (and comps) are
         imported ONCE into Market Intelligence and reused across every
         presentation in the suburb, pulled automatically by suburb. The
         primary path is the MIC importer; the per-presentation upload below
         is the LEGACY fallback only. --}}
    <div class="mt-4 pt-4 border-t border-slate-100">
        <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2.5 mb-3">
            <div class="text-xs font-semibold text-blue-900">Suburb &amp; market data comes from Market Intelligence</div>
            <p class="text-[11px] text-blue-800 mt-0.5">
                Import a suburb / CMA report once into Market Intelligence and it populates this presentation —
                and every other presentation in the suburb — automatically. No per-presentation upload needed.
            </p>
            @if(\Illuminate\Support\Facades\Route::has('market-intelligence.reports.create'))
            <a href="{{ route('market-intelligence.reports.create') }}"
               class="corex-btn-outline text-xs mt-2 inline-flex items-center gap-1">
                Import via Market Intelligence →
            </a>
            @endif
        </div>

    <details class="mt-1">
        <summary class="text-[11px] text-slate-400 cursor-pointer select-none">Legacy: upload a report to this presentation only (not reusable)</summary>
    <form method="POST" action="{{ route('presentations.upload', $presentation) }}"
          enctype="multipart/form-data" class="space-y-2.5 mt-2">
        @csrf
        <div class="flex gap-2 items-center">
            <select name="doc_type" class="pres-select text-xs" required>
                <option value="auto" selected>Auto-detect (Recommended)</option>
                @foreach($docTypeLabels as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="file" name="documents[]" multiple accept=".pdf"
                   class="pres-input flex-1 text-xs" required>
            <button type="submit"
                    class="corex-btn-outline text-xs shrink-0">
                Upload
            </button>
        </div>
        <p class="text-[11px] text-slate-400">Legacy per-presentation upload. Suburb data should be imported via Market Intelligence (above) so it's reusable. CMA Info PDFs are auto-detected by filename.</p>
        @error('doc_type')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('documents')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('documents.*')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </form>
    </details>

    </div>

    {{-- Document Library button (feature-flagged) --}}
    @if(config('features.document_library_v1'))
        <div class="mt-4 pt-4 border-t border-slate-100">
            <a href="{{ route('documents.library.index', ['presentation_id' => $presentation->id, 'return' => url()->current() . '#documents']) }}"
               class="corex-btn-primary text-xs">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                Document Library
            </a>
        </div>

        {{-- Attached from Library --}}
        @php
            $libraryDocs = $presentation->documentLibraryItems()->with('uploader')->get();
        @endphp
        @if($libraryDocs->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-slate-100">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-2.5">Attached from Library</h3>
                <ul class="space-y-2 text-xs text-slate-600">
                    @foreach($libraryDocs as $libDoc)
                        <li class="pres-doc-row flex items-center justify-between">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-slate-400 shrink-0">&#128206;</span>
                                <span class="truncate font-medium">{{ $libDoc->title ?? $libDoc->original_name }}</span>
                                <span class="pres-badge bg-emerald-50 text-[#00d4aa]">
                                    {{ $libDoc->doc_type }}
                                </span>
                                <span class="text-slate-400">{{ $libDoc->uploader->name ?? '' }}</span>
                                <span class="text-slate-400">{{ $libDoc->pivot->created_at ? \Carbon\Carbon::parse($libDoc->pivot->created_at)->format('d M Y') : '' }}</span>
                            </div>
                            <a href="{{ route('documents.library.download', $libDoc) }}"
                               class="text-[#00d4aa] hover:text-[#0f172a] font-semibold shrink-0 ml-2">
                                Download
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
</div>

{{-- ── PHASE 6: Send-to-Recipient modal ──────────────────────────────── --}}
<div id="send-presentation-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--surface);border-radius:8px;max-width:780px;width:100%;max-height:92vh;overflow:auto;padding:24px;box-shadow:0 12px 48px rgba(0,0,0,0.22);">
        <div class="flex items-center justify-between mb-3">
            <h3 style="font-size:1.0625rem;font-weight:600;margin:0;">Send presentation: {{ \Illuminate\Support\Str::limit($presentation->property_address ?: ('Presentation #' . $presentation->id), 60) }}</h3>
            <button type="button" onclick="document.getElementById('send-presentation-modal').style.display='none'"
                    style="background:none;border:0;font-size:1.25rem;color:var(--text-muted);cursor:pointer;">×</button>
        </div>

        {{-- Step indicators --}}
        <div style="display:flex;gap:6px;margin-bottom:16px;font-size:0.6875rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">
            <span id="step-tab-1" style="padding:4px 10px;border-radius:999px;background:var(--brand-button);color:#fff;font-weight:600;">1 · Pick</span>
            <span id="step-tab-2" style="padding:4px 10px;border-radius:999px;background:var(--surface-2);font-weight:500;">2 · Preview</span>
            <span id="step-tab-3" style="padding:4px 10px;border-radius:999px;background:var(--surface-2);font-weight:500;">3 · Send</span>
        </div>

        {{-- ─ Step 1: Pick Recipients ─ --}}
        <div id="send-step-1">
            <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:8px;">
                Select recipients linked to this property, or add an ad-hoc address. Each recipient gets their own unique tracked link.
            </div>
            <div style="border:1px solid var(--border);border-radius:6px;max-height:240px;overflow-y:auto;background:var(--surface-2);">
                @forelse($contactsForLink as $c)
                    <label style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--border);cursor:pointer;">
                        <input type="checkbox" data-recip-contact-id="{{ $c->id }}"
                               data-recip-name="{{ trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) }}"
                               data-recip-first="{{ $c->first_name }}"
                               data-recip-email="{{ $c->email }}"
                               data-recip-phone="{{ $c->phone }}"
                               data-recip-role="{{ $c->pivot->role ?? '' }}"
                               class="recip-check" checked>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.875rem;color:var(--text-primary);font-weight:500;">
                                {{ trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ('Contact #' . $c->id) }}
                                @if($c->pivot->role)<span class="ds-badge" style="margin-left:6px;background:var(--surface);color:var(--text-secondary);font-size:0.6875rem;">{{ ucfirst($c->pivot->role) }}</span>@endif
                            </div>
                            <div style="font-size:0.6875rem;color:var(--text-muted);">{{ $c->email }}{{ $c->email && $c->phone ? ' · ' : '' }}{{ $c->phone }}</div>
                        </div>
                    </label>
                @empty
                    <div style="padding:14px;color:var(--text-muted);font-size:0.8125rem;text-align:center;">No contacts linked to this property yet. Add an ad-hoc recipient below.</div>
                @endforelse
            </div>

            {{-- Ad-hoc recipient --}}
            <details style="margin-top:10px;">
                <summary style="font-size:0.75rem;color:var(--brand-button);cursor:pointer;">+ Add ad-hoc recipient</summary>
                <div style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <input type="text" id="adhoc-name" placeholder="Name" style="padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    <input type="email" id="adhoc-email" placeholder="Email" style="padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    <input type="tel" id="adhoc-phone" placeholder="Phone" style="padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    <button type="button" onclick="window.__corexSendAddAdhoc()" class="corex-btn-outline corex-btn-xs">Add</button>
                </div>
                <div id="adhoc-list" style="margin-top:6px;font-size:0.75rem;color:var(--text-muted);"></div>
            </details>

            {{-- Default channel + mode --}}
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label style="font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;">Default channel</label>
                    <div style="display:flex;flex-direction:column;gap:3px;margin-top:4px;font-size:0.8125rem;">
                        <label><input type="radio" name="default_channel" value="email" {{ $sendDefaults['channel'] === 'email' ? 'checked' : '' }}> Email</label>
                        <label><input type="radio" name="default_channel" value="whatsapp" {{ $sendDefaults['channel'] === 'whatsapp' ? 'checked' : '' }}> WhatsApp</label>
                        <label><input type="radio" name="default_channel" value="copy" {{ $sendDefaults['channel'] === 'copy' ? 'checked' : '' }}> Copy URL only</label>
                    </div>
                </div>
                <div>
                    <label style="font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;">Default mode</label>
                    <div style="display:flex;flex-direction:column;gap:3px;margin-top:4px;font-size:0.8125rem;">
                        <label><input type="radio" name="default_mode" value="full" {{ $sendDefaults['mode'] === 'full' ? 'checked' : '' }}> Full presentation</label>
                        <label><input type="radio" name="default_mode" value="teaser" {{ $sendDefaults['mode'] === 'teaser' ? 'checked' : '' }}> Teaser (lead capture)</label>
                    </div>
                </div>
            </div>
            <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:6px;">You can override mode and channel per-recipient on the next step.</div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button type="button" onclick="document.getElementById('send-presentation-modal').style.display='none'" class="corex-btn-outline">Cancel</button>
                <button type="button" id="send-step-1-next" class="corex-btn-primary">Continue →</button>
            </div>
        </div>

        {{-- ─ Step 2: Preview & Customise ─ --}}
        <div id="send-step-2" style="display:none;">
            <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:8px;">
                Review per-recipient. Customise the message templates below — placeholders auto-substitute per recipient on send.
            </div>
            <div id="recipient-table-wrap" style="border:1px solid var(--border);border-radius:6px;overflow:hidden;font-size:0.8125rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.625rem;text-transform:uppercase;letter-spacing:0.04em;">
                        <th style="padding:6px 8px;text-align:left;">Recipient</th>
                        <th style="padding:6px 8px;text-align:left;">Channel</th>
                        <th style="padding:6px 8px;text-align:left;">Mode</th>
                        <th style="padding:6px 8px;text-align:left;">Status</th>
                    </tr></thead>
                    <tbody id="recipient-table-body"></tbody>
                </table>
            </div>

            <details style="margin-top:14px;" id="template-details">
                <summary style="font-size:0.75rem;color:var(--text-secondary);cursor:pointer;font-weight:600;">Customise message templates</summary>
                <div style="margin-top:10px;">
                    <label style="font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;">Email subject</label>
                    <input type="text" id="send-subject" maxlength="300" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;margin-top:4px;margin-bottom:8px;"
                           value="{{ optional(\App\Models\Agency::find($presentation->agency_id))->email_default_subject_template }}">
                    <label style="font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;">Email body</label>
                    <textarea id="send-body" rows="8" maxlength="8000" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;margin-top:4px;font-family:inherit;">{{ optional(\App\Models\Agency::find($presentation->agency_id))->email_default_body_template }}</textarea>
                    <div style="font-size:0.625rem;color:var(--text-muted);margin-top:4px;">
                        Placeholders: <code>{recipient_first_name}</code> <code>{property_address}</code> <code>{agent_name}</code> <code>{agency_name}</code> <code>{presentation_url}</code>
                    </div>
                </div>
            </details>

            <div style="display:flex;gap:8px;justify-content:space-between;margin-top:16px;">
                <button type="button" id="send-step-2-back" class="corex-btn-outline">← Back</button>
                <button type="button" id="send-step-2-send" class="corex-btn-primary">Send Now →</button>
            </div>
        </div>

        {{-- ─ Step 3: Send / Results ─ --}}
        <div id="send-step-3" style="display:none;">
            <div id="send-progress" style="font-size:0.8125rem;color:var(--text-secondary);"></div>
            <div id="send-results" style="margin-top:14px;"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button type="button" onclick="document.getElementById('send-presentation-modal').style.display='none';window.location.reload();" class="corex-btn-primary">Done</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    const PREVIEW_URL = @json(route('presentations.deliveries.preview', $presentation));
    const SEND_URL    = @json(route('presentations.deliveries.send', $presentation));
    const CSRF        = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const adhocList   = [];
    let collected     = [];

    function $(id) { return document.getElementById(id); }
    function show(id) { $(id).style.display = 'block'; }
    function hide(id) { $(id).style.display = 'none'; }
    function activeTab(n) {
        ['step-tab-1','step-tab-2','step-tab-3'].forEach((t,i) => {
            const el = $(t);
            const active = (i+1) === n;
            el.style.background = active ? 'var(--brand-button)' : 'var(--surface-2)';
            el.style.color      = active ? '#fff' : '';
            el.style.fontWeight = active ? '600' : '500';
        });
    }

    window.__corexSendInit = function () {
        adhocList.length = 0;
        $('adhoc-list').innerHTML = '';
        document.querySelectorAll('.recip-check').forEach(cb => cb.checked = true);
        show('send-step-1'); hide('send-step-2'); hide('send-step-3');
        activeTab(1);
    };

    window.__corexSendAddAdhoc = function () {
        const name  = $('adhoc-name').value.trim();
        const email = $('adhoc-email').value.trim();
        const phone = $('adhoc-phone').value.trim();
        if (!name || (!email && !phone)) {
            alert('Provide name + (email or phone).'); return;
        }
        adhocList.push({ name, email, phone });
        $('adhoc-name').value = $('adhoc-email').value = $('adhoc-phone').value = '';
        renderAdhocList();
    };
    function renderAdhocList() {
        $('adhoc-list').innerHTML = adhocList.map((r, i) =>
            '<div>+ ' + escHtml(r.name) + (r.email ? ' · ' + escHtml(r.email) : '') + (r.phone ? ' · ' + escHtml(r.phone) : '')
            + ' <button type="button" data-rm="' + i + '" style="margin-left:6px;background:none;border:0;color:#dc2626;cursor:pointer;font-size:0.6875rem;">remove</button></div>'
        ).join('');
        $('adhoc-list').querySelectorAll('[data-rm]').forEach(btn => btn.addEventListener('click', () => {
            adhocList.splice(parseInt(btn.dataset.rm, 10), 1);
            renderAdhocList();
        }));
    }

    function collectRecipients() {
        const picked = Array.from(document.querySelectorAll('.recip-check:checked')).map(cb => ({
            contact_id: parseInt(cb.dataset.recipContactId, 10),
            name:       cb.dataset.recipName,
            first_name: cb.dataset.recipFirst || cb.dataset.recipName.split(' ')[0],
            email:      cb.dataset.recipEmail || null,
            phone:      cb.dataset.recipPhone || null,
        }));
        return picked.concat(adhocList.map(r => ({
            name: r.name, first_name: r.name.split(' ')[0],
            email: r.email || null, phone: r.phone || null,
        })));
    }

    $('send-step-1-next').addEventListener('click', async () => {
        const recipients = collectRecipients();
        if (!recipients.length) { alert('Pick at least one recipient.'); return; }
        const defaultChannel = document.querySelector('[name=default_channel]:checked').value;
        const defaultMode    = document.querySelector('[name=default_mode]:checked').value;
        recipients.forEach(r => { r.channel = defaultChannel; r.mode = defaultMode; });
        collected = recipients;
        await renderStep2();
        hide('send-step-1'); show('send-step-2'); activeTab(2);
    });

    async function renderStep2() {
        const body = $('recipient-table-body');
        body.innerHTML = '';
        collected.forEach((r, i) => {
            body.insertAdjacentHTML('beforeend',
                '<tr style="border-top:1px solid var(--border);">'
                + '<td style="padding:6px 8px;"><div style="font-weight:500;">' + escHtml(r.name) + '</div>'
                +   '<div style="font-size:0.6875rem;color:var(--text-muted);">' + escHtml(r.email || r.phone || '') + '</div></td>'
                + '<td style="padding:6px 8px;"><select data-row="' + i + '" data-field="channel" style="padding:3px 6px;border:1px solid var(--border);border-radius:3px;font-size:0.75rem;">'
                +   '<option value="email"'    + (r.channel === 'email'    ? ' selected' : '') + '>Email</option>'
                +   '<option value="whatsapp"' + (r.channel === 'whatsapp' ? ' selected' : '') + '>WhatsApp</option>'
                +   '<option value="copy"'     + (r.channel === 'copy'     ? ' selected' : '') + '>Copy URL</option>'
                + '</select></td>'
                + '<td style="padding:6px 8px;"><select data-row="' + i + '" data-field="mode" style="padding:3px 6px;border:1px solid var(--border);border-radius:3px;font-size:0.75rem;">'
                +   '<option value="full"'   + (r.mode === 'full'   ? ' selected' : '') + '>Full</option>'
                +   '<option value="teaser"' + (r.mode === 'teaser' ? ' selected' : '') + '>Teaser</option>'
                + '</select></td>'
                + '<td style="padding:6px 8px;" data-status="' + i + '"><span style="color:var(--text-muted);">Ready</span></td>'
                + '</tr>');
        });
        body.querySelectorAll('select').forEach(sel => sel.addEventListener('change', () => {
            const idx = parseInt(sel.dataset.row, 10);
            collected[idx][sel.dataset.field] = sel.value;
            previewValidate();
        }));
        previewValidate();
    }
    async function previewValidate() {
        try {
            const resp = await fetch(PREVIEW_URL, {
                method: 'POST',
                headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF },
                body: JSON.stringify({
                    recipients: collected,
                    subject: $('send-subject').value,
                    body:    $('send-body').value,
                }),
                credentials: 'same-origin',
            });
            const j = await resp.json();
            const errors = j.errors || {};
            collected.forEach((r, i) => {
                const cell = document.querySelector('[data-status="' + i + '"]');
                if (errors[i]) {
                    cell.innerHTML = '<span style="color:#dc2626;font-size:0.6875rem;">' + escHtml(errors[i]) + '</span>';
                } else {
                    cell.innerHTML = '<span style="color:var(--ds-green,#16a34a);">Ready</span>';
                }
            });
        } catch (e) { /* silent */ }
    }
    $('send-step-2-back').addEventListener('click', () => {
        hide('send-step-2'); show('send-step-1'); activeTab(1);
    });
    $('send-step-2-send').addEventListener('click', async () => {
        hide('send-step-2'); show('send-step-3'); activeTab(3);
        $('send-progress').textContent = 'Sending to ' + collected.length + ' recipient' + (collected.length === 1 ? '' : 's') + '…';
        try {
            const resp = await fetch(SEND_URL, {
                method: 'POST',
                headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF },
                body: JSON.stringify({
                    recipients: collected,
                    subject: $('send-subject').value,
                    body:    $('send-body').value,
                }),
                credentials: 'same-origin',
            });
            const j = await resp.json();
            if (!resp.ok || !j.ok) {
                $('send-progress').innerHTML = '<span style="color:#dc2626;">Send failed: ' + escHtml(j.errors ? JSON.stringify(j.errors) : 'unknown error') + '</span>';
                return;
            }
            renderResults(j.results, j.summary);
        } catch (e) {
            $('send-progress').innerHTML = '<span style="color:#dc2626;">Network error.</span>';
        }
    });

    function renderResults(results, summary) {
        const lines = results.map(r => {
            const emoji = r.status === 'failed' ? '✗' : '✓';
            const colour = r.status === 'failed' ? '#dc2626' : 'var(--ds-green,#16a34a)';
            let row = '<div style="padding:8px 10px;border:1px solid var(--border);border-radius:4px;margin-bottom:6px;font-size:0.8125rem;">'
                + '<span style="color:' + colour + ';font-weight:600;">' + emoji + '</span> '
                + escHtml(r.recipient) + ' — ' + r.channel + ' / ' + r.mode + ' · ' + r.status;
            if (r.whatsapp_url) {
                const waRedirect = @json(route('corex.deliveries.whatsapp-redirect', '__ID__')).replace('__ID__', r.delivery_id);
                row += ' · <a href="' + waRedirect + '" target="_blank" style="color:var(--brand-button);">Open WhatsApp →</a>';
            }
            if (r.channel === 'copy' && r.snapshot_url) {
                row += ' · <button type="button" onclick="navigator.clipboard.writeText(\'' + r.snapshot_url + '\').then(()=>this.textContent=\'Copied!\')" class="corex-btn-outline corex-btn-xs">Copy URL</button>';
            }
            if (r.error) row += '<div style="color:#dc2626;font-size:0.6875rem;margin-top:2px;">' + escHtml(r.error) + '</div>';
            row += '</div>';
            return row;
        }).join('');
        $('send-progress').innerHTML = '<strong>Sent ' + (summary.by_status.sent || 0) + ' of ' + summary.total + '</strong>'
            + (summary.whatsapp_links > 0 ? ' · ' + summary.whatsapp_links + ' WhatsApp link' + (summary.whatsapp_links === 1 ? '' : 's') + ' ready to open.' : '');
        $('send-results').innerHTML = lines;
    }

    function escHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
})();
</script>

{{-- ── PHASE 3: AI SUMMARY ───────────────────────────────────────────────── --}}
@php
    $aiVariants    = \App\Models\PresentationAiVariant::where('is_active', true)->orderBy('sort_order')->get();
    $currentSummary = $latestVersion?->ai_summary_text;
    $summaryStale   = $latestVersion && $latestSnapshot && $latestVersion->ai_summary_generated_at
        && $latestSnapshot->created_at && $latestVersion->ai_summary_generated_at->lt($latestSnapshot->created_at);
    $summaryHistory = $latestVersion
        ? \App\Models\PresentationAiSummaryHistory::where('presentation_version_id', $latestVersion->id)
            ->with('variant:id,key,display_name')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
        : collect();
@endphp
<div class="ds-status-card mb-8" id="ai-summary">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="ds-section-header" style="margin-bottom:0">Executive Summary <span style="font-size:0.6875rem;color:var(--text-muted);font-weight:500;text-transform:none;letter-spacing:0;">(AI-generated, agent-reviewable)</span></h2>
            <p class="text-xs" style="color:var(--text-muted);margin:2px 0 0 0;">
                Choose a tone, generate the narrative, edit if needed, then accept before compiling the pack.
            </p>
        </div>
        @if($currentSummary)
            <span class="ds-badge ds-badge-success">Summary set</span>
        @elseif($latestVersion)
            <span class="ds-badge ds-badge-warning">No summary yet</span>
        @endif
    </div>

    @if(!$latestVersion)
        <div style="padding:14px;background:var(--surface-2);border:1px dashed var(--border);border-radius:6px;font-size:0.8125rem;color:var(--text-muted);">
            Run analysis first — the AI summary needs the compiled analytics snapshot.
        </div>
    @else
        @if($summaryStale)
            <div style="margin-bottom:10px;padding:8px 12px;background:color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent);border-left:3px solid var(--ds-amber, #d97706);border-radius:4px;font-size:0.8125rem;">
                Analysis was re-run after this summary was generated — consider regenerating.
            </div>
        @endif

        <div style="display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:start;margin-bottom:10px;">
            <div>
                <label style="display:block;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Variant</label>
                @if($aiVariants->isEmpty())
                    {{-- Bug-class fallback: if the presentation_ai_variants table is
                         empty, the dropdown previously rendered as a blank control
                         and Generate failed with "Generation failed: unknown".
                         Now we show a disabled control + admin-actionable copy
                         and disable Generate, so the agent sees the actual cause. --}}
                    <select id="ai-variant-select" disabled
                            style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;background:var(--surface-2);font-size:0.875rem;color:var(--text-muted);">
                        <option>No variants configured</option>
                    </select>
                    <div id="ai-variant-desc" style="font-size:0.6875rem;color:var(--ds-amber,#d97706);margin-top:4px;line-height:1.4;">
                        Summary variants haven't been seeded. Contact admin.
                    </div>
                    <button type="button" id="ai-generate-btn" class="corex-btn-primary corex-btn-xs" disabled
                            style="margin-top:10px;width:100%;opacity:0.5;cursor:not-allowed;">
                        Generate Summary
                    </button>
                @else
                    <select id="ai-variant-select" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;background:var(--surface);font-size:0.875rem;">
                        @foreach($aiVariants as $v)
                            <option value="{{ $v->id }}" data-desc="{{ $v->description }}" {{ $latestVersion->ai_variant_id === $v->id ? 'selected' : '' }}>
                                {{ $v->display_name }}
                            </option>
                        @endforeach
                    </select>
                    <div id="ai-variant-desc" style="font-size:0.6875rem;color:var(--text-muted);margin-top:4px;line-height:1.4;"></div>
                    <button type="button" id="ai-generate-btn" class="corex-btn-primary corex-btn-xs" style="margin-top:10px;width:100%;">
                        {{ $currentSummary ? 'Regenerate' : 'Generate Summary' }}
                    </button>
                @endif
            </div>
            <div>
                <label style="display:block;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Summary text (editable)</label>
                <textarea id="ai-summary-text" rows="9" maxlength="5000"
                          style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;background:var(--surface);font-size:0.875rem;font-family:inherit;line-height:1.55;">{{ $currentSummary }}</textarea>
                <div style="display:flex;justify-content:space-between;font-size:0.6875rem;color:var(--text-muted);margin-top:4px;">
                    <span id="ai-summary-meta">
                        @if($latestVersion->ai_summary_generated_at)
                            Generated {{ $latestVersion->ai_summary_generated_at->diffForHumans() }}
                            @if($latestVersion->ai_summary_edited_by_agent) · <strong>edited by agent</strong>@endif
                        @endif
                    </span>
                    <span><span id="ai-summary-word-count">0</span> words · <span id="ai-summary-char-count">0</span>/5000 chars</span>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
                    <button type="button" id="ai-accept-btn" class="corex-btn-primary corex-btn-xs" disabled>Accept &amp; Save</button>
                </div>
            </div>
        </div>

        @if($summaryHistory->isNotEmpty())
            <details style="margin-top:10px;">
                <summary style="font-size:0.6875rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;cursor:pointer;">Previous attempts ({{ $summaryHistory->count() }})</summary>
                <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px;">
                    @foreach($summaryHistory as $h)
                        <div style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.75rem;">
                            <strong>{{ $h->variant->display_name ?? '—' }}</strong>
                            · {{ $h->generated_at->diffForHumans() }}
                            @if($h->was_saved)<span class="ds-badge ds-badge-success" style="margin-left:6px;">Saved</span>@endif
                            @if($h->failure_reason)<span style="color:#dc2626;margin-left:6px;">FAILED</span>@endif
                            @if($h->tokens_used)<span style="color:var(--text-muted);"> · {{ $h->tokens_used }} tokens · {{ $h->latency_ms }}ms</span>@endif
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    @endif
</div>

<script>
(function () {
    'use strict';
    const GENERATE_URL = @json(route('presentations.ai-summary.generate', $presentation));
    const ACCEPT_URL   = @json(route('presentations.ai-summary.accept',   $presentation));
    const CSRF         = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let currentHistoryId = null;
    const textArea  = document.getElementById('ai-summary-text');
    const acceptBtn = document.getElementById('ai-accept-btn');
    const generateBtn = document.getElementById('ai-generate-btn');
    const variantSelect = document.getElementById('ai-variant-select');
    const descEl  = document.getElementById('ai-variant-desc');
    const wordEl  = document.getElementById('ai-summary-word-count');
    const charEl  = document.getElementById('ai-summary-char-count');
    const metaEl  = document.getElementById('ai-summary-meta');

    if (!textArea) return; // panel hidden when no version

    function updateCounts() {
        const t = textArea.value || '';
        wordEl.textContent = (t.trim().match(/\S+/g) || []).length;
        charEl.textContent = t.length;
        acceptBtn.disabled = !(currentHistoryId && t.trim().length >= 50);
    }
    function refreshDesc() {
        const opt = variantSelect.options[variantSelect.selectedIndex];
        descEl.textContent = opt?.dataset.desc || '';
    }
    variantSelect.addEventListener('change', refreshDesc);
    textArea.addEventListener('input', updateCounts);
    refreshDesc();
    updateCounts();

    generateBtn.addEventListener('click', async () => {
        generateBtn.disabled = true;
        generateBtn.textContent = 'Generating…';
        try {
            const resp = await fetch(GENERATE_URL, {
                method: 'POST',
                headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF },
                body: JSON.stringify({ variant_id: parseInt(variantSelect.value, 10) }),
                credentials: 'same-origin',
            });
            const j = await resp.json();
            if (resp.ok && j.ok) {
                textArea.value = j.text;
                currentHistoryId = j.history_id;
                metaEl.innerHTML = 'Just generated · ' + (j.tokens_used || 0) + ' tokens · ' + (j.latency_ms || 0) + 'ms · ' + (j.model || '—') + (j.from_cache ? ' · <strong>cached</strong>' : '');
                updateCounts();
            } else {
                alert('Generation failed: ' + (j.error || 'unknown'));
            }
        } catch (e) {
            alert('Network error: ' + e.message);
        } finally {
            generateBtn.disabled = false;
            generateBtn.textContent = 'Regenerate';
        }
    });

    acceptBtn.addEventListener('click', async () => {
        if (!currentHistoryId) return;
        acceptBtn.disabled = true;
        acceptBtn.textContent = 'Saving…';
        try {
            const resp = await fetch(ACCEPT_URL, {
                method: 'POST',
                headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF },
                body: JSON.stringify({ history_id: currentHistoryId, edited_text: textArea.value }),
                credentials: 'same-origin',
            });
            const j = await resp.json();
            if (resp.ok && j.ok) {
                window.location.reload();
            } else {
                alert('Save failed: ' + (j.error || 'unknown'));
            }
        } catch (e) {
            alert('Network error: ' + e.message);
        } finally {
            acceptBtn.disabled = false;
            acceptBtn.textContent = 'Accept & Save';
        }
    });
})();
</script>

{{-- Pack Readiness checklist (P16) removed — the auto-presentation flow
     guarantees data capture, so the checklist could only ever say "ready"
     (redundant) or "not ready" (which would contradict the flow itself).
     The real compile gate is now (compiled snapshot + AI summary), enforced
     on the Compile Pack CTA above. The PresentationReadinessService class
     is intentionally retained — it's still consumed by analysis.blade.php
     and as a no-op gate inside PresentationController::compile(). --}}

{{-- ── POWER PANEL (UI1) ──────────────────────────────────────────────── --}}
@if($powerPanel)
<div class="ds-status-card mb-8">
    <div class="flex items-center justify-between mb-3">
        <h2 class="ds-section-header" style="margin-bottom:0">Power Panel</h2>
        <span class="text-xs text-slate-400 font-medium">Snapshot {{ $powerPanel['snapshot_at']->format('Y-m-d H:i') }}</span>
    </div>
    <div>

    {{-- Row 1: Probability + Confidence + PPI --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 mb-5">
        {{-- P30 --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">P30</p>
            <p class="ds-value-lg {{ ($powerPanel['p30'] ?? 0) >= 0.5 ? 'text-[#00d4aa]' : 'text-slate-800' }}">
                @if($powerPanel['p30'] !== null)
                    {{ number_format($powerPanel['p30'] * 100, 0) }}%
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- P60 --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">P60</p>
            <p class="ds-value-lg {{ ($powerPanel['p60'] ?? 0) >= 0.5 ? 'text-[#00d4aa]' : 'text-slate-800' }}">
                @if($powerPanel['p60'] !== null)
                    {{ number_format($powerPanel['p60'] * 100, 0) }}%
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- P90 --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">P90</p>
            <p class="ds-value-lg {{ ($powerPanel['p90'] ?? 0) >= 0.65 ? 'text-[#00d4aa]' : 'text-slate-800' }}">
                @if($powerPanel['p90'] !== null)
                    {{ number_format($powerPanel['p90'] * 100, 0) }}%
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- Expected Days --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">Exp. Days</p>
            <p class="ds-value-lg text-slate-800">
                @if($powerPanel['expected_days'] !== null)
                    {{ $powerPanel['expected_days'] }}
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- Confidence --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">Confidence</p>
            @if($powerPanel['confidence'])
                @php
                    $confScore = $powerPanel['confidence']['confidence_score'] ?? 0;
                    $confGrade = $powerPanel['confidence']['confidence_grade'] ?? '-';
                    $confColor = match($confGrade) {
                        'A' => 'text-[#00d4aa]',
                        'B' => 'text-[#00d4aa]',
                        'C' => 'text-slate-500',
                        default => 'text-slate-400',
                    };
                @endphp
                <p class="ds-value-lg {{ $confColor }}">{{ $confScore }} <span class="text-xs font-medium">({{ $confGrade }})</span></p>
            @else
                <p class="ds-value-lg text-slate-300">--</p>
            @endif
        </div>
        {{-- PPI --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">PPI</p>
            @if($powerPanel['ppi'])
                @php
                    $ppiScore = $powerPanel['ppi']['ppi_score'] ?? 0;
                    $ppiLabel = $powerPanel['ppi']['ppi_label'] ?? '-';
                    $ppiColor = match($ppiLabel) {
                        'Strong' => 'text-[#00d4aa]',
                        'Balanced' => 'text-slate-600',
                        default => 'text-slate-400',
                    };
                @endphp
                <p class="ds-value-lg {{ $ppiColor }}">{{ $ppiScore }} <span class="text-xs font-medium">({{ $ppiLabel }})</span></p>
            @else
                <p class="ds-value-lg text-slate-300">--</p>
            @endif
        </div>
    </div>

    {{-- Row 2: Competitive Stock + Holding Cost --}}
    @php
        $compStock = $powerPanel['competitive_stock'] ?? null;
        $holdingCost = $powerPanel['holding_cost'] ?? null;
    @endphp
    @if($compStock || $holdingCost)
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-5 pt-4 border-t border-slate-100">
        @if($compStock)
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Active Stock</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">{{ $compStock['total_active_stock'] ?? '--' }}</p>
            </div>
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Below Subject</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">{{ $compStock['below_subject_count'] ?? '--' }}</p>
            </div>
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Above Subject</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">{{ $compStock['above_subject_count'] ?? '--' }}</p>
            </div>
        @endif
        @if($holdingCost)
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Monthly Hold Cost</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">R{{ number_format($holdingCost['monthly_total'] ?? 0, 0) }}</p>
            </div>
        @endif
    </div>
    @endif

    {{-- Row 3: Explainability --}}
    @if($powerPanel['explainability'])
        @php $explain = $powerPanel['explainability']; @endphp
        <div class="pt-4 border-t border-slate-100">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Key Drivers --}}
                @if(!empty($explain['key_drivers']))
                    <div class="bg-emerald-50 rounded-lg p-3">
                        <p class="text-[11px] font-semibold text-[#0f172a] mb-2 uppercase tracking-widest">Key Drivers</p>
                        <ul class="space-y-1.5">
                            @foreach($explain['key_drivers'] as $driver)
                                <li class="text-xs text-slate-600 flex items-start gap-2">
                                    <span class="text-[#00d4aa] mt-0.5 shrink-0 font-bold">+</span>
                                    {{ $driver }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                {{-- Risk Factors --}}
                @if(!empty($explain['risk_factors']))
                    <div class="bg-amber-50 rounded-lg p-3">
                        <p class="text-[11px] font-semibold text-amber-700 mb-2 uppercase tracking-widest">Risk Factors</p>
                        <ul class="space-y-1.5">
                            @foreach($explain['risk_factors'] as $risk)
                                <li class="text-xs text-slate-600 flex items-start gap-2">
                                    <span class="text-amber-500 mt-0.5 shrink-0 font-bold">!</span>
                                    {{ $risk }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            {{-- Position summary --}}
            @if(!empty($explain['position_summary']))
                <p class="mt-3 text-xs text-slate-500 italic bg-slate-50 rounded-lg px-3 py-2">{{ $explain['position_summary'] }}</p>
            @endif
        </div>
    @endif
    </div>
</div>
@endif

{{-- ═══════ BUYER DEMAND INTELLIGENCE (Module 13 · AT-74 honest split) ═══════ --}}
@php
    $bdActive    = (int) ($buyerDemand['active']['count'] ?? 0);
    $bdHistoric  = (int) ($buyerDemand['historic']['count'] ?? 0);
    $bdAreaCount = (int) ($buyerDemand['area']['area_buyers'] ?? 0);
    $bdPreapp    = (int) ($buyerDemand['area']['preapproved_count'] ?? 0);
    $bdSuburb    = $buyerDemand['area']['suburb'] ?? null;
    $bdShow      = !empty($buyerDemand) && ($bdActive > 0 || $bdHistoric > 0 || $bdAreaCount > 0 || $bdPreapp > 0);
@endphp
@if($bdShow)
<div class="ds-status-card mb-8">
    <h2 class="ds-section-header mb-4">
        <span class="flex items-center gap-2">
            <svg class="w-5 h-5" style="color:#10b981;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            Buyer Demand
        </span>
    </h2>

    {{-- ── ACTIVE buyers matched to THIS property (canonical %) ── --}}
    @if($bdActive > 0)
    <div class="mb-5">
        <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary, #1e293b);">Active buyers matched to your property</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted, #94a3b8);">{{ $bdActive }} {{ \Illuminate\Support\Str::plural('buyer', $bdActive) }} actively searching whose criteria match this property.</p>
        @if(!empty($buyerDemand['active']['anonymised_buyers']))
        <div class="space-y-2">
            @foreach($buyerDemand['active']['anonymised_buyers'] as $buyer)
            <div class="flex items-center justify-between py-2 px-3 rounded-lg" style="background: var(--surface-2, #f1f5f9); border: 1px solid var(--border, #e2e8f0);">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold" style="background: rgba(16,185,129,0.15); color: #10b981;">{{ $buyer['label'][strlen($buyer['label'])-1] }}</div>
                    <span class="text-sm font-medium" style="color: var(--text-primary, #1e293b);">{{ $buyer['label'] }}</span>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                      style="{{ $buyer['tier'] === 'strong' ? 'background:rgba(16,185,129,0.15);color:#10b981;' : ($buyer['tier'] === 'good' ? 'background:rgba(14,165,233,0.15);color:#0ea5e9;' : 'background:rgba(245,158,11,0.15);color:#f59e0b;') }}">
                    {{ $buyer['score'] }}% · {{ ucfirst($buyer['tier'] ?? 'match') }}
                </span>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    {{-- ── HISTORIC interest in THIS property ── --}}
    @if($bdHistoric > 0)
    <div class="mb-5">
        <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary, #1e293b);">Past interest in your property</h3>
        <p class="text-xs" style="color:var(--text-muted, #94a3b8);">{{ $bdHistoric }} {{ \Illuminate\Support\Str::plural('buyer', $bdHistoric) }} previously viewed or engaged this property but {{ $bdHistoric === 1 ? 'is' : 'are' }} not actively searching right now.</p>
    </div>
    @endif

    {{-- ── WIDER AREA demand (explicitly labelled — NEVER "for this property") ── --}}
    @if($bdAreaCount > 0 || $bdPreapp > 0)
    <div class="mb-2 pt-3" style="border-top: 1px dashed var(--border, #e2e8f0);">
        <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary, #1e293b);">Wider market demand{{ $bdSuburb ? ' in ' . $bdSuburb : '' }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @if($bdAreaCount > 0)
            <div class="rounded-lg p-4 text-center" style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.2);">
                <div class="text-2xl font-bold" style="color: #f59e0b;">{{ $bdAreaCount }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);">buyers active in {{ $bdSuburb ?? 'this area' }}</div>
            </div>
            @endif
            @if($bdPreapp > 0)
            <div class="rounded-lg p-4 text-center" style="background: rgba(14,165,233,0.08); border: 1px solid rgba(14,165,233,0.2);">
                <div class="text-2xl font-bold" style="color: #0ea5e9;">{{ $bdPreapp }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);">pre-approved buyers in your price band (agency-wide)</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <p class="text-[10px] mt-4" style="color: var(--text-muted, #94a3b8);">Data as of {{ now()->format('d M Y') }}. Buyer identities protected per POPIA requirements.</p>
</div>
@endif

<div class="grid grid-cols-1 gap-6 md:grid-cols-2 mb-8">

    {{-- LAST ANALYSIS SUMMARY --}}
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Last Analysis</h2>
        <div>
        @if($lastSummary)
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between items-center py-1.5 border-b border-slate-50">
                    <dt class="text-slate-400 text-xs font-medium">60-day sale probability</dt>
                    <dd class="font-bold text-slate-800">
                        @if(isset($lastSummary['p60']) && $lastSummary['p60'] !== null)
                            {{ number_format($lastSummary['p60'] * 100, 0) }}%
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between items-center py-1.5 border-b border-slate-50">
                    <dt class="text-slate-400 text-xs font-medium">Expected Days to Sell</dt>
                    <dd class="font-bold text-slate-800">
                        @if(isset($lastSummary['expected_days']) && $lastSummary['expected_days'] !== null)
                            {{ $lastSummary['expected_days'] }} days
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between items-center py-1.5">
                    <dt class="text-slate-400 text-xs font-medium">Months of Inventory</dt>
                    <dd class="font-bold text-slate-800">
                        @if(isset($lastSummary['months_of_inventory']) && $lastSummary['months_of_inventory'] !== null)
                            {{ number_format($lastSummary['months_of_inventory'], 1) }} mo
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-slate-400 font-medium">
                Snapshot saved {{ $latestSnapshot->created_at->format('Y-m-d H:i') }}
            </p>
        @else
            <p class="text-sm text-slate-400 italic">No analysis run yet.</p>
            <a href="{{ route('presentations.analysis', $presentation) }}"
               class="mt-3 inline-block text-xs text-[#00d4aa] hover:underline font-medium">
                Run first analysis →
            </a>
        @endif
        </div>
    </div>

    {{-- SNAPSHOTS --}}
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Snapshots</h2>
        <div class="flex flex-col items-start">
            <p class="ds-value-lg text-slate-800 mb-1">{{ $snapshotCount }}</p>
            <p class="text-xs text-slate-400 font-medium">
                {{ $snapshotCount === 1 ? 'snapshot saved' : 'snapshots saved' }}
            </p>
            @if($latestSnapshot)
                <a href="{{ route('presentations.snapshots.show', [$presentation, $latestSnapshot]) }}"
                   class="mt-4 inline-block text-xs text-[#00d4aa] hover:underline font-medium">
                    View latest →
                </a>
            @endif
        </div>
    </div>

</div>


{{-- ── MARKET NEWS & ARTICLES ─────────────────────────────────────────── --}}
@if(config('features.article_suggestions_v1'))
<div class="mb-8" id="articles">
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Market News &amp; Articles</h2>

        {{-- Part A — Added Articles --}}
        @if($addedArticles->isNotEmpty())
            <div class="mb-4">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-2.5">Added to Presentation</h3>
                <ul class="space-y-3">
                    @foreach($addedArticles as $article)
                        <li class="bg-slate-50 rounded-lg p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ $article->url }}" target="_blank"
                                       class="text-sm font-semibold text-[#0f172a] hover:text-[#00d4aa] leading-tight">
                                        {{ $article->tags_json['title'] ?? Str::limit($article->url, 60) }}
                                    </a>
                                    <div class="text-[11px] text-slate-400 mt-0.5">
                                        {{ $article->tags_json['source'] ?? 'Unknown source' }}
                                        @if(!empty($article->tags_json['published_at']))
                                            &middot; {{ \Carbon\Carbon::parse($article->tags_json['published_at'])->format('d M Y') }}
                                        @endif
                                    </div>
                                    @if($article->ai_summary_text)
                                        <p class="text-xs text-slate-600 mt-1.5 leading-relaxed">
                                            {{ Str::limit($article->ai_summary_text, 250) }}
                                        </p>
                                    @endif
                                </div>
                                <form method="POST"
                                      action="{{ route('presentations.articles.remove', [$presentation, $article]) }}"
                                      onsubmit="return confirm('Remove this article?');"
                                      class="shrink-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="text-xs text-red-400 hover:text-red-600 font-medium">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Part B — Suggested Articles --}}
        @if($suggestedArticles->isNotEmpty())
            <div class="@if($addedArticles->isNotEmpty()) pt-3 border-t border-slate-100 @endif">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-2.5">
                    Suggested Articles
                    @if($presentation->suburb)
                        <span class="font-normal">&mdash; based on {{ $presentation->suburb }}{{ $presentation->property_type ? ', ' . $presentation->property_type : '' }}</span>
                    @endif
                </h3>
                <ul class="space-y-2">
                    @foreach($suggestedArticles as $poolArticle)
                        <li class="flex items-start justify-between gap-2 py-2 {{ !$loop->last ? 'border-b border-slate-50' : '' }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ $poolArticle->url }}" target="_blank"
                                   class="text-sm font-medium text-[#0f172a] hover:text-[#00d4aa] leading-tight">
                                    {{ $poolArticle->title }}
                                </a>
                                <div class="text-[11px] text-slate-400 mt-0.5">
                                    {{ $poolArticle->source }}
                                    @if($poolArticle->published_at)
                                        &middot; {{ $poolArticle->published_at->format('d M Y') }}
                                    @endif
                                </div>
                                @if($poolArticle->snippet)
                                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                        {{ Str::limit($poolArticle->snippet, 150) }}
                                    </p>
                                @endif
                            </div>
                            <form method="POST"
                                  action="{{ route('presentations.articles.add', $presentation) }}"
                                  class="shrink-0">
                                @csrf
                                <input type="hidden" name="article_pool_id" value="{{ $poolArticle->id }}">
                                <button type="submit"
                                        class="corex-btn-outline text-xs whitespace-nowrap">
                                    + Add
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @elseif($addedArticles->isEmpty())
            <p class="text-xs text-slate-400">
                No matching articles found. Articles are updated daily from SA property news sources.
                Run <code class="bg-slate-100 px-1 rounded">php artisan articles:scrape</code> to populate.
            </p>
        @endif
    </div>
</div>
@endif

{{-- Standalone Asking Price section removed — the asking price is the
     single source of truth the entire presentation + PDF was built from,
     confirmed on the property page modal at generation time. Allowing an
     inline edit here would desync the computed evaluation / holding
     cost / PDF from the snapshot. Price is LOCKED at generation; changing
     it requires regenerating from the property, not an inline edit on
     this screen. (Backend endpoint route('presentations.holding-cost.update')
     still accepts asking_price_inc for non-UI callers like the analysis
     edit form; the show screen just doesn't expose it.) --}}

{{-- ── HOLDING COST INPUTS (read-only on Overview) ───────────────────────────
     AT-27 fix 2 — holding costs are edited on the ANALYSIS screen, PRE-CONFIRM,
     while the version is still a mutable draft. Editing them here (post-confirm,
     after the snapshot freeze) would defeat the confirm model — confirmed means
     final — so the Overview shows them READ-ONLY with a link back to Analysis. --}}
<div class="mb-8" id="holding-costs">
    <div class="ds-status-card">
        @php
            $hcRows = [
                'Bond payment'     => $presentation->monthly_bond,
                'Rates'            => $presentation->monthly_rates,
                'Levies'           => $presentation->monthly_levies,
                'Insurance'        => $presentation->monthly_insurance,
                'Utilities'        => $presentation->monthly_utilities,
                'Opportunity cost' => $presentation->monthly_opportunity_cost,
            ];
            $hcTotal = collect($hcRows)->sum();
        @endphp
        <div class="flex items-center justify-between mb-3">
            <h2 class="ds-section-header">Holding Cost Inputs (monthly, ZAR)</h2>
            <a href="{{ route('presentations.analysis', $presentation) }}" class="corex-btn-outline text-xs">Edit on Analysis</a>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach($hcRows as $label => $val)
            <div>
                <div class="text-xs text-slate-500 mb-1 font-medium">{{ $label }}</div>
                <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $val ? 'R ' . number_format($val, 0) : '—' }}</div>
            </div>
            @endforeach
        </div>
        @if($hcTotal > 0)
        <div class="pt-3 mt-2 border-t" style="border-color: var(--border);">
            <span class="text-xs text-slate-500 font-medium">Monthly total: <strong style="color: var(--text-primary);">R{{ number_format($hcTotal, 0) }}</strong></span>
        </div>
        @endif
    </div>
</div>

{{-- ── LIVE UPDATES POLLING (B1) ────────────────────────────────────────── --}}
@if(config('features.presentation_live_updates_v1') && config('features.portal_extension_capture_v1'))
{{-- New captures banner (fixed at top of captures section) --}}
<div id="live-new-captures-banner" class="hidden fixed bottom-4 right-4 z-50 px-4 py-2 bg-[#0f172a] text-white text-sm font-medium rounded-lg shadow-lg cursor-pointer hover:bg-[#1e293b] transition-colors"
     onclick="window.__liveUpdates && window.__liveUpdates.scrollToCaptures()">
    <span id="live-banner-text">0 new captures</span>
</div>

{{-- Live debug indicator (visible when window.PRESENTATIONS_LIVE_DEBUG = true) --}}
<div id="live-debug-indicator" class="hidden fixed top-2 right-2 z-50 bg-gray-900 text-green-400 text-xs font-mono rounded-lg shadow-lg px-3 py-2 max-w-xs opacity-90">
    <div>Live: <span id="ldi-status">OFF</span></div>
    <div>Last poll: <span id="ldi-poll-time">-</span></div>
    <div>HTTP: <span id="ldi-http-status">-</span></div>
    <div>New: <span id="ldi-new-captures">0</span> | Upd: <span id="ldi-updated-captures">0</span> | Links: <span id="ldi-updated-links">0</span></div>
    <div id="ldi-error" class="text-red-400 hidden"></div>
</div>

<script>
(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────────────────────
    var POLL_ACTIVE_MS   = 2000;   // 2s when tab visible
    var POLL_HIDDEN_MS   = 10000;  // 10s when tab hidden
    var POLL_URL         = '{{ route("presentations.live-snapshot", $presentation) }}';
    var CSRF_TOKEN       = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // ── State ───────────────────────────────────────────────────────────
    var lastCaptureId        = {{ $maxCaptureId }};
    var lastLinkUpdatedAt    = null;  // null → first polls omit cursor for wide catch-up
    var lastCaptureUpdatedAt = null;
    var pollCycleCount       = 0;     // tracks poll cycles; first 2 are "wide catch-up"
    var pollTimer            = null;
    var pendingNewCaptures   = 0;
    var isCapturesSectionVisible = false;

    // ── DOM refs ────────────────────────────────────────────────────────
    var capturesContainer = document.getElementById('captures-container');
    var banner            = document.getElementById('live-new-captures-banner');
    var bannerText        = document.getElementById('live-banner-text');

    // ── Helpers ─────────────────────────────────────────────────────────
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function isCapturesInView() {
        if (!capturesContainer) return false;
        var rect = capturesContainer.getBoundingClientRect();
        return rect.top < window.innerHeight && rect.bottom > 0;
    }

    function showBanner(count) {
        pendingNewCaptures = count;
        if (count > 0 && !isCapturesInView()) {
            bannerText.textContent = count + ' new capture' + (count > 1 ? 's' : '');
            banner.classList.remove('hidden');
        } else {
            banner.classList.add('hidden');
            pendingNewCaptures = 0;
        }
    }

    function scrollToCaptures() {
        if (capturesContainer) {
            capturesContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        banner.classList.add('hidden');
        pendingNewCaptures = 0;
    }

    // ── In-place link badge update ──────────────────────────────────────
    function updateLinkBadge(linkData) {
        var badgeEl = document.querySelector('[data-link-badge="' + linkData.id + '"]');
        if (!badgeEl) return;

        if (linkData.portal_capture_id) {
            badgeEl.className = 'inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-50 text-[#00d4aa]';
            badgeEl.textContent = 'Captured';
        } else {
            var statusMap = {
                'ok':      { cls: 'bg-emerald-50 text-[#00d4aa]', label: 'Extracted' },
                'failed':  { cls: 'bg-slate-100 text-slate-500',  label: 'Failed' },
                'pending': { cls: 'bg-slate-50 text-slate-400',   label: 'Pending' },
            };
            var st = statusMap[linkData.extraction_status] || statusMap['pending'];
            badgeEl.className = 'inline-block px-1.5 py-0.5 rounded text-xs font-medium ' + st.cls;
            badgeEl.textContent = st.label;
        }

        // Price change indicator
        if (linkData.price_change_indicator) {
            var priceEl = document.querySelector('[data-price-change="' + linkData.id + '"]');
            if (priceEl) {
                priceEl.classList.remove('hidden');
            }
        }
    }

    // ── In-place capture status update ─────────────────────────────────
    function updateCaptureRow(c) {
        var row = capturesContainer ? capturesContainer.querySelector('[data-capture-id="' + c.id + '"]') : null;
        if (!row) return;

        var statusEl = row.querySelector('[data-capture-status]');
        if (statusEl) {
            if (c.parse_status === 'parsed') {
                statusEl.className = 'px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]';
                statusEl.textContent = 'parsed';
            } else {
                statusEl.className = 'px-1 py-0.5 rounded bg-slate-50 text-slate-400';
                statusEl.textContent = c.parse_status || 'unknown';
            }
        }

        // Flash highlight
        row.style.backgroundColor = '#fef9c3';
        setTimeout(function () {
            row.style.transition = 'background-color 2s';
            row.style.backgroundColor = '';
        }, 50);
    }

    // ── Capture card builder ────────────────────────────────────────────
    function buildCaptureRow(c) {
        var shortUrl = (c.source_url || '').length > 45
            ? c.source_url.substring(0, 45) + '...'
            : c.source_url;
        var capturedAt = c.captured_at ? c.captured_at.substring(0, 16).replace('T', ' ') : '';
        var statusBadge = c.parse_status === 'parsed'
            ? '<span class="px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]" data-capture-status>parsed</span>'
            : '<span class="px-1 py-0.5 rounded bg-slate-50 text-slate-400" data-capture-status>' + esc(c.parse_status || 'unknown') + '</span>';

        var row = '<tr class="border-b border-gray-50 live-capture-new" data-capture-id="' + c.id + '">';
        row += '<td class="py-1.5 pr-2 text-gray-600">' + esc(c.source_site || '') + '</td>';
        row += '<td class="py-1.5 pr-2"><span class="px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]">' + esc(c.page_type) + '</span></td>';
        row += '<td class="py-1.5 pr-2"><a href="' + esc(c.source_url) + '" target="_blank" class="text-[#00d4aa] hover:underline">' + esc(shortUrl) + '</a></td>';
        row += '<td class="py-1.5 pr-2">' + statusBadge + '</td>';
        row += '<td class="py-1.5 pr-2 text-gray-500">' + capturedAt + '</td>';
        row += '<td class="py-1.5 text-gray-500">' + (c.html_bytes ? Number(c.html_bytes).toLocaleString() + 'b' : '-') + '</td>';

        // Price change indicator
        if (c.price_change_count > 0) {
            row += '</tr><tr class="border-b border-gray-50"><td colspan="6"><div class="bg-amber-50 border border-amber-300 rounded px-2 py-1 text-xs text-amber-800 font-medium">';
            row += 'Price Change Detected — ' + c.price_change_count + ' listing' + (c.price_change_count > 1 ? 's' : '') + ' changed';
            row += '</div></td></tr>';
        } else {
            row += '</tr>';
        }

        return row;
    }

    // ── Inject new captures into existing table ─────────────────────────
    function injectCaptures(captures) {
        if (!captures || captures.length === 0) return;

        // Find the "Attached" table body
        var tbody = capturesContainer.querySelector('table tbody');
        if (!tbody) {
            // Captures section might not have loaded yet or is empty — trigger a full reload
            if (typeof window.loadCaptures === 'function') window.loadCaptures();
            return;
        }

        // Prepend rows (newest first, so reverse the array which came oldest-first)
        var reversed = captures.slice().reverse();
        for (var i = 0; i < reversed.length; i++) {
            var c = reversed[i];
            // Skip if already in DOM
            if (tbody.querySelector('[data-capture-id="' + c.id + '"]')) continue;

            var temp = document.createElement('template');
            temp.innerHTML = buildCaptureRow(c);
            var newRow = temp.content.firstChild;

            // Flash animation
            newRow.style.backgroundColor = '#eef2ff';
            tbody.insertBefore(newRow, tbody.firstChild);

            // Also insert price-change row if present
            if (temp.content.firstChild) {
                tbody.insertBefore(temp.content.firstChild, newRow.nextSibling);
            }

            // Fade out highlight
            setTimeout(function (el) {
                el.style.transition = 'background-color 2s';
                el.style.backgroundColor = '';
            }.bind(null, newRow), 50);
        }
    }

    // ── Debug indicator refs ────────────────────────────────────────────
    var debugPanel     = document.getElementById('live-debug-indicator');
    var ldiStatus      = document.getElementById('ldi-status');
    var ldiPollTime    = document.getElementById('ldi-poll-time');
    var ldiHttpStatus  = document.getElementById('ldi-http-status');
    var ldiNewCap      = document.getElementById('ldi-new-captures');
    var ldiUpdCap      = document.getElementById('ldi-updated-captures');
    var ldiUpdLinks    = document.getElementById('ldi-updated-links');
    var ldiError       = document.getElementById('ldi-error');
    var isFirstPoll    = true;

    function updateDebugPanel(httpStatus, data, error) {
        if (!window.PRESENTATIONS_LIVE_DEBUG) {
            if (debugPanel) debugPanel.classList.add('hidden');
            return;
        }
        if (debugPanel) debugPanel.classList.remove('hidden');
        ldiStatus.textContent = 'ON';
        ldiPollTime.textContent = new Date().toLocaleTimeString();
        ldiHttpStatus.textContent = httpStatus || '-';
        if (data) {
            ldiNewCap.textContent = (data.counts || {}).new_captures || 0;
            ldiUpdCap.textContent = (data.counts || {}).updated_captures || 0;
            ldiUpdLinks.textContent = (data.counts || {}).updated_links || 0;
        }
        if (error) {
            ldiError.textContent = error;
            ldiError.classList.remove('hidden');
        } else {
            ldiError.classList.add('hidden');
        }
    }

    // ── Poll ────────────────────────────────────────────────────────────
    function poll() {
        pollCycleCount++;

        // Build poll URL — omit cursor params during first 2 cycles (wide catch-up)
        var url = POLL_URL + '?after_capture_id=' + lastCaptureId;
        if (pollCycleCount > 2 && lastLinkUpdatedAt) {
            url += '&after_link_updated_at=' + encodeURIComponent(lastLinkUpdatedAt);
        }
        if (pollCycleCount > 2 && lastCaptureUpdatedAt) {
            url += '&after_capture_updated_at=' + encodeURIComponent(lastCaptureUpdatedAt);
        }

        // Include debug=1 on first poll if debug mode is on
        if (window.PRESENTATIONS_LIVE_DEBUG && isFirstPoll) {
            url += '&debug=1';
        }
        isFirstPoll = false;

        if (window.PRESENTATIONS_LIVE_DEBUG) {
            console.log('[LiveUpdates] poll #' + pollCycleCount, {
                url: url,
                cursors: {
                    lastCaptureId: lastCaptureId,
                    lastLinkUpdatedAt: lastLinkUpdatedAt,
                    lastCaptureUpdatedAt: lastCaptureUpdatedAt,
                },
                wideCatchUp: pollCycleCount <= 2,
            });
        }

        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(function (r) {
            var status = r.status;
            if (!r.ok) {
                console.error('[LiveUpdates] HTTP error', status);
                updateDebugPanel(status, null, 'HTTP ' + status);
                throw new Error('HTTP ' + status);
            }
            return r.json().then(function (d) { return { status: status, data: d }; });
        })
        .then(function (result) {
            var data = result.data;
            if (data.enabled === false) return;

            updateDebugPanel(result.status, data, null);

            // Update cursors from server response only
            if (data.latest_capture_id)          lastCaptureId        = data.latest_capture_id;
            if (data.latest_link_updated_at)     lastLinkUpdatedAt    = data.latest_link_updated_at;
            if (data.latest_capture_updated_at)  lastCaptureUpdatedAt = data.latest_capture_updated_at;

            // Debug logging
            if (window.PRESENTATIONS_LIVE_DEBUG) {
                console.log('[LiveUpdates] response', {
                    new_captures: (data.new_captures || []).length,
                    updated_captures: (data.updated_captures || []).length,
                    updated_links: (data.updated_links || []).length,
                    upd_link_ids: (data.updated_links || []).map(function(l) { return l.id; }),
                    latest_link_updated_at: data.latest_link_updated_at,
                    latest_capture_updated_at: data.latest_capture_updated_at,
                    debug: data.debug || null,
                });
            }

            // Inject new captures
            if (data.new_captures && data.new_captures.length > 0) {
                injectCaptures(data.new_captures);
                showBanner(pendingNewCaptures + data.new_captures.length);
            }

            // Update existing capture rows in-place
            if (data.updated_captures && data.updated_captures.length > 0) {
                data.updated_captures.forEach(updateCaptureRow);
            }

            // Update link badges in-place
            if (data.updated_links && data.updated_links.length > 0) {
                data.updated_links.forEach(updateLinkBadge);
            }

            schedulePoll();
        })
        .catch(function (err) {
            console.error('[LiveUpdates] Poll failed:', err.message);
            updateDebugPanel(null, null, err.message);
            // On error, back off and retry
            schedulePoll();
        });
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        var interval = document.hidden ? POLL_HIDDEN_MS : POLL_ACTIVE_MS;
        pollTimer = setTimeout(poll, interval);
    }

    // ── Visibility change ───────────────────────────────────────────────
    document.addEventListener('visibilitychange', function () {
        clearTimeout(pollTimer);
        if (!document.hidden) {
            // Returning to tab — poll immediately to catch up
            poll();
        } else {
            schedulePoll();
        }
    });

    // Scroll listener to auto-dismiss banner when captures section is visible
    window.addEventListener('scroll', function () {
        if (pendingNewCaptures > 0 && isCapturesInView()) {
            showBanner(0);
        }
    }, { passive: true });

    // ── Start ───────────────────────────────────────────────────────────
    schedulePoll();

    // Public API for banner click
    window.__liveUpdates = { scrollToCaptures: scrollToCaptures };

})();
</script>

@endif

{{-- Scroll & focus preservation for form submits --}}
<script>
(function () {
    'use strict';
    var STORAGE_KEY = 'pres_show_scroll_{{ $presentation->id }}';

    // On page load: restore scroll position (also respects URL hash fragments)
    try {
        if (window.location.hash) {
            // Browser will auto-scroll to the hash target — let it handle it
        } else {
            var saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                sessionStorage.removeItem(STORAGE_KEY);
                var state = JSON.parse(saved);
                if (state.scrollY) {
                    window.scrollTo(0, state.scrollY);
                }
                if (state.focusId) {
                    var el = document.getElementById(state.focusId);
                    if (el) el.focus();
                } else if (state.focusName) {
                    var el2 = document.querySelector('[name="' + state.focusName + '"]');
                    if (el2) el2.focus();
                }
            }
        }
    } catch (e) { /* ignore */ }

    // Before form submit: save scroll + focus
    document.addEventListener('submit', function (e) {
        if (!e.target || e.target.tagName !== 'FORM') return;
        // Skip AJAX forms (those with fetch-based handlers)
        if (e.defaultPrevented) return;

        try {
            var focused = document.activeElement;
            var state = { scrollY: window.scrollY };
            if (focused && focused.id) {
                state.focusId = focused.id;
            } else if (focused && focused.name) {
                state.focusName = focused.name;
            }
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (ex) { /* ignore */ }
    });

    // Before link clicks that navigate to the same page: save scroll
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') return;
        // Only save for same-page navigation (links back to this presentation)
        try {
            var linkUrl = new URL(href, window.location.origin);
            if (linkUrl.pathname === window.location.pathname) {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ scrollY: window.scrollY }));
            }
        } catch (ex) { /* ignore */ }
    });
})();
</script>

</div>{{-- /.pres-page --}}

@endsection
