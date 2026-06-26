{{--
    Agent delete modal — Alpine component (UI_DESIGN_SYSTEM.md §3.11).
    Include once per page. Triggered by any element with [data-agent-delete]
    carrying data-user-id and data-user-name attributes.
--}}
<div
    x-data="agentDeleteModal()"
    x-on:open-agent-delete.window="open($event.detail.userId, $event.detail.userName)"
    x-on:keydown.escape.window="close()"
    x-show="visible"
    x-cloak
    class="fixed inset-0 z-40 flex items-center justify-center p-4"
    style="background: rgba(0,0,0,0.5);"
>
    <div
        @click.outside="close()"
        x-show="visible"
        x-transition
        class="rounded-md w-full"
        style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border); max-width: 560px; max-height: 90vh; overflow: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
    >
        {{-- Header --}}
        <div class="flex items-start justify-between gap-3 p-5" style="border-bottom: 1px solid var(--border);">
            <div>
                <h3 class="text-lg font-semibold" style="color: var(--text-primary);">
                    Delete <span x-text="userName"></span>
                </h3>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">Reassign their work before removing the account.</p>
            </div>
            <button type="button" @click="close()" class="corex-btn-icon" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6"/>
                </svg>
            </button>
        </div>

        {{-- Body --}}
        <div class="p-5 space-y-4">
            <template x-if="loading">
                <p class="text-sm" style="color: var(--text-muted);">Loading…</p>
            </template>

            <template x-if="error">
                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                     style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                            color: var(--text-primary);">
                    <span x-text="error"></span>
                </div>
            </template>

            <template x-if="!loading && !error && counts && counts.has_any">
                <div class="space-y-4">
                    <div>
                        <p class="text-sm mb-2" style="color: var(--text-secondary);">This agent currently owns:</p>
                        <ul class="space-y-1.5 text-sm" style="color: var(--text-primary);">
                            <li class="flex items-center gap-2">
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); white-space: nowrap;" x-text="counts.properties_primary"></span>
                                properties as primary agent
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); white-space: nowrap;" x-text="counts.properties_secondary"></span>
                                properties as secondary agent
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); white-space: nowrap;" x-text="counts.contacts"></span>
                                contacts
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); white-space: nowrap;" x-text="counts.calendar_events"></span>
                                calendar events <span class="text-xs" style="color: var(--text-muted);">(will be deleted)</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); white-space: nowrap;" x-text="counts.command_tasks"></span>
                                tasks <span class="text-xs" style="color: var(--text-muted);">(will be deleted)</span>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <label for="agent-delete-target" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                            Reassign properties + contacts to <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="agent-delete-target"
                            x-model="targetUserId"
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                        >
                            <option value="">— choose an agent —</option>
                            <template x-for="t in targets" :key="t.id">
                                <option :value="t.id" x-text="t.label"></option>
                            </template>
                        </select>
                    </div>

                    <fieldset class="rounded-md p-3" style="border: 1px solid var(--border);">
                        <legend class="px-1 text-xs font-medium" style="color: var(--text-secondary);">
                            For properties where this agent is primary AND a secondary agent exists
                        </legend>
                        <label class="flex items-start gap-2 text-sm cursor-pointer py-1" style="color: var(--text-primary);">
                            <input type="radio" value="promote" x-model="secondaryHandling" class="mt-1">
                            <span>Promote the secondary agent to primary <span class="text-xs" style="color: var(--text-muted);">(default)</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm cursor-pointer py-1" style="color: var(--text-primary);">
                            <input type="radio" value="replace" x-model="secondaryHandling" class="mt-1">
                            <span>Keep them as secondary; assign chosen agent as primary</span>
                        </label>
                    </fieldset>

                    <p class="text-xs" style="color: var(--text-muted);">
                        Calendar events and tasks will be soft-deleted.
                    </p>
                </div>
            </template>

            <template x-if="!loading && !error && counts && !counts.has_any">
                <p class="text-sm" style="color: var(--text-primary);">
                    This agent has no attached properties, contacts, events, or tasks.
                </p>
            </template>

            {{-- Deals — leave under this agent (default) or move everything to another agent --}}
            <template x-if="!loading && !error && counts && counts.deals > 0">
                <fieldset class="rounded-md p-3" style="border: 1px solid var(--border);">
                    <legend class="px-1 text-xs font-medium" style="color: var(--text-secondary);">Deals</legend>
                    <p class="text-sm mb-2" style="color: var(--text-secondary);">
                        This agent is on
                        <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); white-space: nowrap;" x-text="counts.deals"></span>
                        deals, including any commission &amp; settlement allocations.
                    </p>
                    <label class="flex items-start gap-2 text-sm cursor-pointer py-1" style="color: var(--text-primary);">
                        <input type="radio" value="leave" x-model="dealHandling" class="mt-1">
                        <span>Leave the deals under <span x-text="userName"></span>'s name <span class="text-xs" style="color: var(--text-muted);">(default)</span></span>
                    </label>
                    <label class="flex items-start gap-2 text-sm cursor-pointer py-1" style="color: var(--text-primary);">
                        <input type="radio" value="move" x-model="dealHandling" class="mt-1">
                        <span>Move the deals (and their commission) to another agent</span>
                    </label>

                    <div x-show="dealHandling === 'move'" x-cloak class="mt-2">
                        <label for="agent-delete-deal-target" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                            Move deals to <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="agent-delete-deal-target"
                            x-model="dealTargetUserId"
                            @change="dealTouched = true"
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                        >
                            <option value="">— choose an agent —</option>
                            <template x-for="t in targets" :key="'deal-' + t.id">
                                <option :value="t.id" x-text="t.label"></option>
                            </template>
                        </select>
                    </div>
                </fieldset>
            </template>

            {{-- QR rerouting — always required (every agent has a QR code) --}}
            <template x-if="!loading && !error && counts">
                <div class="rounded-md p-3" style="border: 1px solid var(--border); background: color-mix(in srgb, var(--brand-icon) 5%, transparent);">
                    <label for="agent-delete-qr-target" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                        Reroute this agent's QR code to <span class="text-red-500">*</span>
                    </label>
                    <select
                        id="agent-delete-qr-target"
                        x-model="qrRerouteUserId"
                        @change="qrTouched = true"
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                    >
                        <option value="">— choose an agent —</option>
                        <template x-for="t in targets" :key="'qr-' + t.id">
                            <option :value="t.id" x-text="t.label"></option>
                        </template>
                    </select>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Clients who scan this agent's existing QR code (printed cards, signage) will be onboarded to the chosen agent instead. The original code keeps working — nothing needs reprinting.
                    </p>
                </div>
            </template>
        </div>

        {{-- Footer --}}
        <div class="flex items-center justify-end gap-2 p-4" style="border-top: 1px solid var(--border);">
            <button type="button" @click="close()" class="corex-btn-outline">Cancel</button>
            <button
                type="button"
                @click="submit()"
                :disabled="!canSubmit() || submitting"
                class="corex-btn-primary"
                style="background: var(--ds-crimson); border-color: var(--ds-crimson);"
                :class="(!canSubmit() || submitting) ? 'opacity-40 cursor-not-allowed' : ''"
                x-text="submitting ? 'Deleting…' : 'Delete and reassign'"
            ></button>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-agent-delete]');
    if (!btn) return;
    e.preventDefault();
    window.dispatchEvent(new CustomEvent('open-agent-delete', {
        detail: {
            userId: btn.getAttribute('data-user-id'),
            userName: btn.getAttribute('data-user-name'),
        },
    }));
});

