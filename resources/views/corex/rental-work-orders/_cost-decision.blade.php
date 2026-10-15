{{--
    .ai/specs/rentals-faults-work-orders.md section 22.6 - "Approve the cost". Nothing is approved by itself: a quote that is selected, or a crew price on a job card,
    only puts an AMOUNT in front of someone. The work cannot start until the cost has an authorisation record. Two equal buttons:
      "Authorise R___ within the repair limit" (or the emergency limit, when "This is an emergency" is ticked and a reason is written) - only when the amount is inside the limit the owner set;
      "Record the owner's decision" - the owner told you (phone, WhatsApp, email) that they approve up to an amount, or decline.
    Above the limit, or with no limit set, the owner decides first (their link, or the agent recording their words). Replaces the old "Emergency approval" form AND the old
    separate "Approval" section: ONE section - status, the owner's limits, the amount, the buttons, and below them the records (who, basis, when).
    Expects: $workOrder, $isOpen. (The rest is read here when the page did not pass it - the job card page includes this too.)
    AT-460: the job card passes $cdCompact = true. It then owns the status, the owner's limits, the amount and the buttons itself (its Money & approval
    card says each of those ONCE), and this partial only supplies the working parts: the typed-amount and emergency fields, the hidden Authorise / Record
    buttons the card's one button clicks, the owner-decision dialog and the records. Without the flag (the work order screen) nothing changes.
--}}
@php
    $cdSvc = app(\App\Services\Rentals\RentalAuthorisationService::class);
    $costRows ??= $cdSvc->rowsFor($workOrder)->where('point', \App\Models\RentalAuthorisation::POINT_COST)->values();
    $costActive ??= $cdSvc->activeFor($workOrder, \App\Models\RentalAuthorisation::POINT_COST);
    $costMeasure ??= $cdSvc->costMeasure($workOrder);
    $costRepair ??= $cdSvc->costEvaluation($workOrder, null, false);
    $costEmergency ??= $cdSvc->costEvaluation($workOrder, null, true);
    $costBlock ??= $cdSvc->costBlockReason($workOrder);
    $costOwners ??= $cdSvc->ownersFor($workOrder);
    $cdTz = $workOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $cdAuthorised = $costActive && $costActive->isAuthorised();
    $cdDeclined = $costActive && ! $costActive->isAuthorised();
    $cdLegacy = ! $costActive && $cdSvc->hasCostAuthority($workOrder);
    $cdCanAuthorise = auth()->user()->hasPermission('rental_work_orders.record_approval');
    $cdCanEmergency = auth()->user()->hasPermission('rental_work_orders.record_emergency_approval');
    $cdPending = $workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_PENDING;
    $cdNeedsDecision = $isOpen && ! $cdAuthorised && ! $cdLegacy && $costBlock === null;
    $cdMeasure = $costMeasure['amount'];
    $cdEmActive = $workOrder->activeEmergencyApproval();
    $cdOpenVariation = $workOrder->openVariation();
    $cdAutoCount = $workOrder->variations()->where('status', \App\Models\RentalWorkOrderVariation::STATUS_AUTO_APPROVED)->count();
    $cdOwnerSettings = $workOrder->property ? \App\Services\Rentals\RentalOwnerFaultSettings::for($workOrder->property) : null;
