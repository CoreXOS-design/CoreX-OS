@extends('layouts.corex')

{{--
    .ai/specs/leases.md §2 — a lease takes a Property (owner already known),
    adds Tenant(s) (N-party, never assumed 1-2), and carries terms.
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4"
     x-data="leaseTenantPicker('{{ route('corex.properties.contacts.search-global') }}', {{ \Illuminate\Support\Js::from([]) }})">
    <h1 class="text-lg font-semibold">New Lease</h1>

    <form method="POST" action="{{ route('corex.leases.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
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

        <div>
            <label class="text-xs font-medium">Property</label>
            @if($property)
                <input type="hidden" name="property_id" value="{{ $property->id }}">
                <div class="text-sm mt-1">{{ $property->buildDisplayAddress() }}</div>
            @else
                <select name="property_id" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">Select a property…</option>
                    @foreach(\App\Models\Property::where('listing_type', 'rental')->orderBy('title')->limit(500)->get() as $p)
                        <option value="{{ $p->id }}">{{ $p->buildDisplayAddress() }}</option>
                    @endforeach
                </select>
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
function leaseTenantPicker(searchUrl, seed) {
    return {
        query: '',
        results: [],
        selected: seed || [],
        submitAttempted: false,
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
