{{--
    .ai/specs/rentals-faults-work-orders.md section 22.5 - "Record the owner's decision" (Decision 1): the ONE way an agent records what the owner told them (phone call,
    WhatsApp, email) while the owner's answer is awaited. Replaces the fault page's older "Record decision on the owner's behalf" form. A popup OVER the page; saved under
    the agent's name, never edited, replaceable by a newer one until a work order exists; the owner is emailed the same sentence at once.
    Expects: $faultReport, $authorityOwners, $recordBlock, $authorisations.
--}}
@php
    $roTz = $faultReport->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $roActive = $authorisations->where('point', 'inspect')->whereNull('superseded_at')->last();
    $roCan = auth()->user()->hasPermission('rental_fault_reports.record_approval');
@endphp
@if($roCan)
    @error('owner_decision')<div class="text-xs w-full" style="color: var(--ds-crimson);" data-owner-decision-error>{{ $message }}</div>@enderror
    @if($recordBlock === null)
        <div x-data="{ open: {{ $errors->has('owner_decision') ? 'true' : 'false' }}, decision: '{{ old('decision', 'authorise') }}' }" x-on:keydown.escape.window="open = false" class="contents">
            <button type="button" class="corex-btn-outline text-xs" data-owner-decision-open @click="open = true">Record the owner's decision</button>
            <div x-show="open" x-cloak class="cxod-overlay" role="dialog" aria-modal="true" aria-labelledby="cxod-title" data-owner-decision-modal>
                <div class="cxod-box" x-on:click.outside="open = false">
                    <h3 id="cxod-title" class="cxod-title">Record the owner's decision</h3>
                    <p class="cxod-sub">Use this when the owner has told you their decision by phone, WhatsApp or email. It is saved under your name and cannot be edited afterwards &mdash; it can only be replaced by a newer one, until a work order is created. The owner is emailed what you write down and can query it.</p>
                    @if($roActive && $roActive->isCaptured())
                        <p class="cxod-warn" data-owner-decision-replaces>A decision is already recorded ({{ $roActive->isAuthorised() ? 'Send someone to look and quote' : 'Declined' }}, {{ $roActive->created_at?->copy()->setTimezone($roTz)->format('j M Y H:i') }}). Saving this replaces it; both stay in the history.</p>
                    @endif
                    <form method="POST" action="{{ route('corex.rental-fault-reports.owner-decision.store', $faultReport) }}" enctype="multipart/form-data" class="space-y-3" data-owner-decision-form>
                        @csrf
                        <div>
                            <label class="cxod-lbl" for="cxod-owner">Which owner told you?</label>
                            <select id="cxod-owner" name="owner_contact_id" required class="cxod-input" data-od-owner>
                                @if($authorityOwners->count() > 1)<option value="">Choose the owner</option>@endif
                                @foreach($authorityOwners as $odOwner)
                                    <option value="{{ $odOwner->id }}" @selected((string) old('owner_contact_id') === (string) $odOwner->id)>{{ trim(($odOwner->first_name ?? '') . ' ' . ($odOwner->last_name ?? '')) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <span class="cxod-lbl">What did the owner decide?</span>
                            <div class="flex flex-wrap gap-4 mt-1 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" name="decision" value="authorise" x-model="decision" required data-od-decision="authorise"> Send someone to look and quote</label>
                                <label class="flex items-center gap-2"><input type="radio" name="decision" value="declined" x-model="decision" data-od-decision="declined"> Declined</label>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="cxod-lbl" for="cxod-via">How did the owner tell you?</label>
                                <select id="cxod-via" name="told_via" required class="cxod-input" data-od-via>
                                    <option value="phone_call" @selected(old('told_via') === 'phone_call')>Phone call</option>
                                    <option value="whatsapp" @selected(old('told_via') === 'whatsapp')>WhatsApp</option>
                                    <option value="email" @selected(old('told_via') === 'email')>Email</option>
                                </select>
                            </div>
                            <div>
                                <label class="cxod-lbl" for="cxod-when">When did they tell you?</label>
                                <input id="cxod-when" type="datetime-local" name="told_at" required class="cxod-input" data-od-when value="{{ old('told_at', now()->setTimezone($roTz)->format('Y-m-d\TH:i')) }}">
                            </div>
                        </div>
                        <div>
                            <label class="cxod-lbl" for="cxod-note"><span x-show="decision === 'declined'" x-cloak>Why did the owner decline? What did they say?</span><span x-show="decision !== 'declined'">What did the owner say?</span> <span style="color: var(--ds-crimson);">*</span></label>
                            <textarea id="cxod-note" name="note" required rows="3" maxlength="4000" class="cxod-input" data-od-note>{{ old('note') }}</textarea>
                        </div>
                        <div>
                            <label class="cxod-lbl" for="cxod-proof">Proof (optional &mdash; a screenshot of the WhatsApp or email)</label>
                            <input id="cxod-proof" type="file" name="proof" accept="image/*" class="text-xs" data-od-proof>
                        </div>
                        <div class="cx-action-row">
                            <x-confirm-submit title="Record the owner's decision" message="This is saved under your name and cannot be edited afterwards. The owner is emailed what you wrote down. Record it now?" confirm-label="Record the decision" data-od-save>Record the decision</x-confirm-submit>
                            <button type="button" class="corex-btn-outline text-xs" @click="open = false" data-od-cancel>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @else
        <p class="text-xs" style="color: var(--text-muted);" data-owner-decision-blocked>{{ $recordBlock }}</p>
    @endif
    @once
    <style>
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
@endif
