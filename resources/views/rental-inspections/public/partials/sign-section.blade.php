{{--
    §46 — the "Sign" step at the end of a party's personal signing page (rental-inspections.sign.show) and of the agent's
    "sign on this device" page. Included by public/show.blade.php only when a $signing context is present.

    One of four states, decided server-side from RentalInspectionSigningLinkService::signability():
      1. the party has already signed / responded through this link  -> a read-only confirmation + download
      2. the link is the agent's own                                  -> the agent signs in CoreX (login + PIN)
      3. signing is not open (not ready to sign yet / already complete) -> a plain message (+ download when complete)
      4. signing is open                                              -> the form

    Declining reuses the agency's own refusal reasons (RentalInspectionSetting::refusalReasonPresetsFor — "Disputes the
    recorded condition" etc.); nothing new is invented. A comment can be left with a signature.
--}}
@php
    /** @var array<string, mixed> $signing */
    $isDevice = ($signing['mode'] ?? 'link') === 'device';
    $outcome = $signing['outcome'] ?? null;
@endphp
<div id="sign" class="no-print bg-white rounded-2xl shadow-sm border border-slate-200 p-6" data-qa="sign-section" data-mode="{{ $signing['mode'] }}">
    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">
        {{ ($signing['role'] ?? '') === 'agent' ? 'Agent' : 'Sign this report' }}
    </h2>

    @if(! empty($signing['resign_notice']) && ! $outcome)
        <div class="rounded-lg border border-amber-300 bg-amber-50 text-amber-900 p-3 text-sm mb-3" data-qa="resign-notice">
            This report was changed after you signed it, so your earlier signature no longer counts. Please read the changed report above and sign it again.
        </div>
    @endif

    @if($outcome)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-900 p-4 text-sm" data-qa="sign-confirmation">
            @if($outcome === 'signed')
                <p class="font-semibold">Thank you{{ $signing['party_name'] ? ', ' . $signing['party_name'] : '' }} — your signature is recorded.</p>
                <p class="mt-1">You signed on {{ $signing['outcome_at']?->format('d M Y \a\t H:i') }}.@if($signing['completed']) The inspection is now complete — the report above is the signed record.@else Everyone's signatures are collected by the agent, who completes the inspection.@endif</p>
            @else
                <p class="font-semibold">Your response is recorded.</p>
                <p class="mt-1">You told us you do not agree or cannot sign, on {{ $signing['outcome_at']?->format('d M Y \a\t H:i') }}. The agent has it on record.</p>
            @endif
        </div>
        @include('rental-inspections.public.partials.download-link', ['signing' => $signing])
    @elseif(($signing['reason'] ?? null) === 'agent_link')
        <p class="text-sm text-slate-600">{{ $signing['message'] }}</p>
        @if(! empty($signing['agent_open_url']))
            <a href="{{ $signing['agent_open_url'] }}" class="mt-3 inline-block text-sm font-semibold px-4 py-2 rounded-md bg-slate-800 text-white">Open this inspection in CoreX</a>
        @endif
    @elseif(! $signing['can_sign'])
        <p class="text-sm text-slate-600" data-qa="sign-unavailable">{{ $signing['message'] }}</p>
        @include('rental-inspections.public.partials.download-link', ['signing' => $signing, 'cls' => 'bg-slate-100 text-slate-700 border border-slate-200'])
    @else
        <div x-data="inspectionLinkSigner({
                submitUrl: @js($signing['submit_url']),
                presets: @js(collect($signing['presets'])->map(fn ($p) => ['key' => $p['key'], 'label' => $p['label']])->values()),
                partyName: @js($signing['party_name']),
             })"
             x-init="init()">
            <div x-show="!done" class="space-y-4">
                <p class="text-sm text-slate-600">
                    @if($isDevice)
                        Please read the report above, then complete this section for <strong>{{ $signing['party_name'] }}</strong>.
                    @else
                        Please read the report above. When you are happy with it you can sign here; if you do not agree with something you can say so instead.
                    @endif
                </p>

                <div class="grid grid-cols-2 gap-2 text-sm">
                    <label class="flex items-center gap-2 border rounded-lg px-3 py-3" :class="action === 'sign' ? 'border-slate-800 bg-slate-50' : 'border-slate-200'">
                        <input type="radio" value="sign" x-model="action"> <span>I agree — I will sign</span>
                    </label>
                    <label class="flex items-center gap-2 border rounded-lg px-3 py-3" :class="action === 'decline' ? 'border-slate-800 bg-slate-50' : 'border-slate-200'">
                        <input type="radio" value="decline" x-model="action"> <span>I do not agree / cannot sign</span>
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1" for="signer-name">Your full name</label>
                    <input id="signer-name" type="text" x-model="typedName" maxlength="191" autocomplete="name" placeholder="Type your full name"
                           class="w-full rounded-lg border border-slate-300 px-3 py-3 text-base">
                </div>

                <div x-show="action === 'sign'" class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Your signature — draw with your finger</label>
                        <canvas x-ref="pad" class="w-full block rounded-lg border border-slate-300 bg-white" style="height:160px; touch-action:none;"></canvas>
                        <button type="button" @click="clearPad()" class="mt-1 text-xs underline text-slate-500">Clear</button>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" x-model="readConfirmed" class="mt-1">
                        <span>I have read this inspection report.</span>
                    </label>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1" for="signer-comment">Comment or note (optional)</label>
                        <textarea id="signer-comment" x-model="comment" rows="3" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>

                <div x-show="action === 'decline'" x-cloak class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1" for="decline-reason">Reason</label>
                        <select id="decline-reason" x-model="reasonPreset" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-base bg-white">
                            <option value="">Choose a reason…</option>
                            <template x-for="p in presets" :key="p.key"><option :value="p.key" x-text="p.label"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1" for="decline-note">What do you dispute? <span x-show="reasonPreset !== 'other'">(optional)</span></label>
                        <textarea id="decline-note" x-model="reasonNote" rows="3" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>

                <p x-show="error" x-cloak class="text-sm text-red-600" x-text="error" data-qa="sign-error"></p>

                <button type="button" @click="submit()" :disabled="busy"
                        class="w-full rounded-lg bg-slate-800 text-white font-semibold py-3 text-base disabled:opacity-60"
                        x-text="busy ? 'Saving…' : (action === 'sign' ? 'Sign the report' : 'Send my response')"></button>
            </div>

            <div x-show="done" x-cloak class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-900 p-4 text-sm" data-qa="sign-confirmation">
                <p class="font-semibold" x-text="doneOutcome === 'signed' ? 'Thank you — your signature is recorded.' : 'Your response is recorded.'"></p>
                <p class="mt-1">The agent has it on record.</p>
                @include('rental-inspections.public.partials.download-link', ['signing' => $signing])
                @if($isDevice && ! empty($signing['back_url']))
                    <a href="{{ $signing['back_url'] }}" class="mt-3 ml-2 inline-block text-sm font-semibold underline">Back to CoreX</a>
                @endif
            </div>
        </div>

        <script>
            function inspectionLinkSigner(cfg) {
                return {
                    submitUrl: cfg.submitUrl,
                    presets: cfg.presets,
                    action: 'sign',
                    typedName: '',
                    readConfirmed: false,
                    comment: '',
                    reasonPreset: '',
                    reasonNote: '',
                    busy: false,
                    error: '',
                    done: false,
                    doneOutcome: null,
                    pad: null,
                    init() {
                        this.$nextTick(() => this.setupPad());
                        this.$watch('action', (a) => { if (a === 'sign') this.$nextTick(() => this.setupPad()); });
                    },
                    setupPad() {
                        const canvas = this.$refs.pad;
                        if (!canvas || !window.SignaturePad) return;
                        const ratio = Math.max(window.devicePixelRatio || 1, 1);
                        const had = this.pad && !this.pad.isEmpty() ? this.pad.toData() : null;
                        canvas.width = canvas.offsetWidth * ratio;
                        canvas.height = canvas.offsetHeight * ratio;
                        canvas.getContext('2d').scale(ratio, ratio);
                        this.pad = new window.SignaturePad(canvas, { penColor: '#111827', backgroundColor: 'rgba(255,255,255,0)' });
                        if (had) this.pad.fromData(had);
                    },
                    clearPad() { if (this.pad) this.pad.clear(); },
                    async submit() {
                        this.error = '';
                        const name = this.typedName.trim();
                        if (name.length < 2) { this.error = 'Please type your full name.'; return; }
                        const body = { action: this.action, typed_name: name };
                        if (this.action === 'sign') {
                            if (!this.pad || this.pad.isEmpty()) { this.error = 'Please draw your signature.'; return; }
                            if (!this.readConfirmed) { this.error = 'Please tick that you have read this inspection report.'; return; }
                            body.signature_image = this.pad.toDataURL('image/png');
                            body.read_confirmed = true;
                            body.comment = this.comment;
                        } else {
                            if (!this.reasonPreset) { this.error = 'Please choose a reason.'; return; }
                            if (this.reasonPreset === 'other' && !this.reasonNote.trim()) { this.error = 'Please tell us the reason.'; return; }
                            body.reason_preset = this.reasonPreset;
                            body.reason_note = this.reasonNote;
                        }
                        this.busy = true;
                        try {
                            const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
                            const res = await fetch(this.submitUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                                body: JSON.stringify(body),
                            });
                            const data = await res.json().catch(() => ({}));
                            if (!res.ok) { this.error = data.message || ('That did not save (error ' + res.status + ').'); return; }
                            this.done = true;
                            this.doneOutcome = data.outcome;
                            window.scrollTo({ top: document.getElementById('sign').offsetTop - 12, behavior: 'smooth' });
                        } catch (e) {
                            this.error = 'We could not reach the server. Please check your connection and try again.';
                        } finally {
                            this.busy = false;
                        }
                    },
                };
            }
        </script>
    @endif
</div>
