{{--
    Logo → palette tool (shared by Admin → Agencies → Branding and
    Admin → Company Settings → Branding).

    Lets an admin generate the four semantic brand colours from the agency
    logo: either auto-detected (dominant-colour extraction) or hand-picked with
    an eyedropper directly on the logo.

    Props:
      $logoUrl     — asset() URL of the currently saved logo, or '' if none.
      $logoInputId — id of the company/agency <input type="file" name="logo">
                     on the Company tab, so a freshly chosen (unsaved) logo is
                     used for detection without a round-trip.

    Writes into the standard branding inputs that live on the same page:
      {role}_picker / {role}_text  for role in
      sidebar_color, icon_color, default_color, button_color.
    Applying a colour dispatches an `input` event on the picker so the existing
    syncPair()/updatePreviews() handlers refresh the live preview for free.
--}}
<div class="rounded-md p-4 space-y-3"
     style="background:var(--surface-2); border:1px dashed var(--border);"
     x-data="logoBrandKit({ savedLogo: @js($logoUrl), inputId: @js($logoInputId) })"
     x-init="init()">

    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-primary);">Brand from logo</div>
            <div class="text-xs" style="color:var(--text-muted);">Auto-detect the four roles from your logo, or click the logo to sample a colour.</div>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="autoDetect()" x-bind:disabled="!logoSrc || busy"
                    class="corex-btn-primary text-xs disabled:opacity-50">
                <span x-text="busy ? 'Detecting…' : 'Auto-detect colours'"></span>
            </button>
            <button type="button" @click="toggleEyedropper()" x-bind:disabled="!logoSrc"
                    class="corex-btn-outline text-xs disabled:opacity-50"
                    x-bind:style="eyedropper ? 'border-color:var(--brand-button, #0ea5e9); color:var(--brand-button, #0ea5e9);' : ''">
                <span x-text="eyedropper ? 'Picking… (click logo)' : 'Pick from logo'"></span>
            </button>
        </div>
    </div>

    {{-- Empty state — no logo available to read --}}
    <div x-show="!logoSrc" x-cloak class="text-xs rounded-md px-3 py-2"
         style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
        Upload a logo on the <span class="font-semibold">Company</span> tab to detect colours from it.
        @if(!empty($logoUrl))@else You can still set the four roles manually below.@endif
    </div>

    {{-- Logo canvas + eyedropper --}}
    <div x-show="logoSrc" x-cloak class="flex flex-col sm:flex-row gap-4 sm:items-start">
        <div class="flex-shrink-0">
            <img x-ref="logoImg" :src="logoSrc" alt="Logo preview"
                 @load="onLogoLoad()"
                 @click="pickFromLogo($event)" @mousemove="hoverLogo($event)" @mouseleave="hoverHex = ''"
                 class="rounded-md p-2 w-auto select-none transition-all duration-200"
                 :style="(eyedropper
                        ? 'cursor:crosshair; max-height:240px; max-width:480px;'
                        : 'max-height:112px; max-width:240px;')
                        + ' background:#ffffff; border:1px solid var(--border);'">
            <canvas x-ref="canvas" class="hidden"></canvas>
        </div>

        {{-- Eyedropper controls — choose which role the next click fills --}}
        <div x-show="eyedropper" x-cloak class="space-y-2 flex-1 min-w-0">
            <div class="text-xs" style="color:var(--text-secondary);">Click a colour in the logo to assign it to:</div>
            <div class="flex flex-wrap gap-1.5">
                <template x-for="role in roles" :key="role.key">
                    <button type="button" @click="armedRole = role.key"
                            class="text-xs px-2 py-1 rounded-md transition-colors"
                            :style="armedRole === role.key
                                ? 'background:var(--brand-button, #0ea5e9); color:#fff; border:1px solid transparent;'
                                : 'background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);'"
                            x-text="role.label"></button>
                </template>
            </div>
            <div class="flex items-center gap-2 pt-1" x-show="hoverHex || lastPicked">
                <span class="inline-block w-5 h-5 rounded-full border" style="border-color:var(--border);"
                      :style="'background:' + (hoverHex || lastPicked)"></span>
                <span class="text-xs font-mono" style="color:var(--text-secondary);" x-text="hoverHex || lastPicked"></span>
            </div>
        </div>
    </div>

    {{-- Status / error line --}}
    <div x-show="message" x-cloak class="text-xs" :style="error ? 'color:var(--ds-crimson);' : 'color:var(--ds-green);'" x-text="message"></div>
