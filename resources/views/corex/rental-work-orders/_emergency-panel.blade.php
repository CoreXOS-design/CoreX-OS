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
    <details @if($emActive || $errors->has('emergency')) open @endif data-emergency-collapse>
    <summary class="text-sm font-semibold cursor-pointer">Emergency approval <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; only if the work cannot wait for the owner</span></summary>

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
        <p class="text-xs" style="color: var(--text-muted);">
            For genuine emergencies only. The crew phones the office with the reason; the office phones or messages the owner; once the owner has agreed,
            record it here and the work can go ahead. Nothing about cost is recorded — it is settled afterwards.
        </p>
        @permission('rental_work_orders.record_emergency_approval')
        <details @if($errors->has('emergency') || old('approved_by_name')) open @endif>
            <summary class="corex-btn-primary text-xs cursor-pointer" style="display:inline-block;">Record owner's emergency approval</summary>
            <form method="POST" action="{{ route('corex.rental-work-orders.emergency-approval.store', $workOrder) }}" enctype="multipart/form-data" class="space-y-3 pt-3">
                @csrf
                @error('emergency')<div class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium">Who at the owner's end agreed? (required)</label>
                        <input type="text" name="approved_by_name" required maxlength="191" value="{{ old('approved_by_name') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        @error('approved_by_name')<div class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium">Owner on file (optional)</label>
                        <select name="owner_contact_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                            <option value="">— not listed —</option>
                            @foreach($ownerContacts as $oc)
                                <option value="{{ $oc->id }}" @selected((string) old('owner_contact_id') === (string) $oc->id)>{{ $oc->full_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium">How did the owner agree? (required)</label>
                        <select name="approved_via" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                            @foreach(\App\Models\RentalEmergencyApproval::VIAS as $via)
                                <option value="{{ $via }}" @selected(old('approved_via') === $via)>{{ $emViaLabel($via) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium">When did the owner agree? (required)</label>
                        <input type="datetime-local" name="approved_at" required value="{{ old('approved_at', now()->setTimezone($emTz)->format('Y-m-d\TH:i')) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        @error('approved_at')<div class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium">Why is it an emergency? (required)</label>
                        <textarea name="reason" required rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('reason') }}</textarea>
                        @error('reason')<div class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium">Who phoned the office about it? (optional)</label>
                        <input type="text" name="reported_by_crew_name" maxlength="191" value="{{ old('reported_by_crew_name') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="text-xs font-medium">Attachment (optional — e.g. a WhatsApp screenshot)</label>
                        <input type="file" name="attachment" accept="image/*,.pdf" class="w-full text-xs mt-1">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium">Notes (optional)</label>
                        <textarea name="notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('notes') }}</textarea>
                    </div>
                </div>
                <button type="submit" class="corex-btn-primary text-xs">Record the owner's agreement</button>
            </form>
        </details>
        @else
            <p class="text-xs" style="color: var(--text-muted);">Only staff with the emergency-approval permission can record this.</p>
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
