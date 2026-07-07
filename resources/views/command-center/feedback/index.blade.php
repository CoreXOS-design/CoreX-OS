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
    $activeStatus = request('status');
    $activeSearch = request('q');
    $hasFilters   = filled($activeStatus) || filled($activeSearch);
    $pageIds      = collect($reports->items())->pluck('id')->values()->all();
    $statusUpdateTpl = route('command-center.feedback-reports.update-status', ['id' => '__ID__']);
@endphp
<div class="w-full space-y-5" x-data="feedbackReportsList(@js($pageIds), @js($statusUpdateTpl))">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="feedback-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Feedback Reports</h1>
                <p class="text-sm text-white/60">Bug reports, enhancement requests and feedback submitted by your team.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap" data-tour="feedback-export">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('command-center.feedback-reports.export', ['format' => 'markdown'] + request()->only(['status', 'q'])) }}"
                   class="corex-btn-outline corex-btn-on-brand corex-btn-xs">Export MD</a>
                <a href="{{ route('command-center.feedback-reports.export', ['format' => 'json'] + request()->only(['status', 'q'])) }}"
                   class="corex-btn-outline corex-btn-on-brand corex-btn-xs">Export JSON</a>
                <a href="{{ route('command-center.feedback-reports.export', ['format' => 'csv'] + request()->only(['status', 'q'])) }}"
                   class="corex-btn-outline corex-btn-on-brand corex-btn-xs">Export CSV</a>
            </div>
        </div>
    </div>

    {{-- Filter + search bar --}}
    <div class="rounded-md px-4 py-3" data-tour="feedback-filter" style="background:var(--surface);border:1px solid var(--border);">
        <div class="flex flex-col lg:flex-row lg:items-center gap-3">
            <form method="GET" action="{{ route('command-center.feedback-reports') }}" class="flex items-center gap-2 w-full lg:max-w-md">
                @if(filled($activeStatus))<input type="hidden" name="status" value="{{ $activeStatus }}">@endif
                <div class="relative flex-1 min-w-0">
                    <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style="color:var(--text-muted);"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z"/>
                    </svg>
                    <input type="text" name="q" value="{{ $activeSearch }}" placeholder="Search title or description..."
                           class="w-full rounded-md pl-9 pr-3 py-2 text-sm"
                           style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
                </div>
                <button type="submit" class="corex-btn-primary corex-btn-xs">Search</button>
            </form>

            <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                <a href="{{ route('command-center.feedback-reports', array_filter(['q' => $activeSearch])) }}"
                   class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline transition-all duration-300"
                   style="{{ !$activeStatus ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);' }}">
                    All
                </a>
                @foreach(['new','reviewing','in_progress','fixed','wont_fix'] as $s)
                    <a href="{{ route('command-center.feedback-reports', array_filter(['status' => $s, 'q' => $activeSearch])) }}"
                       class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline transition-all duration-300"
                       style="{{ $activeStatus === $s ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);' }}">
                        {{ ucfirst(str_replace('_', ' ', $s)) }}
                    </a>
                @endforeach
                @if($hasFilters)
                    <a href="{{ route('command-center.feedback-reports') }}"
                       class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline transition-all duration-300"
                       style="color:var(--brand-icon,#0ea5e9);">
                        Clear filters
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Reports table --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        {{-- Toolbar strip: title + bulk actions --}}
        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-2"
             style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold" style="color:var(--text-primary);">Reports</span>
                <span class="text-xs" style="color:var(--text-muted);">{{ number_format($reports->total()) }} total</span>
                <span x-show="selected.length" x-cloak class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">
                    <span x-text="selected.length"></span> selected
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="selectAll()"
                        class="corex-btn-outline corex-btn-xs" :disabled="!allIds.length"
                        :style="!allIds.length ? 'opacity:0.4;cursor:not-allowed;' : ''">
                    Select all
                </button>
                <button type="button" @click="clearAll()"
                        class="corex-btn-outline corex-btn-xs" :disabled="!selected.length"
                        :style="!selected.length ? 'opacity:0.4;cursor:not-allowed;' : ''">
                    Clear
                </button>
                <button type="button" @click="markDone()"
                        class="corex-btn-primary corex-btn-xs" :disabled="!selected.length || busy"
                        :style="(!selected.length || busy) ? 'opacity:0.4;cursor:not-allowed;' : ''">
                    <svg x-show="!busy" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                    <span x-show="!busy">Mark done</span>
                    <span x-show="busy" x-cloak>Working…</span>
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="px-4 py-2.5 w-10">
                            <input type="checkbox" aria-label="Select all reports on this page"
                                   @change="$event.target.checked ? selectAll() : clearAll()" :checked="allChecked"
                                   class="w-4 h-4 align-middle" style="accent-color:var(--brand-button,#0ea5e9);cursor:pointer;">
                        </th>
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
                            <td class="px-4 py-3" @click.stop>
                                <input type="checkbox" value="{{ $r->id }}" x-model.number="selected"
                                       aria-label="Select report {{ $r->id }}"
                                       class="w-4 h-4 align-middle" style="accent-color:var(--brand-button,#0ea5e9);cursor:pointer;">
                            </td>
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
                            <td colspan="8" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">
                                {{ $hasFilters ? 'No feedback reports match these filters.' : 'No feedback reports yet.' }}
                            </td>
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

<script>
    function feedbackReportsList(ids, statusTemplate) {
        return {
            allIds: ids || [],
            selected: [],
            busy: false,
            get allChecked() {
                return this.allIds.length > 0 && this.selected.length === this.allIds.length;
            },
            selectAll() {
                this.selected = [...this.allIds];
            },
            clearAll() {
                this.selected = [];
            },
            async markDone() {
                if (!this.selected.length || this.busy) return;
                if (!confirm('Mark ' + this.selected.length + ' report(s) as Fixed (Done)? This can be changed again later.')) return;
                this.busy = true;
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                try {
                    for (const id of this.selected) {
                        const body = new FormData();
                        body.append('status', 'fixed');
                        body.append('_token', token);
                        const res = await fetch(statusTemplate.replace('__ID__', id), {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body,
                        });
                        if (!res.ok) throw new Error('Request failed for ' + id);
                    }
                    window.location.reload();
                } catch (e) {
                    this.busy = false;
                    if (window.showToast) {
                        window.showToast('Could not update all reports. Please try again.', 'error');
                    } else {
                        alert('Could not update all reports. Please try again.');
                    }
                }
            },
        };
    }
</script>
@endsection
