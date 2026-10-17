{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    "Create with AI" pop-up (AT-456 Part B, restructured in §B.10 - .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md).

    A centred dialog teleported to <body> (so no page container can stretch or clip it): pinned header, a body that is the ONLY
    thing that scrolls, pinned footer. Its styles live HERE (prefixed aitp-) and use CoreX tokens only, so light/dark both work
    and no `npm run build` is needed.

    Expects $aiPanel (see AdTemplateManagerController::aiPanelData) and $inputStyle from the parent view.
--}}
<style>
    .aitp-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; }
    .aitp-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .55); }
    .aitp-dialog {
        position: relative; display: flex; flex-direction: column; width: 100%; max-width: 1040px;
        max-height: 90vh; max-height: 90dvh; min-height: 0;
        background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);
        border-radius: 8px; box-shadow: 0 20px 50px rgba(0, 0, 0, .35); outline: none; overflow: hidden;
    }
    .aitp-head, .aitp-foot { flex: 0 0 auto; position: sticky; background: var(--surface); z-index: 2; }
    .aitp-head { top: 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
    .aitp-foot { bottom: 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px 16px; padding: 12px 20px; border-top: 1px solid var(--border); }
    .aitp-foot-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
    .aitp-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; padding: 16px 20px 20px; }
    .aitp-x { flex: 0 0 auto; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid var(--border); background: transparent; color: var(--text-secondary); cursor: pointer; font-size: 20px; line-height: 1; }
    .aitp-x:hover:not(:disabled) { background: var(--surface-2); color: var(--text-primary); }
    .aitp-x:disabled { opacity: .5; cursor: not-allowed; }
    .aitp-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px 16px; align-items: start; }
    .aitp-full { grid-column: 1 / -1; }
    .aitp-lbl { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; color: var(--text-secondary); }
    .aitp-hint { font-size: 12px; color: var(--text-muted); }
    .aitp-field { width: 100%; max-width: 100%; box-sizing: border-box; border-radius: 6px; padding: 8px 12px; font-size: 14px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); }
    .aitp-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr)); gap: 6px 14px; }
    .aitp-check { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; cursor: pointer; min-width: 0; }
    .aitp-check input { margin-top: 3px; flex: 0 0 auto; }
    .aitp-check[data-disabled="1"] { opacity: .55; cursor: not-allowed; }
    .aitp-group { border: 1px solid var(--border); border-radius: 6px; padding: 10px 12px; background: var(--surface-2); min-width: 0; }
    .aitp-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .aitp-note { border-radius: 6px; padding: 10px 14px; font-size: 14px; background: var(--surface-2); border: 1px solid var(--border); }
    .aitp-alert { border-radius: 6px; padding: 10px 14px; font-size: 14px; background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 35%, transparent); }
    .aitp-results { margin-top: 18px; }
    .aitp-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 290px), 1fr)); gap: 16px; }
    .aitp-card { min-width: 0; border-radius: 6px; padding: 12px; background: var(--surface-2); border: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; }
    .aitp-prev { position: relative; width: 100%; overflow: hidden; border-radius: 4px; background: #111; }
    .aitp-prev-inner { position: relative; overflow: hidden; transform-origin: top left; }
    .aitp-skel { background: linear-gradient(100deg, color-mix(in srgb, var(--text-muted) 18%, transparent) 30%, color-mix(in srgb, var(--text-muted) 32%, transparent) 50%, color-mix(in srgb, var(--text-muted) 18%, transparent) 70%); background-size: 300% 100%; animation: aitp-shimmer 1.6s linear infinite; border-radius: 4px; }
    .aitp-skel-line { height: 12px; }
    @keyframes aitp-shimmer { from { background-position: 100% 0; } to { background-position: 0 0; } }
    @media (prefers-reduced-motion: reduce) { .aitp-skel { animation: none; } }
    .aitp-switch { display: flex; flex-wrap: wrap; gap: 4px; }
    .aitp-pill { font-size: 12px; padding: 3px 10px; border-radius: 999px; border: 1px solid var(--border); background: transparent; color: var(--text-secondary); cursor: pointer; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .aitp-pill[aria-pressed="true"] { background: var(--accent, #4f7cff); border-color: var(--accent, #4f7cff); color: #fff; }
    .aitp-small { font-size: 12px; color: var(--text-secondary); }
    @media (max-width: 760px) {
        .aitp-form { grid-template-columns: minmax(0, 1fr); }
        .aitp-groups { grid-template-columns: minmax(0, 1fr); }
        .aitp-head, .aitp-foot { padding-left: 14px; padding-right: 14px; }
        .aitp-body { padding: 14px; }
        .aitp-dialog { max-height: calc(100vh - 32px); max-height: calc(100dvh - 32px); }
        .aitp-foot-actions { width: 100%; }
        .aitp-foot-actions > * { flex: 1 1 auto; justify-content: center; }
    }
</style>

<script src="{{ asset_v('js/corex-ad-render.js') }}"></script>
<div x-data="aiTemplatePanel(@js([
        'usage'    => $aiPanel['usage'],
        'sample'   => $aiPanel['sample'],
        'styles'   => $aiPanel['styles'],
        'sizes'    => $aiPanel['sizes'],
        'maxTypes' => $aiPanel['maxTypes'],
        'ribbonMax' => $aiPanel['ribbonMax'],
        'templateCount' => $aiPanel['templateCount'],
        'generate' => route('api.v1.ad-manager.templates.ai.generate', [], false),
        'save'     => route('api.v1.ad-manager.templates.ai.save', ['generation' => '__GEN__'], false),
    ]))"
     x-on:open-ai-template-panel.window="openPanel()">
    <template x-teleport="body">
        <div x-show="open" x-cloak class="aitp-overlay" data-aitp="overlay"
             role="dialog" aria-modal="true" aria-labelledby="aitp-title"
             @keydown.escape.window="if (open && !loading) closePanel()"
             @keydown.tab="trapTab($event)">
            <div class="aitp-backdrop" @click="if (!loading) closePanel()"></div>

            <div class="aitp-dialog" x-ref="dialog" tabindex="-1" data-aitp="dialog">
                {{-- Pinned header --}}
                <div class="aitp-head" data-aitp="header">
                    <div style="min-width:0;">
                        <h2 id="aitp-title" class="text-lg font-bold" style="margin:0;">Create a template with AI</h2>
                        <p class="text-sm" style="color:var(--text-muted); margin:2px 0 0;">Describe the look you want. You get three different designs to choose from — keep the ones you like, then fine-tune them in the Ad Builder.</p>
                    </div>
                    <button type="button" class="aitp-x" :disabled="loading" @click="closePanel()" aria-label="Close" title="Close">&times;</button>
                </div>

                {{-- The only part that scrolls --}}
                <div class="aitp-body" data-aitp="body" x-ref="body">
                    <form id="aitp-form" class="aitp-form" @submit.prevent="generate()" autocomplete="off">
                        <label class="aitp-full" style="display:block;">
                            <span class="aitp-lbl">Your idea</span>
                            <textarea x-ref="idea" x-model="idea" maxlength="1000" rows="3" required :disabled="loading"
                                      placeholder="e.g. A bold, modern look for new listings — big photo, price in a strong colour bar, agent photo bottom right."
                                      class="aitp-field" style="resize:vertical;"></textarea>
                            <span class="aitp-hint" x-text="idea.length + ' / 1000'"></span>
                        </label>

                        <label style="display:block;">
                            <span class="aitp-lbl">Ad size</span>
                            <select x-model="size" :disabled="loading" class="aitp-field">
                                @foreach($aiPanel['sizes'] as $sz)
                                    <option value="{{ $sz['key'] }}">{{ $sz['label'] }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label style="display:block;">
                            <span class="aitp-lbl">Style</span>
                            <select x-model="style" :disabled="loading" class="aitp-field">
                                <option value="auto">Let the AI choose</option>
                                @foreach($aiPanel['styles'] as $st)
                                    <option value="{{ $st['key'] }}">{{ $st['name'] }}</option>
                                @endforeach
                            </select>
                            <span class="aitp-hint" x-text="styleHint()"></span>
                        </label>

                        <label style="display:block;">
                            <span class="aitp-lbl">Reference image (optional)</span>
                            <input type="file" accept="image/jpeg,image/png" :disabled="loading" @change="pickFile($event)" class="aitp-field" style="padding:6px 8px; font-size:12px;">
                            <span class="aitp-hint">JPG or PNG, up to 5 MB. Used for inspiration only and deleted after 7 days.</span>
                            <span class="aitp-hint" style="display:block; color:var(--ds-crimson,#c41e3a);" x-show="fileError" x-text="fileError"></span>
                        </label>

                        {{-- Property types: the agency's OWN list (never a built-in one). --}}
                        <fieldset class="aitp-full" style="border:0; padding:0; margin:0; min-width:0;" data-aitp="types">
                            <legend class="aitp-lbl" style="padding:0;">Property types (optional)</legend>
                            @if(count($aiPanel['propertyTypes']) > 0)
                                <div class="aitp-checks">
                                    @foreach($aiPanel['propertyTypes'] as $pt)
                                        <label class="aitp-check" :data-disabled="(!types.includes(@js($pt)) && types.length >= maxTypes) || loading ? 1 : 0">
                                            <input type="checkbox" value="{{ $pt }}" x-model="types"
                                                   :disabled="loading || (!types.includes(@js($pt)) && types.length >= maxTypes)">
                                            <span>{{ $pt }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <span class="aitp-hint" style="display:block; margin-top:6px;">
                                    Tick the kinds of property this template is for (up to {{ $aiPanel['maxTypes'] }}) and each design also comes in a version made for each one — for example no bedrooms for vacant land, the levy for an apartment. It saves as one template that picks the right version for each listing. Tick none for one general design.
                                    <strong x-show="types.length" x-text="types.length + ' of ' + maxTypes + ' chosen'"></strong>
                                </span>
                            @else
                                <span class="aitp-hint">Your agency has no property types set up yet, so the design will be one general version.</span>
                            @endif
                        </fieldset>

                        {{-- Must-include options --}}
                        <fieldset class="aitp-full" style="border:0; padding:0; margin:0; min-width:0;" data-aitp="must-include">
                            <legend class="aitp-lbl" style="padding:0;">Make sure every design includes (optional)</legend>
                            <div class="aitp-groups">
                                @foreach($aiPanel['mustIncludes'] as $group)
                                    <div class="aitp-group">
                                        <div class="aitp-small" style="font-weight:600; margin-bottom:6px;">{{ $group['name'] }}</div>
                                        <div style="display:grid; gap:6px;">
                                            @foreach($group['options'] as $opt)
                                                <div style="min-width:0;">
                                                    <label class="aitp-check">
                                                        <input type="checkbox" value="{{ $opt['key'] }}" x-model="must" :disabled="loading">
                                                        <span>{{ $opt['label'] }}</span>
                                                    </label>
                                                    @if($opt['key'] === 'ribbon')
                                                        <div x-show="must.includes('ribbon')" style="margin:6px 0 0 24px;">
                                                            <input type="text" x-model="ribbon" maxlength="{{ $aiPanel['ribbonMax'] }}" :disabled="loading" :required="must.includes('ribbon')"
                                                                   placeholder="e.g. JUST LISTED" aria-label="Words on the corner ribbon" class="aitp-field">
                                                            <span class="aitp-hint">The words on the ribbon, up to {{ $aiPanel['ribbonMax'] }} characters. They are used exactly as you type them.</span>
                                                        </div>
                                                    @endif
                                                    @if($opt['key'] === 'logo' && ! $aiPanel['hasLogo'])
                                                        <span class="aitp-hint" style="display:block; margin-left:24px;">No logo file is set up for your agency yet, so its name is shown in the logo's place.</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>

                        {{-- Only offered when the agency has at least one saved, non-archived template (ticked by default then). --}}
                        @if(($aiPanel['templateCount'] ?? 0) > 0)
                            <div class="aitp-full">
                                <span class="aitp-lbl">Your own look</span>
                                <label class="aitp-check">
                                    <input type="checkbox" x-model="match" :disabled="loading">
                                    <span>Match our existing templates
                                        <span class="aitp-hint" style="display:block;">The AI looks at up to three of your agency's saved templates and designs in the same spirit. It adds a little to the cost of each generation.</span>
                                    </span>
                                </label>
                            </div>
                        @endif
                    </form>

                    {{-- Result area. Its space is reserved while generating (skeleton cards) so nothing jumps when the designs arrive. --}}
                    <div class="aitp-results" data-aitp="results" x-ref="results" aria-live="polite">
                        <div x-show="loading" class="aitp-note" style="margin-bottom:14px;">
                            <span x-text="types.length ? 'Designing your three templates and their versions for ' + types.length + ' property type' + (types.length === 1 ? '' : 's') + ' — this usually takes about two minutes. Please keep this window open.' : 'Designing your three templates — this usually takes about a minute. Please keep this window open.'"></span>
                        </div>

                        <div x-show="error" class="aitp-alert" role="alert" style="margin-bottom:14px;" x-text="error"></div>

                        <div x-show="loading" class="aitp-cards" aria-hidden="true">
                            <template x-for="n in 3" :key="'skel' + n">
                                <div class="aitp-card">
                                    <div class="aitp-prev aitp-skel" :style="'aspect-ratio:' + canvas().w + ' / ' + canvas().h"></div>
                                    <div class="aitp-skel aitp-skel-line" style="width:55%;"></div>
                                    <div class="aitp-skel aitp-skel-line" style="width:100%; height:30px;"></div>
                                    <div class="aitp-skel aitp-skel-line" style="width:40%; height:28px;"></div>
                                </div>
                            </template>
                        </div>

                        <div x-show="options.length && !loading" data-aitp="options">
                            <h3 class="text-sm font-semibold" style="margin:0 0 8px;">Pick the ones you like</h3>
                            <div class="aitp-cards">
                                <template x-for="opt in options" :key="generationId + '-' + opt.index">
                                    <article class="aitp-card" data-aitp="card">
                                        <div class="aitp-prev" :id="'aitp-prev-' + opt.index" x-init="$nextTick(() => paintPreview(opt))">
                                            <div class="aitp-prev-inner"></div>
                                        </div>

                                        <div class="aitp-switch" x-show="variantNames(opt).length" role="group" aria-label="Version to preview">
                                            <button type="button" class="aitp-pill" :aria-pressed="opt.view === '' ? 'true' : 'false'" @click="showView(opt, '')">Default</button>
                                            <template x-for="t in variantNames(opt)" :key="t">
                                                <button type="button" class="aitp-pill" :aria-pressed="opt.view === t ? 'true' : 'false'" :title="t" @click="showView(opt, t)" x-text="t"></button>
                                            </template>
                                        </div>

                                        <div class="aitp-small">Style: <strong x-text="opt.style_name || 'not named'"></strong></div>
                                        <template x-for="(note, t) in opt.variant_notes" :key="t">
                                            <div class="aitp-small" x-text="note"></div>
                                        </template>

                                        <input type="text" x-model="opt.name" maxlength="100" :disabled="opt.saved || opt.saving" class="aitp-field" style="padding:6px 12px;" aria-label="Template name">
                                        <div class="aitp-small" style="color:var(--ds-crimson,#c41e3a);" x-show="opt.err" x-text="opt.err"></div>
                                        <div x-show="!opt.saved">
                                            <button type="button" class="corex-btn-primary text-xs px-3 py-1.5" :disabled="opt.saving" @click="save(opt)">
                                                <span x-show="!opt.saving">Save this template</span><span x-show="opt.saving">Saving…</span>
                                            </button>
                                        </div>
                                        <div class="text-xs" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;" x-show="opt.saved">
                                            <span style="color:var(--ds-green,#059669);" x-text="variantNames(opt).length ? 'Saved to your templates with ' + variantNames(opt).length + ' property-type version' + (variantNames(opt).length === 1 ? '' : 's') + '.' : 'Saved to your templates.'"></span>
                                            <a :href="opt.editUrl" class="corex-btn-outline text-xs">Open in Ad Builder</a>
                                        </div>
                                    </article>
                                </template>
                            </div>
                            <p class="aitp-hint" style="margin:10px 0 0;">Previews use sample photos and details, with your agency's own name and logo. Real listings are filled in when you make an ad. Switching a card between Default and a property type only changes the preview — saving keeps every version.</p>
                        </div>
                    </div>
                </div>

                {{-- Pinned footer: the actions are always visible --}}
                <div class="aitp-foot" data-aitp="footer">
                    <div style="min-width:0;">
                        <span class="text-sm" style="color:var(--text-secondary);" x-show="usage.remaining > 0"
                              x-text="usage.remaining + ' of ' + usage.limit + ' generations left this month'"></span>
                        <span class="text-sm" style="color:var(--ds-crimson,#c41e3a);" x-show="usage.remaining <= 0 && usage.limit > 0"
                              x-text="'You have used all ' + usage.limit + ' generations this month. They reset on ' + usage.resets_on + '.'"></span>
                        <span class="text-sm" style="color:var(--ds-crimson,#c41e3a);" x-show="usage.limit <= 0">AI template generation is switched off for your agency.</span>
                    </div>
                    <div class="aitp-foot-actions">
                        <button type="button" class="corex-btn-outline text-sm px-4 py-2" :disabled="loading" @click="closePanel()">Close</button>
                        <button type="submit" form="aitp-form" class="corex-btn-primary text-sm px-4 py-2" :disabled="loading || usage.remaining <= 0 || idea.trim() === ''">
                            <span x-show="!loading">Design 3 options</span>
                            <span x-show="loading">Designing…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function aiTemplatePanel(cfg) {
    return {
        open: false, idea: '', size: 'facebook', file: null, fileError: '',
        style: 'auto', styles: cfg.styles || [],
        types: [], maxTypes: cfg.maxTypes || 5, must: [], ribbon: '',
        hasTemplates: (cfg.templateCount || 0) > 0, match: (cfg.templateCount || 0) > 0,
        usage: cfg.usage, sample: cfg.sample,
        loading: false, error: '', generationId: null, options: [], savedAny: false,
        _opener: null, _bodyOverflow: '',

        openPanel() {
            this._opener = document.activeElement;
            this._bodyOverflow = document.body.style.overflow;
            this.open = true; document.body.style.overflow = 'hidden';
            this.$nextTick(() => { (this.$refs.idea || this.$refs.dialog).focus(); });
        },
        closePanel() {
            this.open = false; document.body.style.overflow = this._bodyOverflow;
            if (this._opener && typeof this._opener.focus === 'function') { try { this._opener.focus(); } catch (e) {} }
            // Newly saved templates only appear in the table after a reload.
            if (this.savedAny) window.location.reload();
        },
        // Keep Tab / Shift+Tab inside the dialog.
        trapTab(e) {
            const dlg = this.$refs.dialog; if (!dlg) return;
            const items = Array.from(dlg.querySelectorAll('a[href], button, textarea, input, select, [tabindex]'))
                .filter(el => !el.disabled && el.tabIndex !== -1 && el.type !== 'hidden' && el.offsetParent !== null);
            if (!items.length) { e.preventDefault(); dlg.focus(); return; }
            const first = items[0], last = items[items.length - 1], active = document.activeElement;
            if (e.shiftKey && (active === first || active === dlg)) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && (active === last || !dlg.contains(active))) { e.preventDefault(); first.focus(); }
        },
        canvas() { return (cfg.sizes || []).find(s => s.key === this.size) || { w: 1200, h: 628 }; },
        styleHint() {
            if (this.style === 'auto') return 'The AI picks the best of our eight styles for each design, and tells you which.';
            const s = this.styles.find(x => x.key === this.style);
            return s ? s.summary : '';
        },
        pickFile(e) {
            this.fileError = ''; this.file = null;
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            if (!/^image\/(jpeg|png)$/.test(f.type)) { this.fileError = 'Please choose a JPG or PNG image.'; e.target.value = ''; return; }
            if (f.size > 5 * 1024 * 1024) { this.fileError = 'That image is larger than 5 MB.'; e.target.value = ''; return; }
            this.file = f;
        },
        async generate() {
            if (this.loading) return;
            if (this.must.includes('ribbon') && this.ribbon.trim() === '') { this.error = 'Type the words you want on the corner ribbon, for example JUST LISTED.'; return; }
            this.loading = true; this.error = ''; this.options = []; this.generationId = null;
            const body = new FormData();
            body.append('idea', this.idea); body.append('ad_size', this.size);
            this.types.forEach(t => body.append('property_types[]', t));
            this.must.forEach(k => body.append('must_include[]', k));
            if (this.must.includes('ribbon')) body.append('ribbon_text', this.ribbon.trim());
            body.append('style', this.style);
            body.append('match_existing', (this.hasTemplates && this.match) ? '1' : '0');
            if (this.file) body.append('reference_image', this.file);
            try {
                // window.CoreX.api.fetch adds the CSRF token + JSON headers and throws on a non-2xx (error.status / error.data).
                const data = await window.CoreX.api.fetch(cfg.generate, { method: 'POST', body });
                if (data.usage) this.usage = data.usage;
                if (!data.ok) {
                    this.error = data.message || 'Something went wrong. Nothing was counted — please try again.';
                } else {
                    this.generationId = data.generation_id;
                    this.options = data.options.map(o => ({
                        ...o, variants: o.variants || {}, variant_notes: o.variant_notes || {},
                        view: '', saving: false, saved: false, editUrl: '', err: '',
                    }));
                    // Bring the new designs into view (the body is the only thing that scrolls).
                    this.$nextTick(() => {
                        const b = this.$refs.body, r = this.$refs.results;
                        if (b && r) b.scrollTo({ top: b.scrollTop + r.getBoundingClientRect().top - b.getBoundingClientRect().top - 8, behavior: 'smooth' });
                    });
                }
            } catch (e) {
                const data = (e && e.data && typeof e.data === 'object') ? e.data : null;
                if (data && data.usage) this.usage = data.usage;
                if (e && e.status === 429 && !(data && data.usage)) {
                    // The request throttle's own "Too Many Attempts." (no usage payload). The monthly-limit 429 carries usage + its own message and is shown below.
                    this.error = 'You are going a little fast — wait a minute and try again.';
                } else if (data) {
                    this.error = data.message
                        || (data.errors ? Object.values(data.errors).flat().join(' ') : '')
                        || 'Something went wrong. Nothing was counted — please try again.';
                } else if (e && e.status) {
                    this.error = e.status === 429 ? 'You are going a little fast — wait a minute and try again.' : 'Something went wrong. Nothing was counted — please try again.';
                } else {
                    this.error = 'We could not reach the server. Nothing was counted — please try again.';
                }
            } finally { this.loading = false; }
        },
        // The property types this design has its own version for (in the order they were ticked).
        variantNames(opt) { return Object.keys(opt.variants || {}); },
        showView(opt, view) { opt.view = view; this.paintPreview(opt); },
        layoutFor(opt) { return (opt.view && opt.variants[opt.view] && opt.variants[opt.view].layout) || opt.layout; },
        // Draws the chosen version into the card's preview box and keeps it scaled to the box's width as the box resizes.
        paintPreview(opt) {
            const box = document.getElementById('aitp-prev-' + opt.index);
            const l = this.layoutFor(opt);
            if (!box || !l || !window.CoreXAd) return;
            const inner = box.firstElementChild;
            box.style.aspectRatio = l.canvasW + ' / ' + l.canvasH;
            inner.style.width = l.canvasW + 'px'; inner.style.height = l.canvasH + 'px';
            box.dataset.cw = l.canvasW;
            window.CoreXAd.renderLayout(l, this.sample, inner, { paintBackground: true, placeholders: true });
            this.scalePreview(box);
            if (!box._aitpRO && window.ResizeObserver) {
                box._aitpRO = new ResizeObserver(() => this.scalePreview(box));
                box._aitpRO.observe(box);
            }
        },
        scalePreview(box) {
            const inner = box.firstElementChild, cw = parseFloat(box.dataset.cw || '0');
            if (!inner || !cw) return;
            inner.style.transform = 'scale(' + ((box.clientWidth || 320) / cw) + ')';
        },
        async save(opt) {
            opt.saving = true; opt.err = '';
            try {
                const data = await window.CoreX.api.fetch(cfg.save.replace('__GEN__', this.generationId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ option: opt.index, name: opt.name }),
                });
                if (data.ok) { opt.saved = true; opt.editUrl = data.edit_url; this.savedAny = true; }
                else { opt.err = data.message || 'Could not save this template — please try again.'; }
            } catch (e) {
                if (e && e.data && typeof e.data === 'object') { opt.err = e.data.message || 'Could not save this template — please try again.'; }
                else if (e && e.status) { opt.err = 'Could not save this template — please try again.'; }
                else { opt.err = 'We could not reach the server — please try again.'; }
            }
            finally { opt.saving = false; }
        },
    };
}
</script>
