<?php
/**
 * Agency switcher with cross-agency access consent flow.
 * Owner-role users only.
 *
 * Spec: .ai/specs/agency-access-authorization-spec.md
 */
?>
<div x-data="agencyAccessSwitcher({
        agencies: {{ $agencies->values()->map(fn($a) => [
            'id'   => $a->id,
            'name' => $a->name,
            'requires_auth' => (bool) $a->require_external_access_authorization,
        ])->toJson() }},
        accessGrants: {{ json_encode($accessGrants ?? (object) []) }},
        switchUrl: '{{ url('/corex') }}',
        directSwitchUrlBase: '{{ url('/agency/switch') }}',
        csrfToken: '{{ csrf_token() }}',
        activeAgencyId: {{ (int) ($activeAgencyId ?? 0) }},
        currentUserId: {{ (int) auth()->id() }}
     })">

    {{-- Sidebar dropdown trigger (original UX) --}}
    <div class="px-3 pb-2">
        <button type="button" @click="dropdownOpen = !dropdownOpen"
                class="w-full flex items-center gap-2 px-3 py-2 rounded-md text-xs font-medium transition-colors"
                style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 flex-shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
            </svg>
            <span class="flex-1 text-left truncate">{{ $activeAgency ? $activeAgency->name : 'All Agencies' }}</span>
            @if(!$activeAgency)
            <span class="w-2 h-2 rounded-full flex-shrink-0 animate-pulse" style="background:var(--ds-amber);"></span>
            @endif
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3 flex-shrink-0 transition-transform duration-150" :class="dropdownOpen && 'rotate-90'"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>
        <div x-show="dropdownOpen" x-cloak @click.outside="dropdownOpen = false" x-transition
             class="mt-1 rounded-md overflow-hidden shadow-lg"
             style="background:var(--surface-2, #1a1e28); border:1px solid var(--border);">
            <template x-for="ag in agencies" :key="ag.id">
                <button type="button" @click="onAgencyClick(ag); dropdownOpen = false"
                        class="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-[color:var(--surface)] flex items-center justify-between gap-2"
                        :class="activeAgencyId === ag.id ? 'font-semibold' : ''"
                        :style="activeAgencyId === ag.id ? 'color:var(--brand-icon, #0ea5e9);' : 'color:var(--text-secondary);'">
                    <span class="truncate" x-text="ag.name"></span>
                    <span class="flex items-center gap-1 flex-shrink-0">
                        {{-- Live 24h grant — show remaining time --}}
                        <span x-show="grantRemaining(ag.id)"
                              :title="'Access granted — ' + grantRemaining(ag.id) + ' remaining'"
                              class="text-[10px] px-1.5 py-0.5 rounded font-mono"
                              style="background:color-mix(in srgb, var(--ds-green) 20%, transparent); color:var(--ds-green);"
                              x-text="grantRemaining(ag.id)"></span>
                        {{-- Lock icon when consent required and no live grant --}}
                        <span x-show="ag.requires_auth && !grantRemaining(ag.id)"
                              title="Requires consent"
                              class="text-[10px] px-1.5 py-0.5 rounded"
                              style="background:color-mix(in srgb, var(--ds-amber) 20%, transparent); color:var(--ds-amber);">🔒</span>
                    </span>
                </button>
            </template>
        </div>
    </div>

    {{-- ── Admin picker modal (teleported to <body> to escape sidebar transform) ── --}}
    <template x-teleport="body">
    <div x-show="pickerOpen" x-cloak
         class="fixed inset-0 z-[1000] flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);">
        <div class="rounded-md w-full max-w-md mx-4 p-5 space-y-4"
             style="background:var(--surface); border:1px solid var(--border);"
             @click.outside="!busy && (pickerOpen = false)">
            <div>
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">
                    Request access to <span x-text="picker.agencyName"></span>
                </h3>
                <p class="text-xs mt-1" style="color:var(--text-secondary);">
                    This agency requires admin authorization. Pick which admin(s) to ask. Only those admins will see the request. The first to approve unlocks access for 24h.
                </p>
            </div>

            <div class="space-y-1 max-h-56 overflow-y-auto rounded-md p-2"
                 style="background:var(--surface-2); border:1px solid var(--border);">
                <template x-for="admin in picker.admins" :key="admin.id">
                    <label class="flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer hover:bg-white/5">
                        <input type="checkbox" :value="admin.id" x-model="picker.selected" class="h-4 w-4">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm truncate" style="color:var(--text-primary);" x-text="admin.name"></div>
                            <div class="text-xs truncate" style="color:var(--text-muted);" x-text="admin.email"></div>
                        </div>
                    </label>
                </template>
                <div x-show="!picker.admins || picker.admins.length === 0" class="text-xs px-2 py-3 text-center" style="color:var(--text-muted);">
                    No active admins to ask.
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">
                    Reason <span class="opacity-60">(optional)</span>
                </label>
                <textarea x-model="picker.reason" rows="2" maxlength="1000"
                          placeholder="e.g. Investigating ticket #1234"
                          class="w-full rounded-md px-3 py-2 text-sm outline-none"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
            </div>

            <div x-show="picker.error" class="text-xs px-3 py-2 rounded-md"
                 style="background:color-mix(in srgb, var(--ds-crimson) 15%, transparent); color:var(--ds-crimson);"
                 x-text="picker.error"></div>

            <div class="flex items-center justify-end gap-2">
                <button type="button" @click="pickerOpen = false" :disabled="busy"
                        class="px-3 py-2 rounded-md text-xs font-semibold"
                        style="border:1px solid var(--border); color:var(--text-secondary);">
                    Cancel
                </button>
                <button type="button" @click="sendRequest()"
                        :disabled="busy || picker.selected.length === 0"
                        class="px-4 py-2 rounded-md text-xs font-semibold text-white disabled:opacity-40"
                        style="background:var(--brand-button, #0ea5e9);">
                    <span x-show="!busy">Send request</span>
                    <span x-show="busy">Sending…</span>
                </button>
            </div>
        </div>
    </div>

    </div>
    </template>

    {{-- ── Waiting modal ── --}}
    <template x-teleport="body">
    <div x-show="waitingOpen" x-cloak
         class="fixed inset-0 z-[1000] flex items-center justify-center"
         style="background:rgba(0,0,0,0.7);">
        <div class="rounded-md w-full max-w-sm mx-4 p-6 text-center space-y-4"
             style="background:var(--surface); border:1px solid var(--border);">
            <div class="mx-auto w-12 h-12 rounded-full flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent);">
                <svg class="animate-spin w-6 h-6" style="color:var(--brand-icon, #0ea5e9);" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">
                    Waiting for authorization
                </h3>
                <p class="text-xs mt-1" style="color:var(--text-secondary);">
                    From <span x-text="waiting.agencyName" class="font-semibold"></span>.
                </p>
                <p class="text-xs mt-2" style="color:var(--text-muted);">
                    Expires in <span x-text="waiting.countdown" class="font-mono font-semibold"></span>
                </p>
            </div>
            <button type="button" @click="cancelRequest()" :disabled="busy"
                    class="px-3 py-2 rounded-md text-xs font-semibold"
                    style="border:1px solid var(--border); color:var(--text-secondary);">
                Cancel request
            </button>
        </div>
    </div>

    </div>
    </template>

    {{-- ── Result modal (denied / expired / no-admin / error) ── --}}
    <template x-teleport="body">
    <div x-show="resultOpen" x-cloak
         class="fixed inset-0 z-[1000] flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);">
        <div class="rounded-md w-full max-w-sm mx-4 p-6 space-y-4"
             style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);" x-text="result.title"></h3>
            <p class="text-xs" style="color:var(--text-secondary);" x-text="result.message"></p>
            <div class="text-right">
                <button type="button" @click="resultOpen = false"
                        class="px-4 py-2 rounded-md text-xs font-semibold text-white"
                        style="background:var(--brand-button, #0ea5e9);">Close</button>
            </div>
        </div>
    </div>
    </template>
