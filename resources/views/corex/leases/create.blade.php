@extends('layouts.corex')

{{--
    .ai/specs/leases.md §2 — a lease takes a Property (owner already known),
    adds Tenant(s) (N-party, never assumed 1-2), and carries terms.
    The Property field is a type-to-search picker (§7.2), not a dropdown.
--}}

@section('content')
@php
    $propertyPickerConfig = [
        'active' => !$property,
        'searchUrl' => route('corex.leases.search-rental-properties'),
        'id' => ($oldProperty ?? null)?->id,
        'label' => ($oldProperty ?? null)?->buildDisplayAddress() ?? '',
    ];
@endphp
<div class="p-6 max-w-2xl mx-auto space-y-4"
     x-data="leaseCreateForm('{{ route('corex.properties.contacts.search-global') }}', {{ \Illuminate\Support\Js::from([]) }}, {{ \Illuminate\Support\Js::from($propertyPickerConfig) }})">
    <h1 class="text-lg font-semibold">New Lease</h1>

    <form method="POST" action="{{ route('corex.leases.store') }}" @submit="guardSubmit($event)" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if ($errors->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div @click.outside="closePropertyResults()">
            <label for="lease-property-search" class="text-xs font-medium">Property</label>
            @if($property)
                <input type="hidden" name="property_id" value="{{ $property->id }}">
                <div class="text-sm mt-1">{{ $property->buildDisplayAddress() }}</div>
            @else
                {{-- .ai/specs/leases.md §7.2 — same type-to-search pattern as the rental
                     application / work order property pickers. The hidden input carries the
                     id; editing the text clears it, so the box and the id can never disagree. --}}
                <input type="text" id="lease-property-search" x-ref="propertySearch" x-model="propertyQuery" autocomplete="off"
                       role="combobox" aria-autocomplete="list" aria-controls="lease-property-results"
                       :aria-expanded="propertyOpen ? 'true' : 'false'"
                       placeholder="Search by address, property name or reference…"
                       @input="propertyEdited()" @input.debounce.300ms="searchProperties()"
                       @focus="reopenPropertyResults()"
                       @keydown.down.prevent="moveHighlight(1)" @keydown.up.prevent="moveHighlight(-1)"
                       @keydown.enter="chooseHighlighted($event)" @keydown.escape="closePropertyResults()"
                       class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <input type="hidden" name="property_id" :value="propertyId">
                <div id="lease-property-results" role="listbox" x-show="propertyOpen && propertyResults.length" x-cloak
                     class="mt-1 rounded-md max-h-72 overflow-y-auto" style="border: 1px solid var(--border); background: var(--surface);">
                    <template x-for="(p, idx) in propertyResults" :key="p.id">
                        <button type="button" role="option" :aria-selected="highlighted === idx ? 'true' : 'false'"
                                @click="selectProperty(p)" @mouseenter="highlighted = idx"
                                :class="highlighted === idx ? 'bg-slate-100' : ''"
                                class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50">
                            <div class="flex items-center gap-1.5">
                                <span x-text="p.label"></span>
                                <span x-show="p.status" x-text="p.status" class="text-[10px] px-1 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); white-space: nowrap;"></span>
                            </div>
                            <div class="text-xs" style="color: var(--text-muted);" x-text="[p.ref ? ('Ref: ' + p.ref) : '', p.agent].filter(Boolean).join(' · ')"></div>
                        </button>
                    </template>
                </div>
                <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="propertySearching" x-cloak>Searching…</p>
                <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="propertySearched && !propertySearching && propertyResults.length === 0 && !propertyId" x-cloak
                   x-text="'No rental properties match ' + propertyQuery.trim() + '. Try the street, suburb, property name or reference.'"></p>
                <p class="text-xs mt-1" style="color: var(--ds-crimson);" x-show="propertyError" x-cloak x-text="propertyError"></p>
                <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="propertyId" x-cloak>Property selected.</p>
                <p class="text-xs mt-1" style="color: var(--ds-crimson);" x-show="propertyAttempted && !propertyId" x-cloak>Choose a property from the list.</p>
            @endif
        </div>

        @if($rentalApplication)
            <input type="hidden" name="rental_application_id" value="{{ $rentalApplication->id }}">
            <p class="text-xs" style="color: var(--text-muted);">Linked to rental application #{{ $rentalApplication->id }}.</p>
        @endif

        <div>
            <label class="text-xs font-medium">Tenant(s)</label>
            <p class="text-xs mb-1" style="color: var(--text-muted);">Add one or more — a lease can have joint tenants.</p>
            <input type="text" x-model="query" @input.debounce.300ms="search()" placeholder="Search contacts by name, phone, or email…"
                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <div class="mt-1 rounded-md" style="border: 1px solid var(--border);" x-show="results.length > 0" x-cloak>
                <template x-for="r in results" :key="r.id">
                    <button type="button" @click="add(r)" class="block w-full text-left px-3 py-2 text-sm" style="border-bottom: 1px solid var(--border);" x-text="r.name + (r.email ? ' — ' + r.email : '')"></button>
                </template>
            </div>
            <div class="mt-2 space-y-1">
                <template x-for="(t, idx) in selected" :key="t.id">
                    <div class="flex items-center justify-between text-sm rounded px-2 py-1" style="background: var(--surface-2);">
                        <span x-text="t.name + (idx === 0 ? ' (primary)' : '')"></span>
                        <button type="button" @click="remove(idx)" class="text-xs" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
            </div>
            <template x-for="t in selected" :key="'input-' + t.id">
                <input type="hidden" name="tenant_contact_ids[]" :value="t.id">
            </template>
            <p class="text-xs mt-1" style="color: var(--ds-crimson);" x-show="submitAttempted && selected.length === 0" x-cloak>At least one tenant is required.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs font-medium">Monthly rental (R)</label>
                <input type="number" name="rental_amount" step="0.01" min="0" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Deposit (R)</label>
                <input type="number" name="deposit_amount" step="0.01" min="0" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Start date</label>
                <input type="date" name="start_date" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">End date</label>
                <input type="date" name="end_date" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_month_to_month" value="1">
            Month-to-month (no fixed end date)
        </label>

        <div>
            <label class="text-xs font-medium">Lease type</label>
            <select name="lease_type" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="">—</option>
                {{-- .ai/specs/rental-property-tab.md §5, Part 4 — agency-editable
                     list, same source as the property screen's Lease Type select. --}}
                @foreach($leaseTypes ?? [] as $lt)
                    <option value="{{ $lt->name }}">{{ $lt->name }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="activate_immediately" value="1">
            Activate immediately (skip draft)
        </label>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" @click="submitAttempted = true" class="corex-btn-primary text-xs">Create Lease</button>
        </div>
    </form>
</div>

<script>
function leaseCreateForm(searchUrl, seed, propertyCfg) {
    propertyCfg = propertyCfg || {};
    return {
        query: '',
        results: [],
        selected: seed || [],
        submitAttempted: false,
        // Property picker (.ai/specs/leases.md §7.2). propertyId is the single source of
        // truth for what gets posted; propertyQuery is only the text in the box.
        propertyActive: !!propertyCfg.active,
        propertySearchUrl: propertyCfg.searchUrl || '',
        propertyId: propertyCfg.id || '',
        propertyQuery: propertyCfg.label || '',
        propertyResults: [],
        propertyOpen: false,
        propertySearching: false,
        propertySearched: false,
        propertyError: '',
        propertyAttempted: false,
        highlighted: -1,
        propertySeq: 0,
        propertyEdited() {
            // Typing after a pick means the pick is no longer what is in the box.
            this.propertyId = '';
            this.propertyError = '';
            this.highlighted = -1;
            this.propertySeq++;
            this.propertySearching = false;
            if (this.propertyQuery.trim().length < 2) {
                this.propertyResults = [];
                this.propertyOpen = false;
                this.propertySearched = false;
            }
        },
        async searchProperties() {
            const term = this.propertyQuery.trim();
            if (term.length < 2 || this.propertyId) { return; }
            const seq = ++this.propertySeq;
            this.propertySearching = true;
            this.propertyError = '';
            try {
                const url = new URL(this.propertySearchUrl, window.location.origin);
                url.searchParams.set('q', term);
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                const data = await res.json();
                if (seq !== this.propertySeq) { return; }
                this.propertyResults = data;
                this.highlighted = data.length ? 0 : -1;
                this.propertyOpen = true;
                this.propertySearched = true;
            } catch (e) {
                if (seq !== this.propertySeq) { return; }
                this.propertyResults = [];
                this.propertyOpen = false;
                this.propertySearched = false;
                this.propertyError = 'Could not search properties just now. Please try again.';
            } finally {
                if (seq === this.propertySeq) { this.propertySearching = false; }
            }
        },
        selectProperty(p) {
            this.propertyId = p.id;
            this.propertyQuery = p.label;
            this.propertyResults = [];
            this.propertyOpen = false;
            this.propertySearched = false;
            this.propertyAttempted = false;
            this.highlighted = -1;
        },
        moveHighlight(step) {
            const n = this.propertyResults.length;
            if (!n) { return; }
            this.propertyOpen = true;
            this.highlighted = (this.highlighted + step + n) % n;
        },
        chooseHighlighted(e) {
            // Enter picks the highlighted row instead of submitting the form; with no
            // list open it falls through to a normal submit (guardSubmit then explains).
            if (this.propertyOpen && this.propertyResults.length && this.highlighted >= 0) {
                e.preventDefault();
                this.selectProperty(this.propertyResults[this.highlighted]);
            }
        },
        reopenPropertyResults() {
            if (this.propertyResults.length && !this.propertyId) { this.propertyOpen = true; }
        },
        closePropertyResults() {
            this.propertyOpen = false;
        },
        guardSubmit(e) {
            if (this.propertyActive && !this.propertyId) {
                e.preventDefault();
                this.propertyAttempted = true;
                if (this.$refs.propertySearch) { this.$refs.propertySearch.focus(); }
            }
        },
        async search() {
            if (this.query.trim().length < 2) { this.results = []; return; }
            const excludeIds = this.selected.map(t => t.id);
            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('q', this.query);
            excludeIds.forEach(id => url.searchParams.append('exclude[]', id));
            const res = await fetch(url);
            const data = await res.json();
            this.results = data.map(c => ({
                id: c.id,
                name: [c.first_name, c.last_name].filter(Boolean).join(' ') || c.name || ('Contact #' + c.id),
                email: c.email || '',
            }));
        },
        add(contact) {
            if (this.selected.find(t => t.id === contact.id)) return;
            this.selected.push(contact);
            this.query = '';
            this.results = [];
        },
        remove(idx) {
            this.selected.splice(idx, 1);
        },
    };
}
</script>
@endsection
