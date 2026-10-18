{{--
    .ai/specs/rental-work-orders.md §17.8 — HISTORY ONLY since rentals-faults-work-orders.md section 22.6: an emergency agreement recorded before stage 2 stays readable (and
    can be voided if it was a mistake). New emergencies are authorised in "Approve the cost" (_cost-decision): the "This is an emergency" tick, a required reason and the owner's
    emergency limit. There is no form here any more. Needs $workOrder, $isOpen.
--}}
@php
    $emActive = $workOrder->activeEmergencyApproval();
    $emVoided = $workOrder->emergencyApprovals()->whereNotNull('voided_at')->get();
    $emTz = $workOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $emViaLabel = fn (?string $via) => $via ? ucfirst(str_replace('_', ' ', $via)) : '—';
@endphp
{{-- Johan, 8 Oct 2026: shown only while an emergency agreement can still matter - not once the owner has approved or the work has started - and collapsed
     until opened (an emergency is the exception, not a step). An agreement already on record always shows. --}}
@if($emActive || $emVoided->isNotEmpty())
<div id="emergency-panel" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <details @if($emActive || $errors->has('emergency')) open @endif data-emergency-collapse>
    <summary class="text-sm font-semibold cursor-pointer">Earlier emergency approval <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; recorded before costs were approved in "Approve the cost"</span></summary>

    @if($emActive)
        <div class="text-sm space-y-1">
            <span class="ds-badge ds-badge-warning">Approved as emergency work — owner agreed</span>
            <div>Agreed by <strong>{{ $emActive->approved_by_name }}</strong>@if($emActive->ownerContact) ({{ $emActive->ownerContact->full_name }})@endif
                by {{ strtolower($emViaLabel($emActive->approved_via)) }} on {{ $emActive->approved_at?->copy()->setTimezone($emTz)->format('j M Y H:i') }}.</div>
            <div><span style="color: var(--text-muted);">Why it is an emergency:</span> {{ $emActive->reason }}</div>
            @if($emActive->reported_by_crew_name)<div><span style="color: var(--text-muted);">Reported to the office by:</span> {{ $emActive->reported_by_crew_name }}</div>@endif
            @if($emActive->notes)<div><span style="color: var(--text-muted);">Notes:</span> {{ $emActive->notes }}</div>@endif
            <div class="text-xs" style="color: var(--text-muted);">
                Recorded by {{ $emActive->recordedByUser?->name ?? 'a former user' }} on {{ $emActive->created_at?->copy()->setTimezone($emTz)->format('j M Y H:i') }}.
                No cost is attached — costs are captured and settled afterwards, and the owner sees the final amount flagged as emergency work.
                @if($emActive->attachment_path)
                    <a href="{{ route('corex.rental-work-orders.emergency-approval.attachment', [$workOrder, $emActive]) }}" class="underline">View attachment</a>
                @endif
            </div>
        </div>
        @permission('rental_work_orders.record_emergency_approval')
        @if($isOpen)
        <details>
            <summary class="corex-btn-outline text-xs cursor-pointer" style="display:inline-block;">Void this approval (recorded by mistake)</summary>
            <form method="POST" action="{{ route('corex.rental-work-orders.emergency-approval.void', [$workOrder, $emActive]) }}" class="space-y-2 pt-2">
                @csrf
                <label class="text-xs font-medium">Why is it being voided? (required)</label>
                <textarea name="void_reason" required rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">After voiding, the work order goes back to waiting for the owner's approval of the selected quote — record the right approval afterwards.</p>
                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Void emergency approval</button>
            </form>
        </details>
        @endif
        @endpermission
    @endif

    @if($emVoided->isNotEmpty())
        <ul class="text-xs space-y-1" style="color: var(--text-muted);">
            @foreach($emVoided as $v)
                <li>Voided {{ $v->voided_at?->copy()->setTimezone($emTz)->format('j M Y H:i') }} — agreed by {{ $v->approved_by_name }} on {{ $v->approved_at?->copy()->setTimezone($emTz)->format('j M Y H:i') }}: {{ $v->void_reason }}</li>
            @endforeach
        </ul>
    @endif
    </details>
</div>
@endif