</div>

@once
@push('scripts')
<script>
function logoBrandKit(cfg) {
    return {
        logoSrc: '',
        eyedropper: false,
        busy: false,
        message: '',
        error: false,
        armedRole: 'icon_color',
        lastPicked: '',
        hoverHex: '',
        _ready: false,
        roles: [
            { key: 'sidebar_color', label: 'Sidebar' },
            { key: 'icon_color',    label: 'Icons'   },
            { key: 'default_color', label: 'Default' },
            { key: 'button_color',  label: 'Buttons' },
        ],

        init() {
            if (cfg.savedLogo) { this.logoSrc = cfg.savedLogo; }
            // Pick up a freshly chosen (unsaved) logo from the Company tab input.
            const input = cfg.inputId ? document.getElementById(cfg.inputId) : null;
            if (input) {
                input.addEventListener('change', () => {
                    const file = input.files && input.files[0];
                    if (!file) { return; }
                    const reader = new FileReader();
                    reader.onload = e => {
                        this._ready = false;
                        this.message = '';
                        this.lastPicked = '';
                        this.logoSrc = e.target.result;
                    };
                    reader.readAsDataURL(file);
                });
            }
        },

        // Draw the current logo into the offscreen canvas at (capped) natural size.
        onLogoLoad() {
            const img = this.$refs.logoImg;
            const canvas = this.$refs.canvas;
            if (!img || !canvas || !img.naturalWidth) { return; }
            const max = 600;
            const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
            canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
            try {
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                this._ready = true;
            } catch (e) {
                this._ready = false;
            }
        },

        _pixels() {
            const canvas = this.$refs.canvas;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            try {
                return ctx.getImageData(0, 0, canvas.width, canvas.height).data;
            } catch (e) {
                return null; // tainted (cross-origin) canvas
            }
        },

        // ── Colour helpers ───────────────────────────────────────────────
        _hex(r, g, b) {
            const h = n => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
            return '#' + h(r) + h(g) + h(b);
        },
        _hsl(r, g, b) {
            r /= 255; g /= 255; b /= 255;
            const max = Math.max(r, g, b), min = Math.min(r, g, b);
            let h = 0, s = 0; const l = (max + min) / 2;
            const d = max - min;
            if (d !== 0) {
                s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                if (max === r) { h = ((g - b) / d + (g < b ? 6 : 0)); }
                else if (max === g) { h = (b - r) / d + 2; }
                else { h = (r - g) / d + 4; }
                h *= 60;
            }
            return { h, s, l };
        },

        // ── Auto-detect ──────────────────────────────────────────────────
        autoDetect() {
            if (!this.logoSrc) { return; }
            this.busy = true;
            this.message = '';
            // Allow the spinner to paint before the (sync) crunch.
            requestAnimationFrame(() => {
                try {
                    if (!this._ready) { this.onLogoLoad(); }
                    const data = this._pixels();
                    if (!data) {
                        this.error = true;
                        this.message = 'Could not read the logo image. Try re-uploading it.';
                        this.busy = false;
                        return;
                    }
                    const palette = this._buildPalette(data);
                    if (!palette) {
                        this.error = true;
                        this.message = 'No usable colours found in the logo.';
                        this.busy = false;
                        return;
                    }
                    // Ranked by frequency: most-used -> Sidebar, then Icons,
                    // Default, Buttons (cfg order in this.roles).
                    this.roles.forEach((role, i) => this.applyRole(role.key, palette[i]));
                    this.error = false;
                    this.message = 'Colours detected from logo (most-used first). Tweak any role below before saving.';
                } catch (e) {
                    this.error = true;
                    this.message = 'Colour detection failed. Set the roles manually below.';
                }
                this.busy = false;
            });
        },

        // Returns 4 hex colours ranked by how often they appear in the logo:
        // [most-used, 2nd, 3rd, 4th]. Near-duplicate shades are merged so each
        // rank is a genuinely distinct colour. Transparent + near-white
        // background pixels are ignored.
        _buildPalette(data) {
            // Quantise opaque pixels into coarse buckets and tally popularity.
            const buckets = {};
            const step = 16;
            for (let i = 0; i < data.length; i += 4) {
                if (data[i + 3] < 125) { continue; }            // skip transparent
                const r = data[i], g = data[i + 1], b = data[i + 2];
                const key = Math.round(r / step) + ',' + Math.round(g / step) + ',' + Math.round(b / step);
                let bk = buckets[key];
                if (!bk) { bk = buckets[key] = { r: 0, g: 0, b: 0, n: 0 }; }
                bk.r += r; bk.g += g; bk.b += b; bk.n++;
            }
            let entries = Object.values(buckets).map(bk => {
                const r = bk.r / bk.n, g = bk.g / bk.n, b = bk.b / bk.n;
                const hsl = this._hsl(r, g, b);
                return { r, g, b, n: bk.n, hex: this._hex(r, g, b), l: hsl.l };
            });
            // Drop near-white background; it's almost never a brand colour and a
            // white sidebar/accent is unusable. (Kept if the logo is ONLY white.)
            const nonWhite = entries.filter(e => e.l < 0.92);
            if (nonWhite.length) { entries = nonWhite; }
            if (!entries.length) { return null; }

            entries.sort((a, b) => b.n - a.n);

            // Merge near-duplicate shades into the most-frequent representative,
            // so rank 2/3/4 are distinct colours rather than shades of rank 1.
            const distinct = [];
            const MERGE = 46; // RGB euclidean distance threshold
            for (const e of entries) {
                const near = distinct.find(d =>
                    Math.sqrt((d.r - e.r) ** 2 + (d.g - e.g) ** 2 + (d.b - e.b) ** 2) < MERGE);
                if (near) { near.n += e.n; } else { distinct.push(e); }
            }
            distinct.sort((a, b) => b.n - a.n);

            // Fill four roles by rank; if the logo has fewer distinct colours,
            // cycle through what's available so every role still gets a colour.
            const out = [];
            for (let i = 0; i < 4; i++) {
                out.push(distinct[i] ? distinct[i].hex : distinct[i % distinct.length].hex);
            }
            return out;
        },

        // ── Eyedropper ───────────────────────────────────────────────────
        toggleEyedropper() {
            this.eyedropper = !this.eyedropper;
            this.message = '';
        },
        _sampleAt(evt) {
            const img = this.$refs.logoImg;
            const canvas = this.$refs.canvas;
            if (!img || !canvas || !this._ready) { return null; }
            const rect = img.getBoundingClientRect();
            if (!rect.width || !rect.height) { return null; }
            const px = Math.floor(((evt.clientX - rect.left) / rect.width) * canvas.width);
            const py = Math.floor(((evt.clientY - rect.top) / rect.height) * canvas.height);
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            try {
                const d = ctx.getImageData(
                    Math.max(0, Math.min(canvas.width - 1, px)),
                    Math.max(0, Math.min(canvas.height - 1, py)),
                    1, 1
                ).data;
                if (d[3] < 20) { return null; } // transparent pixel
                return this._hex(d[0], d[1], d[2]);
            } catch (e) {
                return null;
            }
        },
        hoverLogo(evt) {
            if (!this.eyedropper) { return; }
            const hex = this._sampleAt(evt);
            this.hoverHex = hex || '';
        },
        pickFromLogo(evt) {
            if (!this.eyedropper) { return; }
            const hex = this._sampleAt(evt);
            if (!hex) { return; }
            this.lastPicked = hex;
            this.applyRole(this.armedRole, hex);
            // Advance to the next role so four clicks fill the whole palette.
            const idx = this.roles.findIndex(r => r.key === this.armedRole);
            this.armedRole = this.roles[(idx + 1) % this.roles.length].key;
            this.error = false;
            const label = this.roles[idx]?.label || 'role';
            this.message = label + ' set to ' + hex + '.';
        },

        // ── Apply to the standard branding inputs (drives the live preview) ──
        applyRole(role, hex) {
            if (!/^#[0-9a-fA-F]{6}$/.test(hex)) { return; }
            const picker = document.getElementById(role + '_picker');
            const text   = document.getElementById(role + '_text');
            if (picker) {
                picker.value = hex;
                picker.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (text) {
                text.value = hex;
                text.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },
    };
}
</script>
@endpush
@endonce
