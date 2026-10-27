{{--
    AT-509 — Compliance Switches form fields (.ai/specs/property-compliance-gate-settings.md §7.1).
    Shared by Settings → Compliance Switches and the Setup Wizard's Compliance step: it renders NO <form> of
    its own, it sits inside the host page's form and posts to ComplianceSwitchesController@update.
    Clicking a check OFF opens the disclaimer straight away (Andre 2026-10-26); Confirm switches it off and,
    on the Settings page ($csAutoSave), saves at once. Cancel leaves it on. The server refuses a switch-off
    without the accepted version anyway (ComplianceGateSettingsService::apply).

    Needs: $complianceGates, $complianceLastChange, $complianceDisclaimer (ComplianceSwitchesController::formData()).
--}}
@php
    $csGates = $complianceGates;
    // Only the document checks the agency has today (Andre 2026-10-26: no "other document types you can
    // require" list, and no Photos / Listing details switches — those two checks always stay on).
    $csAll = array_merge([$csGates['master']], $csGates['documents']);
    $csUid = 'cs' . substr(md5(uniqid('', true)), 0, 8);
    $csData = [
        'initial'  => collect($csAll)->mapWithKeys(fn ($g) => [$g['key'] => (bool) $g['enabled']])->all(),
        'labels'   => collect($csAll)->mapWithKeys(fn ($g) => [$g['key'] => $g['label']])->all(),
        'order'    => array_column($csAll, 'key'),
        'template' => $complianceDisclaimer['template'],
        'version'  => $complianceDisclaimer['version'],
        // Settings page: confirming the disclaimer saves straight away. Setup Wizard: it only marks the
        // switch off; the wizard's own Save posts it with the rest of the step.
        'autoSave' => (bool) ($csAutoSave ?? false),
    ];
    $csGroups = [
        ['title' => 'Documents', 'note' => 'Each switched-on document must be on file before the property can be marketed.', 'items' => $csGates['documents']],
    ];
@endphp

