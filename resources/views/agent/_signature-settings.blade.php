{{--
    My Portal — Saved signature / initial / signing PIN. The agent draws their
    signature + initial and sets a signing PIN. Images are encrypted at rest and
    the PIN is hashed server-side (AgentSignatureService). Disabled under
    impersonation (a switch-user session cannot set or use another agent's signature).
--}}
<div id="signature" class="rounded-md p-5" style="background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);"
     x-data="signatureSettings()" x-init="init()">
    <div class="flex items-start justify-between gap-3 flex-wrap mb-1">
        <h3 class="text-sm font-bold" style="color:var(--text-primary,#0f172a);">Signature &amp; signing PIN</h3>
        <div class="text-xs" style="color:var(--text-muted,#64748b);">
            <span x-show="status.signature">✓ Signature set</span>
            <span x-show="status.signature && status.pin"> · </span>
            <span x-show="status.pin">✓ PIN set</span>
            <span x-show="!status.signature && !status.pin">Not set up yet</span>
        </div>
    </div>
    <p class="text-xs mb-4" style="color:var(--text-muted,#64748b);">
        Draw your signature and initial, and set a signing PIN. Your signature is stored encrypted; the PIN is
        separate from your login password. You'll enter the PIN once per document to place your signature.
    </p>

    <template x-if="impersonating">
        <div class="rounded-md px-3 py-2 mb-4 text-xs" style="background:#fef2f2; color:#991b1b;">
            You're viewing as another user — signature settings are disabled in this mode.
        </div>
    </template>

    @if(session('success'))
        <div class="rounded-md px-3 py-2 mb-4 text-xs" style="background:#f0fdf4; color:#166534;">{{ session('success') }}</div>
    @endif
    @error('signature')<div class="rounded-md px-3 py-2 mb-4 text-xs" style="background:#fef2f2; color:#991b1b;">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('agent.portal.signature.save') }}" @submit="beforeSubmit($event)">
        @csrf @method('PATCH')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach(['signature' => 'Signature', 'initial' => 'Initial'] as $key => $label)
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary,#475569);">{{ $label }}</label>
                        <button type="button" @click="clearPad('{{ $key }}')" :disabled="impersonating"
                                class="text-[11px] font-semibold" style="color:var(--ds-crimson,#dc2626);">Clear</button>
                    </div>
                    <canvas x-ref="{{ $key }}Pad" width="500" height="160"
                            class="w-full rounded-md touch-none"
                            style="background:#fff; border:1px dashed var(--border-hover,#cbd5e1); aspect-ratio:500/160;"></canvas>
                    <input type="hidden" name="{{ $key }}_image" x-ref="{{ $key }}Input">
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary,#475569);">Signing PIN (4–20 digits)</label>
                <input type="password" name="pin" x-model="pin" inputmode="numeric" maxlength="20" autocomplete="new-password"
                       :disabled="impersonating" placeholder="Leave blank to keep current"
                       class="w-full px-3 py-2 text-sm rounded-md" style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e2e8f0); color:var(--text-primary,#0f172a);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary,#475569);">Confirm PIN</label>
                <input type="password" name="pin_confirm" x-model="pinConfirm" inputmode="numeric" maxlength="20" autocomplete="new-password"
                       :disabled="impersonating"
                       class="w-full px-3 py-2 text-sm rounded-md" style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e2e8f0); color:var(--text-primary,#0f172a);">
            </div>
        </div>
        <p x-show="pin && pin !== pinConfirm" x-cloak class="text-xs mt-1" style="color:#dc2626;">PINs don't match.</p>

        <div class="mt-4">
            <button type="submit" :disabled="impersonating || (pin && pin !== pinConfirm)"
                    class="text-xs font-semibold px-5 py-2 rounded-md text-white"
                    :style="(impersonating || (pin && pin !== pinConfirm)) ? 'background:#94a3b8; cursor:not-allowed;' : 'background:var(--brand-button,#0ea5e9); cursor:pointer;'">
                Save signing details
            </button>
        </div>
    </form>
</div>

@once
<script>
    function signatureSettings() {
        return {
            impersonating: false,
            status: { signature: false, pin: false },
            pin: '', pinConfirm: '',
            _pads: {},

            async init() {
                try {
                    const res = await fetch(@json(route('signature.status')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    const d = await res.json();
                    this.impersonating = !!d.impersonating;
                    this.status.signature = !!d.configured;   // configured = images + pin both set
                    this.status.pin = !!d.configured;
                } catch (e) {}
                this.$nextTick(() => { this.setupPad('signature'); this.setupPad('initial'); });
            },

            setupPad(key) {
                const canvas = this.$refs[key + 'Pad'];
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a';
                let drawing = false, dirty = false;
                const pos = (e) => {
                    const r = canvas.getBoundingClientRect();
                    const p = (e.touches && e.touches[0]) || e;
                    return { x: (p.clientX - r.left) * (canvas.width / r.width), y: (p.clientY - r.top) * (canvas.height / r.height) };
                };
                const start = (e) => { if (this.impersonating) return; drawing = true; dirty = true; const { x, y } = pos(e); ctx.beginPath(); ctx.moveTo(x, y); e.preventDefault(); };
                const move  = (e) => { if (!drawing) return; const { x, y } = pos(e); ctx.lineTo(x, y); ctx.stroke(); e.preventDefault(); };
                const end   = () => { drawing = false; };
                canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
                canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); window.addEventListener('touchend', end);
                this._pads[key] = { canvas, ctx, isDirty: () => dirty, clear: () => { ctx.clearRect(0, 0, canvas.width, canvas.height); dirty = false; } };
            },

            clearPad(key) { if (this._pads[key]) this._pads[key].clear(); },

            beforeSubmit(e) {
                // Only send a canvas that was actually drawn on (blank = keep current).
                for (const key of ['signature', 'initial']) {
                    const pad = this._pads[key];
                    const input = this.$refs[key + 'Input'];
                    if (pad && input) input.value = pad.isDirty() ? pad.canvas.toDataURL('image/png') : '';
                }
            },
        };
    }
</script>
@endonce
