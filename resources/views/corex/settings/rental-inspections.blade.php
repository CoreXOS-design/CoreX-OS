@extends('layouts.corex')

{{--
    .ai/specs/agency-onboarding-rentals-step.md — Johan's standing rule: any
    threshold is agency-configurable with a sensible default, never
    hardcoded, and the control ships with the feature.
--}}

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Rental Inspection Settings</h1>
            <p class="text-sm text-white/60">How long a tenant has to report a fault, and how long they have to sign an out-inspection.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-inspections.update') }}" class="space-y-4"
          x-data="{ presets: {{ Js::from(collect($refusalReasonPresets)->reject(fn($p) => $p['key'] === 'other')->values()) }} }">
        @csrf

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Inspection windows</h3>
            </div>
            <div class="p-5 space-y-5">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Days a tenant has to report a fault after moving in</label>
                    <input type="number" name="fault_report_window_days" value="{{ old('fault_report_window_days', $faultReportWindowDays) }}"
                           min="1" max="90" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultFaultReportDays }} days. A report after this window still
                        reaches the agent — it is just their call whether to accept it.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Days a tenant has to sign the out-inspection</label>
                    <input type="number" name="out_inspection_signing_window_days" value="{{ old('out_inspection_signing_window_days', $signingWindowDays) }}"
                           min="1" max="60" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultSigningDays }} days. After this, an agent may sign on the
                        tenant's behalf, with a note recording why.
                    </p>
                </div>
            </div>
        </div>

        {{-- §15.5/§15.6 — the one-tap preset list an agent picks a refusal
             reason from. "Other" is always available and is never listed
             here — it can't be removed or reworded, so there's nothing to
             edit about it. --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Refusal reasons</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    When a tenant or landlord refuses to sign an inspection, the agent picks one of
                    these reasons (plus "Other", always available, not editable here).
                </p>
                <template x-for="(preset, i) in presets" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="preset.label" :name="`refusal_reason_presets[${i}][label]`"
                               maxlength="191" required
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <input type="hidden" :name="`refusal_reason_presets[${i}][key]`" :value="preset.key">
                        <button type="button" @click="presets.splice(i, 1)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button"
                        @click="presets.push({ key: 'custom_' + Date.now(), label: '' })"
                        class="corex-btn-outline text-xs">+ Add a reason</button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>

    {{-- Johan, 2026-09-20 — "we have a features list in settings somewhere.
         should we expand it that we tick which features are allowed on
         inspections? I think its the better shape than relying on the
         system to do it automatically." The catalog itself (labels,
         categories) is unchanged and shared with the property edit screen —
         this is only which of those EXISTING labels this agency wants
         carried into an inspection checklist. Own form/endpoint, separate
         from the section above. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.features') }}" class="space-y-3"
          x-data="{
              query: '',
              included: {{ Js::from($includedFeatureLabels) }},
              categories: {{ Js::from($featureCategories) }},
              isChecked(label) { return this.included.includes(label); },
              toggle(label) {
                  const i = this.included.indexOf(label);
                  if (i === -1) { this.included.push(label); } else { this.included.splice(i, 1); }
              },
              matches(label) { return this.query === '' || label.toLowerCase().includes(this.query.toLowerCase()); },
              categoryHasMatch(catDef) { return catDef.features.some(f => this.matches(f)); },
          }">
        @csrf
        <input type="hidden" name="inspection_features_submitted" value="1">
        <template x-for="label in included" :key="'selected-' + label">
            <input type="hidden" name="inspection_feature_labels[]" :value="label">
        </template>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Inspection features</h3>
            </div>
            <div class="p-5 space-y-4">
                <p class="text-xs" style="color: var(--text-muted);">
                    When an inspection checklist is seeded from a property's advertising features,
                    only the features ticked below become inspection items. A feature can be worth
                    advertising without being something an inspector checks — the split is yours to
                    make, not the system's.
                </p>
                <input type="text" x-model="query" placeholder="Search features…"
                       class="w-full max-w-xs rounded-md px-3 py-2 text-sm" style="border:1px solid var(--border);">

                <template x-for="[catKey, catDef] in Object.entries(categories)" :key="catKey">
                    <div x-show="categoryHasMatch(catDef)" class="space-y-2">
                        <h4 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);" x-text="catDef.label"></h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-1.5">
                            <template x-for="feature in catDef.features" :key="feature">
                                <label class="flex items-center gap-2 text-sm py-0.5" x-show="matches(feature)" style="color:var(--text-primary);">
                                    <input type="checkbox" :checked="isChecked(feature)" @change="toggle(feature)"
                                           class="rounded" style="accent-color:var(--brand-icon,#0ea5e9);">
                                    <span x-text="feature"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save features</button>
        </div>
    </form>

    {{-- Johan, 2026-09-20 — "we should have a setting somewhere on rentals
         that defines room types and what gets added - ceiling, walls,
         floors, windows, doors - that should be a std. if its a patio as
         example there are still things to check." Every room type not
         listed here uses that standard baseline automatically (never
         nothing) — this list is only the types an agency has actively
         customized away from it. Agreed the underlying shape with cc4
         (owns the inspections-rework seeder that reads this) before
         building. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.room-type-defaults') }}" class="space-y-3"
          x-data="{
              standard: {{ Js::from($standardRoomTypeItems) }},
              allTypes: {{ Js::from($allSpaceTypes) }},
              rows: {{ Js::from(collect($roomTypeOverrides)->map(fn ($items, $type) => ['type' => $type, 'items' => $items])->values()) }},
              picking: '',
              availableTypes() { return this.allTypes.filter(t => !this.rows.some(r => r.type === t)); },
              addType() {
                  if (!this.picking) return;
                  this.rows.push({ type: this.picking, items: [...this.standard] });
                  this.picking = '';
              },
              removeType(i) { this.rows.splice(i, 1); },
          }">
        @csrf
        <input type="hidden" name="room_type_item_defaults_submitted" value="1">

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Room type default items</h3>
            </div>
            <div class="p-5 space-y-4">
                <p class="text-xs" style="color: var(--text-muted);">
                    Every room type starts with the standard checklist —
                    <span x-text="standard.join(', ')"></span> — until you customize it here. A type
                    that needs fewer or different items, like a patio, is edited below; anything not
                    listed keeps the standard checklist automatically.
                </p>

                <template x-if="rows.length === 0">
                    <p class="text-sm py-3" style="color: var(--text-muted);">
                        No room types customized yet — every room type uses the standard checklist above.
                    </p>
                </template>

                <template x-for="(row, i) in rows" :key="row.type">
                    <div class="rounded-md p-4 space-y-2" style="border:1px solid var(--border);">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold" style="color:var(--text-primary);" x-text="row.type"></span>
                            <button type="button" @click="removeType(i)" class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                        </div>
                        <template x-for="(item, j) in row.items" :key="j">
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="row.items[j]" :name="`room_type_item_defaults[${row.type}][]`"
                                       maxlength="100" required
                                       class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                                <button type="button" @click="row.items.splice(j, 1)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                            </div>
                        </template>
                        <button type="button" @click="row.items.push('')" class="corex-btn-outline text-xs">+ Add an item</button>
                        <template x-if="row.items.length === 0">
                            <input type="hidden" :name="`room_type_item_defaults[${row.type}][]`" value="">
                        </template>
                    </div>
                </template>

                <div class="flex items-center gap-2 pt-2" style="border-top:1px solid var(--border);">
                    <select x-model="picking" class="rounded-md px-3 py-2 text-sm" style="border:1px solid var(--border);">
                        <option value="">Customize a room type…</option>
                        <template x-for="type in availableTypes()" :key="type">
                            <option :value="type" x-text="type"></option>
                        </template>
                    </select>
                    <button type="button" @click="addType()" class="corex-btn-outline text-xs" :disabled="!picking">+ Add</button>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save room type defaults</button>
        </div>
    </form>
</div>
@endsection
