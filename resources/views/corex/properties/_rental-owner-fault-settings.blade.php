{{--
    .ai/specs/rentals-faults-work-orders.md section 22.5 - "How the owner wants faults handled", on a SETTLED rental property's Rental tab, under the work terms.
    The OWNER's own answers: repairs / emergency repairs the agent may authorise without asking, and whether a contractor who charges a call-out fee may be sent
    without asking first. The agent may capture them FOR the owner only with evidence; the owner is emailed at once and can confirm, change or dispute.
    Receives: $property.
--}}
@php
    $ofsSvc = app(\App\Services\Rentals\RentalOwnerFaultSettingsService::class);
    $ofs = \App\Services\Rentals\RentalOwnerFaultSettings::for($property);
    $ofsRow = $ofs->row();
    $ofsStatus = $ofs->status();
    $ofsValues = $ofs->values();
    $ofsProposed = $ofs->proposed();
    $ofsHistory = $ofsSvc->history($property);
    $ofsMoney = fn ($x) => $x === null ? 'not set' : \App\Models\DealV2\AgencyServiceProvider::formatRand((float) $x);
    $ofsBadge = match ($ofsStatus) { 'confirmed_by_owner' => 'ds-badge-success', 'captured_by_agent' => 'ds-badge-info', 'disputed' => 'ds-badge-danger', default => 'ds-badge-default' };
    $ofsLabel = match ($ofsStatus) { 'confirmed_by_owner' => 'Confirmed by the owner', 'captured_by_agent' => 'Recorded by an agent - the owner has not confirmed yet', 'disputed' => 'The owner does not agree', default => 'Not set yet' };
    $ofsHasErrors = $errors->hasAny(['repair_limit', 'emergency_limit', 'callout_mode', 'callout_limit', 'basis', 'occurred_at', 'note', 'evidence']);
    $ofsOpen = $ofsHasErrors || $ofsStatus === 'not_set';
    $ofsMode = old('callout_mode', $ofsValues['callout_mode']);
