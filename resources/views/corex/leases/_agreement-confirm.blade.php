{{--
    .ai/specs/leases.md §15.9 (Build L3c) — the lease screen in `mode=confirm`. Shown when the lease agreement was changed
    in e-sign (or something in it cannot be read), at the agent's final approval or after a signing that skipped it.

    Every compared field is listed, filled with what the AGREEMENT prints. A field that differs is highlighted and
    carries the lease's own value beside it ("Lease says R6 500 · Agreement says R6 940"); fields that agree are plain.
    A different person is never accepted (R7): it blocks, with the way out. A value the agreement does not show
    clearly is an input — "type what the agreement says". One button. Leaving the screen changes nothing.

    $verdict (LeaseAgreementCheck::verdict) · $stage approve|activate|preview · $postUrl · $agreementUrl · $partyLinks · $entered
--}}
@php
    $rows = collect($verdict['rows']);
    $needs = $verdict['needs_confirmation'];
    $blocked = $verdict['blocked'];
    $buttonLabel = $stage === 'activate' ? 'Confirm and activate' : 'Confirm lease details and approve';
    $addressLine = $lease->property?->buildDisplayAddress() ?? 'Unknown property';
    $groupLabels = ['lease' => 'Lease', 'parties' => 'Who signs', 'term' => 'Term', 'agreement' => 'Agreement details', 'schedule' => 'Schedule', 'calculated' => 'Worked out from the figures'];
