{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $pct = $monthlyTarget > 0 ? min(100, round(($mtdPoints / $monthlyTarget) * 100)) : 0;

    // Progress / score colours — never red for neutral values (UI_DESIGN_SYSTEM §5.3)
    $progressBarClass = $pct >= 80 ? 'ds-bar-green' : ($pct >= 50 ? 'ds-bar-navy' : 'ds-bar-amber');
    $progressTextVar  = $pct >= 80 ? 'var(--ds-green)' : ($pct >= 50 ? 'var(--brand-icon)' : 'var(--ds-amber)');
@endphp

<div class="space-y-6">

    {{-- ══════ PAGE HEADER (Pattern A — branded) ══════ --}}
    <div class="rounded-md px-6 py-5" data-tour="cc-performance-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Performance</h1>
                <p class="text-sm text-white/60">How you're tracking this week and this month.</p>
            </div>
            <div class="flex items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('corex.dashboard') }}" class="corex-btn-outline">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Today
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Scorecard --}}
        <div class="corex-panel lg:col-span-1" data-tour="cc-performance-scorecard">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">My Scorecard</h3>
                <span class="text-xs" style="color: var(--text-muted);">This Week</span>
            </div>
            <div class="corex-panel-body">
                @if($scorecard)
                    @php
                        // Overall score colour: 90+ green, 70-89 brand teal, <70 amber. Never red (neutral metric).
                        $scoreBg = match(true) {
                            $scorecard->overall_score >= 90 => 'var(--ds-green)',
                            $scorecard->overall_score >= 70 => 'var(--brand-icon)',
                            default                         => 'var(--ds-amber)',
                        };
                    @endphp
                    <div class="text-center mb-4">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full text-2xl font-bold text-white"
                             style="background: {{ $scoreBg }};">
                            {{ number_format($scorecard->overall_score) }}
                        </div>
                        <p class="text-xs mt-2" style="color: var(--text-muted);">Overall Score</p>
                    </div>

                    @php
                        $metrics = [
                            ['label' => 'Tasks Completed',     'value' => $scorecard->tasks_completed.'/'.$scorecard->tasks_total, 'pct' => $scorecard->tasks_total > 0 ? (int) round(($scorecard->tasks_completed / $scorecard->tasks_total) * 100) : 0],
                            ['label' => 'Properties Attended', 'value' => $scorecard->properties_attended.'/'.$scorecard->properties_total, 'pct' => $scorecard->properties_total > 0 ? (int) round(($scorecard->properties_attended / $scorecard->properties_total) * 100) : 0],
                            ['label' => 'Events Completed',    'value' => $scorecard->events_completed.'/'.$scorecard->events_total, 'pct' => $scorecard->events_total > 0 ? (int) round(($scorecard->events_completed / $scorecard->events_total) * 100) : 0],
                            ['label' => 'Docs Uploaded',       'value' => number_format($scorecard->documents_uploaded), 'pct' => null],
                            ['label' => 'Avg Response',        'value' => $scorecard->avg_response_hours ? number_format($scorecard->avg_response_hours, 1).'h' : '—', 'pct' => null],
                        ];
                    @endphp
                    <div class="space-y-2.5 text-sm">
                        @foreach($metrics as $m)
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span style="color: var(--text-secondary);">{{ $m['label'] }}</span>
                                    <span class="font-medium" style="color: var(--text-primary);">{{ $m['value'] }}</span>
                                </div>
                                @if($m['pct'] !== null)
                                    <div class="ds-progress-track">
                                        <div class="ds-progress-bar ds-bar-navy" style="width: {{ $m['pct'] }}%;"></div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if($scorecard->tasks_overdue > 0)
                        <div class="mt-4 rounded-md px-3 py-2 text-xs flex items-start gap-2"
                             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                                    border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                                    color: var(--text-primary);">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            <span>{{ number_format($scorecard->tasks_overdue) }} overdue task(s) this week.</span>
                        </div>
                    @endif
                @else
                    <div class="py-8 px-4 text-center">
                        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                        </div>
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No scorecard yet</h3>
                        <p class="text-sm" style="color: var(--text-muted);">Your scorecard will appear once activity data accumulates.</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Activity Points --}}
        <div class="corex-panel lg:col-span-2" data-tour="cc-performance-points">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Activity Points</h3>
                <span class="text-xs" style="color: var(--text-muted);">{{ now()->format('F Y') }}</span>
            </div>
            <div class="corex-panel-body">
                <div class="flex items-end gap-6 flex-wrap">
                    <div>
                        <p class="text-xs mb-1" style="color: var(--text-muted);">Points this month</p>
                        <p class="text-[1.75rem] font-semibold leading-none" style="color: var(--text-primary);">{{ number_format($mtdPoints) }}</p>
                    </div>
                    @if($monthlyTarget > 0)
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted);">Target</p>
                            <p class="text-xl font-semibold" style="color: var(--text-secondary);">{{ number_format($monthlyTarget) }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted);">Progress</p>
                            <p class="text-xl font-semibold" style="color: {{ $progressTextVar }};">{{ $pct }}%</p>
                        </div>
                    @endif
                </div>
                @if($monthlyTarget > 0)
                    <div class="ds-progress-track mt-4">
                        <div class="ds-progress-bar {{ $progressBarClass }}" style="width: {{ $pct }}%;"></div>
                    </div>
                @endif
                <div class="mt-4">
                    <a href="{{ route('agent.daily') }}" class="corex-btn-outline" data-tour="cc-performance-capture">
                        Capture Daily Activity
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Candidate Documents (supervisors only) --}}
    @if($candidateDocs->count() > 0)
        <div class="corex-panel">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Candidate Documents — Needs Authorisation</h3>
                <span class="ds-badge ds-badge-warning">{{ number_format($candidateDocs->count()) }} Pending</span>
            </div>
            <div class="corex-panel-body">
                <div class="divide-y" style="border-color: var(--border);">
                    @foreach($candidateDocs as $doc)
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between py-3 gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm truncate" style="color: var(--text-primary);">{{ $doc->document->name ?? 'Untitled Document' }}</p>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-xs" style="color: var(--text-secondary);">
                                    <span>Candidate: <strong style="color: var(--text-primary);">{{ $doc->creator->name ?? 'Unknown' }}</strong></span>
                                    <span>Created: {{ $doc->created_at->format('d M Y') }}</span>
                                    <span class="ds-badge ds-badge-warning">
                                        {{ $doc->status === 'awaiting_supervisor' ? 'Initial Review' : 'Final Sign-off' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <a href="{{ route('docuperfect.signatures.review', $doc->document_id) }}" class="corex-btn-primary">
                                    Review &amp; Authorise
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Property Health --}}
    <div class="corex-panel" data-tour="cc-performance-prop-health">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title">Properties Needing Attention</h3>
            <div class="flex items-center gap-2">
                @if($propHealthSummary['critical'] > 0)
                    <span class="ds-badge ds-badge-danger">{{ number_format($propHealthSummary['critical']) }} Critical</span>
                @endif
                @if($propHealthSummary['attention'] > 0)
                    <span class="ds-badge ds-badge-warning">{{ number_format($propHealthSummary['attention']) }} Attention</span>
                @endif
                <span class="ds-badge ds-badge-success">{{ number_format($propHealthSummary['good']) }} Good</span>
            </div>
        </div>
        <div class="corex-panel-body">
            @if($propsNeedingAttention->isEmpty())
                <div class="py-12 px-6 text-center">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">All properties are in good health</h3>
                    <p class="text-sm" style="color: var(--text-muted);">Nothing needs your attention right now.</p>
                </div>
            @else
                <div class="divide-y" style="border-color: var(--border);">
                    @foreach($propsNeedingAttention as $health)
                        @php
                            $prop = $health->property;
                            // Grade colour: critical = genuine danger, attention = amber, other = green
                            $gradeBg = match($health->grade) {
                                'critical'  => 'var(--ds-crimson)',
                                'attention' => 'var(--ds-amber)',
                                default     => 'var(--ds-green)',
                            };
                        @endphp
                        <div class="flex items-center gap-3 py-3">
                            <div class="flex-shrink-0 w-10 h-10 rounded-md flex items-center justify-center text-sm font-bold text-white"
                                 style="background: {{ $gradeBg }};">
                                {{ number_format($health->score) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                @if($prop)
                                    <a href="{{ route('corex.properties.show', $prop) }}"
                                       class="text-sm font-medium truncate block hover:underline"
                                       style="color: var(--text-primary);">
                                        {{ $prop->buildDisplayAddress() ?: ($prop->title ?: 'Property #'.$prop->id) }}
                                    </a>
                                @endif
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                                    @if(is_array($health->factors))
                                        @foreach($health->factors as $key => $factor)
                                            @if(($factor['penalty'] ?? 0) > 0)
                                                @php
                                                    $factorColor = ($factor['status'] ?? null) === 'critical'
                                                        ? 'var(--ds-crimson)'
                                                        : 'var(--ds-amber)';
                                                @endphp
                                                <span class="text-xs" style="color: {{ $factorColor }};">{{ $factor['label'] ?? $key }}</span>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                            <div class="flex-shrink-0 text-xs" style="color: var(--text-muted);">
                                {{ $prop?->agent?->name ?? 'Unassigned' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</div>
@endsection