</div>

<script>
// Alpine component for the cross-agency consent flow.
// Loaded once per request via the sidebar partial.
window.agencyAccessSwitcher = function (cfg) {
    return {
        agencies: cfg.agencies || [],
        accessGrants: cfg.accessGrants || {},   // { agency_id: ISO-expires-at }
        activeAgencyId: cfg.activeAgencyId || 0,
        csrfToken: cfg.csrfToken,
        _grantTick: null,
        _grantNow: Date.now(),

        dropdownOpen: false,
        busy: false,

        init() {
            // Tick once a minute so the "Xh remaining" badge stays fresh.
            this._grantTick = setInterval(() => { this._grantNow = Date.now(); }, 60000);
        },

        grantRemaining(agencyId) {
            const iso = this.accessGrants?.[agencyId] || this.accessGrants?.[String(agencyId)];
            if (!iso) return null;
            const ms = (new Date(iso)).getTime() - this._grantNow;
            if (ms <= 0) return null;
            const hours = Math.floor(ms / 3_600_000);
            if (hours >= 1) return hours + 'h';
            const mins = Math.max(1, Math.floor(ms / 60_000));
            return mins + 'm';
        },

        pickerOpen: false,
        picker: { agencyId: null, agencyName: '', admins: [], selected: [], reason: '', error: '' },

        waitingOpen: false,
        waiting: { requestId: null, agencyName: '', expiresAt: null, countdown: '5:00', _poll: null, _tick: null },

        resultOpen: false,
        result: { title: '', message: '' },

        async onAgencyClick(ag) {
            if (ag.id === this.activeAgencyId) { this.dropdownOpen = false; return; }
            this.dropdownOpen = false;
            // Always inspect — server is the source of truth on requires_auth.
            try {
                const res = await fetch(`/api/v1/agency-access/inspect/${ag.id}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (!data.ok) {
                    this.showResult('Cannot switch', data.error || 'Inspection failed.');
                    return;
                }
                if (data.mode === 'instant') {
                    this.directSwitch(ag.id);
                    return;
                }
                // consent mode
                this.picker = {
                    agencyId: ag.id, agencyName: ag.name,
                    admins: data.admins || [], selected: [], reason: '', error: '',
                };
                this.pickerOpen = true;
            } catch (e) {
                this.showResult('Network error', 'Could not reach the server. Try again.');
            }
        },

        directSwitch(agencyId) {
            // Plain form POST to existing route — preserves the redirect-with-flash UX.
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = `${cfg.directSwitchUrlBase}/${agencyId}`;
            f.style.display = 'none';
            const t = document.createElement('input');
            t.name = '_token'; t.value = this.csrfToken; f.appendChild(t);
            document.body.appendChild(f);
            f.submit();
        },

        async sendRequest() {
            if (this.busy || this.picker.selected.length === 0) return;
            this.busy = true; this.picker.error = '';
            try {
                const res = await fetch('/api/v1/agency-access/request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({
                        target_agency_id: this.picker.agencyId,
                        admin_user_ids: this.picker.selected,
                        reason: this.picker.reason || null,
                    }),
                });
                const data = await res.json();
                if (!data.ok) {
                    this.picker.error = data.error || 'Request failed.';
                    return;
                }
                this.pickerOpen = false;
                this.startWaiting(data.request_id, data.expires_at, this.picker.agencyName);
            } catch (e) {
                this.picker.error = 'Network error.';
            } finally {
                this.busy = false;
            }
        },

        startWaiting(requestId, expiresAt, agencyName) {
            this.waiting = {
                requestId, agencyName,
                expiresAt: new Date(expiresAt),
                countdown: '5:00',
                _poll: null, _tick: null,
            };
            this.waitingOpen = true;
            this.tickCountdown();
            this.waiting._tick = setInterval(() => this.tickCountdown(), 1000);
            this.waiting._poll = setInterval(() => this.pollStatus(), 3000);
            this.pollStatus();
        },

        tickCountdown() {
            const ms = (this.waiting.expiresAt - new Date());
            if (ms <= 0) { this.waiting.countdown = '0:00'; return; }
            const total = Math.floor(ms / 1000);
            const m = Math.floor(total / 60), s = total % 60;
            this.waiting.countdown = `${m}:${String(s).padStart(2, '0')}`;
        },

        async pollStatus() {
            try {
                const res = await fetch(`/api/v1/agency-access/${this.waiting.requestId}/status`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (!data.ok) return;
                if (data.status === 'pending') return;

                this.stopWaiting();

                if (data.status === 'approved') {
                    // Confirm switch — server flips active_agency_id, then reload.
                    const conf = await fetch(`/api/v1/agency-access/${this.waiting.requestId}/confirm-switch`, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    });
                    const cdata = await conf.json();
                    if (cdata.ok) {
                        window.location.href = cdata.redirect || '/corex';
                    } else {
                        this.showResult('Switch failed', cdata.error || 'Could not switch.');
                    }
                } else if (data.status === 'denied') {
                    this.showResult('Access denied', data.denial_reason
                        ? `An admin denied your request: ${data.denial_reason}`
                        : 'An admin denied your request.');
                } else if (data.status === 'expired') {
                    this.showResult('Request expired', 'No admin responded in time. Try again later.');
                } else if (data.status === 'cancelled') {
                    // Silent (we cancelled it).
                }
            } catch (e) { /* swallow */ }
        },

        stopWaiting() {
            if (this.waiting._poll) clearInterval(this.waiting._poll);
            if (this.waiting._tick) clearInterval(this.waiting._tick);
            this.waitingOpen = false;
        },

        async cancelRequest() {
            if (this.busy || !this.waiting.requestId) return;
            this.busy = true;
            try {
                await fetch(`/api/v1/agency-access/${this.waiting.requestId}/cancel`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });
            } catch (e) { /* swallow */ }
            this.stopWaiting();
            this.busy = false;
        },

        showResult(title, message) {
            this.result = { title, message };
            this.resultOpen = true;
        },
    };
};
</script>
