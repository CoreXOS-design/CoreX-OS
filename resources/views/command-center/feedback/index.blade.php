{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $severityBadge = [
        'critical' => 'ds-badge-danger',
        'major'    => 'ds-badge-warning',
        'minor'    => 'ds-badge-default',
    ];
    $statusBadge = [
        'new'         => 'ds-badge-warning',
        'reviewing'   => 'ds-badge-info',
        'in_progress' => 'ds-badge-info',
        'fixed'       => 'ds-badge-success',
        'wont_fix'    => 'ds-badge-default',
        'duplicate'   => 'ds-badge-default',
        'deferred'    => 'ds-badge-default',
    ];
@endphp
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="feedback-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Feedback Reports</h1>
                <p class="text-sm text-white/60">Bug reports, enhancement requests and feedback submitted by your team.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap" data-tour="feedback-export">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('command-center.feedback-reports.export', ['format' => 'markdown']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300 no-underline"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'" onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                    Export MD
                </a>
                <a href="{{ route('command-center.feedback-reports.export', ['format' => 'json']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300 no-underline"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'" onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                    Export JSON
                </a>
                <a href="{{ route('command-center.feedback-reports.export', ['format' => 'csv']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300 no-underline"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'" onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                    Export CSV
                </a>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="rounded-md px-4 py-3" data-tour="feedback-filter" style="background:var(--surface);border:1px solid var(--border);">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('command-center.feedback-reports') }}"
               class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline transition-all duration-300"
               style="{{ !request('status') ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);' }}">
                All
            </a>
            @foreach(['new','reviewing','in_progress','fixed','wont_fix'] as $s)
                <a href="{{ route('command-center.feedback-reports', ['status' => $s]) }}"
                   class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline transition-all duration-300"
                   style="{{ request('status') === $s ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);' }}">
                    {{ ucfirst(str_replace('_', ' ', $s)) }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- Reports table --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="text-sm font-bold" style="color:var(--text-primary);">Reports</div>
            <div class="text-xs" style="color:var(--text-muted);">{{ number_format($reports->total()) }} total</div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Date</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">User</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Severity</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Title</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Module</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reports as $r)
                        @php $user = \App\Models\User::withoutGlobalScopes()->find($r->user_id); @endphp
                        <tr style="cursor:pointer;" onclick="window.location='{{ route('command-center.feedback-reports.show', $r->id) }}'">
                            <td class="px-4 py-3 text-xs whitespace-nowrap" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($r->submitted_at)->format('d M H:i') }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $user?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ ucfirst($r->type) }}</td>
                            <td class="px-4 py-3">
                                @if($r->severity)
                                    <span class="ds-badge {{ $severityBadge[$r->severity] ?? 'ds-badge-default' }}">{{ ucfirst($r->severity) }}</span>
                                @else
                                    <span style="color:var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs font-medium truncate max-w-[220px]" style="color:var(--text-primary);">{{ $r->title }}</td>
                            <td class="px-4 py-3 text-xs whitespace-nowrap" style="color:var(--text-muted);">{{ $r->module_tag ? ucfirst(str_replace('_', ' ', $r->module_tag)) : '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $statusBadge[$r->status] ?? 'ds-badge-default' }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No feedback reports yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($reports->hasPages())
        <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $reports->links() }}</div>
        @endif
    </div>
</div>
@endsection
