{{--
    Phase 8 — outcome capture panel + 3-step modal on the Presentation show page.

    Two states:
      - No outcome yet → big "What happened?" CTA panel
      - Outcome recorded → compact summary + Edit (if not locked)

    The modal is teleported to <body> with x-teleport so it sits above
    everything (including the Phase 7 hotfix's z-[9999] modal layer).
    Prefill via ?outcome=... in the URL (from email quick-links) is read
    into Alpine state on init.
--}}
@php
    $outcome = $presentation->outcome;
    $editable = $outcome?->isEditable() ?? false;
    $locked   = $outcome?->locked ?? false;

    $outcomeLabels = [
        'won_mandate'           => ['Won the mandate',       '#16a34a', '#dcfce7', '&check;'],
        'won_sale'              => ['Won the mandate + sale','#0ea5e9', '#dbeafe', '&starf;'],
        'lost_to_competitor'    => ['Lost to competitor',    '#dc2626', '#fee2e2', '&times;'],
        'lost_to_no_decision'   => ['Seller did not decide', '#92400e', '#fef3c7', '&#8629;'],
        'lost_to_price_dispute' => ['Lost on price/strategy','#dc2626', '#fee2e2', '&#8856;'],
        'lost_to_no_response'   => ['Seller went silent',    '#64748b', '#f1f5f9', '&#8230;'],
        'still_pending'         => ['Still working it',      '#0ea5e9', '#dbeafe', '&#9203;'],
        'other'                 => ['Other',                 '#64748b', '#f1f5f9', '&#8230;'],
    ];
    $reasonLabels = [
        'price_too_high_seller'      => 'Seller insisted on higher price than recommended',
        'price_too_low_seller'       => 'Seller wanted to underprice',
        'commission_concerns'        => 'Commission structure concerns',
        'sole_mandate_concerns'      => 'Did not want a sole mandate',
        'family_pressure'            => 'Family wanted a different agency',
        'existing_relationship'      => 'Prior agent relationship',
        'agency_reputation'          => 'Agency reputation concern',
        'agent_personality'          => 'Personality fit concern',
        'timing_change'              => 'Decided to delay/not sell',
        'property_issues_discovered' => 'Survey/inspection revealed problems',
        'price_match_with_other'     => 'Competitor matched price, relationship decided it',
        'other'                      => 'Other (notes required)',
    ];
    $prefillOutcome = request()->string('outcome')->toString();
    if ($prefillOutcome && !in_array($prefillOutcome, array_keys($outcomeLabels), true)) {
        $prefillOutcome = '';
    }
@endphp

<div class="ds-status-card mb-4" x-data="presentationOutcome({{ $presentation->id }}, '{{ $prefillOutcome }}')">

    @if(!$outcome)
        {{-- Empty state: prompt to record --}}
        <div class="flex items-start justify-between gap-4">
            <div style="flex:1;">
                <h2 class="ds-section-header" style="margin-bottom:6px;">What happened with this presentation?</h2>
                <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 12px 0;">
                    Recording the outcome helps you and your team learn what works. You can edit your answer for 90 days.
                </p>
            </div>
            <button type="button"
                    @click="openModal()"
                    class="corex-btn-primary"
                    style="white-space:nowrap;">
                Record Outcome →
            </button>
        </div>
    @else
        {{-- Recorded state --}}
        @php [$label, $color, $bg, $icon] = $outcomeLabels[$outcome->outcome] ?? ['Outcome', '#64748b', '#f1f5f9', '?']; @endphp
        <div class="flex items-start justify-between gap-4">
            <div style="flex:1;">
                <h2 class="ds-section-header" style="margin-bottom:6px;">Outcome</h2>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;background:{{ $bg }};color:{{ $color }};font-size:0.8125rem;font-weight:600;">
                        <span style="font-size:1rem;">{!! $icon !!}</span>
                        {{ $label }}
                    </span>
                    @if($outcome->decision_at)
                        <span style="font-size:0.75rem;color:var(--text-muted);">
                            Decided {{ $outcome->decision_at->format('j M Y') }}
                        </span>
                    @endif
                    @if($locked)
                        <span style="font-size:0.6875rem;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:99px;font-weight:600;">
                            🔒 Locked for analytics
                        </span>
                    @endif
                </div>

                @if($outcome->cancellation_reason)
                    <div style="margin-top:10px;font-size:0.8125rem;color:var(--text-secondary);">
                        <strong>Reason:</strong> {{ $reasonLabels[$outcome->cancellation_reason] ?? $outcome->cancellation_reason }}
                    </div>
                @endif
                @if($outcome->cancellation_competitor_agency)
                    <div style="margin-top:4px;font-size:0.8125rem;color:var(--text-secondary);">
                        <strong>Competitor:</strong> {{ $outcome->cancellation_competitor_agency }}
                        @if($outcome->cancellation_competitor_price)
                            @ R {{ number_format((int) $outcome->cancellation_competitor_price) }}
                        @endif
                    </div>
                @endif
                @if($outcome->notes)
                    <div style="margin-top:8px;padding:8px 12px;background:var(--surface-2);border-radius:4px;font-size:0.8125rem;color:var(--text-secondary);white-space:pre-wrap;">{{ $outcome->notes }}</div>
                @endif
                @if($outcome->resulted_in_deal_id && $outcome->deal)
                    <div style="margin-top:8px;font-size:0.75rem;">
                        <a href="#" style="color:var(--brand-button);">→ Deal #{{ $outcome->deal->deal_no }}</a>
                    </div>
                @endif

                <div style="margin-top:10px;font-size:0.6875rem;color:var(--text-muted);">
                    Recorded by {{ $outcome->recorder?->name }} {{ $outcome->recorded_at?->diffForHumans() }}
                </div>
            </div>

            @if($editable)
                <button type="button"
                        @click="openModal()"
                        class="corex-btn-outline"
                        style="white-space:nowrap;font-size:0.8125rem;">
                    Edit
                </button>
            @endif
        </div>
    @endif

    {{-- ── 3-STEP MODAL (teleported) ────────────────────────────────────── --}}
    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-[9999] flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="modalOpen = false"></div>
            <div class="relative w-full max-w-2xl max-h-[90vh] flex flex-col rounded-md shadow-2xl"
                 style="background:var(--surface); border:1px solid var(--border);"
                 @click.stop>

                <div class="flex items-center justify-between px-5 py-4" style="border-bottom:1px solid var(--border);">
                    <div>
                        <h3 style="margin:0;font-size:1rem;font-weight:600;color:var(--text-primary);">
                            <span x-show="step === 1">What happened with this presentation?</span>
                            <span x-show="step === 2">Tell us more</span>
                            <span x-show="step === 3">Confirm</span>
                        </h3>
                        <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:2px;" x-text="'Step ' + step + ' of ' + maxStep"></div>
                    </div>
                    <button type="button" @click="modalOpen = false" style="background:none;border:0;color:var(--text-muted);font-size:1.25rem;cursor:pointer;">&times;</button>
                </div>

                <form method="POST"
                      x-ref="outcomeForm"
                      :action="`/presentations/${presentationId}/outcome`"
                      class="overflow-y-auto"
                      style="padding:18px 20px;"
                      @submit="submitting = true">
                    @csrf

                    {{-- ── STEP 1 ─────────────────────────────────────── --}}
                    <div x-show="step === 1">
                        <p style="margin:0 0 14px 0;font-size:0.8125rem;color:var(--text-secondary);">
                            Pick the outcome that best describes where this pitch ended up.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            @php
                                $cards = [
                                    ['won_mandate',           '✓', 'Won the mandate',          'Seller signed with us — listing live or about to be.'],
                                    ['won_sale',              '★', 'Won mandate AND sale',     'Seller signed AND a buyer is locked in.'],
                                    ['lost_to_competitor',    '✗', 'Lost to another agency',   "They signed with someone else."],
                                    ['lost_to_no_decision',   '↪', 'Seller did not sell',      "Decided not to list — at least not now."],
                                    ['lost_to_price_dispute', '⊘', "Couldn't agree on price",  "We couldn't align on price or strategy."],
                                    ['still_pending',         '…', 'Other / still working it', 'No final answer yet.'],
                                ];
                            @endphp
                            @foreach($cards as [$val, $iconC, $titleC, $descC])
                                <label style="cursor:pointer;display:flex;gap:10px;padding:12px;border:2px solid var(--border);border-radius:6px;align-items:flex-start;"
                                       :style="form.outcome === '{{ $val }}' ? 'border-color: var(--brand-button); background: rgba(0,212,170,0.04);' : ''">
                                    <input type="radio" name="outcome" value="{{ $val }}" x-model="form.outcome" required style="margin-top:3px;">
                                    <div>
                                        <div style="font-size:1.125rem;">{{ $iconC }}</div>
                                        <div style="font-weight:600;color:var(--text-primary);font-size:0.875rem;">{{ $titleC }}</div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;line-height:1.3;">{{ $descC }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- ── STEP 2 ─────────────────────────────────────── --}}
                    <div x-show="step === 2">

                        {{-- Lost to competitor --}}
                        <template x-if="form.outcome === 'lost_to_competitor'">
                            <div>
                                <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Which agency? (optional)</label>
                                <input type="text" name="cancellation_competitor_agency" x-model="form.cancellation_competitor_agency" maxlength="200"
                                       placeholder="e.g. Pam Golding, RE/MAX, Sole agent unknown"
                                       style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">

                                <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">At what price? (optional, ZAR)</label>
                                <input type="number" name="cancellation_competitor_price" x-model.number="form.cancellation_competitor_price" min="0" step="10000"
                                       style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">

                                <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Why did they choose them? *</label>
                                <select name="cancellation_reason" x-model="form.cancellation_reason" required
                                        style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">
                                    <option value="">Pick a reason…</option>
                                    @foreach(['family_pressure','existing_relationship','agency_reputation','agent_personality','price_match_with_other','commission_concerns','price_too_high_seller','sole_mandate_concerns','other'] as $key)
                                        <option value="{{ $key }}">{{ $reasonLabels[$key] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        {{-- Lost no-decision --}}
                        <template x-if="form.outcome === 'lost_to_no_decision'">
                            <div>
                                <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Why did they decide not to sell? *</label>
                                <select name="cancellation_reason" x-model="form.cancellation_reason" required
                                        style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">
                                    <option value="">Pick a reason…</option>
                                    @foreach(['timing_change','family_pressure','property_issues_discovered','existing_relationship','other'] as $key)
                                        <option value="{{ $key }}">{{ $reasonLabels[$key] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        {{-- Lost on price --}}
                        <template x-if="form.outcome === 'lost_to_price_dispute'">
                            <div>
                                <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">What couldn't you agree on? *</label>
                                <select name="cancellation_reason" x-model="form.cancellation_reason" required
                                        style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">
                                    <option value="">Pick a reason…</option>
                                    @foreach(['price_too_high_seller','price_too_low_seller','commission_concerns','sole_mandate_concerns','other'] as $key)
                                        <option value="{{ $key }}">{{ $reasonLabels[$key] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        {{-- Won mandate / won sale --}}
                        <template x-if="form.outcome === 'won_mandate' || form.outcome === 'won_sale'">
                            <div style="padding:12px;background:rgba(22,163,74,0.06);border-left:3px solid #16a34a;border-radius:4px;margin-bottom:12px;">
                                <div style="font-weight:600;color:#15803d;font-size:0.875rem;">🎉 Nice work.</div>
                                <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:4px;">
                                    Your branch manager will be pinged. Optionally link the deal below so we can show the win in dashboards.
                                </div>
                            </div>
                        </template>

                        {{-- Other --}}
                        <template x-if="form.outcome === 'other' || form.outcome === 'lost_to_no_response'">
                            <div>
                                <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">
                                    Pick a category (or 'Other' if none fit) *
                                </label>
                                <select name="cancellation_reason" x-model="form.cancellation_reason"
                                        :required="form.outcome === 'other'"
                                        style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">
                                    <option value="">Pick a reason…</option>
                                    @foreach($reasonLabels as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        {{-- Common — decision date + notes --}}
                        <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">When was the decision made?</label>
                        <input type="date" name="decision_at" x-model="form.decision_at"
                               :max="new Date().toISOString().slice(0,10)"
                               style="padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;margin-bottom:12px;">

                        <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">
                            Anything else to remember?
                            <span x-show="form.cancellation_reason === 'other'" style="color:#dc2626;">required for "Other"</span>
                        </label>
                        <textarea name="notes" x-model="form.notes" rows="3" maxlength="5000"
                                  :required="form.cancellation_reason === 'other'"
                                  placeholder="Free text — what would help you (or your team) win the next one?"
                                  style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;font-family:inherit;"></textarea>
                    </div>

                    {{-- ── STEP 3 — Confirm ─────────────────────────────── --}}
                    <div x-show="step === 3">
                        <div style="padding:14px;background:var(--surface-2);border-radius:6px;font-size:0.875rem;line-height:1.5;">
                            <div><strong>Outcome:</strong> <span x-text="outcomeLabel()"></span></div>
                            <div x-show="form.cancellation_reason"><strong>Reason:</strong> <span x-text="reasonLabel()"></span></div>
                            <div x-show="form.cancellation_competitor_agency"><strong>Competitor:</strong> <span x-text="form.cancellation_competitor_agency"></span></div>
                            <div x-show="form.decision_at"><strong>Decided:</strong> <span x-text="form.decision_at"></span></div>
                            <div x-show="form.notes"><strong>Notes:</strong> <span x-text="form.notes"></span></div>
                        </div>
                        <div style="margin-top:12px;font-size:0.75rem;color:var(--text-muted);">
                            You can edit this for 90 days before it's locked for analytics.
                        </div>
                    </div>

                </form>

                <div class="flex items-center justify-between px-5 py-3" style="border-top:1px solid var(--border);background:var(--surface-2);">
                    <button type="button" @click="back()" x-show="step > 1"
                            style="font-size:0.8125rem;padding:7px 14px;background:transparent;color:var(--text-muted);border:1px solid var(--border);border-radius:4px;cursor:pointer;">← Back</button>
                    <div style="flex:1;"></div>
                    <button type="button" @click="next()" x-show="step < maxStep && form.outcome"
                            style="font-size:0.8125rem;padding:7px 16px;background:var(--brand-button);color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer;">Next →</button>
                    <button type="submit" @click.prevent="submit()" x-show="step === maxStep" :disabled="submitting"
                            style="font-size:0.8125rem;padding:7px 16px;background:var(--brand-button);color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer;">
                        <span x-show="!submitting">Save Outcome</span><span x-show="submitting">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
function presentationOutcome(presentationId, prefillOutcome) {
    return {
        presentationId: presentationId,
        modalOpen: false,
        step: 1,
        submitting: false,
        form: {
            outcome: @json($outcome?->outcome ?? ''),
            cancellation_reason: @json($outcome?->cancellation_reason ?? ''),
            cancellation_competitor_agency: @json($outcome?->cancellation_competitor_agency ?? ''),
            cancellation_competitor_price:  @json($outcome?->cancellation_competitor_price ?? null),
            decision_at: @json($outcome?->decision_at?->toDateString() ?? ''),
            notes: @json($outcome?->notes ?? ''),
        },
        get maxStep() {
            // still_pending = single-step save (no step 2 questions needed)
            if (this.form.outcome === 'still_pending') return 1;
            return 3;
        },
        outcomeLabel() {
            const m = {
                won_mandate: 'Won the mandate',
                won_sale: 'Won mandate + sale',
                lost_to_competitor: 'Lost to competitor',
                lost_to_no_decision: 'Seller did not sell',
                lost_to_price_dispute: "Couldn't agree on price",
                lost_to_no_response: 'Seller went silent',
                still_pending: 'Still working it',
                other: 'Other',
            };
            return m[this.form.outcome] || '';
        },
        reasonLabel() {
            const m = @json($reasonLabels);
            return m[this.form.cancellation_reason] || '';
        },
        openModal() {
            this.step = 1;
            this.modalOpen = true;
        },
        next() {
            if (this.step < this.maxStep) this.step++;
        },
        back() {
            if (this.step > 1) this.step--;
        },
        submit() {
            this.submitting = true;
            // The form is teleported to <body> (x-teleport), so it is NOT inside
            // $root — use $refs (which survives teleport) to reach it. If it can't
            // be found, reset so the button never strands on "Saving…".
            const form = this.$refs.outcomeForm;
            if (form) { form.submit(); } else { this.submitting = false; }
        },
        init() {
            if (prefillOutcome) {
                this.form.outcome = prefillOutcome;
                this.openModal();
                // For non-pending outcomes, advance past Step 1 since
                // they already picked from the email.
                if (prefillOutcome !== 'still_pending') {
                    this.$nextTick(() => { this.step = 2; });
                }
            }
        },
    };
}
</script>
@endpush
