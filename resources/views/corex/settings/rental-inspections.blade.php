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
    @if(session('warning'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
            {{ session('warning') }}
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
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Days the public inspection-report link stays live</label>
                    <input type="number" name="public_link_expiry_days" value="{{ old('public_link_expiry_days', $publicLinkExpiryDays) }}"
                           min="1" max="3650"
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultPublicLinkExpiryDays }} days. The link on a completed
                        inspection's PDF (a tenant or landlord opens it with no CoreX login) stops working
                        after this — an agent can always issue a fresh one from the inspection's own screen.
                    </p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold" style="color:var(--text-primary);">
                        <input type="hidden" name="require_notes_blocks_progression" value="0">
                        <input type="checkbox" name="require_notes_blocks_progression" value="1" @checked(old('require_notes_blocks_progression', $requireNotesBlocksProgression))>
                        Block an inspection from moving on while a required note is missing
                    </label>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        On (the default): an agent cannot send an inspection for signature or complete it while
                        an item graded with a state that "needs a reason" has no note. Off: the same list of
                        missing notes is still shown, but only as a warning.
                    </p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold" style="color:var(--text-primary);">
                        <input type="hidden" name="all_items_required_to_complete" value="0">
                        <input type="checkbox" name="all_items_required_to_complete" value="1" @checked(old('all_items_required_to_complete', $allItemsRequiredToComplete))>
                        Require every checklist item to be recorded before an inspection can be signed or completed
                    </label>
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        On (the default): an incoming or outgoing inspection cannot be sent for signature or
                        completed while any item in any room has not been recorded — the agent is shown exactly
                        which rooms and items are left. "Not applicable" counts as recorded. Off: an inspection
                        can be completed with items left unrecorded.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Scanned-form tick-box sensitivity</label>
                    <input type="number" name="omr_mark_threshold" value="{{ old('omr_mark_threshold', $omrMarkThreshold) }}"
                           min="0.05" max="0.95" step="0.05"
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is 0.35. The share of a tick box that must be dark before a scanned paper
                        inspection form reads it as marked. Lower it for faint or light scans; raise it if
                        stray marks are being read as ticks.
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

    {{-- .ai/specs/rental-inspections.md §45.4 item 3 (Build I-2) — the agency's
         OWN room types ("Roof space", "DB board", "Pool house"), on top of the 50
         standard ones. A new type starts on the standard checklist until you give
         it its own items in "Room type default items" below. "Archive" never
         deletes: rooms already filed under a type keep it, the type just stops
         being offered for new rooms, and "Restore" brings it back. A type's
         internal key is generated once and never changes when it is renamed, so
         renaming can never orphan an existing room. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.custom-room-types') }}" class="space-y-3"
          x-data="{
              rows: {{ Js::from(collect($customRoomTypes)->map(fn ($t) => ['key' => $t['key'], 'label' => $t['label'], 'archived' => $t['archived']])->values()) }},
              addRow() { this.rows.push({ key: '', label: '', archived: false }); },
              activeCount() { return this.rows.filter(r => !r.archived).length; },
          }">
        @csrf
        <input type="hidden" name="custom_room_types_submitted" value="1">

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Your own room types</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    CoreX offers 50 standard room types. If your inspections need one it does not list &mdash; a roof space, a
                    DB board, a pool house &mdash; add it here and it appears in the room-type picker on every property, in the
                    room-type checklists below and in the walking order. Archiving a type never touches rooms that already use it.
                </p>

                <template x-if="rows.length === 0">
                    <p class="text-sm py-3" style="color: var(--text-muted);">
                        You have not added any room types of your own yet &mdash; every property uses the 50 standard ones. Add one below if you need it.
                    </p>
                </template>

                <template x-for="(row, i) in rows" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="row.label" :name="`custom_room_types[${i}][label]`" maxlength="60"
                               placeholder="Room type name" :readonly="row.archived"
                               class="flex-1 rounded-md px-3 py-2 text-sm"
                               :style="'border: 1px solid var(--border);' + (row.archived ? ' opacity:.55;' : '')">
                        <input type="hidden" :name="`custom_room_types[${i}][key]`" :value="row.key">
                        <input type="hidden" :name="`custom_room_types[${i}][archived]`" :value="row.archived ? '1' : '0'">
                        <template x-if="row.archived">
                            <span class="text-[11px] font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Archived</span>
                        </template>
                        <template x-if="row.key && !row.archived">
                            <button type="button" @click="row.archived = true" class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Archive</button>
                        </template>
                        <template x-if="row.key && row.archived">
                            <button type="button" @click="row.archived = false" class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--brand-button, #0ea5e9);">Restore</button>
                        </template>
                        <template x-if="!row.key">
                            <button type="button" @click="rows.splice(i, 1)" class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                        </template>
                    </div>
                </template>

                <button type="button" @click="addRow()" class="corex-btn-outline text-xs">+ Add a room type</button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save room types</button>
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
              allTypes: {{ Js::from(array_values(array_diff($allSpaceTypes, collect($customRoomTypes)->where('archived', true)->pluck('key')->all()))) }},
              labels: {{ Js::from($roomTypeLabels) }},
              current: {{ Js::from($roomTypeCurrentItems) }},
              rows: {{ Js::from(collect($roomTypeOverrides)->map(fn ($items, $type) => ['type' => $type, 'items' => $items])->values()) }},
              picking: '',
              labelOf(type) { return this.labels[type] || type; },
              availableTypes() { return this.allTypes.filter(t => !this.rows.some(r => r.type === t)); },
              addType() {
                  if (!this.picking) return;
                  this.rows.push({ type: this.picking, items: [...(this.current[this.picking] || this.standard)] });
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
                    Every room type starts with CoreX's standard floor-to-ceiling checklist for that kind of
                    room (a kitchen gets its sink, stove and cupboards; a bathroom its bath, basin and toilet;
                    anything else gets <span x-text="standard.join(', ')"></span>) until you customize it here.
                    A type that needs fewer or different items is edited below &mdash; it starts from the
                    items it has today. Anything not listed keeps the standard checklist automatically, and a
                    change here only affects rooms added from now on (an agent adds the new items to an existing
                    property's room with &ldquo;Add missing standard items&rdquo; on that room).
                </p>

                <template x-if="rows.length === 0">
                    <p class="text-sm py-3" style="color: var(--text-muted);">
                        No room types customized yet — every room type uses its standard checklist.
                    </p>
                </template>

                <template x-for="(row, i) in rows" :key="row.type">
                    <div class="rounded-md p-4 space-y-2" style="border:1px solid var(--border);">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold" style="color:var(--text-primary);" x-text="labelOf(row.type)"></span>
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
                            <option :value="type" x-text="labelOf(type)"></option>
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

    {{-- Johan, 2026-09-21, property 5792 — "theres no logical way to line
         up the rooms as the inspection goes." The default order a NEW
         room's sort_order is computed from. This is a full reordering of
         every space type (not a sparse override like the item defaults
         above), so every type is always listed and always has a position —
         nothing to add or remove here, only to reorder. An agent can still
         reorder any one property's own rooms afterwards from the property
         screen itself; this only sets where a newly added room starts. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.room-type-order') }}" class="space-y-3"
          x-data="{
              order: {{ Js::from(array_values(array_diff($roomTypeWalkingOrder, collect($customRoomTypes)->where('archived', true)->pluck('key')->all()))) }},
              labels: {{ Js::from($roomTypeLabels) }},
              labelOf(type) { return this.labels[type] || type; },
              moveUp(i) { if (i === 0) return; const t = this.order[i - 1]; this.order[i - 1] = this.order[i]; this.order[i] = t; },
              moveDown(i) { if (i === this.order.length - 1) return; const t = this.order[i + 1]; this.order[i + 1] = this.order[i]; this.order[i] = t; },
          }">
        @csrf
        <input type="hidden" name="room_type_walking_order_submitted" value="1">

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Room walking order</h3>
            </div>
            <div class="p-5 space-y-2">
                <p class="text-xs" style="color: var(--text-muted);">
                    The order a new room is placed in on a property's inspection checklist — an agent
                    can always reorder a specific property's own rooms afterwards; this only sets where
                    a newly added room starts.
                </p>
                <template x-for="(type, i) in order" :key="type">
                    <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                        <span class="text-sm" style="color:var(--text-primary);" x-text="(i + 1) + '. ' + labelOf(type)"></span>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="moveUp(i)" :disabled="i === 0"
                                    class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--text-secondary);">Move up</button>
                            <button type="button" @click="moveDown(i)" :disabled="i === order.length - 1"
                                    class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--text-secondary);">Move down</button>
                        </div>
                        <input type="hidden" name="room_type_walking_order[]" :value="type">
                    </div>
                </template>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save walking order</button>
        </div>
    </form>

    {{-- §17, Johan 2026-09-21, from Retha's real paper out-inspection form:
         her vocabulary is Good/OK/Bad, ours is Good/Fair/Damaged/Not
         working/Missing/Other/N/A. Neither is forced on the other agency —
         this is the SET itself, agency-configurable. An existing row's key
         is carried as a hidden field, never re-derived from its label, so
         it can never drift out from under observations already recorded
         against it; only a brand-new row gets a freshly generated key. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.condition-states') }}" class="space-y-3"
          x-data="{
              states: {{ Js::from($conditionStates) }},
              baseline: {{ Js::from($baselineConditionKey) }},
              severities: {{ Js::from(\App\Models\RentalInspectionSetting::SEVERITY_LABELS) }},
              addState() { this.states.push({ key: 'custom_' + Date.now(), label: '', requires_notes: true, severity: 'red' }); },
          }">
        @csrf
        <input type="hidden" name="condition_states_submitted" value="1">

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Condition states</h3>
            </div>
            <div class="p-5 space-y-2">
                <p class="text-xs" style="color: var(--text-muted);">
                    What an inspector can grade an item as, in the order offered. "Needs a reason"
                    means the agent must type a note before that state can be saved — a Good rating
                    or something genuinely not applicable to the property need no explanation, but
                    anything else does. "Colour" is how that condition shows up on the recording
                    screen and the signed PDF report — Issue (red) and Caution (amber) are also what
                    drives the recording screen's "Needs attention" filter and each room's issue
                    count; Calm (blue) and Neutral (grey) do not.
                </p>
                <template x-for="(state, i) in states" :key="state.key">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="state.label" :name="`condition_states[${i}][label]`"
                               maxlength="60" required placeholder="Label"
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                            <input type="checkbox" x-model="state.requires_notes">
                            Needs a reason
                        </label>
                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                            Colour
                            <select x-model="state.severity" :name="`condition_states[${i}][severity]`"
                                    class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                                <template x-for="(label, sevKey) in severities" :key="sevKey">
                                    <option :value="sevKey" x-text="label"></option>
                                </template>
                            </select>
                        </label>
                        <input type="hidden" :name="`condition_states[${i}][key]`" :value="state.key">
                        <input type="hidden" :name="`condition_states[${i}][requires_notes]`" :value="state.requires_notes ? '1' : '0'">
                        <button type="button" @click="states.splice(i, 1)" :disabled="states.length <= 1"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button" @click="addState()" class="corex-btn-outline text-xs">+ Add a condition state</button>

                {{-- Item 5, 2026-09-22 — the Inspections tab's one-tap "All
                     Good" bulk-fill needs to know which of the states above
                     counts as this agency's baseline, never hardcoded to the
                     word "Good". --}}
                <div class="pt-2" style="border-top:1px solid var(--border);">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        "All Good" bulk-fill uses this state
                    </label>
                    <select name="baseline_condition_key" x-model="baseline" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <template x-for="state in states" :key="state.key">
                            <option :value="state.key" x-text="state.label"></option>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save condition states</button>
        </div>
    </form>

    {{-- AT-433 Part C, .ai/specs/rental-inspections.md §25 — the photo note's
         classification list. Same repeater shape as the condition states above; an
         existing row's key is carried as a hidden field, never re-derived. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.photo-note-classifications') }}" class="space-y-3"
          x-data="{ classifications: {{ Js::from($photoNoteClassifications) }} }">
        @csrf
        <input type="hidden" name="photo_note_classifications_submitted" value="1">

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Photo note classifications</h3>
            </div>
            <div class="p-5 space-y-2">
                <p class="text-xs" style="color: var(--text-muted);">
                    What an inspector can classify a photo note as. At least one is required.
                </p>
                <template x-for="(row, i) in classifications" :key="row.key">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="row.label" :name="`photo_note_classifications[${i}][label]`"
                               maxlength="60" required placeholder="Label"
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <input type="hidden" :name="`photo_note_classifications[${i}][key]`" :value="row.key">
                        <button type="button" @click="classifications.splice(i, 1)" :disabled="classifications.length <= 1"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button" @click="classifications.push({ key: 'custom_' + Date.now(), label: '' })" class="corex-btn-outline text-xs">+ Add a classification</button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save classifications</button>
        </div>
    </form>

    {{-- §41, 2026-09-28, Johan's ruling — "auto-send on/off is an agency
         setting, default ON." Filing to the property is never optional
         (this toggle only governs the automatic EMAIL); the manual
         "Resend" button on a completed inspection always works
         regardless of this setting. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.auto-send-report') }}" class="space-y-3">
        @csrf
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Automatic report sending</h3>
            </div>
            <div class="p-5">
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    {{-- Hidden fallback BEFORE the checkbox, same name — an
                         unchecked checkbox sends nothing at all, so without
                         this the field is simply absent from the POST and
                         has('auto_send_report_enabled') below would be
                         false, wrongly rejecting a legitimate "turn this
                         off" save. Same pattern the onboarding wizard's own
                         generic toggle control already uses
                         (agency-setup/wizard.blade.php:119). --}}
                    <input type="hidden" name="auto_send_report_enabled" value="0">
                    <input type="checkbox" name="auto_send_report_enabled" value="1" @checked($autoSendReportEnabled)>
                    Email the signed report to the tenant(s) and landlord automatically the moment an inspection completes
                </label>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    Sent from the completing agent's own mailbox, with a copy in their Sent Items and the agent CC'd.
                    Turning this off does not remove the manual "Resend report" button on a completed inspection.
                </p>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>

    {{-- §45.6 (Build I-4) — who else is copied on the completed report. The tenant(s) and landlord(s) always are. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.report-copies') }}" class="space-y-3" data-qa="report-copies-form">
        @csrf
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Who else gets a copy of the report</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    The tenant(s) and landlord(s) on the lease always receive the signed report. These are the extra copies.
                </p>
                <div>
                    <label for="report_agency_copy_emails" class="block text-sm" style="color: var(--text-primary);">Agency copy address(es)</label>
                    <input id="report_agency_copy_emails" type="text" name="report_agency_copy_emails" maxlength="2000"
                           value="{{ old('report_agency_copy_emails', $reportAgencyCopyEmails) }}" placeholder="e.g. rentals@youragency.co.za"
                           class="mt-1 w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Separate several with commas. Leave empty for none. Up to {{ \App\Models\RentalInspectionSetting::MAX_REPORT_AGENCY_COPY_ADDRESSES }}.</p>
                </div>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="report_copy_inspector" value="0">
                    <input type="checkbox" name="report_copy_inspector" value="1" @checked($reportCopyInspector)>
                    Send a copy to the inspector who ran the inspection
                </label>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="report_copy_creator" value="0">
                    <input type="checkbox" name="report_copy_creator" value="1" @checked($reportCopyCreator)>
                    Send a copy to the agent who created the inspection
                </label>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>

    {{-- §43 — which parties are notified on schedule/reschedule/cancel,
         which channel(s), the minimum notice period, and the reminder
         offset. WhatsApp is logged as queued, not actually sent — see
         App\Services\Rentals\RentalInspectionNotificationService's own
         docblock: there is no server-side WhatsApp sending API anywhere
         in CoreX today. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.schedule-notifications') }}" class="space-y-3">
        @csrf
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Schedule notifications</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">Who is notified when an inspection is scheduled, rescheduled, or cancelled.</p>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_tenant_enabled" value="0">
                    <input type="checkbox" name="notify_tenant_enabled" value="1" @checked($notifyTenantEnabled)>
                    Tenant(s)
                </label>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_landlord_enabled" value="0">
                    <input type="checkbox" name="notify_landlord_enabled" value="1" @checked($notifyLandlordEnabled)>
                    Landlord
                </label>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_inspector_enabled" value="0">
                    <input type="checkbox" name="notify_inspector_enabled" value="1" @checked($notifyInspectorEnabled)>
                    Inspector
                </label>

                <p class="text-xs mt-3" style="color: var(--text-muted);">Which channel(s) to notify through.</p>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_via_mail_enabled" value="0">
                    <input type="checkbox" name="notify_via_mail_enabled" value="1" @checked($notifyViaMailEnabled)>
                    Email
                </label>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_via_whatsapp_enabled" value="0">
                    <input type="checkbox" name="notify_via_whatsapp_enabled" value="1" @checked($notifyViaWhatsappEnabled)>
                    WhatsApp
                </label>
                <p class="text-xs" style="color: var(--text-muted);">
                    There is no automated WhatsApp sending in CoreX today — turning this on logs a ready-to-send
                    message on the inspection for an agent to send by hand, rather than pretending it went out on its own.
                </p>

                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div>
                        <label class="text-xs font-medium">Minimum notice (days)</label>
                        <input type="number" name="minimum_notice_days" min="0" max="90" value="{{ old('minimum_notice_days', $minimumNoticeDays) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Warns the agent when booking inside this window — never blocks.</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium">Reminder (days before)</label>
                        <input type="number" name="reminder_days_before" min="0" max="30" value="{{ old('reminder_days_before', $reminderDaysBefore) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <p class="text-xs mt-1" style="color: var(--text-muted);">0 turns the reminder off.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>

    {{-- §45.7 (Build I-5) — due dates and the agency's own loaded interim dates. There is deliberately no "interim every N
         months" setting: CoreX never works out an interim date — the agency loads the dates it wants on the Due tab. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inspections.due-dates') }}" class="space-y-3" data-qa="due-dates-form">
        @csrf
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Due inspections and reminders</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    The agent responsible for a property is reminded (in CoreX and by email) when a move-in or move-out inspection
                    is due, and about the interim inspection dates your agency has loaded on the Due tab. Tenants and landlords
                    are never contacted by these reminders — they are invited when the inspection is booked.
                </p>
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="raise_due_inspections_enabled" value="0">
                    <input type="checkbox" name="raise_due_inspections_enabled" value="1" @checked($raiseDueInspectionsEnabled)>
                    Remind the agent when a move-in or move-out inspection is due
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium">Interim date reminder — days before</label>
                        <input type="number" name="planned_date_lead_days" min="0" max="90" value="{{ old('planned_date_lead_days', $plannedDateLeadDays) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <p class="text-xs mt-1" style="color: var(--text-muted);">How early the agent is first reminded about a date you loaded. 0 = on the day only.</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium">Move-out inspection shows as due — days before</label>
                        <input type="number" name="out_due_lead_days" min="0" max="90" value="{{ old('out_due_lead_days', $outDueLeadDays) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        <p class="text-xs mt-1" style="color: var(--text-muted);">How long before a tenant moves out (or a fixed term ends) the move-out inspection starts showing as due. 0 = from the day itself.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save due-date settings</button>
        </div>
    </form>
</div>
@endsection
