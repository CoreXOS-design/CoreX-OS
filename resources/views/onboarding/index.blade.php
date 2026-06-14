{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- Full-width container (no extra px-* — the <main> wrapper already applies p-4/lg:p-6),
     so the branded header spans the page like the Properties / Contacts index pages. --}}
<div class="w-full space-y-6">

    {{-- Page header (UI_DESIGN_SYSTEM §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agent Onboarding Pipeline</h1>
                <p class="text-sm text-white/60">
                    {{ number_format($totalPending) }} application{{ $totalPending !== 1 ? 's' : '' }} in progress
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('onboarding.create') }}" class="corex-btn-primary no-underline">
                    New Application
                </a>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('onboarding.index') }}"
          class="rounded-md p-4 flex flex-col md:flex-row md:items-end gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex-1 min-w-[200px]">
            <label for="onboarding-search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--text-muted);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input id="onboarding-search" type="text" name="search" value="{{ request('search') }}" placeholder="Name or email..."
                       class="w-full rounded-md pl-9 pr-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
        </div>
        <div class="md:w-56">
            <label for="onboarding-designation" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Designation</label>
            <select id="onboarding-designation" name="designation" onchange="this.form.submit()"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                @foreach(\App\Models\AgentApplication::DESIGNATION_LABELS as $key => $label)
                    <option value="{{ $key }}" {{ request('designation') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary">Filter</button>
            @if(request('search') || request('designation'))
                <a href="{{ route('onboarding.index') }}" class="corex-btn-outline no-underline">Clear</a>
            @endif
        </div>
    </form>

    {{-- Pipeline columns --}}
    @php
        $statusStyles = [
            'applied'             => ['var' => 'var(--text-muted)',    'soft' => 'color-mix(in srgb, var(--text-muted) 14%, transparent)'],
            'documents_pending'   => ['var' => 'var(--ds-amber)',      'soft' => 'color-mix(in srgb, var(--ds-amber) 14%, transparent)'],
            'compliance_review'   => ['var' => 'var(--brand-icon)',    'soft' => 'color-mix(in srgb, var(--brand-icon) 14%, transparent)'],
            'mentor_assignment'   => ['var' => 'var(--brand-default)', 'soft' => 'color-mix(in srgb, var(--brand-default) 14%, transparent)'],
            'training'            => ['var' => 'var(--brand-icon)',    'soft' => 'color-mix(in srgb, var(--brand-icon) 14%, transparent)'],
            'activated'           => ['var' => 'var(--ds-green)',      'soft' => 'color-mix(in srgb, var(--ds-green) 14%, transparent)'],
        ];
        $designationStyles = [
            'property_practitioner'  => ['var' => 'var(--brand-icon)',    'label' => 'Practitioner', 'full' => 'Property Practitioner'],
            'candidate_practitioner' => ['var' => 'var(--ds-amber)',      'label' => 'Candidate',    'full' => 'Candidate Practitioner'],
            'intern'                 => ['var' => 'var(--brand-default)', 'label' => 'Intern',       'full' => 'Intern'],
        ];
    @endphp

    <div class="flex gap-4 overflow-x-auto pb-4" style="min-height: 400px;">
        @foreach(\App\Models\AgentApplication::PIPELINE_STATUSES as $status)
            @php
                $cards = $pipeline[$status] ?? collect();
                $style = $statusStyles[$status] ?? $statusStyles['applied'];
            @endphp
            <div class="flex-shrink-0 rounded-md" style="width: 240px; background: var(--surface); border: 1px solid var(--border);">
                {{-- Column header --}}
                <div class="px-3 py-2.5 flex items-center justify-between" style="border-bottom: 2px solid {{ $style['var'] }};">
                    <span class="text-xs font-semibold uppercase tracking-wider" style="color: {{ $style['var'] }};">
                        {{ \App\Models\AgentApplication::STATUS_LABELS[$status] }}
                    </span>
                    <span class="ds-badge" style="background: {{ $style['soft'] }}; color: {{ $style['var'] }};">
                        {{ number_format($cards->count()) }}
                    </span>
                </div>

                {{-- Cards --}}
                <div class="p-2 space-y-2 overflow-y-auto" style="max-height: 500px;">
                    @forelse($cards as $app)
                        @php
                            $des = $designationStyles[$app->designation] ?? ['var' => 'var(--text-muted)', 'label' => '—', 'full' => 'Unknown designation'];
                            $pct = (int) round($app->completionPercent());
                        @endphp
                        <a href="{{ route('onboarding.show', $app) }}"
                           class="block p-3 rounded-md no-underline transition-colors hover:[border-color:var(--border-hover)]"
                           style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                {{ $app->full_name }}
                            </div>
                            <div class="flex items-center gap-1.5 mt-1">
                                <span class="ds-badge" title="{{ $des['full'] }}"
                                      style="background: color-mix(in srgb, {{ $des['var'] }} 12%, transparent); color: {{ $des['var'] }};">
                                    {{ $des['label'] }}
                                </span>
                                <span class="text-xs" style="color: var(--text-muted);"
                                      title="Days in current stage">{{ number_format($app->daysInCurrentStage()) }}d</span>
                            </div>
                            <div class="ds-progress-track mt-2">
                                <div class="ds-progress-bar" style="width: {{ $pct }}%; background: {{ $style['var'] }};"></div>
                            </div>
                            <div class="text-xs mt-1 text-right" style="color: var(--text-muted);">{{ $pct }}%</div>
                        </a>
                    @empty
                        <div class="rounded-md py-8 px-3 text-center text-xs" style="color: var(--text-muted);">
                            No applications
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

</div>
@endsection
