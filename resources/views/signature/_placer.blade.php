{{--
    Reusable "place my signature" widget — the PIN-unlock modal + the Alpine
    `signaturePlacer()` component. Consumed by BOTH e-sign and the CMA certificate
    generator (follow-ons). The agent enters their signing PIN ONCE per document
    (context), then click-to-places signature + initial repeatedly (no PIN per page).

    USAGE (consumer):
      <div x-data="signaturePlacer({ context: 'esign:doc:123' })" x-init="init()">
          <button type="button" @click="ensureUnlocked()"
                  x-text="unlocked ? 'Place my signature' : 'Unlock my signature'"></button>
          ... on a placement target: @click="place($event, 'signature')" (or 'initial') ...
          @include('signature._placer')   {{-- PIN modal, inside this x-data scope --}}
      </div>

    After unlock, `signatureImg` / `initialImg` hold the decrypted PNG data-URIs;
    `place(event, type)` returns the image so the consumer can drop it at the click.
    A switch-user/impersonated session can never unlock or read the images (403).
--}}

{{-- PIN unlock modal (rendered inside the consumer's x-data scope) --}}
<div x-show="pinModalOpen" x-cloak
     class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
     @keydown.escape.window="pinModalOpen = false">
    <div class="absolute inset-0 bg-black/60" @click="pinModalOpen = false"></div>
    <div class="relative w-full max-w-sm rounded-lg shadow-2xl overflow-hidden"
         style="background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);" @click.stop>
        <div class="px-5 py-3" style="background:var(--brand-default,#0b2a4a); color:#fff;">
            <span class="text-sm font-bold">Unlock your signature</span>
        </div>
        <div class="p-5 space-y-3">
            <p class="text-sm" style="color:var(--text-primary,#0f172a);">
                Enter your <strong>signing PIN</strong> to place your saved signature on this document.
            </p>
            <template x-if="impersonating">
                <p class="text-xs rounded p-2" style="background:#fef2f2; color:#991b1b;">
                    You're viewing as another user — saved signatures can't be used in this mode.
                </p>
            </template>
            <template x-if="!configured && !impersonating">
                <p class="text-xs rounded p-2" style="background:#fffbeb; color:#92400e;">
                    You haven't set up a saved signature + PIN yet. Set them in My Portal first.
                </p>
            </template>
            <input type="password" x-model="pin" inputmode="numeric" autocomplete="off"
                   placeholder="Signing PIN" :disabled="impersonating || !configured"
                   @keydown.enter="submitPin()"
                   class="w-full px-3 py-2 text-sm rounded-md"
                   style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e2e8f0); color:var(--text-primary,#0f172a);">
            <p x-show="error" x-cloak class="text-xs" style="color:#dc2626;" x-text="error"></p>
            <div class="flex items-center justify-end gap-2 pt-1">
                <button type="button" @click="pinModalOpen = false"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md"
                        style="background:var(--surface-2,#f1f5f9); color:var(--text-secondary,#475569); border:1px solid var(--border,#e2e8f0);">Cancel</button>
                <button type="button" @click="submitPin()" :disabled="loading || impersonating || !configured || !pin"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md text-white"
                        :style="(loading || impersonating || !configured || !pin) ? 'background:#94a3b8; cursor:not-allowed;' : 'background:var(--brand-button,#0ea5e9); cursor:pointer;'"
                        x-text="loading ? 'Unlocking…' : 'Unlock'"></button>
            </div>
        </div>
    </div>
</div>

@once
<script>
    function signaturePlacer(config) {
        return {
            context: (config && config.context) || '',
            statusUrl: @json(route('signature.status')),
            unlockUrl: @json(route('signature.unlock')),
            assetUrl:  @json(dirname(route('signature.asset', ['type' => 'signature']))),
            csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

            configured: false,
            impersonating: false,
            unlocked: false,
            pinModalOpen: false,
            pin: '',
            error: '',
            loading: false,
            signatureImg: null,
            initialImg: null,

            async init() {
                try {
                    const res = await fetch(this.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    const d = await res.json();
                    this.configured = !!d.configured;
                    this.impersonating = !!d.impersonating;
                } catch (e) { /* leave defaults */ }
            },

            // Open the PIN modal if not yet unlocked; otherwise a no-op (already usable).
            ensureUnlocked() {
                if (this.unlocked) return true;
                this.error = '';
                this.pin = '';
                this.pinModalOpen = true;
                return false;
            },

            async submitPin() {
                if (this.loading || this.impersonating || !this.configured || !this.pin) return;
                this.loading = true; this.error = '';
                try {
                    const res = await fetch(this.unlockUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ pin: this.pin, context: this.context }),
                    });
                    const d = await res.json().catch(() => ({}));
                    if (res.ok && d.ok) {
                        await this.loadAssets();
                        this.unlocked = true;
                        this.pinModalOpen = false;
                        this.pin = '';
                    } else {
                        this.error = d.error || 'Could not unlock. Check your PIN.';
                    }
                } catch (e) {
                    this.error = 'Network error — please try again.';
                } finally {
                    this.loading = false;
                }
            },

            async loadAssets() {
                const q = '?context=' + encodeURIComponent(this.context);
                const [sig, ini] = await Promise.all([
                    fetch(this.assetUrl + '/signature' + q, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }),
                    fetch(this.assetUrl + '/initial'   + q, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }),
                ]);
                if (sig.ok) { const d = await sig.json(); this.signatureImg = d.image || null; }
                if (ini.ok) { const d = await ini.json(); this.initialImg   = d.image || null; }
            },

            // Consumer calls this on a placement click. Returns the image data-URI to
            // drop at the click point, or opens the unlock modal if still locked.
            place(event, type) {
                if (!this.unlocked) { this.ensureUnlocked(); return null; }
                return type === 'initial' ? this.initialImg : this.signatureImg;
            },
        };
    }
</script>
@endonce
