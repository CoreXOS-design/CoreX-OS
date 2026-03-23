@extends('layouts.corex')

@section('corex-content')
@php
    $pct = $monthlyTarget > 0 ? min(100, round(($mtdPoints / $monthlyTarget) * 100)) : 0;
    $monthLabel = \Carbon\Carbon::createFromFormat('Y-m', $period)->format('F Y');
@endphp

<div class="space-y-6">

    {{-- Welcome header --}}
    <div>
        <h1 class="text-2xl font-bold tracking-tight" style="color:var(--text-primary);">
            Welcome back, {{ auth()->user()->name }}
        </h1>
        <p class="text-sm mt-1" style="color:var(--text-secondary);">{{ $monthLabel }}</p>
    </div>

    {{-- Candidate Documents — Needs Authorisation (shared queue for full-status users) --}}
    @if(isset($candidateDocs) && $candidateDocs->count() > 0)
        <div class="corex-panel border-l-4" style="border-left-color: #f59e0b;">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title" style="color: #b45309;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 inline-block mr-1 -mt-0.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    CANDIDATE DOCUMENTS &mdash; NEEDS AUTHORISATION
                </h3>
                <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background: #fef3c7; color: #92400e;">
                    {{ $candidateDocs->count() }} pending
                </span>
            </div>
            <div class="corex-panel-body">
                <div class="divide-y" style="border-color: var(--border-default);">
                    @foreach($candidateDocs as $doc)
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between py-3 gap-2 {{ !$loop->first ? 'pt-3' : '' }}">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm truncate" style="color: var(--text-primary);">
                                    {{ $doc->document->name ?? 'Untitled Document' }}
                                </p>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-xs" style="color: var(--text-secondary);">
                                    <span>Candidate: <strong>{{ $doc->creator->name ?? 'Unknown' }}</strong></span>
                                    <span>Created: {{ $doc->created_at->format('d M Y') }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $doc->status === 'awaiting_supervisor' ? 'bg-yellow-100 text-yellow-800' : 'bg-orange-100 text-orange-800' }}">
                                        {{ $doc->status === 'awaiting_supervisor' ? 'Initial Review' : 'Final Sign-off' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <a href="{{ route('docuperfect.signatures.review', $doc->document_id) }}"
                                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-semibold text-white shadow transition-all duration-200 hover:opacity-90"
                                   style="background: #f59e0b;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    Review & Authorise
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Points card --}}
    <div class="corex-panel max-w-md">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title">My Daily Activity Points</h3>
            <span class="text-xs" style="color:var(--text-muted);">MTD</span>
        </div>
        <div class="corex-panel-body space-y-4">

            {{-- Big number --}}
            <div class="flex items-end gap-3">
                <span class="text-5xl font-bold" style="color:var(--brand-default,#0b2a4a);">
                    {{ number_format($mtdPoints) }}
                </span>
                @if($monthlyTarget > 0)
                    <span class="text-lg mb-1" style="color:var(--text-muted);">/ {{ number_format($monthlyTarget) }} target</span>
                @endif
            </div>

            {{-- Progress bar (only if target set) --}}
            @if($monthlyTarget > 0)
                <div>
                    <div class="flex justify-between text-xs mb-1" style="color:var(--text-secondary);">
                        <span>Progress</span>
                        <span>{{ $pct }}%</span>
                    </div>
                    <div class="w-full h-2.5 rounded-md" style="background:var(--surface-2);">
                        <div class="h-2.5 rounded-md transition-all duration-300"
                             style="width:{{ $pct }}%; background:var(--brand-icon,#0ea5e9);"></div>
                    </div>
                </div>
            @else
                <p class="text-xs" style="color:var(--text-muted);">No points target set for this month.</p>
            @endif

            {{-- Quick link --}}
            <div class="pt-2">
                <a href="{{ route('agent.daily') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300 hover:opacity-90"
                   style="background:var(--brand-button,#0ea5e9);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Capture Today's Activity
                </a>
            </div>
        </div>
    </div>

</div>
@endsection
