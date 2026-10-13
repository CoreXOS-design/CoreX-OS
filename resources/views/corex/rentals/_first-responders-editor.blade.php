{{--
    .ai/specs/rentals-faults-work-orders.md section 21.3 - the "Who looks at faults first" list editor: up to 3 ordered rows, each a supplier (search by name or
    kind, or "Add a supplier" in a popup over the page), For (all faults / only these types) and Tell them (inspect and report back / information only).
    ONE editor for the property panel and the owner-default panel. Posts the WHOLE list; the server validates and logs the change.
    Expects: $frAction (PUT url), $frEntries (Collection<RentalFirstResponder>), $frSuppliers (array of id/name/kind/kind_label/company), $frFaultTypes (array of id/name/category),
             $frCanEdit (bool), $frChanges (Collection<RentalFirstResponderChange>), $frPrefix (dom id prefix), optional $frCopyFrom (array of rows to offer as "Change for this property").
--}}
@php
    $frPrefix = $frPrefix ?? 'fr';
    $frKindLabels = \App\Models\DealV2\AgencyServiceProvider::KINDS;
    $frRows = $frEntries->map(fn ($e) => [
        'supplier_id' => (int) $e->agency_service_provider_id, 'fault_scope' => $e->fault_scope, 'fault_type_ids' => array_map('intval', (array) $e->fault_type_ids),
        'tell_mode' => $e->tell_mode,
    ])->values()->all();
    // a supplier already on the list stays selectable (and shown) even if it has since been deactivated
    $frKnown = collect($frSuppliers)->pluck('id')->all();
    foreach ($frEntries as $e) {
        if (! in_array((int) $e->agency_service_provider_id, $frKnown, true) && $e->supplier) {
            $frSuppliers[] = ['id' => (int) $e->supplier->id, 'name' => $e->supplier->displayName() . ($e->supplier->is_active ? '' : ' (inactive)'), 'kind' => (string) $e->supplier->kind, 'kind_label' => \App\Models\DealV2\AgencyServiceProvider::kindLabel($e->supplier->kind), 'company' => $e->supplier->company];
        }
    }
    $frCanAdd = auth()->user()?->hasPermission('deals_v2.manage_suppliers');
