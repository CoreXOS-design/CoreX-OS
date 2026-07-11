{{-- Logo & agency colours — inline wizard step.
     Posts through the wizard form to CompanySettingsController@update (canonical
     branding save; only present keys reach $agency->update()).
     Colours auto-detected from the uploaded logo client-side (canvas, no libs). --}}
@push('head')
<script>
window.brandingStep = function (init) {
    return {
        sidebar: init.sidebar, icon: init.icon, base: init.base, button: init.button,
        logoPreview: init.logoUrl || '',
        detected: [], suggest: null, detecting: false, removeLogo: false,

        init() { if (this.logoPreview) { this.detect(this.logoPreview); } },

        hex(r, g, b) { return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join(''); },

        onLogo(e) {
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            this.removeLogo = false;
            this.logoPreview = URL.createObjectURL(f);
            this.detect(this.logoPreview);
        },

        /* Sample the logo, bucket its pixels, and surface the dominant colours. */
        detect(src) {
            this.detecting = true;
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    const size = 120;
                    const c = document.createElement('canvas');
                    c.width = size; c.height = size;
                    const ctx = c.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(img, 0, 0, size, size);
                    const d = ctx.getImageData(0, 0, size, size).data;

                    const buckets = {};
                    for (let i = 0; i < d.length; i += 4) {
                        if (d[i + 3] < 128) continue;                 // transparent
                        const r = d[i], g = d[i + 1], b = d[i + 2];
                        const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
                        if (mx > 242 && mn > 242) continue;           // near-white
                        if (mx < 18) continue;                        // near-black
                        const key = ((r >> 4) << 8) | ((g >> 4) << 4) | (b >> 4);
                        if (!buckets[key]) buckets[key] = { r: 0, g: 0, b: 0, n: 0 };
                        const q = buckets[key];
                        q.r += r; q.g += g; q.b += b; q.n++;
                    }

                    const list = Object.values(buckets).map(q => {
                        const r = Math.round(q.r / q.n), g = Math.round(q.g / q.n), b = Math.round(q.b / q.n);
                        const mx = Math.max(r, g, b) / 255, mn = Math.min(r, g, b) / 255;
                        const l = (mx + mn) / 2;
                        const s = mx === mn ? 0 : (l > 0.5 ? (mx - mn) / (2 - mx - mn) : (mx - mn) / (mx + mn));
                        return { hex: this.hex(r, g, b), n: q.n, s: s, l: l };
                    }).sort((a, b) => b.n - a.n).slice(0, 12);

                    this.detected = list.slice(0, 6).map(x => x.hex);
                    if (list.length) {
                        const vivid = list.slice().sort((a, b) => (b.s * Math.log(b.n + 1)) - (a.s * Math.log(a.n + 1)))[0];
                        const dark = list.slice().sort((a, b) => a.l - b.l)[0];
                        this.suggest = { accent: vivid.hex, dark: dark.hex };
                    }
                } catch (err) {
                    this.detected = []; this.suggest = null;   // tainted canvas / decode issue
                }
                this.detecting = false;
            };
            img.onerror = () => { this.detecting = false; };
            img.src = src;
        },

        applySuggested() {
            if (!this.suggest) return;
            this.icon = this.suggest.accent;
            this.button = this.suggest.accent;
            this.sidebar = this.suggest.accent;
            this.base = this.suggest.dark;
        },

        useAccent(hex) { this.icon = hex; this.button = hex; this.sidebar = hex; },

        /* Mirrors hexToRgba() in company-settings so the previews match exactly. */
        rgba(hex, a) {
            const h = String(hex || '').replace('#', '');
            if (!/^[0-9a-fA-F]{6}$/.test(h)) return hex;
            const r = parseInt(h.slice(0, 2), 16),
                  g = parseInt(h.slice(2, 4), 16),
                  b = parseInt(h.slice(4, 6), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
        },
    };
};
</script>
@endpush

