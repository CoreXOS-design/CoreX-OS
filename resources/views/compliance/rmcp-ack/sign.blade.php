@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="rmcpSign()">
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

                {{-- Tabs --}}
                <div class="flex gap-2 mb-4">
                    <button @click="mode = 'type'" class="px-4 py-2 text-xs font-semibold transition" :style="mode === 'type' ? 'background:#00d4aa; color:#0f172a; border-radius:3px;' : 'background:var(--surface-alt, #f8fafc); color:#64748b; border-radius:3px;'">Type</button>
                    <button @click="mode = 'draw'; $nextTick(() => initCanvas())" class="px-4 py-2 text-xs font-semibold transition" :style="mode === 'draw' ? 'background:#00d4aa; color:#0f172a; border-radius:3px;' : 'background:var(--surface-alt, #f8fafc); color:#64748b; border-radius:3px;'">Draw</button>
                </div>

                {{-- Type mode --}}
                <div x-show="mode === 'type'">
                    <input type="text" x-model="typedName" placeholder="Type your full name" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                    <div x-show="typedName" class="mt-3 px-4 py-3 text-center" style="border:1px dashed var(--border, #e5e7eb); border-radius:3px;">
                        <span style="font-family:'Dancing Script',cursive; font-size:1.5rem; color:#0f172a;" x-text="typedName"></span>
                    </div>
                </div>

                {{-- Draw mode --}}
                <div x-show="mode === 'draw'" x-cloak>
                    <div class="border-2 rounded overflow-hidden" style="border-color:var(--border, #e5e7eb); touch-action:none;">
                        <canvas x-ref="signatureCanvas" class="w-full block" style="height:160px; cursor:crosshair; background:#fff;"></canvas>
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <button @click="clearCanvas()" class="text-xs font-semibold" style="color:#64748b;">Clear</button>
                        <span class="text-xs" style="color:#94a3b8;">Draw your signature above</span>
                    </div>
                </div>
            </div>

            {{-- Declaration checkbox + submit --}}
            <form method="POST" action="{{ route('rmcp.ack.submit') }}" @submit="prepareSubmit($event)">
                @csrf
                <input type="hidden" name="signature_type" :value="mode">
                <input type="hidden" name="signature_data" x-ref="signatureDataInput">
                <input type="hidden" name="typed_name" :value="typedName">

                <label class="flex items-start gap-3 mb-4 cursor-pointer">
                    <input type="checkbox" name="declaration_acknowledged" value="1" x-model="declarationChecked" style="accent-color:#00d4aa; width:18px; height:18px; margin-top:2px;">
                    <span class="text-xs" style="color:var(--text-primary, #1f2937); line-height:1.5;">
                        I confirm that I have read and understood the RMCP in full, and acknowledge my obligations under FICA and this programme.
                    </span>
                </label>

                @if($errors->any())
                <div class="mb-3 px-3 py-2 text-xs" style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:3px; color:#ef4444;">
                    @foreach($errors->all() as $error) <div>{{ $error }}</div> @endforeach
                </div>
                @endif

                <button type="submit" :disabled="!canSubmit"
                        class="w-full py-3 text-sm font-bold transition"
                        :style="canSubmit ? 'background:#00d4aa; color:#0f172a; border-radius:3px; cursor:pointer;' : 'background:#e5e7eb; color:#94a3b8; border-radius:3px; cursor:not-allowed;'">
                    Sign and Complete
                </button>
            </form>
        </div>
    </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">

<script>
function rmcpSign() {
    return {
        mode: 'type',
        typedName: '',
        declarationChecked: false,
        signaturePad: null,

        get hasSignature() {
            if (this.mode === 'type') return this.typedName.trim().length > 0;
            return this.signaturePad && !this.signaturePad.isEmpty();
        },

        get canSubmit() {
            return this.declarationChecked && this.hasSignature;
        },

        initCanvas() {
            const canvas = this.$refs.signatureCanvas;
            if (!canvas) return;
            canvas.width = canvas.offsetWidth;
            canvas.height = 160;
            if (typeof SignaturePad !== 'undefined') {
                this.signaturePad = new SignaturePad(canvas, { penColor: '#0f172a', minWidth: 1.5, maxWidth: 3 });
            } else {
                // Fallback simple drawing
                const ctx = canvas.getContext('2d');
                let drawing = false;
                canvas.addEventListener('mousedown', () => { drawing = true; ctx.beginPath(); });
                canvas.addEventListener('mousemove', (e) => {
                    if (!drawing) return;
                    const rect = canvas.getBoundingClientRect();
                    ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
                    ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 2; ctx.stroke();
                });
                canvas.addEventListener('mouseup', () => { drawing = false; });
                this._canvas = canvas;
                this._hasDrawn = false;
                canvas.addEventListener('mousemove', () => { if (drawing) this._hasDrawn = true; });
            }
        },

        clearCanvas() {
            if (this.signaturePad) { this.signaturePad.clear(); return; }
            const canvas = this.$refs.signatureCanvas;
            if (canvas) {
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                this._hasDrawn = false;
            }
        },

        prepareSubmit(e) {
            if (this.mode === 'draw') {
                const canvas = this.$refs.signatureCanvas;
                if (canvas) {
                    this.$refs.signatureDataInput.value = canvas.toDataURL('image/png');
                }
            }
        }
    };
}
</script>
@endsection
