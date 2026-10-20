@extends('layouts.corex')

{{--
    .ai/specs/lease-notices.md §5 - the review screen: the notice beside the lease's notice terms, the flags and the decision (accept / accept
    with a corrected date / reject). Nothing reaches the other party until the agent accepts; acceptance tells them.
--}}

@section('content')
@php
    $address = $lease->property?->buildDisplayAddress() ?: ('Lease #' . $lease->id);
    $unit = fn ($v) => $v ?: 'not stated';
    $badge = $notice->isReceived() ? 'ds-badge-warning' : ($notice->isAccepted() ? 'ds-badge-success' : 'ds-badge-muted');
    $warnCount = collect($flags)->where('severity', 'warn')->count();
@endphp
<div class="p-6 space-y-5 max-w-6xl" data-qa="lease-notice-review">
    <div class="flex flex-wrap items-center justify-between gap-3" data-tour="rops-lnd-head">
        <div>
            <h1 class="text-lg font-semibold">{{ $notice->givenByLabel() }}'s notice - {{ $address }}</h1>
            <p class="text-xs" style="color: var(--text-muted);">
                <span class="ds-badge {{ $badge }}" data-notice-status>{{ $notice->statusLabel() }}</span>
                @if($notice->trashed()) <span class="ds-badge ds-badge-muted">Archived</span> @endif
                Lease #{{ $lease->id }} - tenant {{ $lease->tenantNames() }}
            </p>
        </div>
        <div class="flex gap-2">
@feature('rental-leases')
            <a href="{{ route('corex.leases.show', $lease) }}" class="corex-btn-outline text-xs">Open lease</a>
