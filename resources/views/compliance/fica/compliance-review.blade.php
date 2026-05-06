@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8" x-data="coReview()">
    <div class="mb-6">
        <a href="{{ route('compliance.fica.show', $submission) }}" class="text-sm text-slate-500 hover:text-slate-700 mb-2 inline-block">&larr; Back to Submission</a>
        <h1 class="text-2xl font-bold text-slate-900">Compliance Officer Review</h1>
        <p class="text-sm text-slate-500 mt-1">
            {{ $submission->contact ? $submission->contact->full_name : 'Unknown' }}
            — Entity: {{ ucfirst($submission->entity_type) }}
            — Agent approved: {{ $submission->agent_verified_at?->format('d M Y') }}
        </p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('success') }}</div>
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
            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-400">Recipient Submission</h2>
            @include('compliance.fica.partials.submitted-data', ['submission' => $submission, 'personal' => $personal, 'entity' => $entity, 'service' => $service, 'pepData' => $pepData, 'principalData' => $principalData, 'repData' => $repData, 'declData' => $declData])
        </div>

        {{-- MIDDLE: Agent verification (read-only) --}}
        <div class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-400">Agent Verification</h2>
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-indigo-500">Agent Review</h3>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-slate-400 text-xs">Agent</dt><dd class="text-slate-900 font-medium">{{ $submission->agentVerifiedBy->name ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Date</dt><dd class="text-slate-900">{{ $submission->agent_verified_at?->format('d M Y H:i') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Risk Rating</dt><dd class="font-semibold {{ [1 => 'text-emerald-600', 2 => 'text-amber-600', 3 => 'text-red-600'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '—' }}</dd></div>
                    @if($submission->verification_method)
                    <div><dt class="text-slate-400 text-xs">Verification Method</dt><dd>@foreach($submission->verification_method as $m)<span class="inline-block px-1.5 py-0.5 bg-slate-100 text-slate-600 text-xs mr-1 mb-1">{{ str_replace('_', ' ', ucfirst($m)) }}</span>@endforeach</dd></div>
                    @endif
                </dl>
            </div>

            @if(!empty($agentData))
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-indigo-500">Agent Checklist</h3>
                <dl class="space-y-2 text-sm">
                    @foreach($agentData as $key => $val)
                        @if($key !== 'suspicious_details')
                        <div class="flex justify-between">
                            <dt class="text-slate-500 text-xs">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                            <dd class="text-xs font-semibold {{ $val === 'yes' ? 'text-emerald-600' : ($val === 'no' ? 'text-red-600' : 'text-slate-400') }}">{{ ucfirst($val ?: '—') }}</dd>
                        </div>
                        @endif
                    @endforeach
                </dl>
            </div>
            @endif

            @if($submission->agent_notes)
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-indigo-500">Agent Notes</h3>
                <p class="text-sm text-slate-700">{{ $submission->agent_notes }}</p>
            </div>
            @endif
        </div>

        {{-- RIGHT: CO verification form --}}
        <div class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-widest text-slate-400">Your Verification</h2>

            {{-- CO Checklist --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-emerald-500">Compliance Checklist</h3>
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
                        <label class="block text-xs font-semibold text-slate-700 mb-1">{{ $item['label'] }}</label>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-1"><input type="radio" x-model="coChecklist.{{ $item['key'] }}" value="yes"> <span class="text-xs">Yes</span></label>
                            <label class="flex items-center gap-1"><input type="radio" x-model="coChecklist.{{ $item['key'] }}" value="no"> <span class="text-xs">No</span></label>
                            @if($item['type'] === 'yna')
                            <label class="flex items-center gap-1"><input type="radio" x-model="coChecklist.{{ $item['key'] }}" value="na"> <span class="text-xs">N/A</span></label>
                            @endif
                        </div>
                        @if($item['key'] === 'suspicious')
                        <div x-show="coChecklist.suspicious === 'yes'" x-cloak class="mt-1">
                            <textarea x-model="coChecklist.suspicious_details" rows="2" class="w-full px-2 py-1 border border-slate-300 text-xs focus:outline-none focus:border-emerald-500" placeholder="Details..."></textarea>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- TFS Screening Panel --}}
            @include('compliance.fica.partials.tfs-panel', ['submission' => $submission])

            {{-- Approve Form --}}
            <form method="POST" action="{{ route('compliance.fica.compliance-approve', $submission) }}" @submit.prevent="submitApproval">
                @csrf
                {{-- Hidden CO checklist fields --}}
                <template x-for="key in Object.keys(coChecklist)">
                    <input type="hidden" :name="'co_checklist[' + key + ']'" :value="coChecklist[key]">
                </template>

                <div class="bg-white border border-slate-200 p-5 space-y-4">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-emerald-500">Final Approval</h3>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">TFS Screening Completed? *</label>
                        <div class="flex gap-4 text-sm mb-1">
                            <label class="flex items-center gap-1"><input type="radio" name="tfs_screening" value="yes" required> <span class="text-xs">Yes</span></label>
                            <label class="flex items-center gap-1"><input type="radio" name="tfs_screening" value="no"> <span class="text-xs">No</span></label>
                        </div>
                        <p class="text-xs text-slate-400">Use the TFS Screening panel above to perform the check.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Risk Rating (CO can override) *</label>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="1" required {{ $submission->risk_rating === 1 ? 'checked' : '' }}> <span class="text-emerald-600 font-medium">Low</span></label>
                            <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="2" {{ $submission->risk_rating === 2 ? 'checked' : '' }}> <span class="text-amber-600 font-medium">Medium</span></label>
                            <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="3" {{ $submission->risk_rating === 3 ? 'checked' : '' }}> <span class="text-red-600 font-medium">High</span></label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Compliance Officer</label>
                        <input type="text" value="{{ auth()->user()->name }}" class="w-full px-3 py-2 border border-slate-200 text-sm bg-slate-50" readonly>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Notes</label>
                        <textarea name="co_notes" rows="3" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-emerald-500" placeholder="Optional compliance notes..."></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Your Signature *</label>
                        <div style="position: relative;">
                            <canvas x-ref="coSignatureCanvas" width="400" height="120" style="width: 100%; border: 1px solid #cbd5e1; background: #fff; touch-action: none; cursor: crosshair;"></canvas>
                            <button type="button" @click="clearSignature()" style="position: absolute; top: 0.25rem; right: 0.25rem; font-size: 0.7rem; color: #64748b; background: #fff; border: 1px solid #e2e8f0; padding: 0.15rem 0.4rem; cursor: pointer;">Clear</button>
                        </div>
                        <input type="hidden" name="co_signature_data" x-model="signatureDataUrl">
                    </div>

                    <button type="submit" class="w-full px-4 py-2 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition" :disabled="submitting">
                        <span x-show="!submitting">Approve & Finalise</span>
                        <span x-show="submitting" x-cloak>Processing...</span>
                    </button>
                </div>
            </form>

            {{-- Return to Agent --}}
            <form method="POST" action="{{ route('compliance.fica.compliance-reject', $submission) }}">
                @csrf
                <input type="hidden" name="action" value="return_to_agent">
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-amber-500">Return to Agent</h3>
                    <textarea name="reviewer_notes" rows="2" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-amber-500 mb-3" placeholder="Reason for returning..." required></textarea>
                    <button type="submit" class="w-full px-4 py-2 bg-amber-500 text-white text-sm font-semibold hover:bg-amber-600 transition">Return to Agent</button>
                </div>
            </form>

            {{-- Reject --}}
            <form method="POST" action="{{ route('compliance.fica.compliance-reject', $submission) }}">
                @csrf
                <input type="hidden" name="action" value="reject">
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-red-500">Reject</h3>
                    <textarea name="reviewer_notes" rows="2" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-red-500 mb-3" placeholder="Reason for rejection..." required></textarea>
                    <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition" onclick="return confirm('Are you sure?')">Reject</button>
                </div>
            </form>
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
