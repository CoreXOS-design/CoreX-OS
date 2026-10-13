{{--
    .ai/specs/rentals-faults-work-orders.md section 22.7 - on the AGENT's fault page: (1) the PAUSED BY OWNER banner with the reason and Resume (a fresh authority: the owner's
    decision recorded by the agent - the same fields as "Record the owner's decision"), and (2) the "Owner's question" block with the Answer popup.
    Expects: $faultReport, $ownerHold (?RentalOwnerHold), $ownerQueries (Collection), $authorityOwners.
--}}
@php
    $ohTz = $faultReport->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    $ohSvc = app(\App\Services\Rentals\RentalOwnerHoldService::class);
    $ohCanResume = auth()->user()->hasPermission('rental_fault_reports.record_approval');
    $ohCanAnswer = auth()->user()->hasPermission('rental_fault_reports.send_to_owner');
@endphp
@if($ownerHold)
    <div class="rounded-md px-4 py-3 text-sm space-y-2" style="background: color-mix(in srgb, var(--ds-crimson) 10%, var(--surface)); border: 2px solid var(--ds-crimson);" data-paused-by-owner>
        <div class="font-semibold" style="color: var(--ds-crimson);">PAUSED BY OWNER</div>
        <div data-paused-line>{{ $ohSvc->stopLine($ownerHold) }}</div>
        <div class="text-xs" style="color: var(--text-muted);">Nothing can be sent, approved or started while it is on hold. @if($ownerHold->contractor_told_note) {{ $ownerHold->contractor_told_note }} @endif</div>
        @error('owner_decision')<div class="text-xs" style="color: var(--ds-crimson);" data-owner-decision-error>{{ $message }}</div>@enderror
        @if($ohCanResume)
            <div x-data="{ open: {{ $errors->has('owner_decision') ? 'true' : 'false' }} }" x-on:keydown.escape.window="open = false" class="contents">
                <button type="button" class="corex-btn-outline text-xs" data-resume-open @click="open = true">Resume</button>
                <div x-show="open" x-cloak class="cxhold-overlay" role="dialog" aria-modal="true" data-resume-modal>
                    <div class="cxhold-box" x-on:click.outside="open = false">
                        <h3 class="cxhold-title">Resume this job</h3>
                        <p class="cxhold-sub">The owner stopped this. It can only carry on on a fresh authority: record what the owner has now told you (phone call, WhatsApp or email). It is saved under your name and the owner is emailed what you write down.</p>
                        <form method="POST" action="{{ route('corex.rental-fault-reports.resume', $faultReport) }}" enctype="multipart/form-data" class="space-y-3" data-resume-form>
                            @csrf
                            <div>
                                <label class="cxhold-lbl" for="cxhold-owner">Which owner told you?</label>
                                <select id="cxhold-owner" name="owner_contact_id" required class="cxhold-input" data-resume-owner>
                                    @if($authorityOwners->count() > 1)<option value="">Choose the owner</option>@endif
                                    @foreach($authorityOwners as $o)<option value="{{ $o->id }}">{{ trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? '')) }}</option>@endforeach
                                </select>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="cxhold-lbl" for="cxhold-via">How did the owner tell you?</label>
                                    <select id="cxhold-via" name="told_via" required class="cxhold-input" data-resume-via>
                                        <option value="phone_call">Phone call</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="cxhold-lbl" for="cxhold-when">When did they tell you?</label>
                                    <x-date-time-field name="told_at" id="cxhold-when" data-resume-when :value="now()->setTimezone($ohTz)->format('Y-m-d\TH:i')" />
                                </div>
                            </div>
                            <div>
                                <label class="cxhold-lbl" for="cxhold-note">What did the owner say? <span style="color: var(--ds-crimson);">*</span></label>
                                <textarea id="cxhold-note" name="note" required rows="3" maxlength="4000" class="cxhold-input" data-resume-note></textarea>
                            </div>
                            <div>
                                <label class="cxhold-lbl" for="cxhold-proof">Proof (optional &mdash; a screenshot of the WhatsApp or email)</label>
                                <input id="cxhold-proof" type="file" name="proof" accept="image/*" class="text-xs">
                            </div>
                            <div class="cx-action-row">
                                <x-confirm-submit title="Resume this job" message="This is saved under your name and the owner is emailed what you wrote down. Resume the job now?" confirm-label="Resume the job" data-resume-save>Resume the job</x-confirm-submit>
                                <button type="button" class="corex-btn-outline text-xs" @click="open = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif

