{{--
    .ai/specs/rental-work-orders.md §17.8 — the Emergency approval panel. Emergency work ALWAYS needs the owner to agree: there is no
    override. The office phones/messages the owner and records the agreement here — who at the owner's end, how, when, why, an optional
    attachment (e.g. a WhatsApp screenshot) — with NO cost attached. A mistake is voided with a reason and re-recorded, never edited.
    Needs $workOrder, $isOpen, $ownerContacts.
--}}
@php
    $emActive = $workOrder->activeEmergencyApproval();
    $emVoided = $workOrder->emergencyApprovals()->whereNotNull('voided_at')->get();
    $emTz = $workOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $emViaLabel = fn (?string $via) => $via ? ucfirst(str_replace('_', ' ', $via)) : '—';
@endphp
{{-- Johan, 8 Oct 2026: shown only while an emergency agreement can still matter - not once the owner has approved or the work has started - and collapsed
     until opened (an emergency is the exception, not a step). An agreement already on record always shows. --}}
@if($emActive || $emVoided->isNotEmpty() || ($isOpen && $workOrder->owner_approval_status !== \App\Models\RentalWorkOrder::APPROVAL_APPROVED && ! in_array($workOrder->status, [\App\Models\RentalWorkOrder::STATUS_IN_PROGRESS, \App\Models\RentalWorkOrder::STATUS_DISPUTED], true)))
<div id="emergency-panel" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <details @if($emActive || $errors->has('emergency') || $errors->has('owner_decision') || $workOrder->isDeclaredEmergency()) open @endif data-emergency-collapse>
    <summary class="text-sm font-semibold cursor-pointer">Emergency <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; only if the work cannot wait for the owner</span></summary>

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
    @elseif($isOpen)
        {{-- AT-452 round 3 (§20): an emergency is DECLARED here, with a reason - that is what lets the emergency limit apply. The owner's own word is recorded with
             "Record the owner's decision" (Approval panel above); the old record-the-agreement form is gone, old records stay (above) and can still be voided. --}}
        @if($workOrder->isDeclaredEmergency())
            <div class="text-sm space-y-1" data-emergency-declared>
                <span class="ds-badge ds-badge-warning">Declared an emergency</span>
                <div><span style="color: var(--text-muted);">Reason:</span> {{ $workOrder->emergency_declared_reason }}</div>
                <div class="text-xs" style="color: var(--text-muted);">
                    Declared by {{ \App\Models\User::withoutGlobalScopes()->find($workOrder->emergency_declared_by_user_id)?->name ?? 'a former user' }} on {{ $workOrder->emergency_declared_at?->copy()->setTimezone($emTz)->format('j M Y H:i') }}.
                    The emergency limit now applies: R{{ number_format($workOrder->spendThreshold(), 2) }}. Above that the owner must approve (their link, or record what they told you).
                </div>
            </div>
        @else
            <p class="text-xs" style="color: var(--text-muted);">
                For genuine emergencies only. Declaring an emergency (with the reason) lets you approve the repair yourself up to the emergency limit
                (R{{ number_format(max($workOrder->spendThreshold(), (float) ($workOrder->property ? \App\Models\RentalWorkOrderSetting::emergencyThresholdFor($workOrder->property) : \App\Models\RentalWorkOrderSetting::emergencySpendThresholdFor($workOrder->agency_id))), 2) }});
                the owner is told at the start and when it is finished. Above the limit the owner must approve it.
            </p>
            @permission('rental_work_orders.create')
            <form method="POST" action="{{ route('corex.rental-work-orders.declare-emergency', $workOrder) }}" class="space-y-2" data-declare-emergency-form>
                @csrf
                @error('owner_decision')<div class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                <label class="text-xs font-medium" for="wo-emergency-reason">Why is this an emergency? (required)</label>
                <textarea id="wo-emergency-reason" name="emergency_reason" required rows="2" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);" data-emergency-reason>{{ old('emergency_reason') }}</textarea>
                <x-confirm-submit title="Declare an emergency?" message="Only for a real emergency. The emergency limit then applies to this job and the reason goes on the record under your name." confirm-label="Declare an emergency" data-declare-emergency>This is an emergency</x-confirm-submit>
            </form>
            @endpermission
        @endif
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