@php
    $init = [
        'sidebar' => old('sidebar_color', $agency->sidebar_color ?? '#0ea5e9'),
        'icon'    => old('icon_color',    $agency->icon_color    ?? '#0ea5e9'),
        'base'    => old('default_color', $agency->default_color ?? '#0b2a4a'),
        'button'  => old('button_color',  $agency->button_color  ?? '#0ea5e9'),
        'logoUrl' => $agency->logo_path ? asset('storage/' . $agency->logo_path) : '',
    ];
@endphp

<div class="space-y-6" x-data="brandingStep(@js($init))">

    {{-- Logo --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Your logo</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">Used on documents, letterheads, the sidebar and your public property pages. PNG, JPG or WebP, up to 2&nbsp;MB.</p>

        <div class="flex items-center gap-4">
            <div class="h-20 w-20 rounded-md flex items-center justify-center flex-shrink-0 overflow-hidden"
                 style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb);">
                <template x-if="logoPreview && !removeLogo">
                    <img :src="logoPreview" alt="Logo preview" class="max-h-full max-w-full object-contain p-1">
                </template>
                <template x-if="!logoPreview || removeLogo">
                    <span class="text-xs" style="color:var(--text-muted,#94a3b8);">No logo</span>
                </template>
            </div>

            <div class="flex-1 min-w-0 space-y-2">
                <input type="file" name="logo" accept="image/png,image/jpeg,image/jpg,image/webp"
                       x-on:change="onLogo($event)"
                       class="block w-full text-xs" style="color:var(--text-secondary);">
                @error('logo')
                    <p class="text-xs" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>
                @enderror
                <label class="inline-flex items-center gap-2 text-xs" style="color:var(--text-muted);">
                    <input type="hidden" name="remove_logo" value="0">
                    <input type="checkbox" name="remove_logo" value="1" x-model="removeLogo"
                           style="accent-color: var(--brand-button,#0ea5e9);">
                    Remove the current logo
                </label>
            </div>
        </div>
    </div>

    {{-- Auto-detected palette --}}
    <div x-show="detected.length" x-cloak>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Colours we found in your logo</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">Click a swatch to use it as your accent, or apply our suggestion. Nothing changes until you save.</p>
        <div class="flex flex-wrap items-center gap-2">
            <template x-for="hex in detected" :key="hex">
                <button type="button" x-on:click="useAccent(hex)" :title="hex"
                        class="h-9 w-9 rounded-md" style="border:1px solid var(--border,#e5e7eb); cursor:pointer;"
                        :style="'background:' + hex + '; border:1px solid var(--border,#e5e7eb);'"></button>
            </template>
            <button type="button" x-on:click="applySuggested()" x-show="suggest"
                    class="ml-1 rounded-md px-3 py-2 text-xs font-semibold"
                    style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569); cursor:pointer;">
                Apply suggested colours
            </button>
        </div>
    </div>

    {{-- The four roles --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Your four brand colours</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">Each colour has one job, so your system stays consistent everywhere.</p>

        <div class="space-y-3">
            @php
                $roles = [
                    ['name' => 'default_color', 'model' => 'base',    'label' => 'Header & profile colour', 'explain' => 'Deep brand colour used on page headers, profiles and document letterheads.'],
                    ['name' => 'button_color',  'model' => 'button',  'label' => 'Button colour',           'explain' => 'Every primary button and call-to-action.'],
                    ['name' => 'icon_color',    'model' => 'icon',    'label' => 'Icon & link colour',      'explain' => 'Icons, links and small accents throughout CoreX.'],
                    ['name' => 'sidebar_color', 'model' => 'sidebar', 'label' => 'Sidebar highlight',       'explain' => 'The highlight on the active/hovered sidebar item.'],
                ];
            @endphp
            @foreach ($roles as $r)
                <div class="flex items-center gap-3">
                    <input type="color" x-model="{{ $r['model'] }}" aria-label="{{ $r['label'] }} picker"
                           class="h-9 w-12 rounded-md flex-shrink-0" style="border:1px solid var(--border,#e5e7eb); background:none; cursor:pointer;">
                    <input type="text" name="{{ $r['name'] }}" x-model="{{ $r['model'] }}" maxlength="20"
                           class="w-28 rounded-md px-2 py-2 text-xs font-mono flex-shrink-0"
                           style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $r['label'] }}</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $r['explain'] }}</div>
                    </div>
                </div>
                @error($r['name'])
                    <p class="text-xs" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>
                @enderror
            @endforeach
        </div>
    </div>

    {{-- Live preview — dual theme. Mirrors the Company Settings → Branding preview
         exactly (same elements, same colour-role mapping). --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Live preview</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">This is how CoreX will look to your team, in both themes.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {{-- Dark --}}
            <div class="rounded-md p-4" style="background:#0d0f14; color:#eef0f5; border:1px solid var(--border,#e5e7eb);">
                <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:#8890a4;">Dark Preview</div>
                <div class="flex gap-2 mb-3">
                    <div class="rounded-md p-2 w-24 space-y-1" style="background:#13161d;">
                        <div class="rounded px-2 py-1 text-[10px]" style="color:#8890a4;">Menu</div>
                        <div class="rounded px-2 py-1 text-[10px] font-medium" :style="'background:' + rgba(sidebar, 0.12) + '; color:' + sidebar">Active</div>
                        <div class="rounded px-2 py-1 text-[10px]" style="color:#8890a4;">Item</div>
                    </div>
                    <div class="flex-1 space-y-2">
                        <div class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white" :style="'background:' + base">Header</div>
                        <div class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white text-center" :style="'background:' + button">Button</div>
                        <div class="flex items-center gap-1.5">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[8px] font-bold text-white" :style="'background:' + base">AB</div>
                            <span class="text-[10px] font-medium" :style="'color:' + icon">Link text</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="inline-block w-4 h-4 rounded-full border border-white/20" :style="'background:' + sidebar"></span>
                    <span class="inline-block w-4 h-4 rounded-full border border-white/20" :style="'background:' + icon"></span>
                    <span class="inline-block w-4 h-4 rounded-full border border-white/20" :style="'background:' + base"></span>
                    <span class="inline-block w-4 h-4 rounded-full border border-white/20" :style="'background:' + button"></span>
                </div>
            </div>

            {{-- Light --}}
            <div class="rounded-md p-4" style="background:#f4f6fb; color:#111827; border:1px solid var(--border,#e5e7eb);">
                <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:#9ca3af;">Light Preview</div>
                <div class="flex gap-2 mb-3">
                    <div class="rounded-md p-2 w-24 space-y-1" style="background:#ffffff; border:1px solid rgba(0,0,0,0.07);">
                        <div class="rounded px-2 py-1 text-[10px]" style="color:#9ca3af;">Menu</div>
                        <div class="rounded px-2 py-1 text-[10px] font-medium" :style="'background:' + rgba(sidebar, 0.12) + '; color:' + sidebar">Active</div>
                        <div class="rounded px-2 py-1 text-[10px]" style="color:#9ca3af;">Item</div>
                    </div>
                    <div class="flex-1 space-y-2">
                        <div class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white" :style="'background:' + base">Header</div>
                        <div class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white text-center" :style="'background:' + button">Button</div>
                        <div class="flex items-center gap-1.5">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[8px] font-bold text-white" :style="'background:' + base">AB</div>
                            <span class="text-[10px] font-medium" :style="'color:' + icon">Link text</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="inline-block w-4 h-4 rounded-full border border-slate-200" :style="'background:' + sidebar"></span>
                    <span class="inline-block w-4 h-4 rounded-full border border-slate-200" :style="'background:' + icon"></span>
                    <span class="inline-block w-4 h-4 rounded-full border border-slate-200" :style="'background:' + base"></span>
                    <span class="inline-block w-4 h-4 rounded-full border border-slate-200" :style="'background:' + button"></span>
                </div>
            </div>
        </div>
    </div>
</div>
