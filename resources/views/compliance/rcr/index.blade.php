{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    use App\Models\Compliance\Rcr\RcrSubmission;

    // [label, .ds-badge variant] — badge labels kept to ≤2 words / ≤20 chars (§3.4).
    $statusBadge = function (string $s): array {
        return match ($s) {
            RcrSubmission::STATUS_DRAFT                    => ['Draft',     'ds-badge-warning'],
            RcrSubmission::STATUS_IN_REVIEW               => ['In Review', 'ds-badge-info'],
            RcrSubmission::STATUS_APPROVED_FOR_SUBMISSION => ['Approved',  'ds-badge-success'],
            RcrSubmission::STATUS_SUBMITTED               => ['Submitted', 'ds-badge-success'],
            RcrSubmission::STATUS_LOCKED                  => ['Locked',    'ds-badge-default'],
            default                                       => ['Unknown',   'ds-badge-default'],
        };
    };

    $active     = $submissions->first(fn ($s) => in_array($s->status, RcrSubmission::EDITABLE_STATUSES, true));
    $activeCount = $submissions->filter(fn ($s) => in_array($s->status, RcrSubmission::EDITABLE_STATUSES, true))->count();
@endphp

<div class="w-full space-y-5">

    {{-- Page header (§2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="comp-rcr-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Risk &amp; Compliance Returns (RCR)</h1>
                <p class="text-sm text-white/60">
                    FIC Directive 11 of 2026 — submit by 31 July 2026 via the FIC goAML platform. CoreX prepares your answers; you transpose into goAML.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                @if($active)
                <a href="{{ route('corex.compliance.rcr.show', $active->id) }}" class="corex-btn-primary inline-flex items-center gap-2 whitespace-nowrap">
                    Continue active return
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Flash status (§3.9 success alert) --}}
    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--ds-green, #059669);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- KPI tiles (§3.2) --}}
    <div class="corex-kpi-grid" data-tour="comp-rcr-kpis">
        <x-corex-kpi-card title="Total submissions" :value="number_format($submissions->count())" />
        <x-corex-kpi-card title="Active returns" :value="number_format($activeCount)" />
    </div>

    {{-- Active submission (§3.3 status card) --}}
    @if($active)
        @php
            [$lbl, $badgeClass] = $statusBadge($active->status);
            $days     = $active->daysToDeadline();
            $overdue  = $days < 0;
            $nearDue  = $days >= 0 && $days <= 7;
            // Near-deadline = amber (needs attention); overdue = crimson (genuine danger); else brand. Never red for a normal countdown (§1.5).
            $accent   = $overdue ? 'var(--ds-crimson, #c41e3a)' : ($nearDue ? 'var(--ds-amber, #f59e0b)' : 'var(--brand-icon, #0ea5e9)');
        @endphp
        <div class="ds-status-card" style="border-left: 4px solid {{ $accent }};">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <h2 class="ds-section-header" style="margin: 0 0 8px 0;">
                        Active 2026 Submission · {{ $active->questionnaire->title }}
                    </h2>
                    <div class="flex items-center gap-3 flex-wrap text-sm">
                        <span class="ds-badge {{ $badgeClass }}">{{ $lbl }}</span>
                        <span style="color: var(--text-muted);">Deadline: {{ $active->submission_deadline->format('j F Y') }}</span>
                        <span class="font-semibold" style="color: {{ $overdue ? 'var(--ds-crimson, #c41e3a)' : ($nearDue ? 'var(--ds-amber, #f59e0b)' : 'var(--text-secondary)') }};">
                            {{ $overdue ? abs($days) . ' day(s) overdue' : ($days === 0 ? 'Due today' : $days . ' day(s) remaining') }}
                        </span>
                    </div>
                </div>
                <a href="{{ route('corex.compliance.rcr.show', $active->id) }}" class="corex-btn-primary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0">
                    Continue
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
        </div>
    @endif

    {{-- Start a new submission (§3.3 card + §3.6 form) --}}
    <div class="rounded-md p-4" data-tour="comp-rcr-start" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="ds-section-header" style="margin: 0 0 12px 0;">Start a new submission</h2>
        <form method="POST" action="{{ route('corex.compliance.rcr.store') }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
            @csrf
            <div class="flex-1 min-w-0">
                <label for="questionnaire_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Questionnaire</label>
                <select id="questionnaire_id" name="questionnaire_id" required
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($availableQuestionnaires as $aq)
                        <option value="{{ $aq->id }}">{{ $aq->title }} — due {{ $aq->submission_deadline->format('j M Y') }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="corex-btn-primary whitespace-nowrap flex-shrink-0">Start submission</button>
        </form>
        <p class="mt-2 text-xs" data-tour="comp-rcr-autopop" style="color: var(--text-muted);">
            Starting a new submission auto-populates known data (FICA officers, RMCP status, transaction counts) so you only fill in what CoreX can't infer.
        </p>
    </div>

    {{-- Submissions table (§3.7) --}}
    @if($submissions->isEmpty())
        {{-- Empty state (§3.10) --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No RCR submissions yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Use “Start a new submission” above to begin your 2026 Risk &amp; Compliance Return.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Questionnaire</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Period</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deadline</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Assigned to</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($submissions as $s)
                        @php [$lbl, $badgeClass] = $statusBadge($s->status); @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $s->questionnaire?->title }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ $s->reporting_period_from->format('j M Y') }} → {{ $s->reporting_period_to->format('j M Y') }}
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $s->submission_deadline->format('j M Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $badgeClass }}">{{ $lbl }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $s->assignedCo?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('corex.compliance.rcr.show', $s->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">Open →</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
