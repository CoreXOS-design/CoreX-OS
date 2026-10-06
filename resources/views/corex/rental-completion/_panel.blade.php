{{--
    .ai/specs/rental-work-orders.md §17.10 / §17.9.6 — BUILD 3: the office's completion-check panel, shared by the work
    order screen and the job card screen (their `_completion-panel` slot partials include this one file, so the two can
    never word or behave differently).

    Receives: $workOrder (RentalWorkOrder), $context ('work_order' | 'job_card'). Shows, in order:
      1. the Dispute panel (tenant's note + photos, "Send back to crew / contractor") while the work order is DISPUTED;
      2. the tenant check status and "Record tenant's answer" while a round awaits the tenant;
      3. "Contractor reports done" for outside work;
      4. every completion round, newest first (nothing is ever deleted).
    Every action needs the Role Manager key `rental_work_orders.manage_completion`; the controller re-checks it, and
    the own/branch/agency scope of the work order, server-side.
--}}
@php
    $cpOpenStatuses = [\App\Models\RentalWorkOrder::STATUS_REPORTED, \App\Models\RentalWorkOrder::STATUS_ORDERED, \App\Models\RentalWorkOrder::STATUS_IN_PROGRESS, \App\Models\RentalWorkOrder::STATUS_DISPUTED];
    $cpRounds = $workOrder->completionRounds()->with('photos')->get();
    $cpLatest = $cpRounds->last();
    $cpOpenRound = $cpLatest && $cpLatest->isAwaitingTenant() ? $cpLatest : null;
    $cpDisputed = $workOrder->hasOpenDispute();
    $cpDisputedRound = $cpDisputed ? $cpRounds->where('outcome', \App\Models\RentalWorkCompletionRound::OUTCOME_DISPUTED)->last() : null;
    $cpExternal = $workOrder->assignment_type !== \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL;
    $cpCanReportDone = $cpExternal && in_array($workOrder->status, [\App\Models\RentalWorkOrder::STATUS_ORDERED, \App\Models\RentalWorkOrder::STATUS_IN_PROGRESS, \App\Models\RentalWorkOrder::STATUS_DISPUTED], true);
    $cpTz = $workOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $cpReturnTo = ($context ?? 'work_order') === 'job_card' ? 'job_card' : 'work_order';
    $cpShow = $cpRounds->isNotEmpty() || $cpCanReportDone;
@endphp

@if($cpShow)
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid {{ $cpDisputed ? 'var(--ds-crimson, #dc2626)' : 'var(--border)' }};" id="completion-check-panel" data-completion-panel>
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold">Completion check</h2>
        @if($cpDisputed)
            <span class="ds-badge ds-badge-danger">Disputed</span>
        @elseif($cpOpenRound)
            <span class="ds-badge ds-badge-info">Tenant check — answer due {{ $cpOpenRound->window_ends_at?->copy()->setTimezone($cpTz)->format('j M') ?? 'by phone' }}</span>
        @elseif($cpLatest && $cpLatest->outcome === \App\Models\RentalWorkCompletionRound::OUTCOME_CONFIRMED)
            <span class="ds-badge ds-badge-success">Tenant confirmed</span>
        @elseif($cpLatest && $cpLatest->outcome === \App\Models\RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE)
            <span class="ds-badge ds-badge-muted">Accepted — no response</span>
        @endif
    </div>

    @if($errors->has('completion'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">{{ $errors->first('completion') }}</div>
    @endif
    @if(session('completion_warning'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;">{{ session('completion_warning') }}</div>
    @endif
    @if(session('crew_link_url') && $cpDisputed)
        <div class="text-xs p-2 rounded" style="background: var(--surface-2, #f1f5f9); word-break: break-all;">
            Fresh crew link — copy it now, it cannot be shown again:<br><strong>{{ session('crew_link_url') }}</strong>
        </div>
    @endif

    {{-- 1. The dispute: what the tenant said, and the one decision the office must take. --}}
    @if($cpDisputed && $cpDisputedRound)
        <div class="rounded-md p-3 space-y-2" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 6%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);" data-dispute-panel>
            <div class="text-sm font-semibold" style="color: var(--ds-crimson, #dc2626);">The tenant says this is not complete</div>
            <div class="text-xs" style="color: var(--text-muted);">
                Round {{ $cpDisputedRound->round_no }} · {{ $cpDisputedRound->responded_at?->copy()->setTimezone($cpTz)->format('j M Y, H:i') }}
                · {{ $cpDisputedRound->respondedViaLabel() }}
            </div>
            <div class="text-sm p-2 rounded" style="background: var(--surface); border: 1px solid var(--border); white-space: pre-line;">{{ $cpDisputedRound->response_note }}</div>
            @if($cpDisputedRound->photos->isNotEmpty())
                <div class="grid grid-cols-4 gap-2">
                    @foreach($cpDisputedRound->photos as $photo)
                        <a href="{{ $photo->storage_path }}" target="_blank" rel="noopener"><img src="{{ $photo->storage_path }}" alt="Photo from the tenant" class="rounded-md w-full h-24 object-cover"></a>
                    @endforeach
                </div>
            @endif
            <p class="text-xs" style="color: var(--text-muted);">
                The job cannot be closed until it is put right and reported done again.
                @if($cpExternal) Send it back to the contractor, then capture their completion below when they report it fixed.
                @else Send it back to the crew; when they press “Report fixed” a new check starts.
                @endif
            </p>
            @permission('rental_work_orders.manage_completion')
                <form method="POST" action="{{ route('corex.rental-work-orders.send-back', $workOrder) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ $cpReturnTo }}">
                    <button type="submit" class="corex-btn-primary text-xs">{{ $cpExternal ? 'Send back to contractor' : 'Send back to crew' }}</button>
                </form>
            @endpermission
        </div>
    @endif

    {{-- 2. A tenant check is waiting: where it stands, and the phone-answer escape hatch. --}}
    @if($cpOpenRound)
        <p class="text-xs" style="color: var(--text-muted);">{{ $cpOpenRound->statusText($cpTz) }}</p>
        @permission('rental_work_orders.manage_completion')
            <details class="text-sm">
                <summary class="corex-btn-outline text-xs inline-block cursor-pointer">Record tenant's answer</summary>
                <form method="POST" action="{{ route('corex.rental-work-orders.completion-rounds.answer', [$workOrder, $cpOpenRound->id]) }}" enctype="multipart/form-data" class="space-y-2 pt-3">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ $cpReturnTo }}">
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"><input type="radio" name="answer" value="fixed" required> The work is done</label>
                        <label class="flex items-center gap-2"><input type="radio" name="answer" value="not_fixed" required> It is NOT complete</label>
                    </div>
                    <div>
                        <label class="text-xs font-medium">What the tenant said (required if it is not complete)</label>
                        <textarea name="note" rows="2" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('note') }}</textarea>
                    </div>
                    <div>
                        <label class="text-xs font-medium">Photos they sent (optional)</label>
                        <input type="file" name="photos[]" accept="image/*" multiple class="text-xs block mt-1">
                    </div>
                    <button type="submit" class="corex-btn-primary text-xs">Save the tenant's answer</button>
                </form>
            </details>
        @endpermission
    @elseif($cpLatest && ! $cpDisputed)
        <p class="text-xs" style="color: var(--text-muted);">{{ $cpLatest->statusText($cpTz) }}</p>
    @endif

    {{-- 3. Outside work: the agent captures that the contractor has finished. --}}
    @if($cpCanReportDone)
        @permission('rental_work_orders.manage_completion')
            <details class="text-sm" @if($errors->has('completion') && old('reported_via')) open @endif>
                <summary class="corex-btn-outline text-xs inline-block cursor-pointer">Contractor reports done</summary>
                <form method="POST" action="{{ route('corex.rental-work-orders.contractor-done', $workOrder) }}" enctype="multipart/form-data" class="space-y-2 pt-3">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ $cpReturnTo }}">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-medium">Date the work was done</label>
                            <input type="date" name="date_done" max="{{ now()->toDateString() }}" value="{{ old('date_done', now()->toDateString()) }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                        </div>
                        <div>
                            <label class="text-xs font-medium">How did they tell you?</label>
                            <select name="reported_via" required class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                                @foreach(['phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'in_person' => 'In person', 'other' => 'Other'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('reported_via') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs font-medium">Note (optional)</label>
                            <textarea name="note" rows="2" maxlength="2000" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('note') }}</textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs font-medium">Photos the contractor sent (optional)</label>
                            <input type="file" name="photos[]" accept="image/*" multiple class="text-xs block mt-1">
                        </div>
                    </div>
                    <p class="text-xs" style="color: var(--text-muted);">This asks the tenant to check the work. It does not close the work order — use Complete for that.</p>
                    <button type="submit" class="corex-btn-primary text-xs">Save</button>
                </form>
            </details>
        @endpermission
    @endif

    {{-- 4. Every round, newest first. --}}
    @if($cpRounds->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="w-full text-xs" data-completion-rounds>
                <thead>
                    <tr style="color: var(--text-muted); text-align: left;">
                        <th class="py-1 pr-3">Round</th><th class="py-1 pr-3">Reported done</th><th class="py-1">Tenant check</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cpRounds->reverse() as $round)
                        <tr style="border-top: 1px solid var(--border); vertical-align: top;">
                            <td class="py-1 pr-3">#{{ $round->round_no }}</td>
                            <td class="py-1 pr-3">
                                {{ $round->opened_at?->copy()->setTimezone($cpTz)->format('j M Y, H:i') }} — {{ $round->reported_by_label }}
                                <span style="color: var(--text-muted);">({{ $round->reportedViaLabel() }})</span>
                                @if($round->reported_note)<div style="color: var(--text-muted);">{{ $round->reported_note }}</div>@endif
                            </td>
                            <td class="py-1">
                                {{ $round->statusText($cpTz) }}
                                @if($round->response_note)<div style="color: var(--text-muted); white-space: pre-line;">“{{ $round->response_note }}”</div>@endif
                                @if($round->dispute_resolved_at)<div style="color: var(--text-muted);">Put right and reported done again {{ $round->dispute_resolved_at->copy()->setTimezone($cpTz)->format('j M Y') }}.</div>@endif
                                @if($round->photos->isNotEmpty())
                                    <div class="flex gap-1 mt-1">
                                        @foreach($round->photos as $photo)
                                            <a href="{{ $photo->storage_path }}" target="_blank" rel="noopener"><img src="{{ $photo->storage_path }}" alt="" class="rounded w-10 h-10 object-cover"></a>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endif
