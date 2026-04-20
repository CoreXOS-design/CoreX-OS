@extends('layouts.corex-app')

@section('corex-content')
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">

<div class="-m-4 lg:-m-6" x-data="rmcpSign()" x-init="init()">
    <x-page-header title="Complete RMCP Acknowledgement" :back-route="route('rmcp.ack.step', $ack->sections_total_count)" back-label="Back" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl mx-auto space-y-5">
            {{-- Summary --}}
            <div class="bg-white border p-5" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                <h3 class="text-sm font-bold mb-3" style="color:#0f172a; font-family:'Plus Jakarta Sans',sans-serif;">Acknowledgement Summary</h3>
                <div class="grid grid-cols-2 gap-2 text-xs" style="color:#64748b;">
                    <div>Agency: <strong style="color:#0f172a;">{{ $agency->name }}</strong></div>
                    <div>RMCP Version: <strong style="color:#0f172a;">v{{ $version->version_number }}</strong></div>
                    <div>Sections acknowledged: <strong style="color:#00d4aa;">{{ $ack->sections_acknowledged_count }} of {{ $ack->sections_total_count }}</strong></div>
                    <div>Date: <strong style="color:#0f172a;">{{ now()->format('d M Y H:i') }}</strong></div>
                    <div>Your IP: <strong style="color:#0f172a;">{{ request()->ip() }}</strong></div>
                </div>
            </div>

            {{-- Declaration text --}}
            <div class="bg-white border p-5" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                <h3 class="text-sm font-bold mb-3" style="color:#0f172a; font-family:'Plus Jakarta Sans',sans-serif;">Declaration</h3>
                <div class="prose prose-sm max-w-none text-sm" style="color:#334155; line-height:1.7;">
                    {!! $declarationText !!}
                </div>
            </div>

            {{-- Signature --}}
            <div class="bg-white border p-5" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                <h3 class="text-sm font-bold mb-3" style="color:#0f172a; font-family:'Plus Jakarta Sans',sans-serif;">Your Signature</h3>

                {{-- Mode tabs --}}
                <div class="flex gap-2 mb-4">
                    <button type="button" @click="mode = 'typed'" class="px-4 py-2 text-xs font-semibold transition" :style="mode === 'typed' ? 'background:#00d4aa; color:#0f172a; border-radius:3px;' : 'background:var(--surface-alt, #f8fafc); color:#64748b; border-radius:3px;'">Type</button>
                    <button type="button" @click="mode = 'drawn'; $nextTick(() => initSignaturePad())" class="px-4 py-2 text-xs font-semibold transition" :style="mode === 'drawn' ? 'background:#00d4aa; color:#0f172a; border-radius:3px;' : 'background:var(--surface-alt, #f8fafc); color:#64748b; border-radius:3px;'">Draw</button>
                </div>

                {{-- Type mode --}}
                <div x-show="mode === 'typed'">
                    <input type="text" x-model="typedName" placeholder="Type your full name" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                    <div x-show="typedName.trim().length > 0" x-cloak class="mt-3 px-4 py-3 text-center" style="border:1px dashed var(--border, #e5e7eb); border-radius:3px;">
                        <span style="font-family:'Dancing Script',cursive; font-size:1.5rem; color:#0f172a;" x-text="typedName"></span>
                    </div>
                </div>

                {{-- Draw mode --}}
                <div x-show="mode === 'drawn'" x-cloak>
                    <div class="border-2 rounded overflow-hidden" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                        <canvas x-ref="sigCanvas" class="w-full block" style="height:180px; touch-action:none; cursor:crosshair; background:#fff;"></canvas>
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <button type="button" @click="clearSig()" class="text-xs font-semibold" style="color:#64748b;">Clear</button>
                        <span class="text-xs" style="color:#94a3b8;">Draw your signature above</span>
                    </div>
                </div>
            </div>

            {{-- Declaration checkbox + submit --}}
            <div>
                <label class="flex items-start gap-3 mb-4 cursor-pointer p-3 -m-3">
                    <input type="checkbox" x-model="declarationAcknowledged" style="accent-color:#00d4aa; width:24px; height:24px; margin-top:1px; flex-shrink:0;">
                    <span class="text-xs" style="color:var(--text-primary, #1f2937); line-height:1.5;">
                        I confirm that I have read and understood the RMCP in full, and acknowledge my obligations under FICA and this programme.
                    </span>
                </label>

                @if($errors->any())
                <div class="mb-3 px-3 py-2 text-xs" style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:3px; color:#ef4444;">
                    @foreach($errors->all() as $error) <div>{{ $error }}</div> @endforeach
                </div>
                @endif

                <div x-show="errorMessage" x-cloak x-transition class="mb-3 px-3 py-2 text-xs" style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:3px; color:#ef4444;" x-text="errorMessage"></div>

                <button type="button" @click="submitSignature()" :disabled="!canSubmit || isSubmitting"
                        class="w-full py-3 text-sm font-bold transition"
                        :style="canSubmit && !isSubmitting ? 'background:#00d4aa; color:#0f172a; border-radius:3px; cursor:pointer;' : 'background:#e5e7eb; color:#94a3b8; border-radius:3px; cursor:not-allowed;'">
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
        isSubmitting: false,
        errorMessage: '',

        init() {
            window.addEventListener('resize', () => {
                if (this.mode === 'drawn' && this.signaturePad) {
                    this.resizeCanvas();
                }
            });
        },

        initSignaturePad() {
            const canvas = this.$refs.sigCanvas;
            if (!canvas) return;

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
        },

        resizeCanvas() {
            const canvas = this.$refs.sigCanvas;
            if (!canvas || !this.signaturePad) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = 180 * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            this.signaturePad.clear();
        },

        clearSig() {
            if (this.signaturePad) this.signaturePad.clear();
        },

        get hasSignature() {
            if (this.mode === 'typed') return this.typedName.trim().length > 0;
            if (this.mode === 'drawn') return this.signaturePad && !this.signaturePad.isEmpty();
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
                    const text = await res.text();
                    this.errorMessage = 'Submission failed. Please try again.';
                    this.isSubmitting = false;
                    return;
                }

                // Fallback — follow any response
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
