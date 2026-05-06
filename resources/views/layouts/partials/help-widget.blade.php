{{-- HELP_WIDGET — Combined Ellie + Feedback panel, triggered from sidebar icon --}}
<div x-data="helpWidget()" @keydown.escape.window="open = false" id="help-widget-root">

    {{-- Header icon button — rendered via x-teleport into #help-widget-slot --}}
    <template x-teleport="#help-widget-slot">
        <button type="button" @click="toggle()" :aria-expanded="open" aria-label="Help & Feedback"
                class="relative flex items-center justify-center w-9 h-9 rounded-lg transition-colors"
                :style="open ? 'background:var(--brand-button);color:#fff;' : 'background:transparent;color:var(--text-muted);'"
                style="border:0;cursor:pointer;">
            {{-- Sparkle / chat icon --}}
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
            </svg>
        </button>
    </template>

    {{-- Panel (teleported to body for z-index safety) --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[9998]" @click.self="open = false">
            {{-- Panel container — anchored top-left, beside sidebar --}}
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-x-2"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 -translate-x-2"
                 @click.stop
                 class="help-panel fixed flex flex-col shadow-2xl rounded-lg overflow-hidden"
                 style="top:8px; left:16px; width:420px; max-width:calc(100vw - 24px); height:640px; max-height:calc(100vh - 24px); background:var(--surface); border:1px solid var(--border); z-index:9999;">

                {{-- Panel header with tabs --}}
                <div class="flex items-center justify-between px-1 flex-shrink-0" style="background:var(--surface-2); border-bottom:1px solid var(--border); height:44px;">
                    <div class="flex items-center h-full" role="tablist">
                        <button type="button" role="tab" :aria-selected="tab === 'ellie'" @click="tab = 'ellie'"
                                class="h-full px-4 text-xs font-semibold transition-colors relative"
                                :style="tab === 'ellie' ? 'color:var(--text-primary);' : 'color:var(--text-muted);'">
                            <span class="flex items-center gap-1.5">
                                <img src="/images/ellie-32-circle.png" alt="" class="w-4 h-4 rounded-full">
                                Ellie
                            </span>
                            <span x-show="tab === 'ellie'" class="absolute bottom-0 left-2 right-2 h-0.5 rounded-t" style="background:var(--brand-button);"></span>
                        </button>
                        <button type="button" role="tab" :aria-selected="tab === 'feedback'" @click="tab = 'feedback'; captureFeedbackContext()"
                                class="h-full px-4 text-xs font-semibold transition-colors relative"
                                :style="tab === 'feedback' ? 'color:var(--text-primary);' : 'color:var(--text-muted);'">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                                Feedback
                            </span>
                            <span x-show="tab === 'feedback'" class="absolute bottom-0 left-2 right-2 h-0.5 rounded-t" style="background:var(--brand-button);"></span>
                        </button>
                    </div>
                    <button type="button" @click="open = false" aria-label="Close" class="px-3 text-lg leading-none transition-colors" style="color:var(--text-muted); background:transparent; border:0; cursor:pointer;" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">&times;</button>
                </div>

                {{-- ═══════════ ELLIE TAB ═══════════ --}}
                <div x-show="tab === 'ellie'" class="flex flex-col flex-1 min-h-0">
                    {{-- Messages --}}
                    <div x-ref="ellieMessages" class="flex-1 overflow-y-auto p-3 space-y-2" style="background:var(--bg);">
                        {{-- Welcome message (shown when empty) --}}
                        <template x-if="ellieMessages.length === 0">
                            <div class="flex items-start gap-2 py-2">
                                <img src="/images/ellie-32-circle.png" alt="" class="w-6 h-6 rounded-full flex-shrink-0 mt-0.5">
                                <div class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                                    Hi, I'm Ellie — your CoreX companion. I know SA real estate law, your data, and how CoreX works. Ask me anything about this page or your work.
                                </div>
                            </div>
                        </template>
                        {{-- Chat history --}}
                        <template x-for="(msg, idx) in ellieMessages" :key="idx">
                            <div class="flex" :class="msg.who === 'me' ? 'justify-end' : 'justify-start'">
                                <div class="max-w-[85%] px-3 py-2 rounded-lg text-[13px] leading-relaxed whitespace-pre-wrap"
                                     :style="msg.who === 'me'
                                         ? 'background:var(--brand-button);color:#fff;'
                                         : 'background:var(--surface);border:1px solid var(--border);color:var(--text-primary);'"
                                     x-text="msg.text"></div>
                            </div>
                        </template>
                        {{-- Typing indicator --}}
                        <div x-show="ellieTyping" x-cloak class="flex items-center gap-2 py-1">
                            <img src="/images/ellie-32-circle.png" alt="" class="w-5 h-5 rounded-full">
                            <span class="text-xs animate-pulse" style="color:var(--text-muted);">Ellie is typing...</span>
                        </div>
                        {{-- Error state --}}
                        <template x-if="ellieError">
                            <div class="text-xs p-2 rounded" style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);">
                                <span x-text="ellieError"></span>
                                <button type="button" @click="tab = 'feedback'; captureFeedbackContext()" class="underline ml-1" style="color:var(--brand-button);">Send feedback instead</button>
                            </div>
                        </template>
                    </div>
                    {{-- Input --}}
                    <div class="flex gap-2 p-3 flex-shrink-0" style="border-top:1px solid var(--border); background:var(--surface);">
                        <input type="text" x-model="ellieInput" @keydown.enter.prevent="sendEllie()" placeholder="Message Ellie..." autocomplete="off"
                               class="flex-1 rounded-md px-3 py-2 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        <button type="button" @click="sendEllie()" :disabled="!ellieInput.trim() || ellieBusy" class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity disabled:opacity-40" style="background:var(--brand-button);color:#fff;">Send</button>
                    </div>
                    {{-- Footer --}}
                    <div class="px-3 py-1.5 text-[10px] flex-shrink-0" style="color:var(--text-muted);border-top:1px solid var(--border);background:var(--surface);">
                        Ellie knows CoreX, SA real estate law, and your data. Ask anything.
                    </div>
                </div>

                {{-- ═══════════ FEEDBACK TAB ═══════════ --}}
                <div x-show="tab === 'feedback'" class="flex flex-col flex-1 min-h-0">
                    {{-- Confirmation state --}}
                    <template x-if="feedbackSent">
                        <div class="flex-1 flex flex-col items-center justify-center p-6 text-center" style="background:var(--bg);">
                            <svg class="w-12 h-12 mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:#00d4aa;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <div class="text-sm font-semibold mb-1" style="color:var(--text-primary);">Feedback received — thank you.</div>
                            <div class="text-xs mb-4" style="color:var(--text-muted);">Your team has been notified.</div>
                            <button type="button" @click="resetFeedback()" class="text-xs underline" style="color:var(--brand-button);">Send another</button>
                        </div>
                    </template>

                    {{-- Form --}}
                    <template x-if="!feedbackSent">
                        <div class="flex-1 overflow-y-auto p-3 space-y-3" style="background:var(--bg);">
                            {{-- Auto-captured context --}}
                            <div class="text-[10px] space-y-0.5 p-2 rounded" style="background:var(--surface-2); color:var(--text-muted);">
                                <div>Page: <span x-text="fbCtx.pageTitle" style="color:var(--text-secondary);"></span></div>
                                <div>URL: <span x-text="fbCtx.pageUrl" style="color:var(--text-secondary);"></span></div>
                                <div>Time: <span x-text="fbCtx.capturedAt" style="color:var(--text-secondary);"></span></div>
                            </div>
                            {{-- Type --}}
                            <div class="flex gap-1.5 flex-wrap">
                                <template x-for="t in ['bug','enhancement','question','compliment','other']" :key="t">
                                    <label class="text-[11px] cursor-pointer px-2 py-1 rounded transition-colors"
                                           :style="fbForm.type === t ? 'background:var(--brand-button);color:#fff;font-weight:600;' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);'">
                                        <input type="radio" :value="t" x-model="fbForm.type" class="sr-only">
                                        <span x-text="t.charAt(0).toUpperCase() + t.slice(1)"></span>
                                    </label>
                                </template>
                            </div>
                            {{-- Severity (bug only) --}}
                            <div x-show="fbForm.type === 'bug'" class="flex gap-1.5">
                                <template x-for="s in ['critical','major','minor']" :key="s">
                                    <label class="text-[10px] cursor-pointer px-2 py-0.5 rounded"
                                           :style="fbForm.severity === s ? 'background:#ef4444;color:#fff;' : 'background:var(--surface-2);color:var(--text-muted);'">
                                        <input type="radio" :value="s" x-model="fbForm.severity" class="sr-only">
                                        <span x-text="s.charAt(0).toUpperCase() + s.slice(1)"></span>
                                    </label>
                                </template>
                            </div>
                            {{-- Title --}}
                            <input type="text" x-model="fbForm.title" placeholder="Title (required)" maxlength="200"
                                   class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                   style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
                            {{-- Description --}}
                            <textarea x-model="fbForm.description" placeholder="Describe in detail (required)" rows="3"
                                      class="w-full rounded-md px-3 py-2 text-sm outline-none resize-none"
                                      style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></textarea>
                            {{-- Screenshot --}}
                            <div class="flex items-center gap-2">
                                <button type="button" @click="captureScreenshot()" :disabled="fbCapturing" class="text-[11px] px-2 py-1 rounded flex items-center gap-1 transition-colors" style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                                    <span x-show="!fbCapturing && !fbScreenshot">Capture Screenshot</span>
                                    <span x-show="fbCapturing" x-cloak x-text="fbCaptureStatus || 'Capturing...'"></span>
                                    <span x-show="fbScreenshot && !fbCapturing" x-cloak>Recapture</span>
                                </button>
                                <template x-if="fbScreenshot">
                                    <div class="flex items-center gap-1">
                                        <span class="text-[10px]" style="color:#22c55e;">Attached</span>
                                        <button type="button" @click="fbScreenshot = null" class="text-[10px]" style="color:#ef4444;">&times;</button>
                                    </div>
                                </template>
                            </div>
                            <template x-if="fbScreenshot">
                                <div class="rounded overflow-hidden" style="border:1px solid var(--border); max-height:100px;">
                                    <img :src="fbScreenshot" class="w-full object-cover object-top" style="max-height:100px;">
                                </div>
                            </template>
                            {{-- Optional fields --}}
                            <details class="text-[11px]" style="color:var(--text-muted);">
                                <summary class="cursor-pointer">More fields (optional)</summary>
                                <div class="mt-2 space-y-2">
                                    <textarea x-model="fbForm.steps_to_reproduce" placeholder="Steps to reproduce: 1. ..." rows="2" class="w-full rounded-md px-2 py-1.5 text-xs outline-none resize-none" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></textarea>
                                    <textarea x-model="fbForm.expected_behaviour" placeholder="Expected behaviour" rows="2" class="w-full rounded-md px-2 py-1.5 text-xs outline-none resize-none" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></textarea>
                                    <textarea x-model="fbForm.actual_behaviour" placeholder="Actual behaviour" rows="2" class="w-full rounded-md px-2 py-1.5 text-xs outline-none resize-none" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);"></textarea>
                                </div>
                            </details>
                            {{-- Submit --}}
                            <div class="flex justify-end pt-1">
                                <button type="button" @click="submitFeedback()" :disabled="fbSubmitting || !fbForm.title || !fbForm.description"
                                        class="text-xs font-semibold px-4 py-2 rounded-md transition-opacity disabled:opacity-40"
                                        style="background:var(--brand-button);color:#fff;">
                                    <span x-show="!fbSubmitting">Send Feedback</span>
                                    <span x-show="fbSubmitting" x-cloak>Sending...</span>
                                </button>
                            </div>
                        </div>
                    </template>
                    {{-- Footer --}}
                    <div class="px-3 py-1.5 text-[10px] flex-shrink-0" style="color:var(--text-muted);border-top:1px solid var(--border);background:var(--surface);">
                        Sends to your CoreX admin team
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function helpWidget() {
    const CONVO_KEY = 'ELLIE_CONVO_ID';
    const TAB_KEY = 'help_widget_tab';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    return {
        open: false,
        tab: localStorage.getItem(TAB_KEY) || 'ellie',

        // Ellie state
        ellieMessages: [],
        ellieInput: '',
        ellieBusy: false,
        ellieTyping: false,
        ellieError: null,

        // Feedback state
        fbForm: { type: 'bug', severity: 'major', title: '', description: '', steps_to_reproduce: '', expected_behaviour: '', actual_behaviour: '' },
        fbCtx: { pageUrl: '', pageTitle: '', capturedAt: '' },
        fbScreenshot: null,
        fbCapturing: false,
        fbCaptureStatus: '',
        fbSubmitting: false,
        feedbackSent: false,
        _feedbackTimer: null,

        init() {
            this.$watch('tab', (val) => localStorage.setItem(TAB_KEY, val));
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.captureFeedbackContext();
                this.$nextTick(() => {
                    if (this.tab === 'ellie') {
                        const inp = this.$root.querySelector('input[placeholder="Message Ellie..."]');
                        if (inp) inp.focus();
                    }
                    this.scrollEllie();
                });
            }
        },

        // ─── Ellie ────────────────────────────────────────────────
        async sendEllie() {
            const msg = (this.ellieInput || '').trim();
            if (!msg || this.ellieBusy) return;

            this.ellieMessages.push({ who: 'me', text: msg });
            this.ellieInput = '';
            this.ellieBusy = true;
            this.ellieTyping = true;
            this.ellieError = null;
            this.scrollEllie();

            try {
                let convoId = localStorage.getItem(CONVO_KEY);

                const doPost = async (cid) => {
                    const fd = new FormData();
                    fd.append('_token', csrf);
                    fd.append('message', msg);
                    fd.append('page_url', window.location.href);
                    fd.append('page_title', document.title);
                    if (cid) fd.append('conversation_id', String(parseInt(cid, 10)));
                    const r = await fetch('/ellie/send', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
                        body: fd
                    });
                    const raw = await r.text();
                    let data = null;
                    try { data = JSON.parse(raw); } catch(e) {}
                    return { r, raw, data };
                };

                let out = await doPost(convoId);

                if (!out.r.ok && out.r.status === 404 && (out.raw || '').includes('AiConversation')) {
                    localStorage.removeItem(CONVO_KEY);
                    out = await doPost(null);
                }

                if (!out.r.ok) {
                    const errMsg = out.data?.message || out.data?.error || (out.raw || '').slice(0, 200);
                    throw new Error('HTTP ' + out.r.status + (errMsg ? ': ' + errMsg : ''));
                }

                const data = out.data || {};
                if (data.conversation_id) localStorage.setItem(CONVO_KEY, String(data.conversation_id));

                this.ellieTyping = false;
                this.ellieMessages.push({ who: 'ellie', text: data.reply || 'Sorry, I got no reply.' });
            } catch (err) {
                this.ellieTyping = false;
                this.ellieError = 'Ellie is unavailable right now. (' + (err?.message || 'unknown') + ')';
                this.ellieMessages.push({ who: 'ellie', text: 'Sorry — I hit an error. Try again or send feedback.' });
            } finally {
                this.ellieBusy = false;
                this.scrollEllie();
            }
        },

        scrollEllie() {
            this.$nextTick(() => {
                const el = this.$refs.ellieMessages;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        // ─── Feedback ─────────────────────────────────────────────
        captureFeedbackContext() {
            this.fbCtx = {
                pageUrl: window.location.href,
                pageTitle: document.title,
                capturedAt: new Date().toLocaleString('en-ZA'),
            };
        },

        async captureScreenshot() {
            if (typeof html2canvas === 'undefined') {
                alert('Screenshot library not loaded yet. Please wait a moment and try again.');
                return;
            }
            this.fbCapturing = true;
            this.fbCaptureStatus = 'Capturing...';
            const slowTimer = setTimeout(() => { this.fbCaptureStatus = 'Still capturing (complex page)...'; }, 3000);
            try {
                const canvas = await html2canvas(document.body, {
                    scale: 1,
                    useCORS: true,
                    allowTaint: true,
                    foreignObjectRendering: false,
                    logging: false,
                    removeContainer: true,
                    backgroundColor: '#0f172a',
                    ignoreElements: (el) => {
                        if (!el || !el.tagName) return false;
                        if (el.id === 'help-widget-root') return true;
                        const tag = el.tagName.toLowerCase();
                        if (tag === 'template') return true;
                        try { if (el.closest?.('.z-\\[9998\\],.z-\\[9999\\]')) return true; } catch(e) {}
                        const z = el.style?.zIndex;
                        if (z && parseInt(z) >= 9998) return true;
                        return false;
                    },
                });
                this.fbScreenshot = canvas.toDataURL('image/png');
            } catch (e) {
                console.error('Screenshot capture failed:', e);
                const msg = e?.message || String(e);
                alert('Screenshot capture failed: ' + msg.slice(0, 200) + '.\n\nYou can still submit feedback without a screenshot.');
            }
            clearTimeout(slowTimer);
            this.fbCaptureStatus = '';
            this.fbCapturing = false;
        },

        async submitFeedback() {
            this.fbSubmitting = true;
            try {
                const ua = navigator.userAgent;
                const payload = {
                    ...this.fbForm,
                    page_url: this.fbCtx.pageUrl,
                    page_title: this.fbCtx.pageTitle,
                    browser: ua.slice(0, 100),
                    os: navigator.platform,
                    viewport_width: window.innerWidth,
                    viewport_height: window.innerHeight,
                };
                if (this.fbScreenshot) payload.screenshot_base64 = this.fbScreenshot;

                const r = await fetch('/corex/command-center/feedback', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify(payload),
                });

                if (r.ok) {
                    this.feedbackSent = true;
                    this.fbScreenshot = null;
                    // Show toast
                    if (window.dispatchEvent) {
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Feedback received — thank you!', type: 'success' } }));
                    }
                    // Auto-reset after 5s
                    this._feedbackTimer = setTimeout(() => this.resetFeedback(), 5000);
                } else {
                    alert('Failed to submit. Please try again.');
                }
            } catch (e) {
                alert('Network error.');
            }
            this.fbSubmitting = false;
        },

        resetFeedback() {
            if (this._feedbackTimer) clearTimeout(this._feedbackTimer);
            this._feedbackTimer = null;
            this.feedbackSent = false;
            this.fbForm = { type: 'bug', severity: 'major', title: '', description: '', steps_to_reproduce: '', expected_behaviour: '', actual_behaviour: '' };
            this.fbScreenshot = null;
        },
    };
}
</script>
{{-- /HELP_WIDGET --}}