@endphp
<div id="{{ $frPrefix }}-editor" data-first-responders-editor
     x-data="{
        rows: {{ \Illuminate\Support\Js::from(old('responders') ? array_values(old('responders')) : $frRows) }},
        suppliers: {{ \Illuminate\Support\Js::from($frSuppliers) }},
        faultTypes: {{ \Illuminate\Support\Js::from($frFaultTypes) }},
        open: null, q: '', pending: null, max: {{ \App\Models\RentalFirstResponder::MAX_ENTRIES }},
        blank() { return { supplier_id: '', fault_scope: 'all', fault_type_ids: [], tell_mode: 'inspect' }; },
        add() { if (this.rows.length < this.max) { this.rows.push(this.blank()); this.open = this.rows.length - 1; this.q = ''; } },
        remove(i) { this.rows.splice(i, 1); this.open = null; },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.rows.length) return; const t = this.rows[i]; this.rows[i] = this.rows[j]; this.rows[j] = t; this.rows = this.rows.slice(); },
        sup(id) { return this.suppliers.find(s => String(s.id) === String(id)) || null; },
        matches() { const t = this.q.toLowerCase().trim(); return this.suppliers.filter(s => t === '' || (s.name + ' ' + s.kind_label + ' ' + (s.company || '')).toLowerCase().includes(t)); },
        choose(i, s) { this.rows[i].supplier_id = s.id; this.open = null; this.q = ''; },
        added(d) { if (!d || !d.provider) return; if (!this.sup(d.provider.id)) { this.suppliers.push({ id: d.provider.id, name: d.provider.name, kind: d.provider.kind, kind_label: d.provider.kind_label, company: null }); } const i = this.pending; this.pending = null; if (i !== null && this.rows[i]) { this.choose(i, d.provider); } },
        toggleType(i, id) { const l = this.rows[i].fault_type_ids; const k = l.indexOf(id); if (k === -1) l.push(id); else l.splice(k, 1); },
        copy(rows) { this.rows = JSON.parse(JSON.stringify(rows)); this.open = null; this.$nextTick(() => this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' })); }
     }"
     x-on:cx-contractor-changed.window="added($event.detail)"
     x-on:first-responders-copy.window="copy($event.detail.rows)">
    @if($errors->has('first_responders') || $errors->has('responders'))
        <div class="text-xs p-2 rounded mb-2" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-first-responders-error>
            {{ $errors->first('first_responders') ?: $errors->first('responders') }}
        </div>
    @endif

    @if($frCanEdit)
    <form method="POST" action="{{ $frAction }}" class="space-y-3">
        @csrf
        @method('PUT')
        <p class="text-xs" x-show="rows.length === 0" style="color: var(--text-muted);">Nobody is set. Add up to {{ \App\Models\RentalFirstResponder::MAX_ENTRIES }} people or companies, in the order they should be asked.</p>

        <template x-for="(row, i) in rows" :key="i">
            <div class="rounded-md p-3 space-y-2" style="background: var(--surface); border: 1px solid var(--border);" data-first-responder-row>
                <input type="hidden" :name="'responders[' + i + '][supplier_id]'" :value="row.supplier_id">
                <input type="hidden" :name="'responders[' + i + '][fault_scope]'" :value="row.fault_scope">
                <input type="hidden" :name="'responders[' + i + '][tell_mode]'" :value="row.tell_mode">
                <template x-for="tid in (row.fault_scope === 'types' ? row.fault_type_ids : [])" :key="tid"><input type="hidden" :name="'responders[' + i + '][fault_type_ids][]'" :value="tid"></template>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold" x-text="(i + 1) + '.'"></span>
                    <button type="button" class="corex-btn-outline text-xs" @click="open = (open === i ? null : i); q = ''" data-first-responder-pick>
                        <span x-text="sup(row.supplier_id) ? sup(row.supplier_id).name : 'Choose a supplier'"></span>
                    </button>
                    <span class="ds-badge ds-badge-muted" x-show="sup(row.supplier_id) && sup(row.supplier_id).kind !== 'contractor'" x-text="sup(row.supplier_id) ? sup(row.supplier_id).kind_label : ''"></span>
                    <span class="grow"></span>
                    <button type="button" class="corex-btn-outline text-xs" @click="move(i, -1)" :disabled="i === 0" aria-label="Move up">&uarr;</button>
                    <button type="button" class="corex-btn-outline text-xs" @click="move(i, 1)" :disabled="i === rows.length - 1" aria-label="Move down">&darr;</button>
                    <button type="button" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);" @click="remove(i)" data-first-responder-remove>Remove</button>
                </div>

                <div x-show="open === i" x-cloak class="space-y-2" data-first-responder-list>
                    <input type="search" x-model="q" placeholder="Search by name or kind (caretaker, insurer...)" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <div class="space-y-1" style="max-height: 14rem; overflow: auto;">
                        <template x-for="s in matches()" :key="s.id">
                            <button type="button" class="w-full text-left rounded-md px-2 py-1 text-sm flex flex-wrap items-center gap-2" style="border: 1px solid var(--border);" @click="choose(i, s)" :data-supplier-option="s.id">
                                <span class="font-medium" x-text="s.name"></span>
                                <span class="text-xs" style="color: var(--text-muted);" x-text="s.kind_label"></span>
                            </button>
                        </template>
                        <p class="text-xs" x-show="matches().length === 0" style="color: var(--text-muted);">No supplier matches that.</p>
                    </div>
                    @if($frCanAdd)
                        <button type="button" class="underline text-xs" data-contractor-popup="add" data-title="Add a supplier" @click="pending = i" data-first-responder-add-supplier>Add a supplier</button>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">For</label>
                        <select x-model="row.fault_scope" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" data-first-responder-for>
                            <option value="all">All faults</option>
                            <option value="types">Only these types of fault</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Tell them</label>
                        <select x-model="row.tell_mode" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" data-first-responder-tell>
                            @foreach(\App\Models\RentalFirstResponder::TELL_LABELS as $tk => $tl)<option value="{{ $tk }}">{{ $tl }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div x-show="row.fault_scope === 'types'" x-cloak class="flex flex-wrap gap-x-4 gap-y-1" data-first-responder-types>
                    <template x-for="t in faultTypes" :key="t.id">
                        <label class="inline-flex items-center gap-1.5 text-xs" style="color: var(--text-secondary);">
                            <input type="checkbox" :checked="row.fault_type_ids.includes(t.id)" @change="toggleType(i, t.id)" style="accent-color: var(--brand-button, #0ea5e9);">
                            <span x-text="t.name"></span>
                        </label>
                    </template>
                    <p class="text-xs" x-show="faultTypes.length === 0" style="color: var(--text-muted);">No fault types are set up yet.</p>
                </div>
            </div>
        </template>

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);" for="{{ $frPrefix }}-agreed">Agreed how / when (optional)</label>
            <input id="{{ $frPrefix }}-agreed" type="text" name="agreed_with" maxlength="255" value="{{ old('agreed_with') }}" placeholder="e.g. agreed by phone with the owner, 12 Oct 2026" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        </div>

        <div class="grid grid-cols-2 gap-2 sm:max-w-md">
            <button type="button" class="corex-btn-outline text-sm" @click="add()" :disabled="rows.length >= max" data-first-responder-add>Add another</button>
            <button type="submit" class="corex-btn-primary text-sm" data-first-responders-save>Save</button>
        </div>
    </form>
    @endif

    <details class="mt-3">
        <summary class="text-xs cursor-pointer" style="color: var(--text-muted);">History ({{ $frChanges->count() }})</summary>
        @if($frChanges->isEmpty())
            <p class="text-xs mt-2" style="color: var(--text-muted);">No changes recorded yet.</p>
        @else
            <ul class="space-y-2 text-xs mt-2" data-first-responders-history>
                @foreach($frChanges as $ch)
                    <li>
                        <span style="color: var(--text-muted);">{{ $ch->changed_at?->format('j M Y H:i') }} - {{ $ch->changedByUser?->name ?? 'a former user' }}@if($ch->agreed_with) (agreed: {{ $ch->agreed_with }})@endif</span>
                        <div style="white-space: pre-line;">Was: {{ $ch->old_summary }}</div>
                        <div style="white-space: pre-line;"><strong>Now: {{ $ch->new_summary }}</strong></div>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
</div>