<style>
    .cs-switch { position:relative; width:2.25rem; height:1.25rem; border-radius:9999px; background:var(--border); transition:background .2s; flex-shrink:0; }
    .cs-switch::after { content:''; position:absolute; top:2px; left:2px; width:1rem; height:1rem; border-radius:9999px; background:#fff; transition:transform .2s; }
    .cs-switch.on { background:var(--brand-button, #0ea5e9); }
    .cs-switch.on::after { transform:translateX(1rem); }
</style>

<script type="application/json" id="{{ $csUid }}-data">@json($csData)</script>

<div class="space-y-4"
     x-data="{
        state: {}, initial: {}, labels: {}, order: [], template: '', version: '', autoSave: false,
        open: false, accepted: false, confirmed: false, offList: [], pendingKey: null, submitter: null, form: null,
        init() {
            const d = JSON.parse(document.getElementById('{{ $csUid }}-data').textContent);
            this.initial = d.initial; this.state = Object.assign({}, d.initial);
            this.labels = d.labels; this.order = d.order; this.template = d.template; this.version = d.version;
            this.autoSave = d.autoSave;
            this.form = this.$el.closest('form');
            if (this.form) { this.form.addEventListener('submit', (e) => this.onSubmit(e)); }
        },
        // Switching a check that is on today OFF asks for the disclaimer first; switching on needs none.
        flip(key) {
            if (this.state[key] && this.initial[key]) { this.askDisclaimer(key); return; }
            this.state[key] = !this.state[key];
        },
        // Every check being switched off in this save, so the text names all of them (the server stores it).
        offKeys(extra) { return this.order.filter((k) => this.initial[k] && (!this.state[k] || k === extra)); },
        askDisclaimer(key) {
            this.pendingKey = key;
            this.offList = this.offKeys(key).map((k) => this.labels[k]);
            this.accepted = false;
            this.open = true;
        },
        // Safety net: anything still off without an accepted disclaimer is asked before the form posts.
        onSubmit(e) {
            if (this.confirmed) { return; }
            const off = this.offKeys(null);
            if (off.length === 0) { return; }
            e.preventDefault();
            this.submitter = e.submitter || null;
            this.pendingKey = null;
            this.offList = off.map((k) => this.labels[k]);
            this.accepted = false;
            this.open = true;
        },
        disclaimerText() { return this.template.replace('{gates}', this.offList.join(', ')); },
        confirm() {
            if (!this.accepted) { return; }
            if (this.pendingKey) { this.state[this.pendingKey] = false; }
            this.$refs.accepted.value = this.version;
            this.confirmed = true;
            this.open = false;
            const fromSave = this.pendingKey === null;
            this.pendingKey = null;
            if (fromSave || this.autoSave) {
                this.$nextTick(() => { this.submitter ? this.form.requestSubmit(this.submitter) : this.form.requestSubmit(); });
            }
        },
        cancel() { this.open = false; this.accepted = false; this.pendingKey = null; }
     }">

    <input type="hidden" name="compliance_switches_submitted" value="1">
    <input type="hidden" name="compliance_disclaimer_accepted" value="" x-ref="accepted">

    @error('compliance_disclaimer')
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $message }}
        </div>
    @enderror

    {{-- Master switch --}}
    @php $g = $csGates['master']; @endphp
    <div class="flex items-start justify-between gap-4 p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
        <div>
            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $g['label'] }}</div>
            <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $g['explain'] }}</div>
            <div class="text-xs mt-1" x-show="!state['{{ $g['key'] }}']" x-cloak style="color:var(--ds-amber, #b45309);">
                Every check is off: properties can be marketed without any compliance check. Your choices below are kept for when you switch this back on.
            </div>
        </div>
        <input type="hidden" name="gates[{{ $g['key'] }}]" value="{{ $g['enabled'] ? 1 : 0 }}" :value="state['{{ $g['key'] }}'] ? 1 : 0">
        <button type="button" class="cs-switch" :class="{ 'on': state['{{ $g['key'] }}'] }" role="switch"
                :aria-checked="state['{{ $g['key'] }}']" aria-label="{{ $g['label'] }}" @click="flip('{{ $g['key'] }}')"></button>
    </div>

    <div class="space-y-4" :style="state['master'] ? '' : 'opacity:.5;'">
        @if(empty($csGates['documents']))
            <div class="p-4 rounded-md text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                Your agency has no document checks set up, so no documents are required before a property can be marketed.
            </div>
        @endif
        @foreach ($csGroups as $group)
            @continue(empty($group['items']))
            <div class="rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="px-4 pt-3 pb-1">
                    <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">{{ $group['title'] }}</div>
                    @if($group['note'])
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $group['note'] }}</div>
                    @endif
                </div>
                @foreach ($group['items'] as $g)
                    <div class="flex items-start justify-between gap-4 px-4 py-2.5" style="border-top:1px solid var(--border);">
                        <div>
                            <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $g['label'] }}</div>
                            <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $g['explain'] }}</div>
                        </div>
                        <input type="hidden" name="gates[{{ $g['key'] }}]" value="{{ $g['enabled'] ? 1 : 0 }}" :value="state['{{ $g['key'] }}'] ? 1 : 0">
                        <button type="button" class="cs-switch" :class="{ 'on': state['{{ $g['key'] }}'] }" role="switch"
                                :aria-checked="state['{{ $g['key'] }}']" aria-label="{{ $g['label'] }}" @click="flip('{{ $g['key'] }}')"></button>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <p class="text-xs" style="color:var(--text-muted);">
        A check you switch off stops applying straight away. A check you switch back on applies to every listing that is not live yet.
        Listings already live keep the checks they went live with, and their compliance panel says which checks were off at the time.
        @if($complianceLastChange)
            <br>Last changed: {{ $complianceLastChange->gate_label }} switched {{ $complianceLastChange->isSwitchOff() ? 'off' : 'on' }}
            by {{ $complianceLastChange->user_name }}, {{ $complianceLastChange->created_at->timezone(config('app.timezone'))->format('j M Y H:i') }}.
        @endif
    </p>

    {{-- Disclaimer --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.55);" @keydown.escape.window="cancel()">
        <div class="w-full max-w-5xl rounded-md p-8 space-y-5" style="background:var(--surface); border:1px solid var(--border);" @click.outside="cancel()">
            <div class="text-lg font-semibold" style="color:var(--text-primary);">Switching off compliance checks</div>
            <div class="text-base overflow-y-auto" style="max-height:65vh; color:var(--text-secondary); white-space:pre-line;" x-text="disclaimerText()"></div>
            <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
                <input type="checkbox" class="mt-0.5" x-model="accepted">
                <span>{{ $complianceDisclaimer['accept_label'] }}</span>
            </label>
            <div class="flex justify-end gap-2">
                <button type="button" class="corex-btn-outline text-xs" @click="cancel()">Cancel</button>
                <button type="button" class="corex-btn-primary text-xs" :disabled="!accepted" :style="accepted ? '' : 'opacity:.5; cursor:not-allowed;'" @click="confirm()">Confirm and switch off</button>
            </div>
        </div>
    </div>
</div>
