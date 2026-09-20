@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §3.2a/§0c — the agent captures a fault
    report on the reporter's behalf. reported_channel/reported_by_type are
    first-class fields, not an afterthought, per the 2026-09-25 ruling.
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4"
     x-data="faultReportContactPicker('{{ route('corex.properties.contacts.search-global') }}')">
    <h1 class="text-lg font-semibold">Report a Fault</h1>

    <form method="POST" action="{{ route('corex.rental-fault-reports.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
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

        @if($lease)
            <input type="hidden" name="lease_id" value="{{ $lease->id }}">
            <p class="text-xs" style="color: var(--text-muted);">Tenancy: {{ $lease->tenantNames() }}.</p>
        @elseif($property)
            <div>
                <label class="text-xs font-medium">Tenancy (optional)</label>
                <p class="text-xs mb-1" style="color: var(--text-muted);">Leave blank for a vacancy-period fault — nobody was living there when it was noticed.</p>
                <select name="lease_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">— No tenancy (vacancy period) —</option>
                    @foreach(\App\Models\Lease::where('property_id', $property->id)->orderByDesc('start_date')->limit(50)->get() as $l)
                        <option value="{{ $l->id }}">{{ $l->tenantNames() }} ({{ $l->start_date?->format('Y-m-d') }}&ndash;{{ $l->end_date?->format('Y-m-d') ?? 'ongoing' }})</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label class="text-xs font-medium">Reported by</label>
            <select name="reported_by_type" x-model="reportedByType" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="{{ \App\Models\RentalFaultReport::REPORTED_BY_TENANT }}">Tenant</option>
                <option value="{{ \App\Models\RentalFaultReport::REPORTED_BY_AGENT_NOTICED }}">Agent noticed it directly</option>
                <option value="{{ \App\Models\RentalFaultReport::REPORTED_BY_OWNER_INSTRUCTED }}">Owner instructed</option>
            </select>
        </div>

        <div x-show="reportedByType !== '{{ \App\Models\RentalFaultReport::REPORTED_BY_AGENT_NOTICED }}'" x-cloak>
            <label class="text-xs font-medium">Which contact reported it</label>
            <input type="text" x-model="query" @input.debounce.300ms="search()" placeholder="Search contacts by name, phone, or email…"
                   class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            <div class="mt-1 rounded-md" style="border: 1px solid var(--border);" x-show="results.length > 0" x-cloak>
                <template x-for="r in results" :key="r.id">
                    <button type="button" @click="select(r)" class="block w-full text-left px-3 py-2 text-sm" style="border-bottom: 1px solid var(--border);" x-text="r.name + (r.email ? ' — ' + r.email : '')"></button>
                </template>
            </div>
            <div class="mt-2 text-sm rounded px-2 py-1" style="background: var(--surface-2);" x-show="selected" x-cloak>
                <span x-text="selected?.name"></span>
                <button type="button" @click="clearSelection()" class="text-xs ml-2" style="color: var(--ds-crimson);">Clear</button>
            </div>
            <input type="hidden" name="reported_by_contact_id" :value="selected?.id ?? ''">
        </div>

        <div>
            <label class="text-xs font-medium">How was this reported</label>
            <select name="reported_channel" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_PHONE }}">Phone call</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_WHATSAPP }}">WhatsApp</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_EMAIL }}">Email</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_IN_PERSON }}">In person</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_OTHER }}">Other</option>
            </select>
        </div>

        <div>
            <label class="text-xs font-medium">Title</label>
            <input type="text" name="title" required maxlength="191" placeholder="e.g. Geyser burst — upstairs bathroom" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
        </div>

        <div>
            <label class="text-xs font-medium">Description</label>
            <textarea name="description" required rows="4" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $property ? route('corex.properties.show', $property->id) : route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Report Fault</button>
        </div>
    </form>
</div>

<script>
function faultReportContactPicker(searchUrl) {
    return {
        reportedByType: '{{ \App\Models\RentalFaultReport::REPORTED_BY_TENANT }}',
        query: '',
        results: [],
        selected: null,
        async search() {
            if (this.query.trim().length < 2) { this.results = []; return; }
            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('q', this.query);
            const res = await fetch(url);
            const data = await res.json();
            this.results = data.map(c => ({
                id: c.id,
                name: [c.first_name, c.last_name].filter(Boolean).join(' ') || c.name || ('Contact #' + c.id),
                email: c.email || '',
            }));
        },
        select(contact) {
            this.selected = contact;
            this.query = '';
            this.results = [];
        },
        clearSelection() {
            this.selected = null;
        },
    };
}
</script>
@endsection
