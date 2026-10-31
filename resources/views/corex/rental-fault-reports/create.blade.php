@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §3.2a/§0c — the agent captures a fault
    report on the reporter's behalf. reported_channel/reported_by_type are
    first-class fields, not an afterthought, per the 2026-09-25 ruling.
--}}

@section('corex-content')
<div class="w-full space-y-5"
     x-data="faultReportContactPicker('{{ route('corex.properties.contacts.search-global') }}', {
        {{ \App\Models\RentalFaultReport::REPORTED_BY_TENANT }}: {{ Js::from($leaseTenants->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)])) }},
        {{ \App\Models\RentalFaultReport::REPORTED_BY_OWNER_INSTRUCTED }}: {{ Js::from($landlordContact ? [['id' => $landlordContact->id, 'name' => trim($landlordContact->first_name.' '.$landlordContact->last_name)]] : []) }}
     })">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Report a Fault</h1>
        <p class="text-xs" style="color: var(--text-muted);">Log a fault at a rental property so it can be reviewed, approved and fixed.</p>
    </div>

    @if(! $property)
        {{-- Johan, 8 Oct 2026: a searchable pick of tenancies (properties that have a lease, shown with their tenant), not a plain dropdown of every
             rental. Choosing one opens this same form with the property and tenancy filled in - exactly the entry "report a fault" from a lease uses. --}}
        <div class="rounded-md p-6 space-y-4 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);" data-fault-lease-picker data-tour="rmnt-frnew-picker">
            <p class="text-sm font-medium" style="color: var(--text-primary);">Which tenancy is the fault at?</p>
            <form method="GET" action="{{ route('corex.rental-fault-reports.create') }}" class="flex gap-2" data-tour="rmnt-frnew-search">
                <input type="search" name="q" value="{{ $leaseSearch }}" placeholder="Search by tenant name or address" class="prop-input flex-1" autofocus>
                <button type="submit" class="corex-btn-primary text-sm">Search</button>
                @if($leaseSearch !== '')<a href="{{ route('corex.rental-fault-reports.create') }}" class="corex-btn-outline text-sm">Clear</a>@endif
            </form>
            <div class="space-y-1" data-tour="rmnt-frnew-choices">
                @forelse($leaseChoices as $choice)
                    <a href="{{ route('corex.rental-fault-reports.create', ['property_id' => $choice->property_id, 'lease_id' => $choice->id]) }}" class="list-row-hover block rounded-md px-3 py-2.5 text-sm no-underline transition-colors duration-150" style="color: var(--text-secondary); border: 1px solid var(--border);" data-lease-choice="{{ $choice->id }}">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $choice->property?->buildDisplayAddress() }}</span>
                        <span class="text-xs" style="color: var(--text-muted);"> &mdash; {{ $choice->tenantNames() ?: 'no tenant named' }}</span>
                    </a>
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">{{ $leaseSearch !== '' ? 'No current tenancy matches that. ' : 'No current tenancies. ' }}A fault on a property with no tenant can be reported from the property's own page.</p>
                @endforelse
                @if($leaseChoices->count() >= 40)<p class="text-xs" style="color: var(--text-muted);">Showing the first 40 - search to narrow it down.</p>@endif
            </div>
            <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);"><a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-sm">Cancel</a></div>
        </div>
    @endif

    @if($property)
    <form method="POST" action="{{ route('corex.rental-fault-reports.store') }}" class="space-y-5 rounded-md p-6 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rmnt-frnew-form">
        @csrf

        @if ($errors->any())
            <div class="text-sm px-4 py-3 rounded-md" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div data-tour="rmnt-frnew-property">
            <label class="prop-label">Property</label>
            @if($property)
                <input type="hidden" name="property_id" value="{{ $property->id }}">
                <div class="text-sm mt-1">{{ $property->buildDisplayAddress() }}</div>
            @endif
        </div>

        @if($lease)
            <input type="hidden" name="lease_id" value="{{ $lease->id }}">
            <p class="text-xs" style="color: var(--text-muted);">Tenancy: {{ $lease->tenantNames() }}.</p>
        @elseif($property)
            <div>
                <label class="prop-label">Tenancy (optional)</label>
                <p class="text-xs mb-1" style="color: var(--text-muted);">Leave blank for a vacancy-period fault — nobody was living there when it was noticed.</p>
                <select name="lease_id" class="prop-input mt-1">
                    <option value="">— No tenancy (vacancy period) —</option>
                    @foreach(\App\Models\Lease::where('property_id', $property->id)->orderByDesc('start_date')->limit(50)->get() as $l)
                        <option value="{{ $l->id }}">{{ $l->tenantNames() }} ({{ $l->start_date?->format('Y-m-d') }}&ndash;{{ $l->end_date?->format('Y-m-d') ?? 'ongoing' }})</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div data-tour="rmnt-frnew-by">
            <label class="prop-label">Reported by</label>
            <select name="reported_by_type" x-model="reportedByType" required class="prop-input mt-1">
                <option value="{{ \App\Models\RentalFaultReport::REPORTED_BY_TENANT }}">Tenant</option>
                <option value="{{ \App\Models\RentalFaultReport::REPORTED_BY_AGENT_NOTICED }}">Agent noticed it directly</option>
                <option value="{{ \App\Models\RentalFaultReport::REPORTED_BY_OWNER_INSTRUCTED }}">Owner instructed</option>
            </select>
        </div>

        <div x-show="reportedByType !== '{{ \App\Models\RentalFaultReport::REPORTED_BY_AGENT_NOTICED }}'" x-cloak>
            <label class="prop-label">Which contact reported it</label>

            {{-- Already known from the lease/property — the default, not a search. --}}
            <div class="mt-1 text-sm rounded-md px-3 py-2" style="background: var(--surface-2); color: var(--text-primary);" x-show="selected && !searching" x-cloak>
                <span x-text="selected?.name"></span>
                <button type="button" @click="clearSelection()" class="text-xs ml-2" style="color: var(--brand-icon, #2563eb);">Not them? Search instead</button>
            </div>

            {{-- Joint tenancy — more than one tenant on the lease is a real choice, not a search. --}}
            <div x-show="!searching && !selected && knownFor(reportedByType).length > 1" x-cloak class="mt-1 flex flex-wrap gap-2">
                <template x-for="c in knownFor(reportedByType)" :key="c.id">
                    <button type="button" @click="select(c)" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background: var(--surface-2); color: var(--text-primary);" x-text="c.name"></button>
                </template>
            </div>

            {{-- Nobody known for this role (e.g. no lease on file, or no landlord resolved) —
                 falls back to exactly today's search, never worse than before. --}}
            <div x-show="searching || (!selected && knownFor(reportedByType).length === 0)" x-cloak>
                <input type="text" x-model="query" @input.debounce.300ms="search()" placeholder="Search contacts by name, phone, or email…"
                       class="prop-input mt-1">
                <div class="mt-1 rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-show="results.length > 0" x-cloak>
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @click="select(r)" class="list-row-hover block w-full text-left px-3 py-2 text-sm" style="color: var(--text-primary); border-bottom: 1px solid var(--border);" x-text="r.name + (r.email ? ' — ' + r.email : '')"></button>
                    </template>
                </div>
            </div>

            <input type="hidden" name="reported_by_contact_id" :value="selected?.id ?? ''">
        </div>

        <div data-tour="rmnt-frnew-channel">
            <label class="prop-label">How was this reported</label>
            <select name="reported_channel" required class="prop-input mt-1">
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_PHONE }}">Phone call</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_WHATSAPP }}">WhatsApp</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_EMAIL }}">Email</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_IN_PERSON }}">In person</option>
                <option value="{{ \App\Models\RentalFaultReport::CHANNEL_OTHER }}">Other</option>
            </select>
        </div>

        {{-- .ai/specs/rentals-faults-work-orders.md §2.2/§3.2/§4.3a — the
             catalogue picker; picking one shows its first-aid steps (property-
             specific) BEFORE the rest of the form can be filled in. Optional —
             a report can still be free-text-only if nothing in the list fits. --}}
        @if($property)
        <div x-data="faultTypePicker({{ $property->id }})" data-tour="rmnt-frnew-type">
            <label class="prop-label">Fault type (optional)</label>
            <select name="rental_fault_type_id" x-model="selectedId" @change="loadFirstAid()" class="prop-input mt-1">
                <option value="">— No type chosen —</option>
                @foreach(\App\Models\RentalFaultType::where('is_active', true)->orderBy('sort_order')->get() as $ft)
                    <option value="{{ $ft->id }}">{{ $ft->name }}{{ $ft->category ? ' ('.$ft->category.')' : '' }}</option>
                @endforeach
                {{-- AT-452 - always the last choice, on top of the agency's own list --}}
                <option value="other" data-fault-type-other>{{ \App\Models\RentalFaultReport::OTHER_TYPE_OPTION }}</option>
            </select>
            <p x-show="selectedId === 'other'" x-cloak class="text-xs mt-1" style="color: var(--text-muted);" data-other-hint>Say what needs attention in the description below. You can give it a proper type later, from the fault's page.</p>
            <div x-show="firstAid" x-cloak class="mt-2 rounded-md p-3 text-sm space-y-2" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid var(--ds-amber);">
                <p class="font-semibold" x-text="firstAid?.name"></p>
                {{-- Text is rendered via x-text (auto-escaped, never raw HTML) —
                     safe by construction for today's plain-text authoring;
                     whitespace-pre-line preserves the agency's own line breaks. --}}
                <p class="whitespace-pre-line" x-text="firstAid?.first_aid_steps"></p>

                {{-- .ai/specs/rentals-faults-work-orders.md §3.1 — the property's
                     own recorded valve/DB-board photo, shown alongside the
                     steps, per Johan's QA1 review. --}}
                <template x-if="firstAid?.main_water_valve_photo_url">
                    <img :src="firstAid.main_water_valve_photo_url" alt="Main water valve" class="rounded-md max-h-40">
                </template>
                <template x-if="firstAid?.db_board_photo_url">
                    <img :src="firstAid.db_board_photo_url" alt="DB board" class="rounded-md max-h-40">
                </template>

                {{-- §2's own uploaded documents/images/video — the agency's own
                     attachments per fault type. --}}
                <template x-if="firstAid?.documents?.length">
                    <ul class="space-y-1">
                        <template x-for="doc in firstAid.documents" :key="doc.url">
                            <li>
                                <template x-if="doc.type === 'image'">
                                    <img :src="doc.url" :alt="doc.caption || firstAid.name" class="rounded-md max-h-40">
                                </template>
                                <template x-if="doc.type === 'video_link'">
                                    <a :href="doc.url" target="_blank" rel="noopener" class="underline" style="color: var(--brand-icon, #2563eb);" x-text="doc.caption || 'Watch video'"></a>
                                </template>
                                <template x-if="doc.type === 'pdf' || doc.type === 'document'">
                                    <a :href="doc.url" target="_blank" rel="noopener" class="underline" style="color: var(--brand-icon, #2563eb);" x-text="doc.caption || 'View document'"></a>
                                </template>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
        @endif

        <div data-tour="rmnt-frnew-title">
            <label class="prop-label">Title</label>
            <input type="text" name="title" required maxlength="191" placeholder="e.g. Geyser burst — upstairs bathroom" class="prop-input mt-1">
        </div>

        <div data-tour="rmnt-frnew-desc">
            <label class="prop-label">Description</label>
            <textarea name="description" required rows="4" class="prop-input mt-1"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-4" style="border-top: 1px solid var(--border);">
            <a href="{{ $property ? route('corex.properties.show', $property->id) : route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-sm">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm" data-tour="rmnt-frnew-save">Report Fault</button>
        </div>
    </form>
    @endif
</div>

<script>
function faultReportContactPicker(searchUrl, knownContactsByType) {
    return {
        // Johan, 2026-09-21: the lease already knows the tenant, the
        // property already knows the owner — default to that, don't make
        // the agent search for someone we already have on file. Search
        // stays available underneath for a genuine override.
        knownContactsByType,
        reportedByType: '{{ \App\Models\RentalFaultReport::REPORTED_BY_TENANT }}',
        query: '',
        results: [],
        selected: null,
        searching: false,
        init() {
            this.applyKnownDefault();
            this.$watch('reportedByType', () => this.applyKnownDefault());
        },
        knownFor(type) { return this.knownContactsByType[type] || []; },
        applyKnownDefault() {
            this.selected = null;
            this.searching = false;
            const known = this.knownFor(this.reportedByType);
            // Exactly one known contact for this role (the common case — one
            // tenant, one landlord) — default straight to them. More than
            // one (a joint tenancy) is a real choice, not a search; offer
            // them as quick picks instead, handled in the template below.
            if (known.length === 1) { this.selected = known[0]; }
        },
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
            this.searching = false;
            this.query = '';
            this.results = [];
        },
        clearSelection() {
            this.selected = null;
            this.searching = true;
        },
    };
}

function faultTypePicker(propertyId) {
    return {
        selectedId: '',
        firstAid: null,
        async loadFirstAid() {
            if (!this.selectedId || this.selectedId === 'other') { this.firstAid = null; return; }
            const url = `/corex/rental-fault-types/${this.selectedId}/first-aid?property_id=${propertyId}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            this.firstAid = res.ok ? await res.json() : null;
        },
    };
}
</script>
@endsection