@if($ownerQueries->isNotEmpty())
    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);" data-owner-questions>
        <h2 class="text-sm font-semibold">Owner's question</h2>
        @error('owner_query')<div class="text-xs" style="color: var(--ds-crimson);" data-owner-query-error>{{ $message }}</div>@enderror
        <ul class="space-y-3 text-sm">
            @foreach($ownerQueries as $oq)
                <li data-owner-question="{{ $oq->id }}">
                    <div><strong>{{ $oq->askedBy ? trim(($oq->askedBy->first_name ?? '') . ' ' . ($oq->askedBy->last_name ?? '')) : 'The owner' }}</strong> asked on {{ $oq->asked_at->copy()->setTimezone($ohTz)->format('j M Y H:i') }}: &ldquo;{{ $oq->question }}&rdquo;</div>
                    @if($oq->isAnswered())
                        <div class="text-xs" style="color: var(--text-muted);" data-owner-answer>Answered by {{ $oq->answeredBy?->name ?? 'an agent' }} on {{ $oq->answered_at->copy()->setTimezone($ohTz)->format('j M Y H:i') }}: {{ $oq->answer }}</div>
                    @elseif($ohCanAnswer)
                        <div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="contents">
                            <button type="button" class="corex-btn-outline text-xs" data-answer-open @click="open = true">Answer</button>
                            <div x-show="open" x-cloak class="cxhold-overlay" role="dialog" aria-modal="true" data-answer-modal>
                                <div class="cxhold-box" x-on:click.outside="open = false">
                                    <h3 class="cxhold-title">Answer the owner</h3>
                                    <p class="cxhold-sub">&ldquo;{{ $oq->question }}&rdquo; &mdash; your answer is saved under your name, cannot be edited afterwards, and is emailed to the owner. It also shows on their link.</p>
                                    <form method="POST" action="{{ route('corex.rental-fault-reports.owner-query.answer', [$faultReport, $oq->id]) }}" class="space-y-3" data-answer-form>
                                        @csrf
                                        <textarea name="answer" required rows="4" maxlength="2000" class="cxhold-input" data-answer-text></textarea>
                                        <div class="cx-action-row">
                                            <button type="submit" class="corex-btn text-xs" data-answer-send>Send my answer</button>
                                            <button type="button" class="corex-btn-outline text-xs" @click="open = false">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="text-xs" style="color: var(--text-muted);">Not answered yet.</div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
@once
<style>
    .cxhold-overlay { position: fixed; inset: 0; z-index: 90000; display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; background: rgba(15, 23, 42, .55); overflow-y: auto; }
    .cxhold-overlay[x-cloak] { display: none; }
    .cxhold-box { width: 100%; max-width: 640px; background: var(--surface, #fff); color: var(--text-primary, #141821); border: 1px solid var(--border, #ddd); border-radius: 10px; padding: 18px 20px; }
    .cxhold-title { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
    .cxhold-sub { font-size: 12px; color: var(--text-muted, #5a6472); margin: 0 0 12px; }
    .cxhold-lbl { display: block; font-size: 12px; font-weight: 600; margin: 4px 0 3px; }
    .cxhold-input { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border, #ddd); background: var(--surface-2, #f4f6fb); color: var(--text-primary, #141821); font-size: 14px; }
</style>
@endonce
