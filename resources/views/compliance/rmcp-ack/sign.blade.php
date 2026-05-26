@extends('layouts.corex-app')

@section('corex-content')
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">

<div class="-m-4 lg:-m-6" x-data="rmcpSign()" x-init="init()">
    <x-page-header title="Complete RMCP Acknowledgement" :back-route="route('agent.portal') . '#compliance'" back-label="My Portal" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl mx-auto space-y-5">

            {{-- ID number warning --}}
            @if(!$user->id_number)
            <div class="px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid rgba(234,179,8,0.25); border-radius:6px; color:#a16207;">
                <strong>Your ID Number is not captured in your profile.</strong>
                For a more complete FICA acknowledgement record, consider adding it in
                <a href="{{ route('agent.portal') }}#profile" style="text-decoration:underline; color:#92400e;">My Portal - Profile</a> before signing.
                You can still proceed without it.
            </div>
            @endif

            {{-- Declaration â€” electronic signing version --}}
            <div class="bg-white border p-6" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                <h3 class="text-base font-semibold mb-4" style="color:var(--text-primary);">Declaration</h3>
                <div class="space-y-3 text-sm" style="color:#334155; line-height:1.7;">
                    <p>By signing below, I, <strong style="color:var(--text-primary);">{{ $user->name }}</strong>@if($user->id_number), ID Number <strong style="color:var(--text-primary);">{{ $user->id_number }}</strong>@endif, confirm that:</p>
                    <ul style="list-style:disc; padding-left:1.5rem;" class="space-y-2">
                        <li>I have read the contents of this Risk Management and Compliance Programme in full.</li>
                        <li>I understand each section and have acknowledged each where required.</li>
                        <li>Where I did not understand any of my duties under the RMCP, I have contacted the FICA Compliance Officer for clarification.</li>
                        <li>I undertake to observe strictly and diligently all my duties imposed by FICA and this RMCP.</li>
                        <li>I acknowledge that failure to do so:
                            <ul style="list-style:circle; padding-left:1.5rem; margin-top:0.5rem;" class="space-y-1">
                                <li>will potentially expose {{ $agency->name }} to unacceptable risk, as well as financial and reputational risk from the penalties that may be levied by the FIC for any instances of non-compliance with FICA and the RMCP; and</li>
                                <li>is a criminal offence in terms of FICA, and constitutes serious misconduct in terms of the Business' disciplinary code.</li>
                            </ul>
                        </li>
                    </ul>
                </div>
                <div class="mt-4 pt-3 text-xs" style="border-top:1px solid var(--border, #e5e7eb); color:#94a3b8;">
                    RMCP v{{ $version->version_number }} | {{ $agency->name }} | {{ $ack->sections_acknowledged_count }} of {{ $ack->sections_total_count }} sections acknowledged
                </div>
            </div>

            {{-- Signature --}}
            <div class="bg-white border p-5" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                <h3 class="text-sm font-bold mb-3" style="color:var(--text-primary);">Your Signature</h3>

                {{-- Mode tabs --}}
                <div class="flex gap-2 mb-4">
                    <button type="button" @click="switchMode('typed')" class="px-4 py-2 text-xs font-semibold transition" :style="mode === 'typed' ? 'background:var(--brand-icon); color:var(--text-primary); border-radius:6px;' : 'background:var(--surface-alt, #f8fafc); color:#64748b; border-radius:6px;'">Type</button>
                    <button type="button" @click="switchMode('drawn')" class="px-4 py-2 text-xs font-semibold transition" :style="mode === 'drawn' ? 'background:var(--brand-icon); color:var(--text-primary); border-radius:6px;' : 'background:var(--surface-alt, #f8fafc); color:#64748b; border-radius:6px;'">Draw</button>
                </div>

                {{-- Type mode --}}
                <div x-show="mode === 'typed'">
                    <input type="text" x-model="typedName" placeholder="Type your full name" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    <div x-show="typedName.trim().length > 0" x-cloak class="mt-3 px-4 py-3 text-center" style="border:1px dashed var(--border, #e5e7eb); border-radius:6px;">
                        <span style="font-family:'Dancing Script',cursive; font-size:1.5rem; color:var(--text-primary);" x-text="typedName"></span>
                    </div>
                </div>

                {{-- Draw mode --}}
                <div x-show="mode === 'drawn'" x-cloak>
                    <div class="border-2 rounded overflow-hidden" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        <canvas x-ref="sigCanvas" class="w-full block" style="height:180px; touch-action:none; cursor:crosshair; background:#fff;"></canvas>
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <button type="button" @click="clearSig()" class="text-xs font-semibold" style="color:#64748b;">Clear</button>
                        <span class="text-xs" style="color:#94a3b8;">Draw your signature above</span>
                    </div>
                </div>
            </div>

            {{-- Declaration checkbox --}}
            <div>
                <label class="flex items-start gap-3 cursor-pointer p-3 -m-3">
                    <input type="checkbox" x-model="declarationAcknowledged" style="accent-color:var(--brand-icon); width:24px; height:24px; margin-top:1px; flex-shrink:0;">
                    <span class="text-xs" style="color:var(--text-primary, #1f2937); line-height:1.5;">
                        I confirm that I have read and understood the RMCP in full, and acknowledge my obligations under FICA and this programme.
                    </span>
                </label>
            </div>

            {{-- Errors --}}
            @if($errors->any())
            <div class="px-3 py-2 text-xs" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid rgba(239,68,68,0.3); border-radius:6px; color:var(--ds-crimson);">
                @foreach($errors->all() as $error) <div>{{ $error }}</div> @endforeach
            </div>
            @endif
            <div x-show="errorMessage" x-cloak x-transition class="px-3 py-2 text-xs" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid rgba(239,68,68,0.3); border-radius:6px; color:var(--ds-crimson);" x-text="errorMessage"></div>

            {{-- Submit button row --}}
            <div class="flex items-center justify-between gap-4 pb-24">
                <a href="{{ route('agent.portal') }}#compliance" class="text-sm" style="color:#64748b;">
                    Back to My Portal
                </a>
                <button type="button"
                        data-sign-submit
                        @click="submitSignature()"
                        :disabled="!canSubmit || isSubmitting"
                        class="px-6 py-3 text-sm font-semibold transition shadow-sm"
                        :class="canSubmit && !isSubmitting
                            ? 'hover:opacity-90'
                            : 'cursor-not-allowed'"
                        :style="canSubmit && !isSubmitting
                            ? 'background:var(--brand-icon); color:var(--text-primary); border-radius:6px;'
                            : 'background:#e2e8f0; color:#94a3b8; border-radius:6px;'">
                    <span x-show="!isSubmitting">Sign and Complete</span>
                    <span x-show="isSubmitting" x-cloak>Submitting...</span>
                </button>
            </div>

        </div>
    </div>
