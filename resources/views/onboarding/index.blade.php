@extends('layouts.corex')

@section('corex-content')
<div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div class="flex items-center justify-between gap-4" style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <div>
            <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Agent Onboarding Pipeline</h2>
            <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">
                {{ $totalPending }} application{{ $totalPending !== 1 ? 's' : '' }} in progress
            </div>
        </div>
        <a href="{{ route('onboarding.create') }}" class="corex-btn-primary text-sm px-4 py-2 no-underline">
            + New Application
        </a>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('onboarding.index') }}" class="flex items-end gap-3">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or email..."
                   class="rounded-md px-3 py-2 text-sm w-48"
                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Designation</label>
            <select name="designation" class="rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                @foreach(\App\Models\AgentApplication::DESIGNATION_LABELS as $key => $label)
                    <option value="{{ $key }}" {{ request('designation') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Filter</button>
        @if(request('search') || request('designation'))
        <a href="{{ route('onboarding.index') }}" class="px-3 py-2 text-sm rounded-md no-underline" style="color:var(--text-secondary); border:1px solid var(--border);">Clear</a>
        @endif
    </form>

    {{-- Pipeline columns --}}
    <div class="flex gap-3 overflow-x-auto pb-4" style="min-height:400px;">
        @php
            $statusColors = [
                'applied' => '#94a3b8',
                'documents_pending' => '#f59e0b',
                'compliance_review' => '#3b82f6',
                'mentor_assignment' => '#8b5cf6',
                'training' => '#14b8a6',
                'activated' => '#22c55e',
            ];
        @endphp

        @foreach(\App\Models\AgentApplication::PIPELINE_STATUSES as $status)
        @php $cards = $pipeline[$status] ?? collect(); $color = $statusColors[$status] ?? '#94a3b8'; @endphp
        <div class="flex-shrink-0 rounded-lg" style="width:220px; background:var(--surface); border:1px solid var(--border);">
            {{-- Column header --}}
            <div class="px-3 py-2.5 flex items-center justify-between" style="border-bottom:2px solid {{ $color }};">
                <span class="text-xs font-bold uppercase tracking-wider" style="color:{{ $color }};">
                    {{ \App\Models\AgentApplication::STATUS_LABELS[$status] }}
                </span>
                <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold"
                      style="background:{{ $color }}20; color:{{ $color }};">
                    {{ $cards->count() }}
                </span>
            </div>

            {{-- Cards --}}
            <div class="p-2 space-y-2 overflow-y-auto" style="max-height:500px;">
                @forelse($cards as $app)
                <a href="{{ route('onboarding.show', $app) }}"
                   class="block p-3 rounded-md transition-colors no-underline hover:opacity-90"
                   style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-sm font-semibold truncate" style="color:var(--text-primary);">
                        {{ $app->full_name }}
                    </div>
                    <div class="flex items-center gap-1.5 mt-1">
                        @php
                            $desBadge = match($app->designation) {
                                'property_practitioner' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6', 'label' => 'PP'],
                                'candidate_practitioner' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b', 'label' => 'Candidate'],
                                'intern' => ['bg' => 'rgba(168,85,247,0.12)', 'color' => '#a855f7', 'label' => 'Intern'],
                                default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8', 'label' => '?'],
                            };
                        @endphp
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold" style="background:{{ $desBadge['bg'] }}; color:{{ $desBadge['color'] }};">{{ $desBadge['label'] }}</span>
                        <span class="text-[10px]" style="color:var(--text-muted);">{{ $app->daysInCurrentStage() }}d</span>
                    </div>
                    {{-- Progress bar --}}
                    @php $pct = $app->completionPercent(); @endphp
                    <div class="mt-2 h-1 rounded-full overflow-hidden" style="background:var(--border);">
                        <div class="h-full rounded-full" style="width:{{ $pct }}%; background:{{ $color }};"></div>
                    </div>
                    <div class="text-[10px] mt-0.5 text-right" style="color:var(--text-muted);">{{ $pct }}%</div>
                </a>
                @empty
                <div class="p-3 text-center text-xs" style="color:var(--text-muted);">No applications</div>
                @endforelse
            </div>
        </div>
        @endforeach
    </div>

</div>
@endsection