function agentDeleteModal() {
    return {
        visible: false,
        loading: false,
        submitting: false,
        error: null,
        userId: null,
        userName: '',
        counts: null,
        targets: [],
        targetUserId: '',
        secondaryHandling: 'promote',
        qrRerouteUserId: '',
        qrTouched: false,
        dealHandling: 'leave',
        dealTargetUserId: '',
        dealTouched: false,

        init() {
            // QR reroute and the deal-move target both default to the
            // reassignment target until the admin explicitly picks a different
            // agent for each.
            this.$watch('targetUserId', (val) => {
                if (!this.qrTouched) this.qrRerouteUserId = val;
                if (!this.dealTouched) this.dealTargetUserId = val;
            });
        },

        open(userId, userName) {
            this.visible = true;
            this.loading = true;
            this.error = null;
            this.userId = userId;
            this.userName = userName || 'this agent';
            this.counts = null;
            this.targets = [];
            this.targetUserId = '';
            this.secondaryHandling = 'promote';
            this.qrRerouteUserId = '';
            this.qrTouched = false;
            this.dealHandling = 'leave';
            this.dealTargetUserId = '';
            this.dealTouched = false;

            const url = '{{ url('/api/v1/admin/users') }}/' + userId + '/delete-preview';
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
                .then(({ ok, body }) => {
                    if (!ok) { this.error = body.error || 'Failed to load preview.'; return; }
                    this.counts = body.counts;
                    this.targets = body.targets;
                })
                .catch(() => { this.error = 'Network error loading preview.'; })
                .finally(() => { this.loading = false; });
        },

        close() {
            if (this.submitting) return;
            this.visible = false;
        },

        canSubmit() {
            if (!this.counts) return false;
            if (!this.qrRerouteUserId) return false; // QR reroute is mandatory
            if (this.counts.has_any && !this.targetUserId) return false;
            if (this.counts.deals > 0 && this.dealHandling === 'move' && !this.dealTargetUserId) return false;
            return true;
        },

        submit() {
            if (!this.canSubmit()) return;
            this.submitting = true;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ url('/admin/users') }}/' + this.userId + '/delete';

            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = '{{ csrf_token() }}';
            form.appendChild(csrf);

            const qr = document.createElement('input');
            qr.type = 'hidden'; qr.name = 'qr_reroute_user_id'; qr.value = this.qrRerouteUserId;
            form.appendChild(qr);

            if (this.counts.has_any) {
                const t = document.createElement('input');
                t.type = 'hidden'; t.name = 'target_user_id'; t.value = this.targetUserId;
                form.appendChild(t);

                const s = document.createElement('input');
                s.type = 'hidden'; s.name = 'secondary_handling'; s.value = this.secondaryHandling;
                form.appendChild(s);
            }

            if (this.counts.deals > 0) {
                const dh = document.createElement('input');
                dh.type = 'hidden'; dh.name = 'deal_handling'; dh.value = this.dealHandling;
                form.appendChild(dh);

                if (this.dealHandling === 'move') {
                    const dt = document.createElement('input');
                    dt.type = 'hidden'; dt.name = 'deal_target_user_id'; dt.value = this.dealTargetUserId;
                    form.appendChild(dt);
                }
            }

            document.body.appendChild(form);
            form.submit();
        },
    };
}
</script>