@endphp
<div class="p-6 max-w-2xl mx-auto space-y-4" data-qa="agreement-confirm">
    <div>
        <h1 class="text-lg font-semibold">Confirm lease details</h1>
        <p class="text-sm" style="color: var(--text-muted);">{{ $addressLine }} — {{ $lease->tenantNames() }}</p>
    </div>

    @if(session('error'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-qa="confirm-error">{{ session('error') }}</div>
    @endif

    @if($needs)
        <p class="text-sm font-medium" data-qa="confirm-heading">The agreement was changed in e-sign. Check each highlighted value, then confirm.</p>
    @else
        <p class="text-sm" data-qa="confirm-clean">The agreement and the lease agree — there is nothing to confirm.</p>
    @endif

    <form method="POST" action="{{ $postUrl ?? '#' }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <input type="hidden" name="lease_confirm[fingerprint]" value="{{ $verdict['fingerprint'] }}">

        @foreach($rows->groupBy('group') as $group => $groupRows)
            <div class="space-y-2">
                <p class="prop-label">{{ $groupLabels[$group] ?? ucfirst($group) }}</p>
                @foreach($groupRows as $row)
                    @php
                        $state = $row['state'];
                        $differs = $state === 'differs';
                        $isBlocked = $state === 'blocked';
                        $cannot = $state === 'cannot_verify';
                        $info = $state === 'informational';
                        $wrap = $differs ? 'border: 1px solid var(--ds-amber, #d97706); background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent);'
                            : (($isBlocked || $cannot) ? 'border: 1px solid var(--ds-crimson); background: color-mix(in srgb, var(--ds-crimson) 8%, transparent);'
                            : 'border: 1px solid var(--border); background: var(--surface-2);');
                    @endphp
                    <div class="rounded px-3 py-2 text-sm space-y-1" style="{{ $wrap }}" data-qa="confirm-row-{{ $row['key'] }}" data-state="{{ $state }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span class="text-xs" style="color: var(--text-muted);">{{ $row['label'] }}</span>
                            @if($cannot)
                                <span class="text-xs" style="color: var(--ds-crimson);">Type what the agreement says</span>
                            @endif
                        </div>

                        @if($cannot)
                            <input type="text" name="lease_confirm[entered][{{ $row['key'] }}]" value="{{ $entered[$row['key']] ?? '' }}"
                                   class="prop-input" maxlength="2000" @disabled(! $postUrl) aria-label="{{ $row['label'] }} — as printed in the agreement">
                            <p class="text-xs" style="color: var(--text-muted);">
                                The lease says {{ $row['lease'] ?? 'nothing' }}; the agreement {{ $row['agreement'] !== null && $row['agreement'] !== '' ? 'shows “' . $row['agreement'] . '”, which cannot be read as a ' . ($row['type'] ?? 'value') : 'shows nothing here' }}.
                            </p>
                        @elseif($differs || $isBlocked)
                            <div class="font-medium">{{ $row['agreement'] ?? '—' }}</div>
                            <p class="text-xs" data-qa="confirm-says">Lease says <strong>{{ $row['lease'] ?? 'not on record' }}</strong> · Agreement says <strong>{{ $row['agreement'] ?? '—' }}</strong></p>
                            @if(! empty($row['entered']))
                                <p class="text-xs" style="color: var(--text-muted);">Typed by you from the document.</p>
                            @endif
                        @elseif($info)
                            <div>{{ $row['agreement'] ?? '—' }}</div>
                            <p class="text-xs" style="color: var(--text-muted);" data-qa="confirm-calculated">Calculated value differs — the figures give {{ $row['lease'] }}. Nothing on the lease changes.</p>
                        @else
                            <div>{{ $row['agreement'] ?? '—' }}</div>
                        @endif

                        @if($isBlocked)
                            @php $side = str_starts_with($row['key'], 'landlord') ? 'landlord' : 'tenant'; @endphp
                            <p class="text-xs font-medium" style="color: var(--ds-crimson);" data-qa="confirm-person-blocked">The agreement names a different person. A change of tenant is a new lease.</p>
                            <p class="text-xs flex flex-wrap gap-x-3">
                                @foreach($partyLinks[$side] ?? [] as $party)
                                    <a href="{{ $party['url'] }}" target="_blank" rel="noopener" class="underline">Correct the contact — {{ $party['name'] }}</a>
                                @endforeach
                                <a href="{{ route('corex.leases.agreement.confirm', $lease) }}" class="underline">Re-check</a>
                                <a href="{{ $agreementUrl }}" class="underline">Back to the agreement</a>
                            </p>
                            @if(count($partyLinks[$side] ?? []) > 1 && ! preg_match('/_\d+$/', $row['key']))
                                {{-- Joint parties: an agreement that prints each person in a box of their own is mapped one person at a time. --}}
                                <p class="text-xs" style="color: var(--text-muted);" data-qa="confirm-joint-hint">There is more than one {{ $side }} on this lease. If your agreement prints each person in a box of their own, ask an administrator to map them one by one under Settings → Rental lease agreements.</p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @if(! empty($verdict['text_changes']))
            <div class="space-y-1" data-qa="confirm-text-changes">
                <p class="prop-label">Changes to the wording (read only)</p>
                <ul class="text-xs space-y-1">
                    @foreach($verdict['text_changes'] as $change)
                        <li class="rounded px-2 py-1" style="background: var(--surface-2);">
                            <span style="text-decoration: line-through; color: var(--text-muted);">{{ $change['old'] ?: '—' }}</span> → {{ $change['new'] ?: '—' }}
                            <span style="color: var(--text-muted);">{{ $change['actor'] ? ' · ' . $change['actor'] : '' }}{{ $change['at'] ? ' · ' . \Illuminate\Support\Carbon::parse($change['at'])->format('j M Y H:i') : '' }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs" style="color: var(--text-muted);">A lease value changed only inside the wording is not compared. Read it and, if it matters, change the lease.</p>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
            <a href="{{ $agreementUrl }}" class="corex-btn-outline text-xs" data-qa="confirm-back">Back to the agreement</a>
            @if($postUrl && $needs)
                <button type="submit" class="corex-btn-primary text-xs" @disabled($blocked) @if($blocked) aria-disabled="true" style="opacity: 0.5; cursor: not-allowed;" @endif data-qa="confirm-submit">{{ $buttonLabel }}</button>
            @endif
        </div>
        @if($stage === 'preview')
            <p class="text-xs text-right" style="color: var(--text-muted);" data-qa="confirm-preview-note">The agreement is still out for signing. You can confirm these details once everyone has signed and it is waiting for your approval.</p>
        @endif
    </form>
</div>