@endfeature
            <a href="{{ route('corex.lease-notices.index') }}" class="corex-btn-outline text-xs">All notices</a>
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson);" data-qa="lease-notice-errors">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        {{-- ── the notice ─────────────────────────────────────────────── --}}
        <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);" data-qa="notice-details" data-tour="rops-lnd-notice">
            <h2 class="text-sm font-semibold">The notice</h2>
            <dl class="grid grid-cols-3 gap-y-1 text-xs">
                <dt style="color: var(--text-muted);">Given by</dt><dd class="col-span-2">{{ $notice->givenByLabel() }}{{ $notice->givenByContact ? ' - ' . $notice->givenByContact->full_name : '' }}</dd>
                <dt style="color: var(--text-muted);">How</dt><dd class="col-span-2">{{ $notice->via === 'agent' ? 'Recorded by ' . ($notice->recordedByUser?->name ?? 'an agent') . ' on their behalf' : 'Given from the portal' }}</dd>
                <dt style="color: var(--text-muted);">Date given</dt><dd class="col-span-2">{{ $notice->notice_date->format('j F Y') }}</dd>
                <dt style="color: var(--text-muted);">Move-out / end date given</dt><dd class="col-span-2">{{ $notice->intended_end_date->format('j F Y') }}</dd>
                @if($notice->accepted_end_date)
                    <dt style="color: var(--text-muted);">Accepted end date</dt><dd class="col-span-2"><strong>{{ $notice->accepted_end_date->format('j F Y') }}</strong></dd>
                @endif
                <dt style="color: var(--text-muted);">Reason</dt><dd class="col-span-2">{{ $notice->reason ?: 'none given' }}</dd>
                <dt style="color: var(--text-muted);">Attachment</dt>
                <dd class="col-span-2">@if($notice->hasAttachment())<a href="{{ route('corex.lease-notices.attachment', $notice->id) }}" style="color: var(--brand-icon, #0ea5e9);">{{ $notice->attachment_name }}</a>@else none @endif</dd>
            </dl>

            @if($notice->isReceived())
                <details class="pt-2" data-tour="rops-lnd-correct">
                    <summary class="text-xs cursor-pointer" style="color: var(--brand-icon, #0ea5e9);">Correct these details</summary>
                    <form method="POST" action="{{ route('corex.lease-notices.update', $notice->id) }}" class="space-y-2 pt-2">
                        @csrf @method('PUT')
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-xs font-medium">Date given</label><input type="date" name="notice_date" value="{{ old('notice_date', $notice->notice_date->toDateString()) }}" max="{{ now()->toDateString() }}" required class="prop-input mt-1"></div>
                            <div><label class="text-xs font-medium">End date given</label><input type="date" name="intended_end_date" value="{{ old('intended_end_date', $notice->intended_end_date->toDateString()) }}" required class="prop-input mt-1"></div>
                        </div>
                        <div><label class="text-xs font-medium">Reason</label><textarea name="reason" maxlength="2000" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('reason', $notice->reason) }}</textarea></div>
                        <button type="submit" class="corex-btn-outline text-xs">Save correction</button>
                    </form>
                </details>
            @endif
        </div>

        {{-- ── the lease's terms ──────────────────────────────────────── --}}
        <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);" data-qa="notice-terms" data-tour="rops-lnd-terms">
            <h2 class="text-sm font-semibold">What the lease says</h2>
            <dl class="grid grid-cols-3 gap-y-1 text-xs">
                <dt style="color: var(--text-muted);">Lease term</dt>
                <dd class="col-span-2">{{ $terms['lease_start'] ? \Carbon\Carbon::parse($terms['lease_start'])->format('j M Y') : '?' }} to {{ $terms['month_to_month'] ? 'month-to-month' : ($terms['lease_end'] ? \Carbon\Carbon::parse($terms['lease_end'])->format('j M Y') : 'no end date') }}</dd>
                <dt style="color: var(--text-muted);">Notice period</dt><dd class="col-span-2" data-term="notice-period">{{ $terms['period_text'] ?: 'not stated' }}</dd>
                <dt style="color: var(--text-muted);">Earliest date notice may be given</dt><dd class="col-span-2">{{ $terms['earliest_notice_date'] ? \Carbon\Carbon::parse($terms['earliest_notice_date'])->format('j M Y') : 'no restriction' }}</dd>
                <dt style="color: var(--text-muted);">Early cancellation</dt>
                <dd class="col-span-2" data-term="early-cancellation">
                    @if($terms['early_allowed'])
                        Allowed{{ $terms['early_notice_text'] ? ' on ' . $terms['early_notice_text'] . ' notice' : '' }}
                    @else
                        Not allowed by the lease
                    @endif
                </dd>
                <dt style="color: var(--text-muted);">Early-cancellation penalty</dt><dd class="col-span-2">{{ $terms['penalty'] ?: 'none stated' }}</dd>
                <dt style="color: var(--text-muted);">Consumer Protection Act</dt><dd class="col-span-2" data-term="cpa">A tenant may cancel a fixed term on {{ $terms['cpa_business_days'] }} business days' written notice</dd>
                <dt style="color: var(--text-muted);">Checked against the signed lease?</dt><dd class="col-span-2">{{ $terms['confirmed'] ? 'Yes' : ($terms['own_terms'] ? 'Not yet confirmed' : 'The lease holds none - the agency defaults are used') }}</dd>
            </dl>
        </div>
    </div>

    {{-- ── flags ─────────────────────────────────────────────────────── --}}
    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);" data-qa="notice-flags" data-tour="rops-lnd-flags">
        <h2 class="text-sm font-semibold">Does it fit the lease and the law? {{ $warnCount ? '(' . $warnCount . ' to check)' : '' }}</h2>
        @forelse($flags as $f)
            <div class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border); background: {{ $f['severity'] === 'warn' ? 'color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent)' : 'var(--surface-2)' }};" data-flag="{{ $f['code'] }}" data-severity="{{ $f['severity'] }}">
                <strong>{{ $f['severity'] === 'warn' ? 'Check: ' : 'Note: ' }}{{ $f['title'] }}</strong>
                <div>{{ $f['detail'] }}</div>
                @if(!empty($f['suggested_end_date']))<div style="color: var(--text-muted);">Earliest end date that fits: {{ \Carbon\Carbon::parse($f['suggested_end_date'])->format('j F Y') }}</div>@endif
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">Nothing to flag: the dates fit the lease's notice terms.</p>
        @endforelse
    </div>

    {{-- ── decide ────────────────────────────────────────────────────── --}}
    @if($notice->isReceived() && !$notice->trashed())
        <div class="grid gap-4 md:grid-cols-2" data-qa="notice-decision" data-tour="rops-lnd-decision">
            <form method="POST" action="{{ route('corex.lease-notices.accept', $notice->id) }}" data-tour="rops-lnd-accept" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);"
                  x-data="{ end: '{{ old('end_date', $notice->intended_end_date->toDateString()) }}', given: '{{ $notice->intended_end_date->toDateString() }}', suggested: @js($suggestedEnd) }">
                @csrf
                <h2 class="text-sm font-semibold">Accept</h2>
                <p class="text-xs" style="color: var(--text-muted);">Sets the move-out date on the lease. Change the date to accept it with a corrected date.</p>
                <div>
                    <label class="text-xs font-medium">Move-out / end date to record</label>
                    <input type="date" name="end_date" data-tour="rops-lnd-end-date" x-model="end" required max="{{ now()->addYears(2)->toDateString() }}" class="prop-input mt-1">
                    <template x-if="suggested && suggested !== end">
                        <button type="button" class="text-xs mt-1" style="color: var(--brand-icon, #0ea5e9);" @click="end = suggested">Use the earliest date that fits (<span x-text="suggested"></span>)</button>
                    </template>
                </div>
                <div>
                    <label class="text-xs font-medium"><span x-show="end !== given">Why the date was changed (required)</span><span x-show="end === given">Note {{ $hasWarning ? '- required while there are items to check above: why it is fine as given' : '(optional)' }}</span></label>
                    <textarea name="note" maxlength="1000" data-tour="rops-lnd-note" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('note') }}</textarea>
                </div>
                @include('corex.leases._notice-outcome-fields', ['isReopening' => old('notice_outcome') !== null, 'showAvailableFromOnPortals' => true])
                <button type="submit" class="corex-btn-primary text-xs" data-tour="rops-lnd-accept-btn">Accept notice</button>
            </form>

            <form method="POST" action="{{ route('corex.lease-notices.reject', $notice->id) }}" data-tour="rops-lnd-reject" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @csrf
                <h2 class="text-sm font-semibold">Reject</h2>
                <p class="text-xs" style="color: var(--text-muted);">The notice is not accepted. {{ $notice->via === 'portal' ? 'The person who gave it is told why, by email and in their portal.' : '' }} The other party is told nothing.</p>
                <div>
                    <label class="text-xs font-medium">Reason (required)</label>
                    <textarea name="reason" maxlength="1000" required data-tour="rops-lnd-reject-reason" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('reason') }}</textarea>
                </div>
                <button type="submit" class="corex-btn-primary text-xs" data-tour="rops-lnd-reject-btn">Reject notice</button>
            </form>
        </div>
    @endif

    {{-- ── the decision made ─────────────────────────────────────────── --}}
    @if(!$notice->isReceived())
        <div class="rounded-md p-4 text-sm space-y-1" style="background: var(--surface); border: 1px solid var(--border);" data-qa="notice-decided">
            <h2 class="text-sm font-semibold">Decision</h2>
            <p class="text-xs">
                {{ $notice->statusLabel() }}{{ $notice->decision_kind === 'corrected' ? ' with a corrected date' : '' }}
                by {{ $notice->decidedByUser?->name ?? 'an agent' }} on {{ $notice->decided_at?->format('j M Y H:i') }}.
                @if($notice->decision_note) <br>Note: {{ $notice->decision_note }} @endif
            </p>
        </div>
    @endif

    {{-- ── the other party ───────────────────────────────────────────── --}}
    @if($notice->isAccepted())
        <div class="rounded-md p-4 text-sm space-y-1" style="background: var(--surface); border: 1px solid var(--border);" data-qa="notice-other-party">
            <h2 class="text-sm font-semibold">The other party</h2>
            <p class="text-xs">
                The {{ $notice->otherParty() }} ({{ $otherPartyNames ?: 'no one linked' }})
                @if($notice->other_party_told_at) was told on {{ $notice->other_party_told_at->format('j M Y H:i') }} - by email, and they see the notice in their portal under Notices.
                @else has <strong>not</strong> been told - there is no email address on file for them, or the email could not be sent (the lease log says which). Phone them, or add their email address on the contact. They still see the notice in their portal under Notices. @endif
            </p>
        </div>
    @endif

    <div class="flex gap-2" data-tour="rops-lnd-archive">
        @if($notice->trashed())
            <form method="POST" action="{{ route('corex.lease-notices.restore', $notice->id) }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Restore</button></form>
        @else
            <form method="POST" action="{{ route('corex.lease-notices.archive', $notice->id) }}" onsubmit="return confirm('Archive this notice? It can be restored.')">@csrf<button type="submit" class="corex-btn-outline text-xs">Archive</button></form>
        @endif
    </div>
</div>
@endsection
