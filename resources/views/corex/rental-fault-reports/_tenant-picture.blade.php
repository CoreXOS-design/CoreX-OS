{{--
    .ai/specs/rentals-faults-work-orders.md section 22.3 - the top of the agent's fault page, in this order:
      1. What the tenant reported (read-only)         2. What the tenant says they checked (their ticked self-checks)
      3. Confirmed with the tenant (the agent records how / when / a note)     4. Reported before
    Expects: $faultReport, $reportedBefore.
--}}
@php
    $tpTenant = $faultReport->reported_by_type === \App\Models\RentalFaultReport::REPORTED_BY_TENANT;
    $tpChecks = $faultReport->tenantSelfChecks();
    $tpConf = $faultReport->tenantConfirmation();
    $tpTz = $faultReport->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $tpCanConfirm = auth()->user()->hasPermission('rental_fault_reports.create') && $faultReport->status !== \App\Models\RentalFaultReport::STATUS_CANCELLED;
    $tpWho = $faultReport->reportedByContact ? trim($faultReport->reportedByContact->first_name . ' ' . $faultReport->reportedByContact->last_name) : ($faultReport->reportedByUser?->name ?? '');
@endphp
<div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);" data-tenant-picture>
    <section data-tenant-report>
        <h2 class="text-sm font-semibold">{{ $tpTenant ? 'What the tenant reported' : 'What was reported' }}</h2>
        <p class="text-sm mt-1" style="white-space: pre-line;" data-tenant-report-text>{{ trim((string) $faultReport->description) !== '' ? $faultReport->description : 'No description was given.' }}</p>
        <p class="text-xs mt-1" style="color: var(--text-muted);">
            {{ ucfirst(str_replace('_', ' ', $faultReport->reported_by_type)) }}{{ $tpWho !== '' ? ' - ' . $tpWho : '' }}
            &middot; {{ ucfirst(str_replace('_', ' ', $faultReport->reported_channel)) }}
            &middot; {{ ($faultReport->reported_at ?? $faultReport->created_at)?->copy()->setTimezone($tpTz)->format('j M Y H:i') }}
            &middot; Tenancy: {{ $faultReport->lease?->tenantNames() ?? 'None - vacancy period' }}
            @if($faultReport->inspectionItem?->label) &middot; Item: {{ $faultReport->inspectionItem->label }}@endif
            @if($faultReport->capturedByUser) &middot; Captured by {{ $faultReport->capturedByUser->name }}@endif
            @if($faultReport->photos->isNotEmpty()) &middot; {{ $faultReport->photos->count() }} photo{{ $faultReport->photos->count() === 1 ? '' : 's' }} (right-hand side)@endif
        </p>
    </section>

    <section data-tenant-self-checks>
        <h3 class="text-xs font-semibold">What the tenant says they checked</h3>
        @if($tpChecks)
            <ul class="mt-1 space-y-1 text-sm">
                @foreach($tpChecks as $chk)
                    @php $tpAgent = app(\App\Services\Rentals\RentalFaultTypeService::class)->agentWording((string) $chk['text']); @endphp
                    <li data-self-check="{{ $chk['ticked'] ? 'ticked' : 'not-ticked' }}"><span aria-hidden="true">{{ $chk['ticked'] ? '☑' : '☐' }}</span> <span @if(! $chk['ticked']) style="color: var(--text-muted);" @endif>{{ $tpAgent['text'] }}</span>@if($tpAgent['missing'] && $faultReport->property_id) <a class="underline text-xs" href="{{ route('corex.properties.show', ['property' => $faultReport->property_id, 'tab' => 'rental']) }}" data-set-property-field="{{ $tpAgent['field'] }}">Set it on the property</a>@endif<span class="sr-only">{{ $chk['ticked'] ? ' - ticked' : ' - not ticked' }}</span></li>
                @endforeach
            </ul>
        @else
            <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $tpTenant ? 'No self-checks were shown for this fault.' : 'Not a tenant report, so there are no self-checks.' }}</p>
        @endif
    </section>

    <section data-tenant-confirmation>
        <h3 class="text-xs font-semibold">Confirmed with the tenant</h3>
        @if($tpConf)
            <p class="text-sm mt-1" data-confirmation-summary>
                By {{ $tpConf['via_label'] }} on {{ $tpConf['at']?->copy()->setTimezone($tpTz)->format('j M Y H:i') }}{{ $tpConf['by'] ? ', recorded by ' . $tpConf['by'] : '' }}:
                &ldquo;{{ $tpConf['note'] }}&rdquo;
            </p>
        @else
            <p class="text-xs mt-1" style="color: var(--text-muted);" data-confirmation-none>Not confirmed with the tenant yet.</p>
        @endif
        @if($tpCanConfirm)
            <details class="mt-2" @if($errors->has('tenant_confirmation')) open @endif data-confirmation-details>
                <summary class="text-xs cursor-pointer underline" style="color: var(--text-muted);">{{ $tpConf ? 'Record a newer confirmation' : 'Record how you confirmed it with the tenant' }}</summary>
                @error('tenant_confirmation')<div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                <form method="POST" action="{{ route('corex.rental-fault-reports.tenant-confirmation', $faultReport) }}" class="mt-2 space-y-2" data-confirmation-form>
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium" for="tc-via">How did you confirm it?</label>
                            <select id="tc-via" name="via" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                                @foreach(\App\Models\RentalFaultReport::CONFIRMED_VIAS as $via)
                                    <option value="{{ $via }}" @selected(old('via') === $via)>{{ ucfirst(\App\Models\RentalFaultReport::confirmedViaLabel($via)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium" for="tc-at">When?</label>
                            <x-date-time-field name="at" id="tc-at" class="mt-1" :value="old('at', now()->setTimezone($tpTz)->format('Y-m-d\TH:i'))" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-medium" for="tc-note">What did the tenant say?</label>
                        <textarea id="tc-note" name="note" required rows="2" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('note') }}</textarea>
                    </div>
                    <button type="submit" class="corex-btn-outline text-xs">Save</button>
                </form>
            </details>
        @endif
    </section>

    @if($reportedBefore)
        <section data-reported-before>
            <h3 class="text-xs font-semibold">Reported before</h3>
            <p class="text-xs mt-1">{{ $reportedBefore['text'] }} &mdash; <a class="underline" href="{{ route('corex.rental-fault-reports.show', $reportedBefore['id']) }}">open it</a>.</p>
        </section>
    @endif
</div>
