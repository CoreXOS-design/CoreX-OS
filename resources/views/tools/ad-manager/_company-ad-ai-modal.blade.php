{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    "Create company ad with AI" pop-up (.ai/specs/company-ads.md §8). The same dialog shell, behaviour and full-size viewer
    as the property-template pop-up (_ai-dialog-shell / _ai-dialog-viewer, reused - not copied).

    Expects $aiPanel (CompanyAdsController::aiPanelData) and $brandData (the agency's own brand + the signed-in user) from
    the Company ads list, which also loads the render kernel and owns the download (event: company-ad-download).
--}}
@include('tools.ad-manager._ai-dialog-shell')

<style>
    .cadai-lib { display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 8px; max-height: 260px; overflow-y: auto; padding: 2px; }
    .cadai-lib button { position: relative; padding: 0; border: 2px solid transparent; border-radius: 6px; overflow: hidden; background: var(--surface-2); cursor: pointer; aspect-ratio: 1 / 1; }
    .cadai-lib button img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .cadai-lib button[aria-pressed="true"] { border-color: var(--accent, #4f7cff); box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent, #4f7cff) 35%, transparent); }
    .cadai-lib button:focus-visible { outline: 2px solid var(--accent, #4f7cff); outline-offset: 2px; }
    .cadai-choice { display: flex; flex-wrap: wrap; gap: 6px 16px; }
    .cadai-upload-prev { width: 96px; height: 96px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); }
</style>

<div x-data="companyAdAiPanel(@js([
        'usage'        => $aiPanel['usage'],
        'brand'        => $brandData,
        'styles'       => $aiPanel['styles'],
        'sizes'        => $aiPanel['sizes'],
        'library'      => $aiPanel['library'],
        'captionMax'   => $aiPanel['captionMax'],
        'generate'     => route('api.v1.ad-manager.company-ads.ai.generate', [], false),
        'save'         => route('api.v1.ad-manager.company-ads.ai.save', ['generation' => '__GEN__'], false),
        'upload'       => route('api.v1.ad-manager.company-ads.images', [], false),
    ]))"
     x-on:open-company-ad-ai.window="openPanel()">
    <template x-teleport="body">
        <div x-show="open" x-cloak class="aitp-overlay" data-aitp="overlay"
             role="dialog" aria-modal="true" aria-labelledby="cadai-title"
             @keydown.escape.window="onEscape()"
             @keydown.tab="trapTab($event)">
            <div class="aitp-backdrop" @click="if (!loading) closePanel()"></div>

            <div class="aitp-dialog" x-ref="dialog" tabindex="-1" data-aitp="dialog">
                {{-- Pinned header --}}
                <div class="aitp-head" data-aitp="header">
                    <div style="min-width:0;">
                        <h2 id="cadai-title" class="text-lg font-bold" style="margin:0;">Create a company ad with AI</h2>
                        <p class="text-sm" style="color:var(--text-muted); margin:2px 0 0;">Say what the ad is for. You get up to three designs in your agency's colours, each with a suggested post text — keep the ones you like.</p>
                    </div>
                    <button type="button" class="aitp-x" :disabled="loading" @click="closePanel()" aria-label="Close" title="Close">&times;</button>
                </div>

                {{-- The only part that scrolls --}}
                <div class="aitp-body" data-aitp="body" x-ref="body">
                    <form id="cadai-form" class="aitp-form" @submit.prevent="generate()" autocomplete="off">
                        <label style="display:block;">
                            <span class="aitp-lbl">Occasion</span>
                            <select x-model="occasion" :disabled="loading" class="aitp-field" data-cadai="occasion">
                                <option value="">Other / no particular occasion</option>
                                @foreach($aiPanel['occasions'] as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
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

                        <label class="aitp-full" style="display:block;">
                            <span class="aitp-lbl">What is the ad for?</span>
                            <textarea x-ref="idea" x-model="idea" maxlength="1000" rows="3" required :disabled="loading"
                                      placeholder="e.g. Merry Christmas from all of us. Our offices are closed from 24 December to 2 January."
                                      class="aitp-field" style="resize:vertical;"></textarea>
                            <span class="aitp-hint" x-text="idea.length + ' / 1000'"></span>
                        </label>

                        {{-- The picture (spec §8 item 3) --}}
                        <fieldset class="aitp-full" style="border:0; padding:0; margin:0; min-width:0;" data-cadai="picture">
                            <legend class="aitp-lbl" style="padding:0;">Picture</legend>
                            <div class="cadai-choice" role="radiogroup">
                                <label class="aitp-check"><input type="radio" value="none" x-model="pictureSource" :disabled="loading"><span>No picture</span></label>
                                <label class="aitp-check"><input type="radio" value="library" x-model="pictureSource" :disabled="loading"><span>Choose from the library</span></label>
                                <label class="aitp-check"><input type="radio" value="upload" x-model="pictureSource" :disabled="loading"><span>Upload a picture</span></label>
                            </div>

                            <div x-show="pictureSource === 'none'" class="aitp-hint" style="margin-top:6px;">The AI designs with words, your colours, shapes and icons only.</div>

                            <div x-show="pictureSource === 'library'" style="margin-top:8px;" data-cadai="library">
                                <span class="aitp-hint" style="display:block; margin-bottom:6px;" x-text="occasion ? 'Pictures for ' + occasionLabel() + '. Change the occasion to see others.' : 'All library pictures. Pick an occasion to see only its pictures.'"></span>
                                <div class="cadai-lib">
                                    <template x-for="pic in libraryPictures()" :key="pic.url">
                                        <button type="button" :aria-pressed="libraryPicture === pic.url ? 'true' : 'false'" :title="pic.alt" :aria-label="pic.alt || 'Library picture'"
                                                :disabled="loading" @click="libraryPicture = pic.url">
                                            <img :src="pic.url" :alt="pic.alt" loading="lazy">
                                        </button>
                                    </template>
                                </div>
                                <span class="aitp-hint">Free to use: every library picture is public domain.</span>
                            </div>

                            <div x-show="pictureSource === 'upload'" style="margin-top:8px; display:grid; gap:8px;" data-cadai="upload">
                                <div style="display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                                    <img x-show="filePreview" :src="filePreview" alt="The picture you chose" class="cadai-upload-prev">
                                    <div style="min-width:0; flex:1 1 220px;">
                                        <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="loading" @change="pickFile($event)" class="aitp-field" style="padding:6px 8px; font-size:12px;">
                                        <span class="aitp-hint" style="display:block; color:var(--ds-crimson,#c41e3a);" x-show="fileError" x-text="fileError"></span>
                                    </div>
                                </div>
                                <div class="cadai-choice" role="radiogroup" aria-label="What to do with this picture">
                                    <label class="aitp-check"><input type="radio" value="place" x-model="uploadUse" :disabled="loading"><span>Put this picture in my ad</span></label>
                                    <label class="aitp-check"><input type="radio" value="look" x-model="uploadUse" :disabled="loading"><span>Copy the look of this post</span></label>
                                </div>
                                <span class="aitp-hint" x-show="uploadUse === 'place'">JPG, PNG or WebP, up to 8 MB. It is kept with your agency's company ad pictures.</span>
                                <div class="aitp-note" x-show="uploadUse === 'look'" data-cadai="look-note">
                                    We'll copy the layout and style only — never another company's logo, words or photos.
                                    <span class="aitp-hint" style="display:block;">JPG or PNG, up to 5 MB. Used for inspiration only and deleted after 7 days.</span>
                                </div>
                            </div>
                        </fieldset>

                        {{-- Must include (spec §8 item 6). The agency logo / name is always on a company ad - not a tick. --}}
                        <fieldset class="aitp-full" style="border:0; padding:0; margin:0; min-width:0;" data-aitp="must-include">
                            <legend class="aitp-lbl" style="padding:0;">Make sure every design includes (optional)</legend>
                            <div class="aitp-checks">
                                @foreach($aiPanel['mustIncludes'] as $opt)
                                    <div style="min-width:0;">
                                        <label class="aitp-check" @if($opt['key'] === 'contact' && ! $aiPanel['hasWebsite']) data-disabled="1" @endif>
                                            <input type="checkbox" value="{{ $opt['key'] }}" x-model="must"
                                                   @if($opt['key'] === 'contact' && ! $aiPanel['hasWebsite']) disabled @else :disabled="loading" @endif>
                                            <span>{{ $opt['label'] }}</span>
                                        </label>
                                        @if($opt['key'] === 'contact' && ! $aiPanel['hasWebsite'])
                                            <span class="aitp-hint" style="display:block; margin-left:24px;">No website is set up for your agency yet. Add it in Company Settings to use this.</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <span class="aitp-hint" style="display:block; margin-top:6px;">Your agency's logo (or its name, when there is no logo) is always on the ad.</span>
                        </fieldset>
                    </form>

                    {{-- Results. Their space is reserved while designing (skeleton cards) so nothing jumps. --}}
                    <div class="aitp-results" data-aitp="results" x-ref="results" aria-live="polite">
                        <div x-show="loading" class="aitp-note" style="margin-bottom:14px;">
                            <span x-text="uploading ? 'Uploading your picture…' : 'Designing your ads — this usually takes about a minute. Please keep this window open.'"></span>
                        </div>

                        <div x-show="error" class="aitp-alert" role="alert" style="margin-bottom:14px;" x-text="error"></div>

                        <div x-show="loading" class="aitp-cards" aria-hidden="true">
                            <template x-for="n in 3" :key="'skel' + n">
                                <div class="aitp-card">
                                    <div class="aitp-prev aitp-skel" :style="'aspect-ratio:' + canvas().w + ' / ' + canvas().h"></div>
                                    <div class="aitp-skel aitp-skel-line" style="width:55%;"></div>
                                    <div class="aitp-skel aitp-skel-line" style="width:100%; height:60px;"></div>
                                    <div class="aitp-skel aitp-skel-line" style="width:40%; height:28px;"></div>
                                </div>
                            </template>
                        </div>

                        <div x-show="options.length && !loading" data-aitp="options">
                            <h3 class="text-sm font-semibold" style="margin:0 0 8px;">Pick the ones you like</h3>
                            <div class="aitp-cards">
                                <template x-for="opt in options" :key="generationId + '-' + opt.index">
                                    <article class="aitp-card" data-aitp="card">
                                        <div class="aitp-prev" data-zoom="1" :id="'aitp-prev-' + opt.index" title="Click to view full size" @click="openViewer(opt)" x-init="$nextTick(() => paintPreview(opt))">
                                            <div class="aitp-prev-inner"></div>
                                        </div>
                                        <button type="button" class="corex-btn-outline text-xs px-3 py-1.5 aitp-view-btn" data-aitp="view-full" :data-aitp-view="opt.index" @click="openViewer(opt)">View full size</button>

                                        <div class="aitp-small">Style: <strong x-text="opt.style_name || 'not named'"></strong></div>

                                        <label style="display:block;">
                                            <span class="aitp-lbl">Name</span>
                                            <input type="text" x-model="opt.name" maxlength="100" :disabled="opt.saved || opt.saving" class="aitp-field" style="padding:6px 12px;">
                                        </label>
                                        <label style="display:block;">
                                            <span class="aitp-lbl">Suggested post text</span>
                                            <textarea x-model="opt.caption" :maxlength="captionMax" rows="3" :disabled="opt.saved || opt.saving" class="aitp-field" style="resize:vertical; font-size:13px;" data-cadai="caption"></textarea>
                                            <span class="aitp-hint" x-text="(opt.caption || '').length + ' / ' + captionMax"></span>
                                        </label>

                                        <div class="aitp-small" style="color:var(--ds-crimson,#c41e3a);" x-show="opt.err" x-text="opt.err"></div>
                                        <div x-show="!opt.saved">
                                            <button type="button" class="corex-btn-primary text-xs px-3 py-1.5" :disabled="opt.saving" @click="save(opt)">
                                                <span x-show="!opt.saving">Save this ad</span><span x-show="opt.saving">Saving…</span>
                                            </button>
                                        </div>
                                        <div class="text-xs" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;" x-show="opt.saved">
                                            <span style="color:var(--ds-green,#059669);">Saved to your company ads.</span>
                                            <button type="button" class="corex-btn-outline text-xs" @click="download(opt)">Download</button>
                                            <a :href="opt.editUrl" class="corex-btn-outline text-xs">Open in Ad Builder</a>
                                        </div>
                                    </article>
                                </template>
                            </div>
                            <p class="aitp-hint" style="margin:10px 0 0;">Previews show your agency's own name, logo and colours, and you as the person sending it.</p>
                        </div>
                    </div>
                </div>

                {{-- Pinned footer: the actions are always visible --}}
                <div class="aitp-foot" data-aitp="footer">
                    <div style="min-width:0;">
                        <span class="text-sm" style="color:var(--text-secondary);" x-show="usage.remaining > 0"
                              x-text="usage.remaining + ' of ' + usage.limit + ' AI designs left this month (shared with property templates)'"></span>
                        <span class="text-sm" style="color:var(--ds-crimson,#c41e3a);" x-show="usage.remaining <= 0 && usage.limit > 0"
                              x-text="'You have used all ' + usage.limit + ' AI designs this month. They reset on ' + usage.resets_on + '.'"></span>
                        <span class="text-sm" style="color:var(--ds-crimson,#c41e3a);" x-show="usage.limit <= 0">AI design is switched off for your agency.</span>
                    </div>
                    <div class="aitp-foot-actions">
                        <button type="button" class="corex-btn-outline text-sm px-4 py-2" :disabled="loading" @click="closePanel()">Close</button>
                        <button type="submit" form="cadai-form" class="corex-btn-primary text-sm px-4 py-2" :disabled="loading || usage.remaining <= 0 || idea.trim() === ''">
                            <span x-show="!loading">Design 3 options</span>
                            <span x-show="loading">Designing…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    @include('tools.ad-manager._ai-dialog-viewer', ['withVersions' => false])
</div>

<script>
function companyAdAiPanel(cfg) {
    return Object.assign(corexAiDialogShell(), {
        idea: '', occasion: '', size: 'facebook', style: 'auto', styles: cfg.styles || [], must: [],
        pictureSource: 'none', libraryPicture: '', uploadUse: 'place',
        file: null, filePreview: '', fileError: '', uploadedId: null, uploading: false,
        captionMax: cfg.captionMax || 300,
        usage: cfg.usage, error: '', generationId: null,

        canvas() { return (cfg.sizes || []).find(s => s.key === this.size) || { w: 1200, h: 628 }; },
        styleHint() {
            if (this.style === 'auto') return 'The AI picks the best of our eight styles for each design, and tells you which.';
            const s = this.styles.find(x => x.key === this.style);
            return s ? s.summary : '';
        },
        occasionLabel() {
            const g = (cfg.library || []).find(x => x.occasion === this.occasion);
            const opt = document.querySelector('[data-cadai="occasion"] option[value="' + this.occasion + '"]');
            return g ? g.label : (opt ? opt.textContent : '');
        },
        // The chosen occasion's pictures, or every picture when no occasion is chosen.
        libraryPictures() {
            const groups = (cfg.library || []).filter(g => !this.occasion || g.occasion === this.occasion);
            return groups.flatMap(g => g.pictures);
        },
        // The mode the server is told: none | library | place | look.
        pictureMode() {
            if (this.pictureSource === 'library') return 'library';
            if (this.pictureSource === 'upload') return this.uploadUse === 'look' ? 'look' : 'place';
            return 'none';
        },
        pickFile(e) {
            this.fileError = ''; this.file = null; this.uploadedId = null;
            if (this.filePreview) { URL.revokeObjectURL(this.filePreview); this.filePreview = ''; }
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { this.fileError = 'Please choose a JPG, PNG or WebP picture.'; e.target.value = ''; return; }
            if (f.size > 8 * 1024 * 1024) { this.fileError = 'That picture is larger than 8 MB.'; e.target.value = ''; return; }
            this.file = f; this.filePreview = URL.createObjectURL(f);
        },
        // Problems the user can fix before anything is sent (and nothing is counted).
        pictureProblem() {
            const mode = this.pictureMode();
            if (mode === 'library' && !this.libraryPicture) return 'Choose a picture from the library, or pick "No picture".';
            if ((mode === 'place' || mode === 'look') && !this.file) return 'Choose the picture to upload, or pick "No picture".';
            if (mode === 'look' && !/^image\/(jpeg|png)$/.test(this.file.type)) return 'To copy the look of a post, please use a JPG or PNG picture.';
            if (mode === 'look' && this.file.size > 5 * 1024 * 1024) return 'To copy the look of a post, the picture must be 5 MB or smaller.';
            return '';
        },
        friendly(e, fallback) {
            const data = (e && e.data && typeof e.data === 'object') ? e.data : null;
            if (data && data.usage) this.usage = data.usage;
            if (e && e.status === 429 && !(data && data.usage)) return 'You are going a little fast — wait a minute and try again.';
            if (data) return data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : '') || fallback;
            if (e && e.status) return fallback;
            return 'We could not reach the server. Nothing was counted — please try again.';
        },
        async generate() {
            if (this.loading) return;
            const problem = this.pictureProblem();
            if (problem) { this.error = problem; return; }
            this.closeViewer(false);
            this.loading = true; this.error = ''; this.options = []; this.generationId = null;
            const mode = this.pictureMode();
            try {
                // "Put this picture in my ad": the picture is stored with the agency's company ad pictures first (once per file).
                if (mode === 'place' && !this.uploadedId) {
                    this.uploading = true;
                    const up = new FormData(); up.append('file', this.file);
                    const res = await window.CoreX.api.fetch(cfg.upload, { method: 'POST', body: up });
                    this.uploading = false;
                    if (!res.ok) { this.error = res.message || 'The picture could not be uploaded. Please try again.'; return; }
                    this.uploadedId = res.id;
                }
                const body = new FormData();
                body.append('idea', this.idea); body.append('occasion', this.occasion);
                body.append('ad_size', this.size); body.append('style', this.style); body.append('picture', mode);
                if (mode === 'library') body.append('library_picture', this.libraryPicture);
                if (mode === 'place') body.append('company_ad_image_id', this.uploadedId);
                if (mode === 'look') body.append('look_image', this.file);
                this.must.forEach(k => body.append('must_include[]', k));

                const data = await window.CoreX.api.fetch(cfg.generate, { method: 'POST', body });
                if (data.usage) this.usage = data.usage;
                if (!data.ok) {
                    this.error = data.message || 'Something went wrong. Nothing was counted — please try again.';
                } else {
                    this.generationId = data.generation_id;
                    this.options = data.options.map(o => ({ ...o, caption: o.caption || '', saving: false, saved: false, editUrl: '', err: '', savedLayout: null }));
                    this.$nextTick(() => {
                        const b = this.$refs.body, r = this.$refs.results;
                        if (b && r) b.scrollTo({ top: b.scrollTop + r.getBoundingClientRect().top - b.getBoundingClientRect().top - 8, behavior: 'smooth' });
                    });
                }
            } catch (e) {
                this.error = this.friendly(e, 'Something went wrong. Nothing was counted — please try again.');
            } finally { this.loading = false; this.uploading = false; }
        },
        layoutFor(opt) { return opt.savedLayout || opt.layout; },
        sampleFor() { return cfg.brand; },
        async save(opt) {
            opt.saving = true; opt.err = '';
            try {
                const data = await window.CoreX.api.fetch(cfg.save.replace('__GEN__', this.generationId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ option: opt.index, name: opt.name, caption: opt.caption }),
                });
                if (data.ok) { opt.saved = true; opt.editUrl = data.edit_url; opt.savedLayout = data.layout; opt.name = data.name; this.savedAny = true; }
                else { opt.err = data.message || 'Could not save this ad — please try again.'; }
            } catch (e) {
                opt.err = this.friendly(e, 'Could not save this ad — please try again.');
            }
            finally { opt.saving = false; }
        },
        // The list page draws and saves the picture (same capture path as its own Download buttons).
        download(opt) {
            window.dispatchEvent(new CustomEvent('company-ad-download', { detail: { name: opt.name, layout: this.layoutFor(opt) } }));
        },
    });
}
</script>
