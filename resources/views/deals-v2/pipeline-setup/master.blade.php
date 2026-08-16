@extends('layouts.corex')

{{-- AT-334 Phase 2 — GLOBAL composable master pipeline template editor. --}}
{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@push('head')
<style>
    [x-cloak]{display:none !important;}
    .ms-group{background:var(--surface);border:1px solid var(--border);border-radius:.6rem;margin-bottom:1.1rem;overflow:visible;}
    .ms-ghead{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.7rem .9rem;border-bottom:1px solid var(--border);background:var(--surface-2);border-radius:.6rem .6rem 0 0;}
    .ms-gdesc{width:100%;font-size:.72rem;color:var(--text-muted);margin-top:.15rem;}
    .ms-step{border-top:1px solid var(--border);padding:.55rem .9rem;}
    .ms-step:first-child{border-top:0;}
    .ms-row{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;}
    .ms-name{flex:1 1 15rem;min-width:12rem;}
    .ms-follows{flex:0 1 15rem;min-width:11rem;}
    .ms-key{font-family:ui-monospace,monospace;font-size:.62rem;color:var(--text-muted);}
    .ms-badge{font-size:.58rem;font-weight:700;letter-spacing:.03em;padding:.08rem .35rem;border-radius:.3rem;text-transform:uppercase;}
    .ms-more{margin-top:.55rem;padding-top:.55rem;border-top:1px dashed var(--border);}
    .ms-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.55rem;margin-top:.5rem;}
    .ms-fld label{font-size:.62rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em;display:block;margin-bottom:.15rem;}
    .ms-flags{display:flex;gap:1rem;flex-wrap:wrap;margin-top:.6rem;font-size:.78rem;}
    .ms-flags label{display:flex;align-items:center;gap:.35rem;cursor:pointer;}
    .ms-chip{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;padding:.1rem .2rem .1rem .45rem;border-radius:.35rem;background:color-mix(in srgb,var(--brand-icon) 12%,transparent);color:var(--text-primary);}
    .ms-chip button{color:var(--text-muted);font-size:.85rem;line-height:1;padding:0 .2rem;}
    .ms-pop{position:absolute;z-index:40;top:100%;left:0;min-width:14rem;max-height:13rem;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:.4rem;padding:.4rem;box-shadow:0 8px 20px rgba(0,0,0,.2);}
    .ms-pop label{display:flex;align-items:center;gap:.45rem;padding:.15rem .25rem;font-size:.74rem;cursor:pointer;}
    .ms-flow{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;font-size:.72rem;}
    .ms-flow .n{padding:.2rem .5rem;border-radius:.35rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
    .ms-flow .ar{color:var(--text-muted);}
    .ms-mini{background:none;border:1px solid var(--border);border-radius:.35rem;font-size:.68rem;padding:.15rem .45rem;color:var(--text-secondary);cursor:pointer;}
</style>
@endpush

@section('corex-content')
<div class="w-full space-y-4" x-data="masterEditor()">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Structure — Master Template</h1>
                <p class="text-sm text-white/60">The single pipeline every new composable deal is built from. Edits apply to <strong>new deals only</strong> — existing deals are never changed.</p>
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

    {{-- How it comes together (self-explanatory) --}}
    <div class="rounded-md px-4 py-3.5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-sm" style="color: var(--text-primary);">
            <strong>How a deal is built:</strong> every deal runs the <strong>Base spine</strong>. Ticking a condition
            (<em>Bond</em>, <em>Cash</em>, <em>Sale of another</em>) on a deal adds that condition's steps. Each step
            <strong>follows</strong> a predecessor and lands <strong>+N days</strong> after it. <strong>Granted</strong>
            auto-follows <strong>every step you flag Suspensive</strong> — its date is the <strong>latest</strong> of
            them (you never set Granted directly). A suspensive step is a Stage-1 condition that gates the deal;
            every non-suspensive step runs after Granted, under <strong>Transfer &amp; Registration</strong>.
        </div>
        <div class="ms-flow">
            <span class="n">Deal Signed</span><span class="ar">&rarr;</span>
            <span class="n" style="border-color:color-mix(in srgb,var(--ds-amber) 45%,var(--border));">Condition steps <em>(suspensive)</em></span><span class="ar">&rarr;</span>
            <span class="n" style="border-color:color-mix(in srgb,var(--ds-green) 55%,var(--border));">Granted</span><span class="ar">&rarr;</span>
            <span class="n">Attorneys &middot; COCs &middot; Rates &middot; Docs</span><span class="ar">&rarr;</span>
            <span class="n">Lodgement</span><span class="ar">&rarr;</span>
            <span class="n">Registration</span>
        </div>
        <div class="text-xs" style="color: var(--text-muted);">
            Tip: edit a step's name / offset inline; open <strong>⚙ details</strong> to change what it follows, its
            dependencies and flags. Cash payment fan-out and deposit / proof-of-funds inclusion stay automatic — not edited here.
            <span style="margin-left:.4rem;">Grant-convergence markers:
                <strong x-text="grantCount" x-bind:style="grantCount === 1 ? 'color:var(--ds-green)' : 'color:var(--ds-crimson)'"></strong>
                <span x-show="grantCount !== 1" style="color:var(--ds-crimson);">(must be exactly 1)</span>
            </span>
        </div>
    </div>

    <form method="POST" action="{{ route('deals-v2.pipeline.master.update') }}" x-ref="form">
        @csrf
        @method('PUT')
        <input type="hidden" name="payload" x-ref="payload">

        <template x-for="(g, gi) in groups" x-bind:key="g.key">
            <div class="ms-group">
                {{-- Group header --}}
                <div class="ms-ghead">
                    <span class="ms-badge" style="background:var(--surface);border:1px solid var(--border);color:var(--text-muted);" x-text="g.is_base ? 'BASE SPINE' : 'CONDITION'"></span>
                    <input type="text" x-model="g.label" class="corex-input font-semibold" style="max-width:20rem;" x-bind:readonly="g.is_base">
                    <span class="ms-key" x-text="g.key"></span>
                    <button type="button" class="ms-mini ml-auto" x-on:click="addStep(g)">+ Add step</button>
                    <span class="ms-gdesc" x-text="groupHelp(g.key)"></span>
                </div>

                {{-- Steps --}}
                <template x-for="(s, si) in g.steps" x-bind:key="s.step_key">
                    <div class="ms-step" x-data="{ open:false, depsOpen:false }"
                         x-on:dragover.prevent x-on:drop.prevent="onDrop(gi, si)"
                         x-bind:style="dragFrom && dragFrom.gi === gi && dragFrom.si === si ? 'opacity:.5;' : ''">
                        {{-- Primary row: drag handle · name · follows · offset · flags-at-a-glance --}}
                        <div class="ms-row">
                            <span draggable="true" x-on:dragstart="dragFrom = { gi: gi, si: si }" x-on:dragend="dragFrom = null"
                                  title="Drag to reorder — this order is the display priority"
                                  style="cursor:grab;color:var(--text-muted);user-select:none;padding:0 .25rem;font-size:1rem;line-height:1;">&#x2807;</span>
                            <input type="text" x-model="s.name" class="corex-input ms-name" placeholder="Step name">

                            <div class="flex items-center gap-1 ms-follows">
                                <span class="text-xs" style="color:var(--text-muted);white-space:nowrap;">follows</span>
                                <template x-if="!s.is_grant_marker">
                                    <select x-on:change="s.follows_key = $event.target.value || null" class="corex-input" style="width:100%;">
                                        <option value="" x-bind:selected="!s.follows_key">— none (root)</option>
                                        <option value="__grant__" x-bind:selected="s.follows_key === '__grant__'">↳ Granted</option>
                                        <template x-for="o in allSteps" x-bind:key="o.key">
                                            <option x-bind:value="o.key" x-bind:selected="o.key === s.follows_key" x-show="o.key !== s.step_key" x-text="o.name"></option>
                                        </template>
                                    </select>
                                </template>
                                <span x-show="s.is_grant_marker" class="text-xs" style="color:var(--text-muted);">auto (all suspensive)</span>
                            </div>

                            <div class="flex items-center gap-1" style="white-space:nowrap;">
                                <span class="text-xs" style="color:var(--text-muted);">+</span>
                                <input type="number" min="0" x-model.number="s.days_offset" class="corex-input text-center" style="width:3.6rem;">
                                <span class="text-xs" style="color:var(--text-muted);">days</span>
                            </div>

                            {{-- at-a-glance status --}}
                            <span x-show="s.is_anchor" class="ms-badge" style="background:color-mix(in srgb,var(--brand-icon) 18%,transparent);color:var(--brand-icon);">anchor</span>
                            <span x-show="s.is_grant_marker" class="ms-badge" style="background:color-mix(in srgb,var(--ds-green) 20%,transparent);color:var(--ds-green);">grant</span>
                            <span x-show="s.is_suspensive" class="ms-badge" style="background:color-mix(in srgb,var(--ds-amber) 22%,transparent);color:var(--ds-amber,#b45309);" title="Suspensive — gates Granted">susp</span>
                            <span x-show="s.is_milestone && !s.is_grant_marker && !s.is_anchor" title="Milestone" style="color:var(--text-muted);">◆</span>
                            <span x-show="s.deps_keys.length" class="text-xs" style="color:var(--text-muted);" x-text="'· waits on ' + s.deps_keys.length"></span>

                            <button type="button" class="ms-mini ml-auto" x-on:click="open = !open"
                                    x-bind:style="open ? 'border-color:var(--brand-icon);color:var(--brand-icon);' : ''"
                                    x-text="open ? 'Close details ▲' : 'Edit details ▼'"></button>
                        </div>

                        {{-- Expandable details --}}
                        <div class="ms-more" x-show="open" x-cloak>
                            {{-- Details header + explicit CLOSE (collapses only — never deletes) --}}
                            <div class="flex items-center justify-between" style="margin-bottom:.55rem;">
                                <span class="ms-key" x-text="'Step details · ' + s.step_key"></span>
                                <button type="button" class="ms-mini" x-on:click="open = false" title="Collapse these details">&#x2715; Close</button>
                            </div>

                            {{-- Dependencies (chips + popover) --}}
                            <div class="ms-fld">
                                <label>Also waits on (all must be done first)</label>
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <template x-for="dk in s.deps_keys" x-bind:key="dk">
                                        <span class="ms-chip"><span x-text="stepName(dk)"></span><button type="button" x-on:click="toggleDep(s, dk)">&times;</button></span>
                                    </template>
                                    <span x-show="!s.deps_keys.length && !s.is_grant_marker" class="text-xs" style="color:var(--text-muted);">None</span>
                                    <span x-show="s.is_grant_marker" class="text-xs" style="color:var(--text-muted);">Automatic — converges on all suspensive steps</span>
                                    <div style="position:relative;" x-data="{ o:false }" x-show="!s.is_grant_marker">
                                        <button type="button" class="ms-mini" x-on:click="o = !o">+ add</button>
                                        <div class="ms-pop" x-show="o" x-on:click.outside="o = false" x-cloak>
                                            <template x-for="o2 in allSteps" x-bind:key="o2.key">
                                                <label x-show="o2.key !== s.step_key">
                                                    <input type="checkbox" x-bind:checked="s.deps_keys.includes(o2.key)" x-on:change="toggleDep(s, o2.key)">
                                                    <span x-text="o2.name"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Completion / status / due-from --}}
                            <div class="ms-grid">
                                <div class="ms-fld">
                                    <label>Completed by</label>
                                    <select x-on:change="s.completion_type = $event.target.value" class="corex-input" style="width:100%;">
                                        <template x-for="(label, key) in completionTypes" x-bind:key="key">
                                            <option x-bind:value="key" x-bind:selected="key === s.completion_type" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="ms-fld">
                                    <label>Status trigger</label>
                                    <select x-on:change="s.status_trigger = $event.target.value || null" class="corex-input" style="width:100%;">
                                        <option value="" x-bind:selected="!s.status_trigger">— none</option>
                                        <template x-for="(label, key) in statusTriggers" x-bind:key="key">
                                            <option x-bind:value="key" x-bind:selected="key === s.status_trigger" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="ms-fld" x-show="Object.keys(g.date_options).length">
                                    <label>Due date from</label>
                                    <select x-on:change="s.manual_due_option = $event.target.value || null" class="corex-input" style="width:100%;">
                                        <option value="" x-bind:selected="!s.manual_due_option">— offset only</option>
                                        <template x-for="(label, key) in g.date_options" x-bind:key="key">
                                            <option x-bind:value="key" x-bind:selected="key === s.manual_due_option" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            {{-- Flags --}}
                            <div class="ms-flags">
                                <label><input type="checkbox" x-model="s.is_milestone"> Milestone</label>
                                <label><input type="checkbox" x-model="s.is_suspensive"> Suspensive <span style="color:var(--text-muted);">(gates Granted)</span></label>
                                <label><input type="checkbox" x-model="s.is_grant_marker"> Grant convergence <span style="color:var(--text-muted);">(exactly one)</span></label>
                            </div>

                            {{-- Delete — clearly a delete, confirmed; anchor/grant cannot be removed. --}}
                            <div style="margin-top:.7rem;padding-top:.6rem;border-top:1px solid var(--border);">
                                <template x-if="!s.is_anchor && !s.is_grant_marker">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <button type="button" x-on:click="confirmRemove(g, si, s)"
                                                style="font-size:.75rem;font-weight:600;padding:.28rem .7rem;border-radius:.35rem;color:#fff;background:var(--ds-crimson);border:1px solid var(--ds-crimson);">
                                            Delete this step
                                        </button>
                                        <span class="text-xs" style="color:var(--text-muted);">Removes it from new deals — existing deals keep it. You'll be asked to confirm.</span>
                                    </div>
                                </template>
                                <template x-if="s.is_anchor || s.is_grant_marker">
                                    <span class="text-xs" style="color:var(--text-muted);" x-text="s.is_anchor ? 'The anchor (Deal Signed) cannot be deleted.' : 'The Granted convergence step cannot be deleted.'"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
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
            dragFrom: null,
            // Drag-to-reorder within a group. The resulting row order IS the display priority
            // (persisted on save as each step's index within its group).
            onDrop(gi, si) {
                const from = this.dragFrom;
                this.dragFrom = null;
                if (!from || from.gi !== gi || from.si === si) { return; }
                const arr = this.groups[gi].steps;
                const [moved] = arr.splice(from.si, 1);
                arr.splice(from.si < si ? si - 1 : si, 0, moved);
            },
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
            stepName(key) {
                const s = this.allSteps.find(o => o.key === key);
                return s ? s.name : key;
            },
            groupHelp(key) {
                return ({
                    '__base__': 'Runs on every deal — the common conveyancing steps from signing through to registration.',
                    'bond': 'Added when the deal is bond-financed. Suspensive (gates Granted): Application, Approved, Deposit. Guarantees Issued is post-grant (Transfer).',
                    'cash': 'Added for cash deals. If “proof of funds now” is chosen it is suspensive; payments settle after lodgement. (Payment fan-out is automatic.)',
                    'sale_of_another': 'Subject to the sale of another property. “Linked Property Sold” is suspensive — it gates Granted.',
                })[key] || '';
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
            confirmRemove(g, i, s) {
                if (window.confirm('Delete the step "' + s.name + '"?\n\nNew deals will no longer include it. Existing deals are unchanged. This cannot be undone from here (Save to persist).')) {
                    this.removeStep(g, i);
                }
            },
            toggleDep(s, key) {
                const i = s.deps_keys.indexOf(key);
                if (i === -1) { s.deps_keys.push(key); } else { s.deps_keys.splice(i, 1); }
            },
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
