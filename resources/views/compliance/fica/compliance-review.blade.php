@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data="coReview()">
    {{-- Page header (Pattern A — flat neutral) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Compliance Officer Review</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $submission->contact ? $submission->contact->full_name : 'Unknown' }}
                    — Entity: {{ ucfirst($submission->entity_type) }}
                    — Agent approved: {{ $submission->agent_verified_at?->format('d M Y') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 flex-shrink-0">
                <a href="{{ route('compliance.fica.show', $submission) }}" class="corex-btn-outline text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Submission
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">{{ session('success') }}</div>
    @endif

    @php
        $data = $submission->form_data ?? [];
        $personal = $data['personal'] ?? [];
        $entity = $data['entity'] ?? [];
        $service = $data['service'] ?? [];
        $pepData = $data['pep'] ?? [];
        $principalData = $data['principal'] ?? [];
        $repData = $data['representative'] ?? [];
        $declData = $data['declaration'] ?? [];
        $agentData = $submission->agent_verification_data ?? [];
    @endphp

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        {{-- LEFT: Submitted form data --}}
        <div class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-faint)">Recipient Submission</h2>
            @include('compliance.fica.partials.submitted-data', ['submission' => $submission, 'personal' => $personal, 'entity' => $entity, 'service' => $service, 'pepData' => $pepData, 'principalData' => $principalData, 'repData' => $repData, 'declData' => $declData])
        </div>

        {{-- MIDDLE: Agent verification (read-only) --}}
        <div class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-faint)">Agent Verification</h2>
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Agent Review</h3>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-xs" style="color:var(--text-faint)">Agent</dt><dd class="font-medium" style="color:var(--text-primary)">{{ $submission->agentVerifiedBy->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs" style="color:var(--text-faint)">Date</dt><dd style="color:var(--text-primary)">{{ $submission->agent_verified_at?->format('d M Y H:i') }}</dd></div>
                    <div><dt class="text-xs" style="color:var(--text-faint)">Risk Rating</dt><dd class="font-semibold {{ [1 => 'text-emerald-600', 2 => 'text-amber-600', 3 => 'text-red-600'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '—' }}</dd></div>
                    @if($submission->verification_method)
                    <div><dt class="text-xs" style="color:var(--text-faint)">Verification Method</dt><dd>@foreach($submission->verification_method as $m)<span class="inline-block rounded px-1.5 py-0.5 text-xs mr-1 mb-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary)">{{ str_replace('_', ' ', ucfirst($m)) }}</span>@endforeach</dd></div>
                    @endif
                </dl>
            </div>

            @if(!empty($agentData))
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Agent Checklist</h3>
                <dl class="space-y-2 text-sm">
                    @foreach($agentData as $key => $val)
                        @if($key !== 'suspicious_details')
                        <div class="flex justify-between">
                            <dt class="text-xs" style="color:var(--text-muted)">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                            <dd class="text-xs font-semibold {{ $val === 'yes' ? 'text-emerald-600' : ($val === 'no' ? 'text-red-600' : '') }}" @if(!in_array($val, ['yes','no'])) style="color:var(--text-faint)" @endif>{{ ucfirst($val ?: '—') }}</dd>
                        </div>
                        @endif
                    @endforeach
                </dl>
            </div>
            @endif

            @if($submission->agent_notes)
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Agent Notes</h3>
                <p class="text-sm" style="color:var(--text-secondary)">{{ $submission->agent_notes }}</p>
            </div>
            @endif
        </div>

        {{-- RIGHT: CO verification form --}}
        <div class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-faint)">Your Verification</h2>

            {{-- CO Checklist --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-sm font-bold mb-3 pb-2 border-b border-emerald-500" style="color:var(--text-primary)">Compliance Checklist</h3>
                <div class="space-y-3 text-sm">
                    @foreach([
                        ['key' => 'identity_docs', 'label' => 'Identity document verified?', 'type' => 'yn'],
                        ['key' => 'address_docs', 'label' => 'Address proof verified (< 2 months)?', 'type' => 'yn'],
                        ['key' => 'authority_docs', 'label' => 'Authority document verified?', 'type' => 'yna'],
                        ['key' => 'delegating_docs', 'label' => 'Delegating authority verified?', 'type' => 'yna'],
                        ['key' => 'is_vip', 'label' => 'Client is VIP/PEP?', 'type' => 'yn'],
                        ['key' => 'suspicious', 'label' => 'Suspicious or unusual activity?', 'type' => 'yn'],
                        ['key' => 'consistent', 'label' => 'Transaction consistent with knowledge of client?', 'type' => 'yn'],
                    ] as $item)
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary)">{{ $item['label'] }}</label>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-1"><input type="radio" x-model="coChecklist.{{ $item['key'] }}" value="yes"> <span class="text-xs" style="color:var(--text-primary)">Yes</span></label>
                            <label class="flex items-center gap-1"><input type="radio" x-model="coChecklist.{{ $item['key'] }}" value="no"> <span class="text-xs" style="color:var(--text-primary)">No</span></label>
                            @if($item['type'] === 'yna')
                            <label class="flex items-center gap-1"><input type="radio" x-model="coChecklist.{{ $item['key'] }}" value="na"> <span class="text-xs" style="color:var(--text-primary)">N/A</span></label>
                            @endif
                        </div>
                        @if($item['key'] === 'suspicious')
                        <div x-show="coChecklist.suspicious === 'yes'" x-cloak class="mt-1">
                            <textarea x-model="coChecklist.suspicious_details" rows="2" class="w-full rounded-md px-2 py-1 text-xs focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" placeholder="Details..."></textarea>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- TFS Screening Panel --}}
            @include('compliance.fica.partials.tfs-panel', ['submission' => $submission])

            {{-- AT-269 (P2-49) — a REFERRED pack is the station of its recipient / the
                 primary CO only. Any other officer sees why it is read-only, not a row
                 of buttons that would 403. Non-referred packs (RO station) are unchanged. --}}
            @if($submission->status === 'referred_to_co' && ! ($viewerOwnsReferralStation ?? false))
                <div class="rounded-md p-5 text-sm"
                     style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 30%, transparent); color:var(--text-primary);">
                    <strong>Awaiting the Compliance Officer’s decision.</strong>
                    This FICA was escalated and can only be decided by the Compliance Officer it was referred to.
                    You can review the details above, but the approve, return and reject actions are theirs.
                </div>
            @else
            {{-- Approve Form --}}
            <form method="POST" action="{{ route('compliance.fica.compliance-approve', $submission) }}" @submit.prevent="submitApproval">
                @csrf
                {{-- Hidden CO checklist fields --}}
                <template x-for="key in Object.keys(coChecklist)">
                    <input type="hidden" :name="'co_checklist[' + key + ']'" :value="coChecklist[key]">
                </template>

                <div class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2 border-b border-emerald-500" style="color:var(--text-primary)">Final Approval</h3>

                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary)">TFS Screening Completed? *</label>
                        <div class="flex gap-4 text-sm mb-1">
                            <label class="flex items-center gap-1"><input type="radio" name="tfs_screening" value="yes" required> <span class="text-xs" style="color:var(--text-primary)">Yes</span></label>
                            <label class="flex items-center gap-1"><input type="radio" name="tfs_screening" value="no"> <span class="text-xs" style="color:var(--text-primary)">No</span></label>
                        </div>
                        <p class="text-xs" style="color:var(--text-faint)">Use the TFS Screening panel above to perform the check.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary)">Risk Rating (CO can override) *</label>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="1" required {{ $submission->risk_rating === 1 ? 'checked' : '' }}> <span class="text-emerald-600 font-medium">Low</span></label>
                            <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="2" {{ $submission->risk_rating === 2 ? 'checked' : '' }}> <span class="text-amber-600 font-medium">Medium</span></label>
                            <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="3" {{ $submission->risk_rating === 3 ? 'checked' : '' }}> <span class="text-red-600 font-medium">High</span></label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary)">Compliance Officer</label>
                        <input type="text" value="{{ auth()->user()->name }}" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" readonly>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary)">Notes</label>
                        <textarea name="co_notes" rows="3" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" placeholder="Optional compliance notes..."></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary)">Your Signature *</label>
                        <div style="position: relative;">
                            {{-- Signature pad reads as paper (#fff) in BOTH themes: the ink is drawn
                                 black by the canvas API, so a dark surface would hide the stroke. Matches
                                 how a stored signature is rendered on the show/submitted-data panels. --}}
                            <canvas x-ref="coSignatureCanvas" width="400" height="120" style="width: 100%; border: 1px solid var(--border); border-radius: 6px; background: #ffffff; touch-action: none; cursor: crosshair;"></canvas>
                            <button type="button" @click="clearSignature()" style="position: absolute; top: 0.25rem; right: 0.25rem; font-size: 0.7rem; border-radius: 6px; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); padding: 0.15rem 0.4rem; cursor: pointer;">Clear</button>
                        </div>
                        <input type="hidden" name="co_signature_data" x-model="signatureDataUrl">
                    </div>

                    @error('tfs')
                        <div class="rounded-md px-3 py-2 text-xs" style="background:rgba(220,38,38,0.08); border:1px solid var(--ds-crimson,#c41e3a); color:var(--ds-crimson,#c41e3a);">{{ $message }}</div>
                    @enderror
                    @if($submission->tfsGateCleared())
                        <button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-green,#059669); box-shadow:none;" :disabled="submitting">
                            <span x-show="!submitting">Approve & Finalise</span>
                            <span x-show="submitting" x-cloak>Processing...</span>
                        </button>
                    @else
                        <button type="button" disabled class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-green,#059669); box-shadow:none; opacity:0.5; cursor:not-allowed;" title="Resolve TFS sanctions screening first">
                            Approve — blocked by TFS screening
                        </button>
                        <p class="text-xs text-center" style="color:var(--ds-crimson,#c41e3a);">Resolve the TFS sanctions screening (panel above) before finalising.</p>
                    @endif
                </div>
            </form>

            {{-- Return to Agent --}}
            <form method="POST" action="{{ route('compliance.fica.compliance-reject', $submission) }}">
                @csrf
                <input type="hidden" name="action" value="return_to_agent">
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2 border-b border-amber-500" style="color:var(--text-primary)">Return to Agent</h3>
                    <textarea name="reviewer_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-3" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" placeholder="Reason for returning..." required></textarea>
                    <button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-amber,#f59e0b); box-shadow:none;">Return to Agent</button>
                </div>
            </form>

            {{-- Reject --}}
            <form method="POST" action="{{ route('compliance.fica.compliance-reject', $submission) }}">
                @csrf
                <input type="hidden" name="action" value="reject">
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2 border-b border-red-500" style="color:var(--text-primary)">Reject</h3>
                    <textarea name="reviewer_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-3" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" placeholder="Reason for rejection..." required></textarea>
                    <button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-crimson,#c41e3a); box-shadow:none;" onclick="return confirm('Are you sure?')">Reject</button>
                </div>
            </form>
            @endif

            {{-- AT-236 — Escalate to CO (any non-primary-CO reviewer, e.g. an RO who cannot self-approve) --}}
            @include('compliance.fica.partials.refer-to-co', ['submission' => $submission, 'referralEnabled' => $referralEnabled ?? true, 'viewerIsPrimaryCo' => $viewerIsPrimaryCo ?? false])

            {{-- AT-236 — CO returns a REFERRED pack to whoever referred it, with comments.
                 AT-269 — station-owner only, matching the server guard. --}}
            @if($submission->status === 'referred_to_co' && ($viewerOwnsReferralStation ?? false))
                <form method="POST" action="{{ route('compliance.fica.return-to-referrer', $submission) }}">
                    @csrf
                    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                        <h3 class="text-sm font-bold mb-1 pb-2 border-b border-amber-500" style="color:var(--text-primary)">Return to Referrer</h3>
                        <p class="text-xs mb-3" style="color:var(--text-muted)">
                            Send this back to {{ $submission->referredBy->name ?? 'the referrer' }} with your comments
                            @if($submission->referral_note)<br><span class="italic">Referred because: “{{ $submission->referral_note }}”</span>@endif
                        </p>
                        <textarea name="reviewer_notes" rows="2" required minlength="3" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-3" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" placeholder="Comments for the referrer..."></textarea>
                        <button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-amber,#f59e0b); box-shadow:none;">Return to Referrer</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

<script>
function coReview() {
    return {
        submitting: false,
        signatureDataUrl: '',
        signaturePad: null,
        coChecklist: {
            identity_docs: '', address_docs: '', authority_docs: '', delegating_docs: '',
            is_vip: '', suspicious: '', suspicious_details: '', consistent: '',
        },
        init() { this.$nextTick(() => this.initSignaturePad()); },
        initSignaturePad() {
            const canvas = this.$refs.coSignatureCanvas;
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let drawing = false, lastX, lastY;
            const getPos = (e) => {
                const r = canvas.getBoundingClientRect();
                const sx = canvas.width / r.width, sy = canvas.height / r.height;
                if (e.touches) return { x: (e.touches[0].clientX - r.left) * sx, y: (e.touches[0].clientY - r.top) * sy };
                return { x: (e.clientX - r.left) * sx, y: (e.clientY - r.top) * sy };
            };
            const start = (e) => { e.preventDefault(); drawing = true; const p = getPos(e); lastX = p.x; lastY = p.y; };
            const move = (e) => { if (!drawing) return; e.preventDefault(); const p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = 'var(--text-primary)'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; };
            const end = () => { drawing = false; };
            canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
            canvas.addEventListener('touchstart', start); canvas.addEventListener('touchmove', move); canvas.addEventListener('touchend', end);
            this.signaturePad = { canvas, ctx };
        },
        clearSignature() {
            if (!this.signaturePad) return;
            this.signaturePad.ctx.clearRect(0, 0, this.signaturePad.canvas.width, this.signaturePad.canvas.height);
            this.signatureDataUrl = '';
        },
        submitApproval() {
            if (this.signaturePad) {
                const c = this.signaturePad.canvas, ctx = this.signaturePad.ctx;
                const px = ctx.getImageData(0, 0, c.width, c.height).data;
                let has = false; for (let i = 3; i < px.length; i += 4) { if (px[i] > 0) { has = true; break; } }
                if (!has) { alert('Please provide your signature.'); return; }
                this.signatureDataUrl = c.toDataURL('image/png');
            }
            this.submitting = true;
            this.$nextTick(() => this.$el.closest('form').submit());
        }
    };
}
</script>
@endsection
