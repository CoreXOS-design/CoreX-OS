{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    Demo Access — "Add time" dialog. ONE dialog per page, opened by any Extend
    button via a window event carrying that grant's facts:

        $dispatch('demo-extend', { company, status, statusLabel, action, endsAt, trial })

    Those facts come from DemoAccessListing::extendPayload(), so the wording below
    is honest about WHERE the time lands for THIS grant (not started / running /
    already ended) instead of a generic "extend".

    The token is minted fresh on every open and consumed by the server: a
    double-click or a resubmitted form applies once.

    Spec: .ai/specs/demo-access-control.md §9.1
--}}
<div x-data="{
        open: false,
        g: {},
        days: 7,
        custom: '',
        token: '',
        busy: false,
        presets: [ {d:1, l:'1 day'}, {d:3, l:'3 days'}, {d:7, l:'1 week'}, {d:14, l:'2 weeks'}, {d:30, l:'1 month'} ],
        show(g) {
            this.g = g; this.days = 7; this.custom = ''; this.busy = false;
            const raw = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + Math.random().toString(16).slice(2) + '0000000000');
            this.token = raw.replace(/-/g, '');
            this.open = true;
        },
        pick(d) { this.days = d; this.custom = ''; },
        get chosen() {
            if (this.custom !== '') { const c = parseInt(this.custom, 10); return isNaN(c) ? 0 : c; }
            return this.days;
        },
        get valid() { return this.chosen >= 1 && this.chosen <= 365; }
     }"
     x-show="open" x-cloak
     @demo-extend.window="show($event.detail)"
     @keydown.escape.window="open = false"
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     role="dialog" aria-modal="true" aria-labelledby="demo-extend-heading">

    <div class="absolute inset-0" style="background: rgba(0,0,0,0.55);" @click="open = false"></div>

    <form method="POST" :action="g.action" @submit="busy = true"
          class="relative w-full max-w-lg rounded-md shadow-2xl overflow-hidden"
          style="background: var(--surface); border: 1px solid var(--border);" @click.stop>
        @csrf
        <input type="hidden" name="token" :value="token">
        <input type="hidden" name="days" :value="chosen">

        <div class="flex items-start justify-between gap-3 px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <div class="min-w-0">
                <div id="demo-extend-heading" class="text-sm font-bold" style="color: var(--text-primary);">Add time</div>
                <div class="text-xs mt-0.5 truncate" style="color: var(--text-secondary);" x-text="g.company"></div>
            </div>
            <button type="button" @click="open = false" class="p-1 rounded-md" style="color: var(--text-muted);" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="px-5 py-4 space-y-4">
            {{-- Where the time lands, in the owner's words. --}}
            <p class="text-sm" style="color: var(--text-secondary);">
                <template x-if="g.status === 'pending'">
                    <span>They haven't signed in yet, so their clock hasn't started. This makes the trial they get at first sign-in longer (it is <strong x-text="g.trial"></strong> now).</span>
                </template>
                <template x-if="g.status === 'active'">
                    <span>Their access ends <strong x-text="g.endsAt"></strong>. The time you choose is added on top of that.</span>
                </template>
                <template x-if="g.status === 'expired'">
                    <span>Their access ended <strong x-text="g.endsAt"></strong>. Adding time switches it back on from today, for the period you choose.</span>
                </template>
            </p>

            <div>
                <div class="text-xs font-medium mb-2" style="color: var(--text-secondary);">How much time?</div>
                <div class="flex flex-wrap gap-2">
                    <template x-for="p in presets" :key="p.d">
                        <button type="button" @click="pick(p.d)" class="text-xs px-3 py-2 rounded-md font-semibold"
                                :style="(custom === '' && days === p.d)
                                    ? 'background: var(--brand-button, #0ea5e9); color: #fff; border: 1px solid var(--brand-button, #0ea5e9);'
                                    : 'background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);'"
                                x-text="p.l"></button>
                    </template>
                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                        or
                        <input type="number" min="1" max="365" x-model="custom" placeholder="days"
                               class="w-20 text-sm rounded-md px-2 py-1.5"
                               style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);"
                               aria-label="Number of days to add">
                    </label>
                </div>
                <p x-show="!valid" x-cloak class="text-xs mt-2" style="color: var(--ds-crimson, #c41e3a);">Enter between 1 and 365 days.</p>
            </div>

            <div>
                <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);" for="demo-extend-note">Reason (optional, kept on the record)</label>
                <textarea id="demo-extend-note" name="note" rows="2" maxlength="500"
                          class="w-full text-sm rounded-md px-3 py-2"
                          style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);"
                          placeholder="e.g. Asked for another week to show their principal"></textarea>
            </div>

            <p class="text-xs" style="color: var(--text-muted);">
                Same access code and the terms they already accepted — nothing to resend.
                It reaches the demo within {{ (int) config('corex.instance.gate_cache_ttl', 60) }} seconds, not instantly.
            </p>
        </div>

        <div class="flex items-center justify-end gap-2 px-5 py-3" style="background: var(--surface-2); border-top: 1px solid var(--border);">
            <button type="button" @click="open = false" class="corex-btn-outline text-sm">Cancel</button>
            <button type="submit" class="corex-btn-primary text-sm" :disabled="!valid || busy" :style="(!valid || busy) ? 'opacity: .55; cursor: not-allowed;' : ''">
                <span x-text="busy ? 'Adding…' : ('Add ' + chosen + (chosen === 1 ? ' day' : ' days'))"></span>
            </button>
        </div>
    </form>
</div>