@endphp
<div id="cost-decision" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-cost-decision>
    @unless($cdCompact ?? false)
    <div class="flex flex-wrap items-center gap-2">
        <div>
            <h2 class="text-sm font-semibold wo-embedded-title">Approve the cost</h2>
        </div>
        @if($cdAuthorised)
            <span class="ds-badge ds-badge-success" data-cost-status="authorised">Cost authorised</span>
        @elseif($cdLegacy || $cdEmActive)
            <span class="ds-badge ds-badge-success" data-cost-status="authorised">Cost approved</span>
        @elseif($cdDeclined)
            <span class="ds-badge ds-badge-danger" data-cost-status="declined">Owner declined</span>
        @elseif($cdPending)
            <span class="ds-badge ds-badge-warning" data-cost-status="owner">Waiting for the owner</span>
        @else
            <span class="ds-badge ds-badge-muted" data-cost-status="none">Not authorised yet</span>
        @endif
        @if($cdOpenVariation)<span class="ds-badge ds-badge-warning">Extra work waiting for the owner</span>@endif
        @if($cdAutoCount > 0)<span class="ds-badge ds-badge-info">{{ $cdAutoCount }} {{ $cdAutoCount > 1 ? 'extras' : 'extra' }} passed earlier under an old agreement</span>@endif
    </div>
    @endunless

    @error('cost_decision')<div class="text-xs" style="color: var(--ds-crimson);" data-cost-error>{{ $message }}</div>@enderror

    @if($cdAuthorised && ! ($cdCompact ?? false))
        <p class="text-sm" data-cost-authorised>Anything above the authorised amount goes to the owner as a request for the extra.@if($costActive->owner_told_at) The owner was told on {{ $costActive->owner_told_at->copy()->setTimezone($cdTz)->format('j M Y H:i') }}.@endif</p>
    @elseif(($cdLegacy || $cdEmActive) && ! ($cdCompact ?? false))
        <p class="text-sm" data-cost-legacy>This cost was approved before authorisation records were kept: {{ $workOrder->approvalBasisLabel() ?? 'approved' }}@if($workOrder->approved_amount !== null) &mdash; R{{ number_format((float) $workOrder->approved_amount, 2) }}@endif.</p>
    @endif

    {{-- the limits an agent may authorise under are the OWNER's own, read only through RentalOwnerFaultSettings, with who said so --}}
    @if($cdOwnerSettings && ! ($cdHideLimits ?? false))
        @php
            $cdRepair = $cdOwnerSettings->repairLimit();
            $cdEmerg = $cdOwnerSettings->emergencyLimit();
            $cdStatusLine = match ($cdOwnerSettings->status()) {
                'confirmed_by_owner' => 'Confirmed by the owner',
                'captured_by_agent' => 'Recorded by ' . ($cdOwnerSettings->capturedBy() ?: 'an agent') . ' - not yet confirmed by the owner',
                'disputed' => 'The owner does not agree with these',
                default => 'Not set yet',
            };
        @endphp
        <div class="text-sm" data-cost-owner-limits>
            <div class="grid gap-x-3 gap-y-0.5" style="grid-template-columns: max-content 1fr;">
                <span style="color: var(--text-muted);">Repairs you may authorise:</span>
                <span data-limit-repair>{{ $cdRepair !== null ? 'up to R' . number_format($cdRepair, 2) : 'not set - ask the owner' }}</span>
                <span style="color: var(--text-muted);">Emergency repairs you may authorise:</span>
                <span data-limit-emergency>{{ $cdEmerg !== null ? 'up to R' . number_format($cdEmerg, 2) : 'not set - ask the owner' }}</span>
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);" data-limit-status>{{ $cdStatusLine }}
                @if($workOrder->property_id) &middot; <a class="underline" href="{{ route('corex.properties.show', ['property' => $workOrder->property_id, 'tab' => 'rental']) }}#owner-fault-settings-panel" data-owner-fault-settings-link>Owner's fault settings</a>@endif</div>
        </div>
    @endif

    @if(($cdDeclined || $workOrder->owner_approval_status === \App\Models\RentalWorkOrder::APPROVAL_DECLINED) && $isOpen && ! ($cdCompact ?? false))
        <p class="text-sm" data-cost-declined-guidance>The owner declined this. Capture another quote and approve its cost, or cancel the work order.</p>
    @endif

    @if($costBlock !== null && ! $cdAuthorised && ! $cdLegacy)
        <p class="text-xs" style="color: var(--text-muted);" data-cost-blocked>{{ $costBlock }}</p>
    @endif

    @if($cdNeedsDecision)
        <div x-data="{
                measure: @js($cdMeasure), source: @js($costMeasure['source']), label: @js($costMeasure['label']),
                repair: @js($costRepair['limit']), emerg: @js($costEmergency['limit']),
                emergency: {{ old('emergency') ? 'true' : 'false' }}, reason: @js(old('emergency_reason', '')), typed: @js(old('typed_amount', '')),
                open: {{ $errors->has('cost_owner_decision') ? 'true' : 'false' }}, decision: @js(old('decision', 'approved')), upTo: @js(old('approved_up_to', $cdMeasure)),
                busy: false,
                money(n) { return 'R' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); },
                get amount() { if (this.measure !== null) { return Number(this.measure); } const t = parseFloat(this.typed); return t > 0 ? t : null; },
                get limit() { return this.emergency ? this.emerg : this.repair; },
                get word() { return this.emergency ? 'emergency limit' : 'repair limit'; },
                get coversByEmergency() { return {{ $cdCanEmergency ? 'true' : 'false' }} && !this.emergency && this.amount !== null && this.emerg !== null && this.amount <= this.emerg + 0.001; },
                get can() { return this.amount !== null && this.limit !== null && this.amount <= this.limit + 0.001 && (!this.emergency || this.reason.trim() !== ''); },
                get line() {
                    if (this.amount === null) { return 'Nothing is priced yet - select a quote or type an amount.'; }
                    if (this.limit === null) { return 'The owner has not told us what you may authorise without asking (the ' + this.word + '), so the owner must authorise this first.'; }
                    if (this.amount > this.limit + 0.001) { return this.money(this.amount) + ' is above the owner\'s ' + this.word + ' of ' + this.money(this.limit) + '. The owner must authorise this first.'; }
                    if (this.emergency && this.reason.trim() === '') { return 'Write why this is an emergency - it is required.'; }
                    return this.money(this.amount) + ' is inside the owner\'s ' + this.word + ' of ' + this.money(this.limit) + '. You can authorise it. The owner will be told.';
                },
                async authorise() {
                    if (!this.can || this.busy) { return; }
                    const ok = await window.corexConfirm({ title: 'Authorise ' + this.money(this.amount) + '?', message: 'You authorise up to ' + this.money(this.amount) + ' under the owner\'s ' + this.word + '. This is saved under your name and the owner is told at once. Anything above it goes to the owner.', confirmLabel: 'Authorise ' + this.money(this.amount) });
                    if (!ok) { return; }
                    this.busy = true; this.$refs.authForm.submit();
                }
             }"
             x-on:keydown.escape.window="open = false" class="space-y-3" data-cost-actions>

            <div class="text-sm" data-cost-amount>
                @unless($cdCompact ?? false)
                <template x-if="measure !== null"><span><span style="color: var(--text-muted);" x-text="label + ':'"></span> <strong x-text="money(measure)"></strong> <span class="text-xs" style="color: var(--text-muted);">(what the owner pays, VAT included)</span></span></template>
                @endunless
                <template x-if="measure === null">
                    <span class="flex flex-wrap items-center gap-2">
                        <label class="text-xs font-medium" for="cost-typed">Or type the amount for a small job (R):</label>
                        <input id="cost-typed" type="number" step="0.01" min="0.01" x-model="typed" class="rounded-md px-3 py-1 text-sm" style="border: 1px solid var(--border); width: 9rem;" data-cost-typed>
                    </span>
                </template>
            </div>

            @if($cdCanEmergency)
            <div class="space-y-2" x-show="emerg !== null && (amount === null || amount <= emerg + 0.001) || emergency">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="emergency" data-cost-emergency> This is an emergency <span class="text-xs" style="color: var(--text-muted);">(uses the owner's emergency limit; the reason is required)</span></label>
                <div x-show="emergency" x-cloak>
                    <label class="text-xs font-medium" for="cost-reason">Why is it an emergency? <span style="color: var(--ds-crimson);">*</span></label>
                    <textarea id="cost-reason" rows="2" maxlength="2000" x-model="reason" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" data-cost-reason></textarea>
                </div>
            </div>
            @endif

            @if($cdCompact ?? false)
            {{-- the owner-limit sentence lives in the job card's Money & approval card, once; here only the one thing the form still has to say --}}
            <p class="text-xs font-semibold rounded-md px-3 py-2" style="border: 1px solid var(--ds-amber, #f59e0b); background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, var(--surface));" x-show="emergency && reason.trim() === ''" x-cloak data-cost-line>Write why this is an emergency - it is required.</p>
            @else
            <p class="text-xs font-semibold rounded-md px-3 py-2" style="border: 1px solid var(--ds-amber, #f59e0b); background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, var(--surface));" x-text="line" data-cost-line></p>
            @endif

            <form x-ref="authForm" method="POST" action="{{ route('corex.rental-work-orders.cost.authorise', $workOrder) }}" class="hidden" data-cost-authorise-form>
                @csrf
                <input type="hidden" name="typed_amount" x-bind:value="measure === null ? typed : ''">
                <input type="hidden" name="emergency" x-bind:value="emergency ? 1 : ''">
                <input type="hidden" name="emergency_reason" x-bind:value="emergency ? reason : ''">
            </form>

            <div class="flex flex-wrap gap-2" data-cost-buttons @if($cdCompact ?? false) style="display:none;" aria-hidden="true" @endif>
                @if($cdCanAuthorise)
                    <button type="button" class="corex-btn-primary text-xs flex-1" x-show="can || coversByEmergency" x-bind:disabled="!can || busy" x-bind:style="(!can || busy) ? 'opacity:.45; cursor:not-allowed;' : ''" x-on:click="authorise()" data-cost-authorise
                            x-text="amount !== null ? 'Authorise ' + money(amount) + ' within the ' + word : 'Authorise within the ' + word">Authorise within the repair limit</button>
                    <button type="button" class="corex-btn-outline text-xs flex-1" x-on:click="open = true" data-cost-owner-decision-open>Record the owner's decision</button>
                @else
                    <p class="text-xs sm:col-span-2" style="color: var(--text-muted);">Only staff who may record approvals can authorise the cost.</p>
                @endif
            </div>

            @if($cdCanAuthorise)
            <div x-show="open" x-cloak class="cxod-overlay" role="dialog" aria-modal="true" aria-labelledby="cxcd-title" data-cost-owner-decision-modal>
                <div class="cxod-box" x-on:click.outside="open = false">
                    <h3 id="cxcd-title" class="cxod-title">Record the owner's decision</h3>
                    <p class="cxod-sub">Use this when the owner has told you their decision about the cost by phone, WhatsApp or email. It is saved under your name and cannot be edited afterwards &mdash; it can only be replaced by a newer one. The owner is emailed what you write down and can query it.</p>
                    @error('cost_owner_decision')<div class="cxod-warn" data-cost-owner-decision-error>{{ $message }}</div>@enderror
                    @if($costActive && $costActive->isCaptured())
                        <p class="cxod-warn">A decision is already recorded ({{ $costActive->isAuthorised() ? 'Approved' : 'Declined' }}, {{ $costActive->created_at?->copy()->setTimezone($cdTz)->format('j M Y H:i') }}). Saving this replaces it; both stay in the history.</p>
                    @endif
                    <form method="POST" action="{{ route('corex.rental-work-orders.cost.owner-decision', $workOrder) }}" enctype="multipart/form-data" class="space-y-3" data-cost-owner-decision-form>
                        @csrf
                        <div>
                            <label class="cxod-lbl" for="cxcd-owner">Which owner told you?</label>
                            <select id="cxcd-owner" name="owner_contact_id" required class="cxod-input" data-od-owner>
                                @if($costOwners->count() > 1)<option value="">Choose the owner</option>@endif
                                @foreach($costOwners as $cdOwner)
                                    <option value="{{ $cdOwner->id }}" @selected((string) old('owner_contact_id') === (string) $cdOwner->id)>{{ trim(($cdOwner->first_name ?? '') . ' ' . ($cdOwner->last_name ?? '')) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <span class="cxod-lbl">What did the owner decide?</span>
                            <div class="flex flex-wrap gap-4 mt-1 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" name="decision" value="approved" x-model="decision" required data-od-decision="approved"> Approved</label>
                                <label class="flex items-center gap-2"><input type="radio" name="decision" value="declined" x-model="decision" data-od-decision="declined"> Declined</label>
                            </div>
                        </div>
                        <div x-show="decision === 'approved'" x-cloak>
                            <label class="cxod-lbl" for="cxcd-upto">Approved up to (R) <span style="color: var(--ds-crimson);">*</span></label>
                            <input id="cxcd-upto" type="number" step="0.01" min="0.01" name="approved_up_to" x-model="upTo" x-bind:required="decision === 'approved'" class="cxod-input" data-od-upto>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="cxod-lbl" for="cxcd-via">How did the owner tell you?</label>
                                <select id="cxcd-via" name="told_via" required class="cxod-input" data-od-via>
                                    <option value="phone_call" @selected(old('told_via') === 'phone_call')>Phone call</option>
                                    <option value="whatsapp" @selected(old('told_via') === 'whatsapp')>WhatsApp</option>
                                    <option value="email" @selected(old('told_via') === 'email')>Email</option>
                                </select>
                            </div>
                            <div>
                                <label class="cxod-lbl" for="cxcd-when">When did they tell you?</label>
                                <x-date-time-field name="told_at" id="cxcd-when" data-od-when :value="old('told_at', now()->setTimezone($cdTz)->format('Y-m-d\TH:i'))" />
                            </div>
                        </div>
                        <div>
                            <label class="cxod-lbl" for="cxcd-note"><span x-show="decision === 'declined'" x-cloak>Why did the owner decline? What did they say?</span><span x-show="decision !== 'declined'">What did the owner say?</span> <span style="color: var(--ds-crimson);">*</span></label>
                            <textarea id="cxcd-note" name="note" required rows="3" maxlength="4000" class="cxod-input" data-od-note>{{ old('note') }}</textarea>
                        </div>
                        <div>
                            <label class="cxod-lbl" for="cxcd-proof">Proof (optional &mdash; a screenshot of the WhatsApp or email)</label>
                            <input id="cxcd-proof" type="file" name="proof" accept="image/*" class="text-xs" data-od-proof>
                        </div>
                        <div class="cx-action-row">
                            <x-confirm-submit title="Record the owner's decision" message="This is saved under your name and cannot be edited afterwards. The owner is emailed what you wrote down. Record it now?" confirm-label="Record the decision" data-od-save>Record the decision</x-confirm-submit>
                            <button type="button" class="corex-btn-outline text-xs" x-on:click="open = false" data-od-cancel>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            @endif
        </div>
    @endif

    @if($costRows->isNotEmpty())
        @if($cdCompact ?? false)<details class="text-xs"><summary class="cursor-pointer" style="color: var(--text-muted);">Authorisation records ({{ $costRows->count() }})</summary>@endif
        <ul class="space-y-1 text-xs" data-cost-records>
            @foreach($costRows as $cr)
                <li @if($cr->superseded_at) style="opacity:.65" @endif data-cost-record="{{ $cr->id }}" data-cost-basis="{{ $cr->basis }}">
                    <strong>{{ $cr->isAuthorised() ? 'Authorised' : 'Declined' }}</strong> &mdash; {{ $cdSvc->recordTextFor($cr) }}
                    @if($cr->note && $cr->basis === \App\Models\RentalAuthorisation::BASIS_OWNER_INSTRUCTION)<em>&ldquo;{{ $cr->note }}&rdquo;</em>@endif
                    @if($cr->superseded_at)<span style="color: var(--text-muted);">&middot; <em>replaced by a newer record</em></span>@endif
                </li>
            @endforeach
        </ul>
        @if($cdCompact ?? false)</details>@endif
    @endif
</div>
@once
<style>
    [data-cost-buttons] > * { min-width: 220px; }
    .cxod-overlay { position: fixed; inset: 0; z-index: 90000; display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; background: rgba(15, 23, 42, .55); overflow-y: auto; }
    .cxod-overlay[x-cloak] { display: none; }
    .cxod-box { width: 100%; max-width: 640px; background: var(--surface, #fff); color: var(--text-primary, #141821); border: 1px solid var(--border, #ddd); border-radius: 10px; padding: 18px 20px; }
    .cxod-title { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
    .cxod-sub { font-size: 12px; color: var(--text-muted, #5a6472); margin: 0 0 12px; }
    .cxod-warn { font-size: 12px; padding: 6px 10px; border-radius: 8px; background: #fff4e5; border: 1px solid #f59e0b; color: #5a3b00; margin: 0 0 10px; }
    .cxod-lbl { display: block; font-size: 12px; font-weight: 600; margin: 4px 0 3px; }
    .cxod-input { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border, #ddd); background: var(--surface-2, #f4f6fb); color: var(--text-primary, #141821); font-size: 14px; }
</style>
@endonce
