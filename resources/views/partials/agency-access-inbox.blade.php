<?php
/**
 * Admin inbox for cross-agency access requests.
 * Polls /api/v1/agency-access/inbox every 5s. Renders in the header.
 *
 * Spec: .ai/specs/agency-access-authorization-spec.md
 */
?>
<div x-data="agencyAccessInbox({ csrfToken: '{{ csrf_token() }}' })"
     x-init="start()"
     style="position:relative;">
    <button type="button" @click="open = !open"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-md text-xs font-medium transition-colors"
            :style="requests.length > 0
                ? 'background:color-mix(in srgb, var(--ds-amber) 20%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent);'
                : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 flex-shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.063 2.522-.187 3.756-.247 2.456-2.236 4.387-4.694 4.66-1.681.187-3.388.282-5.119.282-1.731 0-3.438-.095-5.119-.282-2.458-.273-4.447-2.204-4.694-4.66A37.59 37.59 0 0 1 1 12c0-1.268.063-2.522.187-3.756.247-2.456 2.236-4.387 4.694-4.66A45.394 45.394 0 0 1 11 3c1.731 0 3.438.095 5.119.282 2.458.273 4.447 2.204 4.694 4.66.124 1.234.187 2.488.187 3.756Z" />
        </svg>
        <span class="flex-1 text-left">Remote Access</span>
        <span x-show="requests.length > 0" x-text="requests.length"
              class="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
              style="background:var(--ds-amber); color:#000;"></span>
    </button>

    {{-- Inbox modal (centered popup, teleported to <body> to escape sidebar transform) --}}
    <template x-teleport="body">
    <div x-show="open" x-cloak
         class="fixed inset-0 z-[1000] flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);">
        <div class="rounded-md w-full max-w-lg mx-4 flex flex-col"
             style="background:var(--surface); border:1px solid var(--border); max-height:80vh;"
             @click.outside="open = false">
            <div class="p-4 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
                <div>
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Remote Access Requests</h3>
                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">A system owner is asking to switch into your agency.</p>
                </div>
                <button type="button" @click="open = false"
                        class="text-xs" style="color:var(--text-muted);">✕</button>
            </div>

        <div class="p-3 space-y-2 overflow-y-auto">
            <template x-for="req in requests" :key="req.id">
                <div class="rounded-md p-3 space-y-2"
                     style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="req.requester.name"></div>
                        <div class="text-xs" style="color:var(--text-muted);">
                            <span x-text="req.requester.role"></span> · expires in <span x-text="countdownFor(req)" class="font-mono"></span>
                        </div>
                    </div>
                    <div x-show="req.reason" class="text-xs italic px-2 py-1.5 rounded"
                         style="background:var(--surface); color:var(--text-secondary);">
                        <span x-text="req.reason"></span>
                    </div>

                    {{-- Deny reason input (revealed) --}}
                    <div x-show="req._showDenyInput" x-cloak>
                        <textarea x-model="req._denyReason" rows="2" maxlength="500"
                                  placeholder="Reason (optional)"
                                  class="w-full rounded-md px-2 py-1.5 text-xs outline-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>

                    <div x-show="req._error" class="text-xs"
                         style="color:var(--ds-crimson);" x-text="req._error"></div>

                    <div class="flex items-center justify-end gap-2">
                        <template x-if="!req._showDenyInput">
                            <button type="button" @click="req._showDenyInput = true" :disabled="req._busy"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold"
                                    style="border:1px solid var(--border); color:var(--text-secondary);">
                                Deny
                            </button>
                        </template>
                        <template x-if="req._showDenyInput">
                            <button type="button" @click="decide(req, 'deny', req._denyReason)" :disabled="req._busy"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold text-white"
                                    style="background:var(--ds-crimson, #dc2626);">
                                Confirm Deny
                            </button>
                        </template>
                        <button type="button" @click="decide(req, 'approve', null)" :disabled="req._busy"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold text-white"
                                style="background:var(--ds-green, #059669);">
                            Approve
                        </button>
                    </div>
                </div>
            </template>

            <div x-show="requests.length === 0" class="text-xs px-3 py-6 text-center" style="color:var(--text-muted);">
                No pending requests.
            </div>
        </div>
        </div>
    </div>
    </template>
</div>

<script>
window.agencyAccessInbox = function (cfg) {
    return {
        open: false,
        requests: [],
        _poll: null,
        _tick: null,
        csrfToken: cfg.csrfToken,

        start() {
            this.refresh();
            this._poll = setInterval(() => this.refresh(), 5000);
            this._tick = setInterval(() => { /* Alpine reactive refresh */ this.requests = [...this.requests]; }, 1000);
        },

        async refresh() {
            try {
                const res = await fetch('/api/v1/agency-access/inbox', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (!data.ok) return;
                // Merge: keep _busy/_showDenyInput state for any request still in the list.
                const prev = Object.fromEntries(this.requests.map(r => [r.id, r]));
                this.requests = (data.requests || []).map(r => ({
                    ...r,
                    _busy: prev[r.id]?._busy || false,
                    _showDenyInput: prev[r.id]?._showDenyInput || false,
                    _denyReason: prev[r.id]?._denyReason || '',
                    _error: prev[r.id]?._error || '',
                }));
            } catch (e) { /* swallow */ }
        },

        countdownFor(req) {
            const ms = (new Date(req.expires_at)) - new Date();
            if (ms <= 0) return '0:00';
            const total = Math.floor(ms / 1000);
            const m = Math.floor(total / 60), s = total % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        },

        async decide(req, decision, reason) {
            if (req._busy) return;
            req._busy = true; req._error = '';
            try {
                const res = await fetch(`/api/v1/agency-access/${req.id}/authorize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ decision, denial_reason: reason || null }),
                });
                const data = await res.json();
                if (!data.ok) {
                    req._error = data.error || 'Failed.';
                    req._busy = false;
                    return;
                }
                // Drop from list — refresh will re-pull anyway
                this.requests = this.requests.filter(r => r.id !== req.id);
            } catch (e) {
                req._error = 'Network error.';
                req._busy = false;
            }
        },
    };
};
</script>
