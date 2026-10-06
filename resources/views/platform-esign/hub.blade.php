{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign overview (AT-447, spec §3A). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $kpis = [
        ['Awaiting signature', ($counts['sent'] ?? 0) + ($counts['in_progress'] ?? 0) + ($counts['awaiting_countersign'] ?? 0) + ($counts['wetink_received'] ?? 0), 'var(--ds-amber)', route('platform-esign.documents.index', ['status' => 'sent'])],
        ['Signed', $counts['completed'] ?? 0, 'var(--ds-green)', route('platform-esign.documents.index', ['status' => 'completed'])],
        ['Declined / expired', ($counts['declined'] ?? 0) + ($counts['expired'] ?? 0), 'var(--ds-crimson)', route('platform-esign.documents.index', ['status' => 'declined'])],
        ['Active templates', $templates, 'var(--brand-icon)', route('platform-esign.templates.index')],
    ];
    $pill = function ($d) {
        $tone = match ($d->status) {
            'awaiting_countersign', 'wetink_received' => 'pe-act',
            'in_progress' => 'pe-warn',
            'completed' => 'pe-ok',
            default => ($d->statusLabel() === 'Opened' ? 'pe-info' : 'pe-neutral'),
        };
        $label = match ($d->status) {
            'awaiting_countersign' => 'Awaiting RR countersign',
            'wetink_received' => 'Wet-ink copy received',
            default => $d->statusLabel(),
        };
        return [$tone, $label];
    };
@endphp
<div class="w-full space-y-5">
    @include('platform-esign._header', [
        'title' => 'Platform E-Sign', 'tab' => 'hub',
        'sub' => "CoreX's own contracts — Subscription Agreement, debit-order form and anything else CoreX sends. Separate from agency e-sign; no agency can see it.",
        'actions' => '<a href="' . route('platform-esign.agreements.create') . '" class="corex-btn-primary">Send Subscription Agreement</a>',
    ])

    <div class="pe-tiles">
        @foreach($kpis as [$label, $n, $color, $href])
            <a href="{{ $href }}" class="pe-tile">
                <div class="pe-num">{{ $n }}</div>
                <div class="pe-lab"><span class="pe-dot" style="background: {{ $color }};"></span>{{ $label }}</div>
            </a>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-5">
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Waiting on a signature</div></div>
            @forelse($awaiting as $d)
                @php([$tone, $statusText] = $pill($d))
                @php($signed = $d->signers->where('status', 'signed')->count())
                @php($total = max(1, $d->signers->count()))
                <a href="{{ route('platform-esign.documents.show', $d->id) }}" class="pe-row">
                    <div class="pe-col">
                        <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $d->title }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            {{ $d->agency?->name ?? 'No agency' }} · sent {{ $d->sent_at?->diffForHumans() }} · waiting on
                            {{ $d->signers->where('status', '!=', 'signed')->pluck('name')->implode(', ') }}
                        </div>
                    </div>
                    <div class="pe-end">
                        <span class="pe-pill {{ $tone }}">{{ $statusText }}</span>
                        <div class="pe-prog" title="{{ $signed }} of {{ $total }} signed"><i style="width: {{ round($signed / $total * 100) }}%;"></i></div>
                    </div>
                </a>
            @empty
                <div class="px-5 py-10 text-center text-sm" style="color: var(--text-muted);">Nothing is waiting on a signature.</div>
            @endforelse
        </div>
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Recently signed</div></div>
            @forelse($recent as $d)
                <a href="{{ route('platform-esign.documents.show', $d->id) }}" class="pe-row">
                    <div class="pe-col">
                        <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $d->title }}</div>
                        <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $d->agency?->name ?? 'No agency' }}</div>
                    </div>
                    <div class="pe-end">
                        <span class="pe-pill pe-ok">Signed</span>
                        <span class="text-xs tabular-nums" style="color: var(--text-muted);">{{ $d->completed_at?->format('j M Y') }}</span>
                    </div>
                </a>
            @empty
                <div class="px-5 py-10 text-center text-sm" style="color: var(--text-muted);">No signed contracts yet.</div>
            @endforelse
        </div>
    </div>
    @include('platform-esign._end')
</div>
@endsection
