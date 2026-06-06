@extends('layouts.corex')

@section('title', 'Private Property — Agents')

@section('corex-content')
<div class="p-6 space-y-6">

    {{-- Header --}}
    <div>
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Private Property — Agents</h1>
        <p class="text-sm mt-1" style="color:var(--text-muted);">
            Every agent in this agency and the External Ref (Agent ID) Private Property uses
            to identify them. Set or remap an agent's External Ref, (re)sync their profile, or
            deactivate them on PP — all without opening each profile.
        </p>
    </div>

    {{-- Tab nav --}}
    @include('admin.pp._tabs')

    {{-- Agents table --}}
    <div class="rounded-xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead style="background:var(--surface-2); color:var(--text-secondary);">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Agent</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Email</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Role</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">External Ref</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">PP Status</th>
                    <th class="text-right px-4 py-3 font-semibold uppercase tracking-wider text-xs">Action</th>
                </tr>
            </thead>
                @forelse($agents as $a)
                    <tbody x-data="ppAgentRow({
                            externalRef: '{{ $a->pp_external_ref ?: $a->id }}',
                            ppUniqueAgentId: '{{ $a->pp_unique_agent_id ?? '' }}',
                            userName: @js($a->name),
                            updateUrl: '{{ route('admin.users.pp.update-external-ref', $a) }}',
                            syncUrl: '{{ route('admin.users.pp.sync', $a) }}',
                            deactivateUrl: '{{ route('corex.properties.syndication.agent.deactivate') }}',
                            userId: {{ $a->id }},
                            csrf: '{{ csrf_token() }}'
                        })" style="border-top:1px solid var(--border);">
                        <tr>
                            <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">
                                {{ $a->name }}
                                @unless($a->is_active)
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold" style="background:var(--surface-2); color:var(--text-muted);">Inactive</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $a->email }}</td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ ucwords(str_replace('_', ' ', $a->role ?? '—')) }}</td>
                            <td class="px-4 py-3 font-mono" style="color:var(--text-primary);" x-text="externalRef"></td>
                            <td class="px-4 py-3">
                                <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded"
                                      :style="'background:' + badgeColor.bg + '; color:' + badgeColor.color + ';'"
                                      x-text="badgeColor.label"></span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="open = !open"
                                        class="px-3 py-1.5 rounded-md text-xs font-medium"
                                        style="color:var(--text-secondary); border:1px solid var(--border); background:var(--surface-2); cursor:pointer;">
                                    <span x-show="!open">Manage</span>
                                    <span x-show="open" x-cloak>Close</span>
                                </button>
                            </td>
                        </tr>
                        {{-- Expandable editor (the controls moved off the user edit page) --}}
                        <tr x-show="open" x-cloak>
                            <td colspan="6" class="px-4 py-4" style="background:var(--surface-2);">
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 max-w-4xl">

                                    {{-- External Ref --}}
                                    <div>
                                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">External Ref (Agent ID)</label>
                                        <input type="text" x-model="externalRef" maxlength="100"
                                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                                        <p class="text-[11px] mt-1.5" style="color:var(--text-muted);">
                                            This is the ID PP shows as "External Ref" in their portal. Type the new value
                                            and click Update PP Agent ID — it remaps PP's existing record via
                                            UpdateUniqueAgentID (no duplicate profile). If we don't yet hold PP's encrypted
                                            ID for this agent we'll fetch it via GetAgent; otherwise paste it into the field.
                                        </p>
                                    </div>

                                    {{-- PP Encrypted Agent ID --}}
                                    <div>
                                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">PP Encrypted Agent ID <span style="color:var(--text-muted); font-weight:400;">(from PP support)</span></label>
                                        <input type="text" x-model="ppEncryptedId" placeholder="Leave blank unless provided by PP" maxlength="500"
                                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                                        <p class="text-[11px] mt-1.5" style="color:#f59e0b;">
                                            Only fill this in if PP support has provided you with an encrypted agent ID.
                                            Required only when claiming ownership of an agent originally created by another vendor.
                                        </p>
                                    </div>
                                </div>

                                {{-- Sync status --}}
                                <div class="mt-4 flex items-center gap-2">
                                    <span class="text-xs font-medium" style="color:var(--text-secondary);">PP Sync Status:</span>
                                    <span class="text-xs font-medium"
                                          :style="ppUniqueAgentId ? 'color:var(--brand-icon)' : 'color:var(--text-muted)'"
                                          x-text="ppUniqueAgentId ? 'Synced' : 'Not synced'"></span>
                                </div>

                                {{-- Actions --}}
                                <div class="mt-4 flex flex-wrap gap-3">
                                    <button type="button" @click="updateExternalRef()" :disabled="updateLoading"
                                            class="px-4 py-2 rounded-md text-sm font-medium text-white transition-colors"
                                            style="background:var(--brand-button, #0ea5e9);"
                                            onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                        <span x-show="!updateLoading">Update PP Agent ID</span>
                                        <span x-show="updateLoading" x-cloak>Updating...</span>
                                    </button>
                                    <button type="button" @click="syncAgent()" :disabled="syncing"
                                            class="px-4 py-2 rounded-md text-sm font-medium transition-colors"
                                            style="color:var(--text-secondary); border:1px solid var(--border); background:var(--surface);">
                                        <span x-show="!syncing">Sync Agent to Private Property</span>
                                        <span x-show="syncing" x-cloak>Syncing...</span>
                                    </button>
                                    <button type="button" @click="deactivateAgent()" :disabled="deactivating"
                                            class="px-4 py-2 rounded-md text-sm font-medium transition-colors"
                                            style="color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); background:color-mix(in srgb, var(--ds-crimson) 8%, transparent);">
                                        <span x-show="!deactivating">Deactivate Agent on PP</span>
                                        <span x-show="deactivating" x-cloak>Deactivating...</span>
                                    </button>
                                </div>

                                {{-- Feedback --}}
                                <p x-show="updateMsg" x-cloak class="mt-2 text-xs font-medium"
                                   :style="updateOk ? 'color:#22c55e' : 'color:var(--ds-crimson)'" x-text="updateMsg"></p>
                                <p x-show="syncMsg" x-cloak class="mt-2 text-xs font-medium"
                                   :style="syncOk ? 'color:#22c55e' : 'color:var(--ds-crimson)'" x-text="syncMsg"></p>
                                <p x-show="deactivateMsg" x-cloak class="mt-2 text-xs font-medium"
                                   :style="deactivateOk ? 'color:#22c55e' : 'color:var(--ds-crimson)'" x-text="deactivateMsg"></p>
                                <p class="text-[11px] mt-2" style="color:var(--text-muted);">
                                    Deactivate sends UpdateAgent with Active=false. PP will refuse if the agent has
                                    active listings — reassign or deactivate those first, wait a few minutes, then retry.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="6" class="px-4 py-8 text-center" style="color:var(--text-muted);">No agents in this agency.</td></tr>
                    </tbody>
                @endforelse
        </table>
    </div>
