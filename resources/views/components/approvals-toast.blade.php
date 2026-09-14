{{--
    Approvals waiting — global popup toast.
    Spec: .ai/specs/esign-compliance-approval-gate.md §8.5
    Design: UI_DESIGN_SYSTEM.md — CSS vars, rounded-md, 300ms transitions.

    A copy of components/reminder-toast.blade.php (the better-behaved of the two existing toasts):
    polls the self-scoped /api/v1/approvals/pending feed every ~60s (guarded by !document.hidden)
    plus immediately on focus / visibility. Items are new FICA packs, held e-sign documents and
    compliance reports the viewer may decide. There is no server "seen" state — dismissal is
    remembered in this browser only (localStorage), and nothing is promised as instant.
--}}
@auth
<div
    x-data="approvalsToast()"
    x-init="start()"
    class="fixed bottom-4 left-4 z-[9998] space-y-2 max-w-sm pointer-events-none"
    aria-live="polite"
>
    <template x-for="r in toasts" :key="r.id">
        <div
            class="pointer-events-auto rounded-md p-3 text-sm shadow-lg transition-all duration-300"
            style="
                background: var(--surface, #ffffff);
                border: 1px solid var(--border, #e2e8f0);
                border-left: 3px solid var(--ds-amber, #f59e0b);
                min-width: 300px;
                color: var(--text-primary, #1a202c);
            "
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                              style="background: var(--ds-amber, #f59e0b);"
                              x-text="r.label"></span>
                        <span class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted, #718096);">Waiting for you</span>
                    </div>
                    <div class="font-semibold truncate" style="color: var(--text-primary, #1a202c);" x-text="r.title"></div>
                    <div class="text-xs" style="color: var(--text-secondary, #4a5568);" x-text="r.body"></div>
                </div>
                <button type="button" class="text-lg leading-none transition-all duration-300"
                        style="color: var(--text-muted, #718096);" @click="dismiss(r)" aria-label="Dismiss">&times;</button>
            </div>
            <div class="mt-2 flex justify-end gap-3 items-center">
                <a :href="r.url" @click="dismiss(r)" class="text-xs font-semibold transition-all duration-300"
                   style="color: var(--brand-default, #1a365d);">Open →</a>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
window.approvalsToast = function () {
    return {
        toasts: [],
        chimed: new Set(),
        dismissed: new Set(),
        feedUrl: '{{ route('api.v1.approvals.pending') }}',
        intervalMs: 60 * 1000,
        storageKey: 'corex.approvals.dismissed',
        timer: null,

        start() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey) || '[]');
                if (Array.isArray(saved)) saved.forEach(id => this.dismissed.add(id));
            } catch (e) { /* storage unavailable — show everything */ }
            this.poll();
            this.timer = setInterval(() => { if (!document.hidden) this.poll(); }, this.intervalMs);
            window.addEventListener('focus', () => this.poll());
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
        },

        async poll() {
            try {
                const data = window.CoreX && window.CoreX.api
                    ? await window.CoreX.api.fetch(this.feedUrl)
                    : await (await fetch(this.feedUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })).json();

                const list = ((data && data.items) ? data.items : []).filter(r => !this.dismissed.has(r.id));
                this.toasts = list.slice(0, 5);
                let isNew = false;
                for (const r of list) {
                    if (!this.chimed.has(r.id)) { this.chimed.add(r.id); isNew = true; }
                }
                if (isNew) this.chime();
            } catch (e) {
                console.warn('Approvals poll failed', e);
            }
        },

        dismiss(r) {
            this.dismissed.add(r.id);
            this.toasts = this.toasts.filter(t => t.id !== r.id);
            try { localStorage.setItem(this.storageKey, JSON.stringify(Array.from(this.dismissed).slice(-200))); } catch (e) { /* ignore */ }
        },

        chime() {
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                const ctx = new AC();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.type = 'sine';
                o.frequency.setValueAtTime(520, ctx.currentTime);
                o.frequency.exponentialRampToValueAtTime(780, ctx.currentTime + 0.15);
                g.gain.setValueAtTime(0.0001, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
                o.start(); o.stop(ctx.currentTime + 0.45);
            } catch (e) { /* autoplay blocked — silent */ }
        },
    };
};
</script>
@endpush
@endauth
