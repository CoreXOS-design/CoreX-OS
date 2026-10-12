{{--
    .ai/specs/rentals-faults-work-orders.md §20 (AT-452 round 3) - "Record the owner's decision": the ONE way an agent records what the owner told them (phone call,
    WhatsApp, email) at the points where the owner's own link decision is awaited. Used on the fault page and the work order page; replaces the three older record
    forms. Shows the decisions already recorded (never edited; only replaced) and, when allowed, a button that opens the popup OVER the page.
    Needs $subject (a RentalFaultReport or a RentalWorkOrder). Include OUTSIDE any other <form>. Optional $contractors (the fault page's picker list).
--}}
@php
    $odSvc = app(\App\Services\Rentals\RentalOwnerDecisionService::class);
    $odIsFault = $subject instanceof \App\Models\RentalFaultReport;
    $odEnabled = $odSvc->enabled($subject->agency_id);
    $odBlock = $odEnabled ? $odSvc->blockReason($subject) : null;
    $odRows = $odSvc->rowsFor($subject);
    $odActive = $odSvc->activeFor($subject);
    $odOwners = $odSvc->ownersFor($subject);
    $odOptions = $odSvc->optionsFor($subject);
    $odTz = $subject->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $odQuote = $odIsFault ? null : $subject->quotes()->where('is_selected', true)->first();
    $odQuoteAmount = $odQuote ? (float) $odQuote->ownerFacingAmount() : null;
    $odContractors = $contractors ?? [];
    $odFmt = fn ($dt) => $dt ? $dt->copy()->setTimezone($odTz)->format('j M Y H:i') : '';
@endphp
@if($odEnabled && ($odRows->isNotEmpty() || $odBlock === null || str_starts_with((string) $odBlock, 'No owner')))
<div class="space-y-2" data-owner-decision-block>
    @if($odRows->isNotEmpty())
        <div class="text-xs" style="color: var(--text-muted);" data-owner-decision-history>
            <div class="font-semibold" style="color: var(--text-primary);">Decisions recorded for the owner</div>
            <ul class="space-y-1 mt-1">
                @foreach($odRows as $odRow)
                    <li @if($odRow->superseded_at) style="opacity:.7" @endif data-owner-decision-row="{{ $odRow->id }}">
                        <strong>{{ \App\Models\RentalRecordedOwnerDecision::decisionLabel($odRow->decision) }}</strong>@if($odRow->approved_up_to !== null) up to R{{ number_format((float) $odRow->approved_up_to, 2, '.', ' ') }}@endif
                        &mdash; recorded by {{ $odRow->recordedBy?->name ?? 'a former user' }} on {{ $odFmt($odRow->created_at) }};
                        {{ trim(($odRow->ownerContact?->first_name ?? '') . ' ' . ($odRow->ownerContact?->last_name ?? '')) ?: 'the owner' }} told them by {{ \App\Models\RentalRecordedOwnerDecision::viaLabel($odRow->told_via) }} on {{ $odFmt($odRow->told_at) }}:
                        &ldquo;{{ $odRow->note }}&rdquo;
                        @if($odRow->proof_path)<a href="{{ $odRow->proof_path }}" target="_blank" rel="noopener" class="underline">Proof</a>@endif
                        @if($odRow->superseded_at)
                            <em>&mdash; replaced {{ $odFmt($odRow->superseded_at) }} by {{ $odRow->superseded_by === 'owner' ? "the owner's own decision" : 'a newer recorded decision' }}.</em>
                        @else
                            <em>&mdash; this is the decision that stands.</em>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @error('owner_decision')<div class="text-xs" style="color: var(--ds-crimson);" data-owner-decision-error>{{ $message }}</div>@enderror

    @if($odBlock === null)
        <button type="button" class="corex-btn-primary text-xs" data-owner-decision-open x-data @click="$dispatch('cx-open-owner-decision')">Record the owner's decision</button>

        <div x-data="{ open: {{ $errors->has('owner_decision') ? 'true' : 'false' }}, decision: '{{ old('decision', 'approved') }}', handled: '{{ old('handled_by', 'agency') }}', cq: '' }"
             x-on:cx-open-owner-decision.window="open = true" x-on:keydown.escape.window="open = false" x-show="open" x-cloak
             class="cxod-overlay" role="dialog" aria-modal="true" aria-labelledby="cxod-title-{{ $subject->id }}" data-owner-decision-modal>
            <div class="cxod-box" x-on:click.outside="open = false">
                <h3 id="cxod-title-{{ $subject->id }}" class="cxod-title">Record the owner's decision</h3>
                <p class="cxod-sub">Use this when the owner has told you their decision by email, WhatsApp or phone. It is saved under your name and cannot be edited afterwards &mdash; it can only be replaced by a new recorded decision or by the owner's own decision{{ $odIsFault ? ', until a work order is created' : ', until the work starts' }}. The owner is emailed what you write down and can query it.</p>
                @if($odActive)
                    <p class="cxod-warn" data-owner-decision-replaces>A decision is already recorded ({{ \App\Models\RentalRecordedOwnerDecision::decisionLabel($odActive->decision) }}, {{ $odFmt($odActive->created_at) }}). Saving this replaces it; both stay in the history.</p>
                @endif
                <form method="POST" action="{{ route($odIsFault ? 'corex.rental-fault-reports.owner-decision.store' : 'corex.rental-work-orders.owner-decision.store', $subject) }}" enctype="multipart/form-data" class="space-y-3" data-owner-decision-form>
                    @csrf
                    <div>
                        <label class="cxod-lbl" for="cxod-owner-{{ $subject->id }}">Which owner told you?</label>
                        <select id="cxod-owner-{{ $subject->id }}" name="owner_contact_id" required class="cxod-input" data-od-owner>
                            @if($odOwners->count() > 1)<option value="">Choose the owner</option>@endif
                            @foreach($odOwners as $odOwner)
                                <option value="{{ $odOwner->id }}" @selected((string) old('owner_contact_id') === (string) $odOwner->id)>{{ trim(($odOwner->first_name ?? '') . ' ' . ($odOwner->last_name ?? '')) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <span class="cxod-lbl">What did the owner decide?</span>
                        <div class="flex flex-wrap gap-4 mt-1 text-sm">
                            @foreach($odOptions as $odOpt)
                                <label class="flex items-center gap-2"><input type="radio" name="decision" value="{{ $odOpt }}" x-model="decision" required data-od-decision="{{ $odOpt }}"> {{ \App\Models\RentalRecordedOwnerDecision::decisionLabel($odOpt) }}</label>
                            @endforeach
                        </div>
                        @if($odIsFault)<p class="cxod-help" x-show="decision === 'get_quote_first'" x-cloak>The fault stays waiting. You create the work order and get a quote; the quote always goes to the owner to approve.</p>@endif
                    </div>
                    <div x-show="decision === 'approved'" x-cloak>
                        <label class="cxod-lbl" for="cxod-upto-{{ $subject->id }}">Approved up to R @if(! $odIsFault && $odQuoteAmount === null)<span class="text-red-500">*</span>@endif</label>
                        <input id="cxod-upto-{{ $subject->id }}" type="number" step="0.01" min="0" name="approved_up_to" class="cxod-input" style="max-width:200px" data-od-upto
                               value="{{ old('approved_up_to', $odQuoteAmount !== null ? number_format($odQuoteAmount, 2, '.', '') : '') }}" @if(! $odIsFault && $odQuoteAmount === null) x-bind:required="decision === 'approved'" @endif>
                        <p class="cxod-help">
                            @if($odIsFault)Optional. If the owner named an amount, a quote up to it goes ahead without asking again. Leave blank if they did not.
                            @elseif($odQuoteAmount !== null)The quote is R{{ number_format($odQuoteAmount, 2, '.', ' ') }}. The amount cannot be less than the quote.
                            @else Required: nothing can be authorised without the most the owner agreed to spend.@endif
                        </p>
                    </div>
                    @if($odIsFault)
                    <div x-show="decision === 'approved'" x-cloak class="space-y-2" data-od-handled>
                        <span class="cxod-lbl">Who arranges the repair?</span>
                        <div class="flex flex-wrap gap-4 mt-1 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="handled_by" value="agency" x-model="handled"> Our agency chooses the contractor</label>
                            @if(count($odContractors))<label class="flex items-center gap-2"><input type="radio" name="handled_by" value="list" x-model="handled"> A contractor from our list</label>@endif
                            <label class="flex items-center gap-2"><input type="radio" name="handled_by" value="own" x-model="handled"> The owner's own contractor</label>
                        </div>
                        <div x-show="handled === 'own'" x-cloak class="grid grid-cols-2 gap-3">
                            <div><label class="cxod-lbl">Their name (optional)</label><input type="text" name="contractor_name" maxlength="191" class="cxod-input"></div>
                            <div><label class="cxod-lbl">Their phone (optional)</label><input type="text" name="contractor_phone" maxlength="40" class="cxod-input"></div>
                        </div>
                        <div x-show="handled === 'list'" x-cloak>
                            <input type="search" x-model="cq" placeholder="Search contractors" class="cxod-input">
                            <div class="space-y-1 mt-1">
                                @foreach($odContractors as $odC)
                                    <label class="flex items-center gap-2 text-sm rounded-md px-2 py-1" style="border:1px solid var(--border);" data-search="{{ mb_strtolower($odC['name'] . ' ' . ($odC['phone'] ?? '')) }}" x-show="cq === '' || $el.dataset.search.indexOf(cq.toLowerCase()) !== -1">
                                        <input type="radio" name="agency_service_provider_id" value="{{ $odC['id'] }}"> <span class="font-medium">{{ $odC['name'] }}</span>@if(! empty($odC['phone']))<span class="text-xs" style="color: var(--text-muted);">{{ $odC['phone'] }}</span>@endif
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @endif
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="cxod-lbl" for="cxod-via-{{ $subject->id }}">How did the owner tell you?</label>
                            <select id="cxod-via-{{ $subject->id }}" name="told_via" required class="cxod-input" data-od-via>
                                <option value="phone_call" @selected(old('told_via') === 'phone_call')>Phone call</option>
                                <option value="whatsapp" @selected(old('told_via') === 'whatsapp')>WhatsApp</option>
                                <option value="email" @selected(old('told_via') === 'email')>Email</option>
                            </select>
                        </div>
                        <div>
                            <label class="cxod-lbl" for="cxod-when-{{ $subject->id }}">When did they tell you?</label>
                            <input id="cxod-when-{{ $subject->id }}" type="datetime-local" name="told_at" required class="cxod-input" data-od-when value="{{ old('told_at', now()->setTimezone($odTz)->format('Y-m-d\TH:i')) }}">
                        </div>
                    </div>
                    <div>
                        <label class="cxod-lbl" for="cxod-note-{{ $subject->id }}"><span x-show="decision === 'declined'" x-cloak>Why did the owner decline? What did they say?</span><span x-show="decision !== 'declined'">What did the owner say?</span> <span class="text-red-500">*</span></label>
                        <textarea id="cxod-note-{{ $subject->id }}" name="note" required rows="3" maxlength="4000" class="cxod-input" data-od-note>{{ old('note') }}</textarea>
                    </div>
                    <div>
                        <label class="cxod-lbl" for="cxod-proof-{{ $subject->id }}">Proof (optional &mdash; a screenshot of the WhatsApp or email)</label>
                        <input id="cxod-proof-{{ $subject->id }}" type="file" name="proof" accept="image/*" class="text-xs" data-od-proof>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-confirm-submit title="Record the owner's decision" message="This is saved under your name and cannot be edited afterwards. The owner is emailed what you wrote down. Record it now?" confirm-label="Record the decision" data-od-save>Record the decision</x-confirm-submit>
                        <button type="button" class="corex-btn-outline text-xs" @click="open = false" data-od-cancel>Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <p class="text-xs" style="color: var(--text-muted);" data-owner-decision-blocked>{{ $odBlock }}</p>
    @endif
</div>
@once
<style>
    .cxod-overlay { position: fixed; inset: 0; z-index: 90000; display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; background: rgba(15, 23, 42, .55); overflow-y: auto; }
    .cxod-overlay[x-cloak] { display: none; }
    .cxod-box { width: 100%; max-width: 640px; background: var(--surface, #fff); color: var(--text-primary, #141821); border: 1px solid var(--border, #ddd); border-radius: 10px; padding: 18px 20px; }
    .cxod-title { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
    .cxod-sub { font-size: 12px; color: var(--text-muted, #5a6472); margin: 0 0 12px; }
    .cxod-warn { font-size: 12px; padding: 6px 10px; border-radius: 8px; background: #fff4e5; border: 1px solid #f59e0b; color: #5a3b00; margin: 0 0 10px; }
    .cxod-lbl { display: block; font-size: 12px; font-weight: 600; margin: 4px 0 3px; }
    .cxod-help { font-size: 11px; color: var(--text-muted, #5a6472); margin-top: 3px; }
    .cxod-input { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border, #ddd); background: var(--surface-2, #f4f6fb); color: var(--text-primary, #141821); font-size: 14px; }
</style>
@endonce
@endif
