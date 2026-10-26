{{--
    AT-509 — Compliance Switches form fields (.ai/specs/property-compliance-gate-settings.md §7.1).
    Shared by Settings → Compliance Switches and the Setup Wizard's Properties & listings step: it renders
    NO <form> of its own, it sits inside the host page's form and posts to ComplianceSwitchesController@update.
    Switching anything OFF opens the disclaimer before the host form is allowed to submit; the server
    refuses a switch-off without the accepted version anyway (ComplianceGateSettingsService::apply).

    Needs: $complianceGates, $complianceLastChange, $complianceDisclaimer (ComplianceSwitchesController::formData()).
--}}
@php
    $csGates = $complianceGates;
    $csAll = array_merge([$csGates['master']], $csGates['documents'], $csGates['other_documents'], $csGates['core']);
    $csUid = 'cs' . substr(md5(uniqid('', true)), 0, 8);
    $csData = [
        'initial'  => collect($csAll)->mapWithKeys(fn ($g) => [$g['key'] => (bool) $g['enabled']])->all(),
        'labels'   => collect($csAll)->mapWithKeys(fn ($g) => [$g['key'] => $g['label']])->all(),
        'order'    => array_column($csAll, 'key'),
        'template' => $complianceDisclaimer['template'],
        'version'  => $complianceDisclaimer['version'],
    ];
    $csGroups = [
        ['title' => 'Documents', 'note' => 'Each switched-on document must be on file before the property can be marketed.', 'items' => $csGates['documents']],
        ['title' => 'Listing', 'note' => null, 'items' => $csGates['core']],
        ['title' => 'Other document types you can require', 'note' => 'Not required today. Switch one on to make it a requirement for marketing.', 'items' => $csGates['other_documents']],
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
        state: {}, initial: {}, labels: {}, order: [], template: '', version: '',
        open: false, accepted: false, confirmed: false, offList: [], submitter: null, form: null,
        init() {
            const d = JSON.parse(document.getElementById('{{ $csUid }}-data').textContent);
            this.initial = d.initial; this.state = Object.assign({}, d.initial);
            this.labels = d.labels; this.order = d.order; this.template = d.template; this.version = d.version;
            this.form = this.$el.closest('form');
            if (this.form) { this.form.addEventListener('submit', (e) => this.onSubmit(e)); }
        },
        flip(key) { this.state[key] = !this.state[key]; },
        onSubmit(e) {
            if (this.confirmed) { return; }
            const off = this.order.filter((k) => this.initial[k] && !this.state[k]);
            if (off.length === 0) { return; }
            e.preventDefault();
            this.submitter = e.submitter || null;
            this.offList = off.map((k) => this.labels[k]);
            this.accepted = false;
            this.open = true;
        },
        disclaimerText() { return this.template.replace('{gates}', this.offList.join(', ')); },
        confirm() {
            if (!this.accepted) { return; }
            this.$refs.accepted.value = this.version;
            this.confirmed = true;
            this.open = false;
            this.$nextTick(() => { this.submitter ? this.form.requestSubmit(this.submitter) : this.form.requestSubmit(); });
        },
        cancel() { this.open = false; this.accepted = false; }
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
        <button type="button" class="cs-switch {{ $g['enabled'] ? 'on' : '' }}" :class="state['{{ $g['key'] }}'] && 'on'" role="switch"
                :aria-checked="state['{{ $g['key'] }}']" aria-label="{{ $g['label'] }}" @click="flip('{{ $g['key'] }}')"></button>
    </div>

    <div class="space-y-4" :style="state['master'] ? '' : 'opacity:.5;'">
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
                        <button type="button" class="cs-switch {{ $g['enabled'] ? 'on' : '' }}" :class="state['{{ $g['key'] }}'] && 'on'" role="switch"
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
        <div class="w-full max-w-lg rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);" @click.outside="cancel()">
            <div class="text-base font-semibold" style="color:var(--text-primary);">Switching off compliance checks</div>
            <div class="text-sm max-h-80 overflow-y-auto" style="color:var(--text-secondary); white-space:pre-line;" x-text="disclaimerText()"></div>
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
