{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $label = [
        'created' => 'Code issued', 'joined' => 'Support joined', 'page' => 'Moved to page', 'highlight' => 'Support highlighted',
        'highlight_cleared' => 'Highlight cleared', 'pointer_on' => 'Support pointer on', 'pointer_off' => 'Support pointer off', 'ended' => 'Session ended',
    ];
    $endedLabel = ['agent' => 'the agent', 'support' => 'support', 'logout' => 'the agent signing out', 'timeout' => 'the connection being lost', 'expired' => 'nobody joining in time', 'disabled' => 'Desk being switched off', 'replaced' => 'a new session being started'];
    $secs = $session->durationSeconds();
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Desk session #{{ $session->id }}</h1>
        <p class="text-xs" style="color: var(--text-muted);">
            {{ $session->agent?->name ?? 'Agent' }}@if($session->agency) · {{ $session->agency->name }}@endif
            · started {{ $session->started_at?->format('d M Y H:i') }}
        </p>
    </div>

    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\ComplianceReportsTabs::class])

    <div class="rounded-md px-5 py-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-xs" style="background:var(--surface); border:1px solid var(--border);">
        <div><div style="color:var(--text-muted);">Agent</div><div style="color:var(--text-primary);">{{ $session->agent?->name ?? '—' }}</div></div>
        <div><div style="color:var(--text-muted);">Support</div><div style="color:var(--text-primary);">{{ $session->supportUser?->name ?? 'Nobody joined' }}</div></div>
        <div><div style="color:var(--text-muted);">Length</div><div style="color:var(--text-primary);">{{ $session->joined_at ? ($secs >= 60 ? floor($secs / 60) . ' min ' . ($secs % 60) . ' s' : $secs . ' s') : '—' }}</div></div>
        <div><div style="color:var(--text-muted);">Status</div><div style="color:var(--text-primary);">{{ ucfirst($session->status) }}@if($session->ended_by) — ended by {{ $endedLabel[$session->ended_by] ?? $session->ended_by }}@endif</div></div>
    </div>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="min-w-full text-sm ds-table">
            <thead><tr style="background:var(--surface-2);">
                <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Time</th>
                <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">What happened</th>
                <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Detail</th>
                <th class="text-left px-4 py-2.5 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">By</th>
            </tr></thead>
            <tbody>
                @forelse($session->events as $e)
                <tr>
                    <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $e->created_at?->format('d M Y H:i:s') }}</td>
                    <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ $label[$e->type] ?? $e->type }}</td>
                    <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">
                        @if($e->type === 'page'){{ $e->payload['page'] ?? '' }}
                        @elseif($e->type === 'highlight'){{ $e->payload['selector'] ?? '' }}@if(! empty($e->payload['note'])) — “{{ $e->payload['note'] }}”@endif
                        @elseif($e->type === 'ended')by {{ $endedLabel[$e->payload['by'] ?? ''] ?? ($e->payload['by'] ?? '') }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $e->actor?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No events recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div><a href="{{ route('compliance.desk-sessions.index') }}" class="text-xs font-semibold" style="color:var(--brand-icon);">← All Desk sessions</a></div>
</div>
@endsection
