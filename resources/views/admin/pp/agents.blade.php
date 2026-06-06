@extends('layouts.corex')

@section('title', 'Private Property — Agents on Branch')

@section('corex-content')
<div class="p-6 space-y-6"
     data-deactivate-url="{{ route('admin.pp.agents.deactivate') }}"
     data-csrf="{{ csrf_token() }}"
     x-data="ppAgentsPage($el)">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Private Property — PP Branch Profiles</h1>
            <p class="text-sm mt-1" style="color:var(--text-muted);">
                Every agent profile PP currently has for this branch. Rows highlighted in red share an email with another row — those are duplicate profiles that need cleanup.
            </p>
        </div>
        <a href="{{ route('admin.pp.agents') }}"
           class="px-4 py-2 rounded-md text-sm font-medium"
           style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
            Refresh from PP
        </a>
    </div>

    @include('admin.pp._tabs')

    @if($error)
        <div class="rounded-md p-4 text-xs whitespace-pre-wrap break-all" style="background:rgba(239,68,68,0.12); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
            {{ $error }}
        </div>
    @endif

    <p x-show="msg" x-cloak class="text-sm font-medium"
       :style="ok ? 'color:#22c55e' : 'color:var(--ds-crimson)'" x-text="msg"></p>

    <div class="rounded-xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead style="background:var(--surface-2); color:var(--text-secondary);">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">External Ref</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Name</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Email</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Cell</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">CoreX User</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Encrypted ID</th>
                    <th class="text-right px-4 py-3 font-semibold uppercase tracking-wider text-xs">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $a)
                    <tr style="border-top:1px solid var(--border); {{ $a['is_duplicate_external_ref'] ? 'background:color-mix(in srgb, var(--ds-crimson) 6%, transparent);' : '' }}">
                        <td class="px-4 py-3 font-mono" style="color:var(--text-primary);">{{ $a['agent_id'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-primary);">{{ trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $a['email'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $a['contact_number'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">
                            @if($a['corex_user_id'])
                                #{{ $a['corex_user_id'] }} {{ $a['corex_user_name'] }}
                            @else
                                <span style="color:var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-muted);">{{ $a['pp_encrypted_id'] }}</td>
                        <td class="px-4 py-3 text-right">
                            <button type="button"
                                    @click="deactivate($event.currentTarget)"
                                    data-encrypted-id="{{ $a['pp_encrypted_id'] }}"
                                    data-agent-id="{{ $a['agent_id'] }}"
                                    data-first-name="{{ $a['first_name'] }}"
                                    data-last-name="{{ $a['last_name'] }}"
                                    data-email="{{ $a['email'] }}"
                                    data-cell="{{ $a['contact_number'] }}"
                                    :disabled="busy === '{{ $a['pp_encrypted_id'] }}'"
                                    class="px-3 py-1.5 rounded-md text-xs font-medium"
                                    style="color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); cursor:pointer;">
                                <span x-show="busy !== '{{ $a['pp_encrypted_id'] }}'">Deactivate</span>
                                <span x-show="busy === '{{ $a['pp_encrypted_id'] }}'" x-cloak>...</span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center" style="color:var(--text-muted);">No agents returned by PP.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Active-listings modal shown when PP refuses deactivation (PP121) --}}
    <div x-show="modal.open" x-cloak
         class="fixed inset-0 flex items-center justify-center p-4"
         style="background:rgba(0,0,0,0.6); z-index:1000;"
         @keydown.escape.window="modal.open = false">
        <div class="rounded-xl max-w-2xl w-full p-6 space-y-4"
             style="background:var(--surface); border:1px solid var(--border); max-height:85vh; overflow:auto;">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold" style="color:var(--text-primary);">Active listings blocking deactivation</h2>
                    <p class="text-xs mt-1" style="color:var(--text-muted);">
                        PP refused to deactivate agent profile <span x-text="modal.agentId" class="font-mono"></span>
                        because these listings are still attached to it. Delete each listing entirely
                        from the system, wait a couple of minutes, then retry deactivating the agent.
                    </p>
                    <p class="text-xs mt-1" style="color:#f59e0b;">
                        ⚠ This is a hard delete — the Property row is removed from the database (not soft-deleted).
                    </p>
                </div>
                <button type="button" @click="modal.open = false"
                        class="text-sm" style="color:var(--text-muted);">✕</button>
            </div>

            <ul class="space-y-2">
                <template x-for="L in modal.listings" :key="L.id">
                    <li class="rounded-md p-3 flex items-start justify-between gap-3"
                        style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-sm min-w-0">
                            <div class="font-mono text-xs" style="color:var(--text-muted);">
                                Listing #<span x-text="L.id"></span>
                                <template x-if="L.pp_ref"><span class="ml-2">PP Ref: <span x-text="L.pp_ref"></span></span></template>
                                <template x-if="L.soft_deleted"><span class="ml-2" style="color:#f59e0b;">(soft-deleted in CoreX)</span></template>
                                <template x-if="!L.exists"><span class="ml-2" style="color:var(--ds-crimson);">(not found in CoreX)</span></template>
                            </div>
                            <div class="font-semibold mt-0.5" style="color:var(--text-primary);" x-text="L.headline"></div>
                            <div class="text-xs mt-0.5" style="color:var(--text-secondary);" x-text="L.address"></div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <template x-if="L.view_url">
                                <a :href="L.view_url" target="_blank"
                                   class="px-2 py-1 rounded-md text-xs font-medium"
                                   style="border:1px solid var(--border); color:var(--text-secondary); background:var(--surface);">View</a>
                            </template>
                            <template x-if="L.purge_url">
                                <button type="button"
                                        @click="purgeListing(L)"
                                        :disabled="L._busy || L._done"
                                        class="px-2 py-1 rounded-md text-xs font-medium"
                                        :style="L._done
                                            ? 'background:rgba(34,197,94,0.12); color:#22c55e; border:1px solid rgba(34,197,94,0.3);'
                                            : 'background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);'">
                                    <span x-show="!L._busy && !L._done">Delete Listing</span>
                                    <span x-show="L._busy" x-cloak>...</span>
                                    <span x-show="L._done" x-cloak>Deleted</span>
                                </button>
                            </template>
                        </div>
                    </li>
                </template>
            </ul>

            <p x-show="modal.msg" x-cloak class="text-xs"
               :style="modal.ok ? 'color:#22c55e' : 'color:var(--ds-crimson)'" x-text="modal.msg"></p>

            <div class="flex items-center justify-end gap-2 pt-2" style="border-top:1px solid var(--border);">
                <button type="button" @click="modal.open = false"
                        class="px-3 py-1.5 rounded-md text-xs font-medium"
                        style="border:1px solid var(--border); color:var(--text-secondary); background:var(--surface-2);">
                    Close
                </button>
                <button type="button" @click="autoPurgeAndDeactivate()"
                        :disabled="modal.retrying"
                        class="px-3 py-1.5 rounded-md text-xs font-medium text-white"
                        style="background:#dc2626;">
                    <span x-show="!modal.retrying">Delete all + deactivate agent (auto)</span>
                    <span x-show="modal.retrying" x-cloak>Working...</span>
                </button>
                <button type="button" @click="retryDeactivateAgent()"
                        :disabled="modal.retrying"
                        class="px-3 py-1.5 rounded-md text-xs font-medium text-white"
                        style="background:var(--brand-button, #0ea5e9);">
                    <span x-show="!modal.retrying">Retry agent deactivation</span>
                    <span x-show="modal.retrying" x-cloak>Retrying...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.ppAgentsPage = function (root) {
    return {
        busy: null, msg: '', ok: null,
        deactivateUrl: root.dataset.deactivateUrl,
        csrf: root.dataset.csrf,
        modal: { open: false, agentId: '', encryptedId: '', payload: null, listings: [], msg: '', ok: null, retrying: false },

        async _postAgent(payload) {
            var res = await fetch(this.deactivateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            var data;
            try { data = await res.json(); }
            catch (_) { data = { success: false, message: 'Bad JSON from server (HTTP ' + res.status + ')' }; }
            return { res: res, data: data };
        },

        async deactivate(btn) {
            var a = {
                pp_encrypted_id: btn.dataset.encryptedId,
                agent_id:        btn.dataset.agentId,
                first_name:      btn.dataset.firstName,
                last_name:       btn.dataset.lastName,
                email:           btn.dataset.email,
                tel_cell:        btn.dataset.cell,
            };
            if (!confirm('Deactivate PP profile ' + a.agent_id + ' (' + a.first_name + ' ' + a.last_name + ')? PP will refuse if this profile has active listings.')) return;
            this.busy = a.pp_encrypted_id; this.msg = ''; this.ok = null;
            try {
                var r = await this._postAgent(a);
                this.ok = !!r.data.success;
                this.msg = r.data.message || (this.ok ? 'Deactivated' : 'Deactivate failed (HTTP ' + r.res.status + ')');
                if (this.ok) {
                    setTimeout(function () { location.reload(); }, 1200);
                } else if (Array.isArray(r.data.active_listings) && r.data.active_listings.length > 0) {
                    this.modal.agentId = a.agent_id;
                    this.modal.encryptedId = a.pp_encrypted_id;
                    this.modal.payload = a;
                    this.modal.listings = r.data.active_listings.map(function (L) {
                        return Object.assign({ _busy: false, _done: false }, L);
                    });
                    this.modal.msg = '';
                    this.modal.ok = null;
                    this.modal.open = true;
                }
            } catch (e) {
                this.ok = false;
                this.msg = 'Network error: ' + (e && e.message ? e.message : e);
            }
            this.busy = null;
        },

        async purgeListing(L) {
            if (!L.purge_url) return;
            if (!confirm('Hard-delete listing #' + L.id + ' from CoreX? This is irreversible — the Property row is removed from the database (not soft-deleted) and PP is told to deactivate the listing.')) return;
            L._busy = true; this.modal.msg = ''; this.modal.ok = null;
            try {
                var res = await fetch(L.purge_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        'Accept': 'application/json',
                    },
                });
                var data;
                try { data = await res.json(); }
                catch (_) { data = { success: false, message: 'Bad JSON from server (HTTP ' + res.status + ')' }; }
                if (data.success) {
                    L._done = true;
                    this.modal.ok = true;
                    this.modal.msg = data.message || ('Listing ' + L.id + ' deleted.');
                } else {
                    this.modal.ok = false;
                    this.modal.msg = 'Listing ' + L.id + ': ' + (data.message || 'failed (HTTP ' + res.status + ')');
                }
            } catch (e) {
                this.modal.ok = false;
                this.modal.msg = 'Network error: ' + (e && e.message ? e.message : e);
            }
            L._busy = false;
        },

        async retryDeactivateAgent() {
            if (!this.modal.payload) return;
            this.modal.retrying = true; this.modal.msg = ''; this.modal.ok = null;
            try {
                var r = await this._postAgent(this.modal.payload);
                if (r.data.success) {
                    this.modal.ok = true;
                    this.modal.msg = r.data.message || 'Agent deactivated.';
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    this.modal.ok = false;
                    this.modal.msg = r.data.message || ('Retry failed (HTTP ' + r.res.status + ')');
                    if (Array.isArray(r.data.active_listings)) {
                        // Update listing list with whatever PP still reports as active.
                        var prev = {};
                        this.modal.listings.forEach(function (x) { prev[x.id] = x; });
                        this.modal.listings = r.data.active_listings.map(function (L) {
                            var existing = prev[L.id];
                            return Object.assign({ _busy: false, _done: existing ? existing._done : false }, L);
                        });
                    }
                }
            } catch (e) {
                this.modal.ok = false;
                this.modal.msg = 'Network error: ' + (e && e.message ? e.message : e);
            }
            this.modal.retrying = false;
        }
    };
};
</script>
@endsection
