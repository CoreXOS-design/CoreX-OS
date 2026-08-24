@php
    $snap = $notificationSnapshot ?? ['master' => ['in_app'=>true,'email'=>true,'push'=>true], 'agency_controlled'=>false, 'groups'=>[]];
@endphp

<div x-data='notificationsPrefs(@json($snap))' class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold" style="color:var(--text-primary);">Notifications</h2>
            <p class="text-xs" style="color:var(--text-muted);">Choose which events alert you, on which channel, and how soon. These preferences are always per-user — agency settings mode does not apply here.</p>
        </div>
        <button type="button" @click="resetDefaults()" :disabled="saving"
                class="corex-btn-outline text-xs">
            Reset to defaults
        </button>
    </div>

    {{-- Master switches --}}
    <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
        <h3 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;">Master switches</h3>
        <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="checkbox" x-model="master.in_app"  class="rounded">
                In-app
            </label>
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="checkbox" x-model="master.email"  class="rounded">
                Email
            </label>
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                <input type="checkbox" x-model="master.push"  class="rounded">
                Push (mobile)
            </label>
        </div>
    </div>

    {{-- Groups --}}
    <template x-for="group in groups" :key="group.pillar">
        <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
            <h3 class="text-xs font-semibold uppercase tracking-wider mb-3"
                style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;"
                x-text="group.label.charAt(0).toUpperCase() + group.label.slice(1)"></h3>
            <div class="space-y-3">
                <template x-for="item in group.items" :key="item.key">
                    <div class="rounded-md p-3"
                         :style="item.enabled
                            ? 'background:var(--surface); border:1px solid var(--border);'
                            : 'background:var(--surface); border:1px solid var(--border); opacity:0.6;'">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="item.label"></div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="item.description"></div>
                            </div>
                            <label class="inline-flex items-center gap-2 text-xs cursor-pointer">
                                <span x-text="item.enabled ? 'On' : 'Off'" style="color:var(--text-secondary);"></span>
                                <input type="checkbox" x-model="item.enabled"  class="rounded">
                            </label>
                        </div>

                        <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3" x-show="item.enabled">
                            <div x-show="item.threshold_unit !== 'none'">
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">
                                    Notify after
                                </label>
                                <div class="flex items-center gap-2">
                                    <input type="number" x-model.number="item.threshold"
                                           :min="item.threshold_min" :max="item.threshold_max"
                                                                                     class="w-24 rounded-md px-2 py-1.5 text-sm"
                                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    <span class="text-xs" style="color:var(--text-secondary);" x-text="item.threshold_unit"></span>
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Channels</label>
                                <div class="flex flex-wrap gap-4 text-xs" style="color:var(--text-primary);">
                                    <label class="flex items-center gap-1.5">
                                        <input type="checkbox" x-model="item.channel_in_app"  class="rounded"> In-app
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <input type="checkbox" x-model="item.channel_email"  class="rounded"> Email
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <input type="checkbox" x-model="item.channel_push"  class="rounded"> Push
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    <div class="flex items-center justify-end gap-3 sticky bottom-0 py-3" style="background:var(--surface);">
        <span x-show="savedMsg" class="text-xs" style="color:var(--ds-green);" x-text="savedMsg"></span>
        <button type="button" @click="save()" :disabled="saving"
                class="corex-btn-primary text-sm">
            <span x-show="!saving">Save preferences</span>
            <span x-show="saving">Saving…</span>
        </button>
    </div>
</div>

<script>
function notificationsPrefs(initial) {
    return {
        master: initial.master || { in_app:true, email:true, push:true },
        groups: initial.groups || [],
        saving: false,
        savedMsg: '',

        async save() {
            this.saving = true;
            this.savedMsg = '';
            const prefs = [];
            this.groups.forEach(g => g.items.forEach(it => prefs.push({
                key: it.key,
                enabled: !!it.enabled,
                threshold: it.threshold,
                channel_in_app: !!it.channel_in_app,
                channel_email: !!it.channel_email,
                channel_push: !!it.channel_push,
            })));
            try {
                const res = await fetch('{{ route('corex.settings.notifications.update') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        master: this.master,
                        preferences: prefs,
                    }),
                });
                if (!res.ok) throw new Error('Save failed');
                this.savedMsg = 'Saved.';
                setTimeout(() => this.savedMsg = '', 2500);
            } catch (e) {
                this.savedMsg = 'Save failed.';
            } finally {
                this.saving = false;
            }
        },

        resetDefaults() {
            this.groups.forEach(g => g.items.forEach(it => {
                it.enabled = true;
                // thresholds left as-is (server-side reset endpoint can be added later)
            }));
        },
    };
}
</script>
