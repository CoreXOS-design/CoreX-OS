{{--
    .ai/specs/lease-notices.md §5.2 - the lease screen's Notice panel: every notice given on this lease (the tenant's or the landlord's) and
    where it stands. "Needs review" is the agent's job: nothing goes to the other party until it has been reviewed and accepted.
--}}
@php
    $leaseNotices = $lease->leaseNotices()->with(['givenByContact'])->limit(5)->get();
    $canReview = auth()->user()?->hasPermission('rental_notices.review');
@endphp
@if($leaseNotices->isNotEmpty())
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="lease-notice-panel">
    <h3 class="text-sm font-semibold">Notices</h3>
    @foreach($leaseNotices as $n)
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs rounded-md px-3 py-2" style="background: {{ $n->isReceived() ? 'color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent)' : 'var(--surface-2)' }}; border: 1px solid var(--border);" data-notice-row="{{ $n->id }}">
            <div>
                <strong>{{ $n->isReceived() ? 'Notice received - needs review' : $n->statusLabel() }}</strong>
                - {{ strtolower($n->givenByLabel()) }} gave notice on {{ $n->notice_date->format('j M Y') }}, ends {{ $n->effectiveEndDate()->format('j M Y') }}
                <span style="color: var(--text-muted);">({{ $n->via === 'agent' ? 'recorded by an agent' : 'from the portal' }})</span>
            </div>
            @feature('rental-notices')
                @if($canReview)
                    <a href="{{ route('corex.lease-notices.show', $n->id) }}" class="corex-btn-outline text-xs">{{ $n->isReceived() ? 'Review' : 'Open' }}</a>
                @endif
            @endfeature
        </div>
    @endforeach
</div>
@endif
