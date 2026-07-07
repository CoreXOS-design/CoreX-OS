{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="fica-intro">
                <h1 class="text-xl font-bold text-white leading-tight">FICA Compliance</h1>
                <p class="text-sm text-white/60">
                    @if($canSeeAll)
                        All FICA submissions for the agency.
                    @else
                        Your FICA verification requests.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('compliance.rmcp') }}" class="corex-btn-outline corex-btn-on-brand" data-tour="fica-rmcp">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    View RMCP
                </a>
                <a href="{{ route('compliance.fica.wet-ink.create') }}" class="corex-btn-outline corex-btn-on-brand" data-tour="fica-wetink">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Create Wet-Ink FICA
                </a>
                <a href="{{ route('compliance.fica.create') }}" class="corex-btn-primary" data-tour="fica-online">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Send Online FICA
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- Pipeline flow indicator --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between text-center text-xs gap-2">
            @php
                $stages = [
                    ['label' => 'Awaiting Client',        'count' => $counts['draft'],          'color' => 'var(--text-muted)'],
                    ['label' => 'Awaiting Agent Review',  'count' => $counts['submitted'],      'color' => 'var(--brand-icon, #0ea5e9)'],
                    /* Single source of truth: every stage counter reads the SAME
                       $counts array the tabs use (same query + scoping), so the
                       top counter and the tab always agree. Was $coQueueCount,
                       which is 0 for non-CO viewers and scoped differently for
                       a CO — making "Awaiting CO Approval" show 0 vs the tab's
                       real agent_approved count. */
                    ['label' => 'Awaiting CO Approval',   'count' => $counts['agent_approved'], 'color' => 'var(--ds-amber, #f59e0b)'],
                    ['label' => 'Complete',               'count' => $counts['approved'],       'color' => 'var(--ds-green, #059669)'],
                ];
            @endphp
            @foreach($stages as $i => $stage)
                <div class="flex-1">
                    <div class="text-[1.625rem] font-semibold leading-none" style="color: {{ $stage['color'] }};">{{ number_format($stage['count']) }}</div>
                    <div class="font-semibold mt-1" style="color: var(--text-secondary);">{{ $stage['label'] }}</div>
                </div>
                @if($i < count($stages) - 1)
                    <svg class="w-4 h-4 flex-shrink-0" style="color: var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                @endif
            @endforeach
        </div>
    </div>

    {{-- CO Queue summary alert --}}
    @if($isCO && ($coQueueStats['count'] ?? 0) > 0)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber, #f59e0b);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">
                <strong>{{ number_format($coQueueStats['count']) }} submission{{ $coQueueStats['count'] !== 1 ? 's' : '' }} awaiting your approval.</strong>
                @if($coQueueStats['oldest_days'] > 0)
                    <span style="color: var(--text-secondary);">Oldest: {{ number_format($coQueueStats['oldest_days']) }} day{{ $coQueueStats['oldest_days'] !== 1 ? 's' : '' }}.</span>
                @endif
            </div>
            <a href="{{ route('compliance.fica.index', ['tab' => 'co_queue']) }}" class="text-xs font-semibold whitespace-nowrap" style="color: var(--ds-amber, #f59e0b);">View CO Queue →</a>
        </div>
    @endif

    {{-- Tabs --}}
    @php
        $tabs = [];
        if ($isCO) {
            $tabs[] = ['key' => 'co_queue', 'label' => 'My CO Queue', 'count' => $coQueueCount];
        }
        $tabs = array_merge($tabs, [
            ['key' => 'all',                   'label' => 'All',                   'count' => $counts['all']],
            ['key' => 'draft',                 'label' => 'Awaiting Client',       'count' => $counts['draft']],
            ['key' => 'submitted',             'label' => 'Awaiting Agent Review', 'count' => $counts['submitted']],
            ['key' => 'agent_approved',        'label' => 'Awaiting CO Approval',  'count' => $counts['agent_approved']],
            ['key' => 'approved',              'label' => 'Approved',              'count' => $counts['approved']],
            ['key' => 'corrections_requested', 'label' => 'Corrections Needed',    'count' => $counts['corrections_requested']],
            ['key' => 'cancelled',             'label' => 'Cancelled',             'count' => $counts['cancelled']],
            ['key' => 'rejected',              'label' => 'Rejected',              'count' => $counts['rejected']],
        ]);
    @endphp
    <div class="flex flex-wrap gap-1 text-sm font-medium" style="border-bottom: 1px solid var(--border);">
        @foreach($tabs as $t)
            @php $active = $tab === $t['key']; @endphp
            <a href="{{ route('compliance.fica.index', ['tab' => $t['key']]) }}"
               class="px-4 py-2 transition-colors"
               style="{{ $active
                    ? 'color: var(--brand-icon, #0ea5e9); border-bottom: 2px solid var(--brand-icon, #0ea5e9); font-weight:600;'
                    : 'color: var(--text-secondary); border-bottom: 2px solid transparent;' }}">
                {{ $t['label'] }}
                <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full"
                      style="background: var(--surface-2); color: var(--text-secondary);">{{ number_format($t['count']) }}</span>
            </a>
        @endforeach
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('compliance.fica.index') }}"
          class="rounded-md p-3 flex flex-col sm:flex-row gap-2"
          style="background: var(--surface); border: 1px solid var(--border);">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Search by contact name or email..."
               class="flex-1 rounded-md px-3 py-2 text-sm"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        <div class="flex gap-2">
            <button type="submit" class="corex-btn-primary">Search</button>
            @if(request('search'))
                <a href="{{ route('compliance.fica.index', ['tab' => $tab]) }}" class="corex-btn-outline">Clear</a>
            @endif
        </div>
    </form>

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Contact</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Intake</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Entity</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Submitted</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent Review</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">CO Review</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Risk</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($submissions as $sub)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3">
                                @if($sub->contact)
                                    <div class="font-medium" style="color: var(--text-primary);">{{ $sub->contact->full_name }}</div>
                                    <div class="text-xs" style="color: var(--text-muted);">{{ $sub->contact->email }}</div>
                                @else
                                    <span style="color: var(--text-muted);">No contact</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($sub->intake_type === 'wet_ink')
                                    <span class="ds-badge ds-badge-warning">Wet-Ink</span>
                                @else
                                    <span class="ds-badge ds-badge-info">Online</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 capitalize" style="color: var(--text-secondary);">{{ $sub->entity_type }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                @if($sub->signed_at)
                                    {{ $sub->signed_at->format('d M Y') }}
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($sub->agent_verified_by)
                                    <div style="color: var(--text-primary);">{{ $sub->agentVerifiedBy->name ?? '—' }}</div>
                                    <div style="color: var(--text-muted);">{{ $sub->agent_verified_at?->format('d M Y') }}</div>
                                @else
                                    <span style="color: var(--text-muted);">Pending</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($sub->co_verified_by)
                                    <div style="color: var(--text-primary);">{{ $sub->coVerifiedBy->name ?? '—' }}</div>
                                    <div style="color: var(--text-muted);">{{ $sub->co_verified_at?->format('d M Y') }}</div>
                                @else
                                    <span style="color: var(--text-muted);">Pending</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($sub->risk_rating)
                                    @php
                                        $riskBadge = [
                                            1 => 'ds-badge ds-badge-success',
                                            2 => 'ds-badge ds-badge-warning',
                                            3 => 'ds-badge ds-badge-danger',
                                        ];
                                        $riskLabel = [1 => 'Low', 2 => 'Med', 3 => 'High'];
                                    @endphp
                                    <span class="{{ $riskBadge[$sub->risk_rating] ?? 'ds-badge ds-badge-default' }}">{{ $riskLabel[$sub->risk_rating] ?? '—' }}</span>
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $sc = [
                                        'draft' => 'ds-badge ds-badge-default',
                                        'submitted' => 'ds-badge ds-badge-info',
                                        'under_review' => 'ds-badge ds-badge-info',
                                        'agent_approved' => 'ds-badge ds-badge-warning',
                                        'corrections_requested' => 'ds-badge ds-badge-warning',
                                        'approved' => 'ds-badge ds-badge-success',
                                        'rejected' => 'ds-badge ds-badge-danger',
                                        'cancelled' => 'ds-badge ds-badge-default',
                                    ];
                                @endphp
                                <span class="{{ $sc[$sub->status] ?? 'ds-badge ds-badge-default' }}">{{ $sub->status_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @php
                                    $authUser = auth()->user();
                                    $isMySubmission = $sub->requested_by === $authUser->id;
                                    $canCoReview = $isCO || $isAdmin;
                                @endphp
                                <div class="flex items-center justify-end gap-2">
                                    @if($sub->intake_type !== 'wet_ink' && $sub->token && in_array($sub->status, ['draft', 'corrections_requested']))
                                    <button type="button" title="Copy form link"
                                            onclick="ficaCopyLink('{{ url('/fica/' . $sub->token) }}', this)"
                                            class="inline-flex items-center justify-center w-6 h-6 transition"
                                            style="color: var(--text-muted);">
                                        <svg class="fica-link-icon w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                        <svg class="fica-check-icon w-3.5 h-3.5" style="display:none; color: var(--ds-green, #059669);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    </button>
                                    @endif

                                    <a href="{{ route('compliance.fica.show', $sub) }}" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">View</a>

                                    @if($sub->status === 'draft' && $sub->intake_type !== 'wet_ink')
                                        <form method="POST" action="{{ route('compliance.fica.resend', $sub) }}" class="inline">@csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">Resend</button>
                                        </form>
                                        <form method="POST" action="{{ route('compliance.fica.cancel', $sub) }}" class="inline"
                                              onsubmit="return confirm('Cancel this FICA request? The client link will be voided.')">@csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson, #c41e3a);">Cancel</button>
                                        </form>
                                    @elseif($sub->status === 'submitted' && $isMySubmission)
                                        <a href="{{ route('compliance.fica.show', $sub) }}" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">Verify</a>
                                    @elseif($sub->status === 'agent_approved' && $canCoReview)
                                        <a href="{{ route('compliance.fica.compliance-review', $sub) }}" class="text-xs font-semibold" style="color: var(--ds-amber, #f59e0b);">Review &amp; Approve</a>
                                    @elseif($sub->status === 'corrections_requested' && $isMySubmission)
                                        <a href="{{ route('compliance.fica.show', $sub) }}" class="text-xs font-semibold" style="color: var(--ds-amber, #f59e0b);">Fix</a>
                                    @endif

                                    @if($sub->status === 'approved')
                                        <a href="{{ route('compliance.fica.pdf', $sub) }}" target="_blank" class="text-xs font-semibold" style="color: var(--text-muted);" title="Download PDF">PDF</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                @if($tab === 'co_queue')
                                    No submissions awaiting compliance officer review.
                                @elseif($tab === 'draft')
                                    No submissions awaiting client response.
                                @elseif($tab === 'submitted')
                                    No submissions awaiting agent review.
                                @elseif($tab === 'cancelled')
                                    No cancelled submissions.
                                @else
                                    No FICA submissions found.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($submissions->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $submissions->links() }}
            </div>
        @endif
    </div>
</div>

<script>
    function ficaCopyLink(text, btn) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        var linkIcon = btn.querySelector('.fica-link-icon');
        var checkIcon = btn.querySelector('.fica-check-icon');
        if (linkIcon && checkIcon) {
            linkIcon.style.display = 'none';
            checkIcon.style.display = '';
            setTimeout(function() { linkIcon.style.display = ''; checkIcon.style.display = 'none'; }, 1500);
        }
    }
</script>
@endsection
