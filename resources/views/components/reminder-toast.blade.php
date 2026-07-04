{{--
    Event Reminders — global popup toast (AT-178).
    Spec: .ai/specs/calendar-event-reminders.md §6
    Design: UI_DESIGN_SYSTEM.md — CSS vars, rounded-md, 300ms transitions.

    Renders on EVERY authenticated CoreX page (injected in layouts/corex.blade.php
    and layouts/corex-app.blade.php) so a due reminder finds the agent wherever they
    are — loading a property, in a deal, anywhere. Polls the self-scoped due-reminders
    endpoint every ~60s (guarded by !document.hidden) plus immediately on window focus /
    tab visibility, mirroring the calendar RAG-refresh pattern (that poll is NOT touched).
    Each reminder can be dismissed, snoozed 10 min, or clicked through to the event.
--}}
@auth
<div
    x-data="reminderToast()"
    x-init="start()"
    class="fixed bottom-4 right-4 z-[9999] space-y-2 max-w-sm pointer-events-none"
    aria-live="polite"
>
    <template x-for="r in toasts" :key="r.id">
        <div
            class="pointer-events-auto rounded-md p-3 text-sm shadow-lg transition-all duration-300"
            style="
                background: var(--surface, #ffffff);
                border: 1px solid var(--border, #e2e8f0);
                border-left: 3px solid var(--brand-default, #1a365d);
                min-width: 300px;
                color: var(--text-primary, #1a202c);
            "
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span
                            class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                            style="background: var(--brand-default, #1a365d);"
                        >REMINDER</span>
                        <span class="text-[10px] uppercase tracking-wider"
                              style="color: var(--text-muted, #718096);"
                              x-text="r.lead_label"></span>
                    </div>
                    <div class="font-semibold truncate" style="color: var(--text-primary, #1a202c);" x-text="r.title"></div>
                    <div class="text-xs" style="color: var(--text-secondary, #4a5568);" x-text="r.when_h"></div>
                    <template x-if="r.property">
                        <div class="text-[11px] mt-0.5 truncate" style="color: var(--text-muted, #718096);">
                            📍 <span x-text="r.property"></span>
                        </div>
                    </template>
                </div>
                <button
                    type="button"
                    class="text-lg leading-none transition-all duration-300"
                    style="color: var(--text-muted, #718096);"
                    @click="dismiss(r)"
                    aria-label="Dismiss"
                >&times;</button>
            </div>
            <div class="mt-2 flex justify-end gap-3 items-center">
                <button type="button" @click="snooze(r)"
                        class="text-xs font-medium transition-all duration-300"
                        style="color: var(--text-muted, #718096);">Snooze 10 min</button>
                <a :href="r.view_url" @click="markRead(r)"
                   class="text-xs font-semibold transition-all duration-300"
                   style="color: var(--brand-default, #1a365d);">View event →</a>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
window.reminderToast = function () {
    return {
        toasts: [],
        chimed: new Set(),
        dueUrl: '{{ route('v1.command-center.reminders.due') }}',
        actionTemplate: '{{ url('/api/v1/command-center/reminders') }}/__ID__',
        intervalMs: {{ (int) ($reminderPollSeconds ?? 60) }} * 1000,
        timer: null,

        start() {
            this.poll();
            this.timer = setInterval(() => { if (!document.hidden) this.poll(); }, this.intervalMs);
            window.addEventListener('focus', () => this.poll());
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
        },

        async poll() {
            try {
                const data = window.CoreX && window.CoreX.api
                    ? await window.CoreX.api.fetch(this.dueUrl)
                    : await (await fetch(this.dueUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })).json();

                const list = (data && data.reminders) ? data.reminders : [];
                // Server is the source of truth (handles read + snooze re-surface):
                // replace the visible set with the currently-due list.
                this.toasts = list;
                // Chime once per newly-seen reminder id.
                let isNew = false;
                for (const r of list) {
                    if (!this.chimed.has(r.id)) { this.chimed.add(r.id); isNew = true; }
                }
                if (isNew) this.chime();
            } catch (e) {
                console.warn('Reminder poll failed', e);
            }
        },

        async postAction(id, action) {
            const url = this.actionTemplate.replace('__ID__', id) + '/' + action;
            try {
                if (window.CoreX && window.CoreX.api) {
                    await window.CoreX.api.fetch(url, { method: 'POST' });
                } else {
                    await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });
                }
            } catch (e) { /* swallow — next poll reconciles */ }
        },

        remove(r) { this.toasts = this.toasts.filter(t => t.id !== r.id); },

        dismiss(r)  { this.remove(r); this.postAction(r.id, 'read'); },
        markRead(r) { this.remove(r); this.postAction(r.id, 'read'); },  // link still navigates
        snooze(r)   { this.remove(r); this.postAction(r.id, 'snooze'); },

        chime() {
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                const ctx = new AC();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.type = 'sine';
                o.frequency.setValueAtTime(660, ctx.currentTime);
                o.frequency.exponentialRampToValueAtTime(990, ctx.currentTime + 0.15);
                g.gain.setValueAtTime(0.0001, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.22, ctx.currentTime + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
                o.start(); o.stop(ctx.currentTime + 0.45);
            } catch (e) { /* autoplay blocked — silent */ }
        },
    };
};
</script>
@endpush
@endauth
