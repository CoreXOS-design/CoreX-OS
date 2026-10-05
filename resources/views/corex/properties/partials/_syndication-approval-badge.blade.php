{{--
    Layer 3 approval badge — .ai/specs/syndication-approval-gate.md §2.

    Deliberately its OWN tag, never the property's `status` column: that column
    is the exact word CoreX sends to Property24 and Private Property, so a
    "pending" value in it would tell the portals a status that does not exist.
    The listing keeps saying Active / Draft as it always has; this sits beside it.

    Requires: $approvalState (App\Services\Syndication\SyndicationApprovalState)
    Renders NOTHING when the agency has not switched layer 3 on.
--}}
@php
    $st = $approvalState ?? null;
@endphp

@if($st && ! $st->isSilent() && $st->badge !== \App\Services\Syndication\SyndicationApprovalState::BADGE_NONE)
    @php
        $tone = match($st->badge) {
            \App\Services\Syndication\SyndicationApprovalState::BADGE_APPROVED => ['bg' => 'rgba(16,185,129,0.12)', 'fg' => 'var(--ds-emerald, #10b981)', 'bd' => 'rgba(16,185,129,0.35)'],
            \App\Services\Syndication\SyndicationApprovalState::BADGE_AWAITING => ['bg' => 'rgba(245,158,11,0.12)', 'fg' => 'var(--ds-amber, #f59e0b)',   'bd' => 'rgba(245,158,11,0.35)'],
            \App\Services\Syndication\SyndicationApprovalState::BADGE_REJECTED => ['bg' => 'rgba(220,38,38,0.12)',  'fg' => 'var(--ds-crimson, #dc2626)', 'bd' => 'rgba(220,38,38,0.35)'],
            default                                                            => ['bg' => 'var(--surface-2)',      'fg' => 'var(--text-muted)',          'bd' => 'var(--border)'],
        };
    @endphp
    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[0.6875rem] font-semibold whitespace-nowrap"
          style="background:{{ $tone['bg'] }}; color:{{ $tone['fg'] }}; border:1px solid {{ $tone['bd'] }};"
          @if($st->badge === \App\Services\Syndication\SyndicationApprovalState::BADGE_REJECTED && $st->lastDecisionNote)
              title="{{ $st->lastDecisionNote }}"
          @elseif($st->badge === \App\Services\Syndication\SyndicationApprovalState::BADGE_AWAITING && $st->requestedByName)
              title="Sent by {{ $st->requestedByName }}{{ $st->requestedAt ? ' on ' . $st->requestedAt->format('d M Y H:i') : '' }}"
          @elseif($st->badge === \App\Services\Syndication\SyndicationApprovalState::BADGE_APPROVED && $st->approvedByName)
              title="Approved by {{ $st->approvedByName }}{{ $st->approvedAt ? ' on ' . $st->approvedAt->format('d M Y') : '' }}"
          @endif>
        {{ $st->badgeLabel() }}
    </span>
@endif
