{{--
    Portal Leads — real-time toast notifier.
    Spec: .ai/specs/portal-leads.md (Step 6)
    Styling: .ai/DESIGN-SYSTEM.md — rounded-md, CSS variables, 300ms transitions.

    Polls /corex/real-estate/portal-leads/poll every 30s; each lead is shown
    once then marked notified server-side so it never re-appears.
--}}
@auth
@if(auth()->user()->hasPermission('access_portal_leads'))
<div
    x-data="portalLeadToast()"
    x-init="start()"
    class="fixed bottom-4 right-4 z-[9999] space-y-2 max-w-sm pointer-events-none"
>
    <template x-for="lead in toasts" :key="lead.id">
        <div
            class="pointer-events-auto rounded-md p-3 text-sm shadow-lg transition-all duration-300"
            style="
                background: var(--surface);
                border: 1px solid var(--border);
                border-left: 3px solid var(--brand-icon, #0ea5e9);
                min-width: 280px;
                color: var(--text-primary);
            "
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span
                            class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                            :style="lead.portal === 'p24'
                                ? 'background: var(--brand-default, #0b2a4a);'
                                : (lead.portal === 'website'
                                    ? 'background: var(--brand-icon, #0ea5e9);'
                                    : 'background: var(--brand-button, #0ea5e9);')"
                            x-text="lead.portal === 'p24' ? 'P24' : (lead.portal === 'website' ? 'WEB' : 'PP')"
                        ></span>
                        <span class="text-[10px] uppercase tracking-wider"
                              style="color: var(--text-muted);"
                              x-text="lead.lead_type"></span>
                    </div>
                    <div class="font-semibold truncate" style="color: var(--text-primary);" x-text="lead.name"></div>
                    <div class="text-xs truncate" style="color: var(--text-secondary);"
                         x-text="lead.phone || lead.email || '—'"></div>
                    <div class="text-[11px] mt-1 font-medium"
                         :style="lead.contact_exists
                            ? 'color: var(--text-secondary);'
                            : 'color: var(--brand-icon, #0ea5e9);'"
                         x-text="lead.contact_exists ? 'Existing contact' : 'New contact created'"></div>
                    <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                        Property
                        <span x-text="lead.listing_id ? ('#' + lead.listing_id) : (lead.listing_portal_ref || '—')"></span>
                    </div>
                </div>
                <button
                    type="button"
                    class="text-lg leading-none transition-all duration-300"
                    style="color: var(--text-muted);"
                    @mouseover="$el.style.color = 'var(--text-primary)'"
                    @mouseleave="$el.style.color = 'var(--text-muted)'"
                    @click="dismiss(lead)"
                    aria-label="Dismiss"
                >&times;</button>
            </div>
            <div class="mt-2 flex justify-end">
                <a :href="lead.view_url" @click="dismiss(lead)"
                   class="text-xs font-semibold transition-all duration-300"
                   style="color: var(--brand-icon, #0ea5e9);">View Lead →</a>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
window.portalLeadToast = function () {
    return {
        toasts: [],
        seen: new Set(),
        pollUrl: '{{ route('corex.portal-leads.poll') }}',
        markUrlTemplate: '{{ route('corex.portal-leads.mark-notified', ['portalLead' => '__ID__']) }}',
        intervalMs: 10000,
        timer: null,

        start() {
            this.poll();
            this.timer = setInterval(() => this.poll(), this.intervalMs);
        },

        async poll() {
            try {
                const res = await fetch(this.pollUrl, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();
                let hadNew = false;
                for (const lead of (data.leads || [])) {
                    if (this.seen.has(lead.id)) continue;
                    this.seen.add(lead.id);
                    this.toasts.push(lead);
                    this.markNotified(lead.id);
                    setTimeout(() => this.autoDismiss(lead.id), 20000);
                    hadNew = true;
                }
                if (hadNew) this.chime();
            } catch (e) {
                console.warn('Portal lead poll failed', e);
            }
        },

        async markNotified(id) {
            try {
                await fetch(this.markUrlTemplate.replace('__ID__', id), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                });
            } catch (e) { /* swallow */ }
        },

        dismiss(lead) {
            this.toasts = this.toasts.filter(t => t.id !== lead.id);
        },

        autoDismiss(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
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
                o.frequency.setValueAtTime(880, ctx.currentTime);
                o.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.15);
                g.gain.setValueAtTime(0.0001, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
                o.start(); o.stop(ctx.currentTime + 0.45);
            } catch (e) { /* browsers that block autoplay — silent fail */ }
        },
    };
};
</script>
@endpush
@endif
@endauth