</div>

<script>
window.ppAgentRow = function (cfg) {
    return {
        open: false,
        syncing: false, syncMsg: '', syncOk: null,
        updateLoading: false, updateMsg: '', updateOk: null,
        deactivating: false, deactivateMsg: '', deactivateOk: null,
        externalRef: cfg.externalRef,
        ppEncryptedId: '',
        ppUniqueAgentId: cfg.ppUniqueAgentId || '',
        userName: cfg.userName,
        userId: cfg.userId,
        updateUrl: cfg.updateUrl,
        syncUrl: cfg.syncUrl,
        deactivateUrl: cfg.deactivateUrl,
        csrf: cfg.csrf,

        get badgeColor() {
            if (this.updateOk === false) return { bg: 'rgba(239,68,68,0.12)', color: '#ef4444', label: 'Error' };
            if (this.ppUniqueAgentId) return { bg: 'color-mix(in srgb, var(--brand-icon) 12%, transparent)', color: '#00d4aa', label: 'Claimed' };
            return { bg: 'var(--surface-2)', color: 'var(--text-muted)', label: 'Default' };
        },

        async updateExternalRef() {
            if (!this.externalRef.toString().trim()) {
                this.updateOk = false; this.updateMsg = 'External Ref cannot be blank'; return;
            }
            if (!confirm('This will update the External Ref for ' + this.userName + ' on Private Property. Are you sure?')) return;
            this.updateLoading = true; this.updateMsg = ''; this.updateOk = null;
            try {
                const res = await fetch(this.updateUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ external_ref: this.externalRef, pp_encrypted_id: this.ppEncryptedId }),
                });
                const data = await res.json();
                this.updateOk = data.success;
                if (data.success) {
                    this.updateMsg = 'Updated — PP External Ref is now ' + (data.external_ref ?? this.externalRef);
                    if (data.pp_unique_agent_id) this.ppUniqueAgentId = data.pp_unique_agent_id;
                    if (data.external_ref) this.externalRef = data.external_ref;
                    this.ppEncryptedId = '';
                } else {
                    this.updateMsg = data.message || 'Update failed';
                }
            } catch (e) { this.updateOk = false; this.updateMsg = 'Network error'; }
            this.updateLoading = false;
        },

        async syncAgent() {
            this.syncing = true; this.syncMsg = ''; this.syncOk = null;
            try {
                const res = await fetch(this.syncUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                this.syncOk = data.success;
                this.syncMsg = data.message;
            } catch (e) { this.syncOk = false; this.syncMsg = 'Network error'; }
            this.syncing = false;
        },

        async deactivateAgent() {
            if (!confirm('Deactivate ' + this.userName + ' on Private Property? PP will refuse this if the agent still has active listings.')) return;
            this.deactivating = true; this.deactivateMsg = ''; this.deactivateOk = null;
            try {
                const res = await fetch(this.deactivateUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ user_id: this.userId }),
                });
                const data = await res.json();
                this.deactivateOk = data.success;
                this.deactivateMsg = data.message || (data.success ? 'Agent deactivated on PP' : 'Deactivate failed');
            } catch (e) { this.deactivateOk = false; this.deactivateMsg = 'Network error'; }
            this.deactivating = false;
        }
    };
};
</script>
@endsection
