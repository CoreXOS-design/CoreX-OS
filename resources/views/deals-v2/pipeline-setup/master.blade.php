@extends('layouts.corex')

{{-- AT-334 Phase 2 — GLOBAL composable master pipeline template editor. --}}
{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5" x-data="masterEditor()">

    {{-- Header (Pattern A — branded banner) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Structure — Master Template</h1>
                <p class="text-sm text-white/60">The single pipeline definition every new composable deal is built from. Edits apply to <strong>new deals only</strong> — existing deals are never changed.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-btn-outline corex-btn-on-brand">Back to templates</a>
                <button type="button" class="corex-btn-primary inline-flex items-center gap-2" x-on:click="submit()" x-bind:disabled="saving">
                    <span x-show="!saving">Save master template</span>
                    <span x-show="saving">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif

    {{-- Guardrail live hint --}}
    <div class="rounded-md px-4 py-2.5 text-xs flex items-center gap-4 flex-wrap" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
        <span>Grant-convergence markers:
            <strong x-text="grantCount" x-bind:style="grantCount === 1 ? 'color:var(--ds-green)' : 'color:var(--ds-crimson)'"></strong>
            <span x-show="grantCount !== 1" style="color:var(--ds-crimson);">(must be exactly 1)</span>
        </span>
        <span style="color:var(--text-muted);">Cash payment fan-out, deposit &amp; proof-of-funds inclusion stay automatic (procedural) — not edited here.</span>
    </div>

    <form method="POST" action="{{ route('deals-v2.pipeline.master.update') }}" x-ref="form">
        @csrf
        @method('PUT')
        <input type="hidden" name="payload" x-ref="payload">

        <template x-for="(g, gi) in groups" x-bind:key="g.key">
            <div class="rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
                {{-- Group header --}}
                <div class="px-4 py-3 flex items-center gap-3 flex-wrap" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                    <span class="ds-badge ds-badge-default font-mono" x-text="g.key"></span>
                    <input type="text" x-model="g.label" class="corex-input font-semibold" style="max-width:22rem;" x-bind:readonly="g.is_base" x-bind:title="g.is_base ? 'The base spine label is fixed' : 'Condition label'">
                    <span class="text-xs" style="color:var(--text-muted);" x-show="!g.is_base">condition</span>
                    <button type="button" class="corex-btn-outline ml-auto text-xs" x-on:click="addStep(g)">+ Add step</button>
                </div>

                {{-- Steps table --}}
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs ds-table">
                        <thead>
                            <tr style="background: var(--surface);">
                                <th class="text-left px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Name</th>
                                <th class="text-left px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Follows</th>
                                <th class="text-left px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Also waits on (deps)</th>
                                <th class="text-center px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Offset</th>
                                <th class="text-center px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Mile</th>
                                <th class="text-center px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Susp</th>
                                <th class="text-center px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Grant</th>
                                <th class="text-left px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Completion</th>
                                <th class="text-left px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status trigger</th>
                                <th class="text-left px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Due from date</th>
                                <th class="text-center px-2 py-2 font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Pos</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(s, si) in g.steps" x-bind:key="si">
                                <tr style="border-top:1px solid var(--border);">
                                    <td class="px-2 py-1.5">
                                        <input type="text" x-model="s.name" class="corex-input" style="min-width:12rem;">
                                        <div class="font-mono mt-0.5" style="color:var(--text-muted);font-size:.65rem;" x-text="s.step_key"></div>
                                        <span x-show="s.is_anchor" class="ds-badge ds-badge-info" style="font-size:.6rem;">anchor</span>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <select x-on:change="s.follows_key = $event.target.value || null" class="corex-input" style="min-width:11rem;" x-bind:disabled="s.is_grant_marker">
                                            <option value="" x-bind:selected="!s.follows_key">— none (root)</option>
                                            <option value="__grant__" x-bind:selected="s.follows_key === '__grant__'">↳ Granted marker</option>
                                            <template x-for="o in allSteps" x-bind:key="o.key">
                                                <option x-bind:value="o.key" x-bind:selected="o.key === s.follows_key" x-show="o.key !== s.step_key" x-text="o.name + ' (' + o.key + ')'"></option>
                                            </template>
                                        </select>
                                        <div x-show="s.is_grant_marker" style="color:var(--text-muted);font-size:.6rem;">auto: converges on all suspensive</div>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <select multiple x-on:change="s.deps_keys = Array.from($event.target.selectedOptions).map(o => o.value)" class="corex-input" style="min-width:11rem;height:3.6rem;" x-bind:disabled="s.is_grant_marker">
                                            <template x-for="o in allSteps" x-bind:key="o.key">
                                                <option x-bind:value="o.key" x-bind:selected="s.deps_keys.includes(o.key)" x-show="o.key !== s.step_key" x-text="o.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        <input type="number" min="0" x-model.number="s.days_offset" class="corex-input text-center" style="width:4rem;">
                                    </td>
                                    <td class="px-2 py-1.5 text-center"><input type="checkbox" x-model="s.is_milestone"></td>
                                    <td class="px-2 py-1.5 text-center"><input type="checkbox" x-model="s.is_suspensive"></td>
                                    <td class="px-2 py-1.5 text-center"><input type="checkbox" x-model="s.is_grant_marker"></td>
                                    <td class="px-2 py-1.5">
                                        <select x-on:change="s.completion_type = $event.target.value" class="corex-input" style="min-width:9rem;">
                                            <template x-for="(label, key) in completionTypes" x-bind:key="key">
                                                <option x-bind:value="key" x-bind:selected="key === s.completion_type" x-text="label"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <select x-on:change="s.status_trigger = $event.target.value || null" class="corex-input" style="min-width:9rem;">
                                            <option value="" x-bind:selected="!s.status_trigger">— none</option>
                                            <template x-for="(label, key) in statusTriggers" x-bind:key="key">
                                                <option x-bind:value="key" x-bind:selected="key === s.status_trigger" x-text="label"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <template x-if="Object.keys(g.date_options).length">
                                            <select x-on:change="s.manual_due_option = $event.target.value || null" class="corex-input" style="min-width:9rem;">
                                                <option value="" x-bind:selected="!s.manual_due_option">— offset only</option>
                                                <template x-for="(label, key) in g.date_options" x-bind:key="key">
                                                    <option x-bind:value="key" x-bind:selected="key === s.manual_due_option" x-text="label"></option>
                                                </template>
                                            </select>
                                        </template>
                                        <span x-show="!Object.keys(g.date_options).length" style="color:var(--text-muted);">—</span>
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        <input type="number" x-model.number="s.position" class="corex-input text-center" style="width:4rem;">
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        <button type="button" class="pipeline-action-btn"
                                                x-show="!s.is_anchor && !s.is_grant_marker"
                                                x-on:click="removeStep(g, si)" title="Remove step"
                                                style="color:var(--ds-crimson);font-size:1rem;line-height:1;">&times;</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </form>
</div>

@push('scripts')
<script>
    function masterEditor() {
        return {
            groups: @json($master['groups']),
            completionTypes: @json($completionTypes),
            statusTriggers: @json($statusTriggers),
            saving: false,
            get allSteps() {
                const out = [];
                this.groups.forEach(g => g.steps.forEach(s => out.push({ key: s.step_key, name: s.name })));
                return out;
            },
            get grantCount() {
                let n = 0;
                this.groups.forEach(g => g.steps.forEach(s => { if (s.is_grant_marker) n++; }));
                return n;
            },
            addStep(g) {
                const maxPos = g.steps.length ? Math.max.apply(null, g.steps.map(s => Number(s.position) || 0)) : 0;
                const suffix = Math.random().toString(36).slice(2, 7);
                g.steps.push({
                    step_key: 'custom_' + suffix, name: 'New step', follows_key: null, deps_keys: [],
                    days_offset: 0, is_milestone: false, is_suspensive: false, is_anchor: false,
                    is_grant_marker: false, completion_type: 'manual_tick', status_trigger: null,
                    manual_due_option: null, position: maxPos + 10,
                    requires_option: null, requires_funds_mode: null, expand: null,
                });
            },
            removeStep(g, i) { g.steps.splice(i, 1); },
            submit() {
                if (this.grantCount !== 1) {
                    alert('Exactly ONE grant-convergence marker is required (currently ' + this.grantCount + ').');
                    return;
                }
                this.$refs.payload.value = JSON.stringify(this.groups);
                this.saving = true;
                this.$refs.form.submit();
            },
        };
    }
</script>
@endpush
@endsection
