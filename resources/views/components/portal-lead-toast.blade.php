{{--
    Portal Leads — real-time toast notifier.
    Spec: .ai/specs/portal-leads.md (Step 6)

    No realtime stack exists; this uses a 30-second poll against
    /real-estate/portal-leads/poll which returns leads scoped by the
    authenticated user's agency (via the AgencyScope global) that have
    notified_at IS NULL. Each toast click marks the lead notified.
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
            class="pointer-events-auto rounded-md shadow-lg p-3 text-sm"
            style="background:#fff; border-left:4px solid #0ea5e9; min-width: 280px;"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <span
                            class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold text-white"
                            :style="lead.portal === 'p24' ? 'background:#ef4444' : 'background:#3b82f6'"
                            x-text="lead.portal === 'p24' ? 'P24' : 'PP'"
                        ></span>
                        <span class="text-[11px] uppercase tracking-wide text-gray-500" x-text="lead.lead_type"></span>
                    </div>
                    <div class="font-semibold text-gray-800" x-text="lead.name"></div>
                    <div class="text-xs text-gray-600" x-text="lead.phone || lead.email || '—'"></div>
                    <div class="text-[11px] mt-1"
                         :class="lead.contact_exists ? 'text-amber-700' : 'text-emerald-700'"
                         x-text="lead.contact_exists ? 'Already exists' : 'New contact created'"></div>
                    <div class="text-[10px] text-gray-400 mt-1">
                        Property:
                        <span x-text="lead.listing_id ? ('#' + lead.listing_id) : (lead.listing_portal_ref || '—')"></span>
                    </div>
                </div>
                <button
                    type="button"
                    class="text-gray-400 hover:text-gray-700 text-lg leading-none"
                    @click="dismiss(lead)"
                    aria-label="Dismiss"
                >&times;</button>
            </div>
            <div class="mt-2 flex justify-end">
                <a :href="lead.view_url" @click="dismiss(lead)"
                   class="text-xs font-semibold text-blue-600 hover:underline">View Lead →</a>
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
        markUrlTemplate: '{{ url('real-estate/portal-leads') }}/__ID__/mark-notified',
        intervalMs: 30000,
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
                for (const lead of (data.leads || [])) {
                    if (this.seen.has(lead.id)) continue;
                    this.seen.add(lead.id);
                    this.toasts.push(lead);
                    this.markNotified(lead.id);
                    setTimeout(() => this.autoDismiss(lead.id), 20000);
                }
            } catch (e) {
                // silent — surface in console only
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
    };
};
</script>
@endpush
@endif
@endauth