</div>

<script>
function rmcpSign() {
    return {
        mode: 'typed',
        typedName: '',
        declarationAcknowledged: false,
        signaturePad: null,
        hasDrawnSignature: false,
        isSubmitting: false,
        errorMessage: '',

        init() {
            window.addEventListener('resize', () => {
                if (this.mode === 'drawn' && this.signaturePad) {
                    this.resizeCanvas();
                }
            });

            this.$watch('canSubmit', (val) => {
                if (val) {
                    this.$nextTick(() => {
                        const btn = this.$el.querySelector('[data-sign-submit]');
                        if (btn) btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                }
            });
        },

        switchMode(newMode) {
            this.mode = newMode;
            if (newMode === 'drawn') {
                this.$nextTick(() => this.initSignaturePad());
            }
        },

        initSignaturePad() {
            const canvas = this.$refs.sigCanvas;
            if (!canvas) return;
            if (this.signaturePad) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = 180 * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            this.signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(255,255,255)',
                penColor: 'rgb(15,23,42)',
                minWidth: 1.5,
                maxWidth: 3,
            });

            // Track drawing state reactively for Alpine
            const self = this;
            this.signaturePad.addEventListener('endStroke', () => {
                self.hasDrawnSignature = !self.signaturePad.isEmpty();
            });
        },

        resizeCanvas() {
            const canvas = this.$refs.sigCanvas;
            if (!canvas || !this.signaturePad) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = 180 * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            this.signaturePad.clear();
            this.hasDrawnSignature = false;
        },

        clearSig() {
            if (this.signaturePad) {
                this.signaturePad.clear();
                this.hasDrawnSignature = false;
            }
        },

        get hasSignature() {
            if (this.mode === 'typed') return this.typedName.trim().length > 0;
            if (this.mode === 'drawn') return this.hasDrawnSignature;
            return false;
        },

        get canSubmit() {
            return this.hasSignature && this.declarationAcknowledged;
        },

        async submitSignature() {
            if (!this.canSubmit || this.isSubmitting) return;
            this.isSubmitting = true;
            this.errorMessage = '';

            const formData = new FormData();
            formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            formData.append('declaration_acknowledged', '1');

            if (this.mode === 'typed') {
                formData.append('signature_type', 'typed');
                formData.append('typed_name', this.typedName.trim());
            } else {
                formData.append('signature_type', 'drawn');
                formData.append('signature_data', this.signaturePad.toDataURL('image/png'));
            }

            try {
                const res = await fetch('{{ route("rmcp.ack.submit") }}', {
                    method: 'POST',
                    headers: { 'Accept': 'text/html' },
                    body: formData,
                });

                if (res.redirected) {
                    window.location.href = res.url;
                    return;
                }

                if (!res.ok) {
                    this.errorMessage = 'Submission failed. Please try again.';
                    this.isSubmitting = false;
                    return;
                }

                window.location.href = res.url || '{{ route("rmcp.ack.receipt", $ack) }}';
            } catch (e) {
                this.errorMessage = 'Network error. Please check your connection and try again.';
                this.isSubmitting = false;
            }
        }
    };
}
</script>
@endsection