@endphp
<div id="owner-fault-settings-panel" class="rounded-md p-4 text-sm space-y-3 mt-4" style="background: var(--surface-2); border: 1px solid var(--border);" data-owner-fault-settings data-status="{{ $ofsStatus }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <strong>How the owner wants faults handled</strong>
        <span class="ds-badge {{ $ofsBadge }}" data-owner-fault-status>{{ $ofsLabel }}</span>
    </div>
    <p class="text-xs" style="color: var(--text-muted);">
        These are the OWNER's decisions about their property. Until the owner has confirmed them (or you have recorded what they told you, with evidence), nothing here counts as a standing
        authority and every decision goes to the owner. The agency's suggested amount is only ever a suggestion.
    </p>

    @if($errors->hasAny(['repair_limit', 'emergency_limit', 'callout_mode', 'callout_limit', 'basis', 'occurred_at', 'note', 'evidence']))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-owner-fault-errors>
            @foreach(['repair_limit', 'emergency_limit', 'callout_mode', 'callout_limit', 'basis', 'occurred_at', 'note', 'evidence'] as $ofsErrKey)
                @error($ofsErrKey)<div>{{ $message }}</div>@enderror
            @endforeach
        </div>
    @endif

    @if($ofsStatus === 'captured_by_agent')
        <div class="rounded-md p-3 text-xs space-y-2" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, var(--surface)); border: 1px solid var(--ds-amber, #f59e0b);" data-owner-not-confirmed-banner>
            <div class="font-semibold">Owner has not confirmed these yet.</div>
            <div>
                @if($ofsRow?->last_sent_to_owner_at)Sent to the owner on {{ $ofsRow->last_sent_to_owner_at->format('j M Y H:i') }}.@else The owner has not been sent these.@endif
            </div>
            @permission('rental_work_orders.manage_work_terms')
            <form method="POST" action="{{ route('corex.properties.rental-owner-fault-settings.send', $property) }}">
                @csrf
                <button type="submit" class="corex-btn-primary text-xs" style="min-width: 12rem;" data-send-to-owner>Send to owner to confirm</button>
            </form>
            @endpermission
        </div>
    @elseif($ofsStatus === 'disputed')
        <div class="rounded-md p-3 text-xs space-y-1" style="background: color-mix(in srgb, var(--ds-crimson) 10%, var(--surface)); border: 1px solid var(--ds-crimson);" data-owner-disputed-banner>
            <div class="font-semibold">The owner does not agree with these settings{{ $ofsRow?->disputed_at ? ' (' . $ofsRow->disputed_at->format('j M Y H:i') . ')' : '' }}.</div>
            @if($ofsRow?->dispute_note)<div>&ldquo;{{ $ofsRow->dispute_note }}&rdquo;</div>@endif
            <div>Every decision goes to the owner until they confirm their own settings, or you record new evidence below.</div>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs" data-owner-fault-values>
        <div><div style="color: var(--text-muted);">Repairs the agent may authorise without asking</div><strong data-v-repair>{{ $ofsMoney($ofsValues['repair_limit']) }}</strong></div>
        <div><div style="color: var(--text-muted);">Emergency repairs without asking</div><strong data-v-emergency>{{ $ofsMoney($ofsValues['emergency_limit']) }}</strong></div>
        <div><div style="color: var(--text-muted);">Contractor who charges a call-out fee, without asking</div>
            <strong data-v-callout>@if($ofsValues['callout_mode'] === 'yes')Yes, up to {{ $ofsMoney($ofsValues['callout_limit']) }}@elseif($ofsValues['callout_mode'] === 'no')No - ask the owner first@else not set @endif</strong></div>
    </div>
    @if($ofsRow && $ofsRow->capture_basis)
        <p class="text-xs" style="color: var(--text-muted);" data-owner-fault-evidence>
            Recorded by {{ $ofs->capturedBy() ?: 'a former user' }}{{ $ofsRow->captured_at ? ' on ' . $ofsRow->captured_at->format('j M Y') : '' }} -
            @if($ofsRow->capture_basis === 'carried_over'){{ strtolower($ofsRow->basisLabel()) }}@else the owner told us by {{ strtolower($ofsRow->basisLabel()) }}{{ $ofsRow->capture_occurred_at ? ' on ' . $ofsRow->capture_occurred_at->format('j M Y') : '' }}@endif.
            @if($ofsRow->capture_note)&ldquo;{{ $ofsRow->capture_note }}&rdquo;@endif
            @if($ofsRow->capture_file_path)<a class="underline" href="{{ route('corex.properties.rental-owner-fault-settings.evidence', $property) }}">{{ $ofsRow->capture_file_name ?: 'Evidence' }}</a>@endif
        </p>
    @endif
    @if($ofsStatus === 'confirmed_by_owner' && $ofs->confirmedAt())
        <p class="text-xs" style="color: var(--text-muted);">Confirmed by the owner on {{ $ofs->confirmedAt()->format('j M Y H:i') }}.</p>
    @endif
    <p class="text-xs" style="color: var(--text-muted);">Who looks at faults first is set in the panel below; the owner can ask for a change from their portal.</p>

    @permission('rental_work_orders.manage_work_terms')
    <details @if($ofsOpen) open @endif data-owner-fault-capture>
        <summary class="cursor-pointer text-xs font-semibold">Record what the owner told us</summary>
        <form method="POST" action="{{ route('corex.properties.rental-owner-fault-settings.capture', $property) }}" enctype="multipart/form-data" class="space-y-3 mt-3" x-data="{ mode: @js($ofsMode ?: '') }">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="prop-label" for="ofs-repair">Repairs my agent may authorise without asking me: up to R <span class="text-red-500">*</span></label>
                    <input id="ofs-repair" type="number" name="repair_limit" min="0" step="0.01" required class="prop-input prop-field-money"
                           value="{{ old('repair_limit', $ofsValues['repair_limit'] ?? ($ofsStatus === 'not_set' ? $ofsProposed['repair_limit'] : null)) }}">
                    @if($ofsStatus === 'not_set')<p class="text-xs mt-1" style="color: var(--text-muted);">Suggested by the agency: {{ $ofsMoney($ofsProposed['repair_limit']) }} - only counts once the owner has told you.</p>@endif
                </div>
                <div>
                    <label class="prop-label" for="ofs-emergency">Emergency repairs my agent may authorise without asking me: up to R <span class="text-red-500">*</span></label>
                    <input id="ofs-emergency" type="number" name="emergency_limit" min="0" step="0.01" required class="prop-input prop-field-money" value="{{ old('emergency_limit', $ofsValues['emergency_limit']) }}">
                </div>
                <div>
                    <label class="prop-label" for="ofs-callout">May my agent send a contractor who charges a call-out fee without asking me first? <span class="text-red-500">*</span></label>
                    <select id="ofs-callout" name="callout_mode" required x-model="mode" class="prop-input">
                        <option value="">Choose...</option>
                        <option value="yes" @selected($ofsMode === 'yes')>Yes, up to an amount</option>
                        <option value="no" @selected($ofsMode === 'no')>No - ask the owner first</option>
                    </select>
                    <div x-show="mode === 'yes'" x-cloak class="mt-2">
                        <label class="prop-label" for="ofs-callout-limit">Up to R <span class="text-red-500">*</span></label>
                        <input id="ofs-callout-limit" type="number" name="callout_limit" min="0.01" step="0.01" :required="mode === 'yes'" :disabled="mode !== 'yes'" class="prop-input prop-field-money" value="{{ old('callout_limit', $ofsValues['callout_limit']) }}">
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="prop-label" for="ofs-basis">How the owner told us <span class="text-red-500">*</span></label>
                    <select id="ofs-basis" name="basis" required class="prop-input">
                        <option value="">Choose...</option>
                        @foreach(\App\Models\RentalPropertyFaultSetting::BASES as $ofsKey => $ofsText)
                            <option value="{{ $ofsKey }}" @selected(old('basis') === $ofsKey)>{{ $ofsText }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="prop-label" for="ofs-when">When <span class="text-red-500">*</span></label>
                    <input id="ofs-when" type="date" name="occurred_at" required max="{{ now()->toDateString() }}" class="prop-input" value="{{ old('occurred_at', now()->toDateString()) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="prop-label" for="ofs-note">Note - what the owner said <span class="text-red-500">*</span></label>
                    <textarea id="ofs-note" name="note" required minlength="3" maxlength="2000" rows="2" class="prop-input" placeholder="e.g. Owner phoned on 12 Oct and said R1 500 for repairs, R5 000 for emergencies, no call-out fees without asking">{{ old('note') }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="prop-label" for="ofs-file">Upload evidence <span class="font-normal" style="color: var(--text-muted);">(optional - the email, a screenshot, the mandate)</span></label>
                    <input id="ofs-file" type="file" name="evidence" class="prop-input">
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs" style="color: var(--text-muted);">The owner is emailed straight away with what you recorded, and can confirm it, change it, or tell us they do not agree.</span>
                <button type="submit" class="corex-btn-primary text-sm" style="min-width: 15rem;" data-save-owner-fault-settings>Save for the owner and tell them</button>
            </div>
        </form>
    </details>
    @endpermission

    <details data-owner-fault-history>
        <summary class="text-xs cursor-pointer" style="color: var(--text-muted);">History ({{ $ofsHistory->count() }})</summary>
        @if($ofsHistory->isEmpty())
            <p class="text-xs mt-2" style="color: var(--text-muted);">Nothing recorded yet.</p>
        @else
            <ul class="space-y-1 text-xs mt-2">
                @foreach($ofsHistory as $ofsH)
                    <li><span style="color: var(--text-muted);">{{ $ofsH['at'] }}</span> &mdash; {{ $ofsH['text'] }}</li>
                @endforeach
            </ul>
        @endif
    </details>
</div>
