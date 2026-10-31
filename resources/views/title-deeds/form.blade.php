@extends('layouts.corex-app')

@section('corex-content')
@php
    $editing = (bool) $deed;
    $isCollected = $editing && $deed->isCollected();
    $input = 'w-full rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $lbl = 'block text-xs font-medium mb-1';
    $noLocations = $locations->isEmpty();
@endphp
<div class="w-full max-w-3xl space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="rpt-tdf-intro">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $editing ? 'Edit title deed' : 'Log a title deed' }}</h1>
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            <a href="{{ $editing ? route('title-deeds.show', $deed->id) : route('title-deeds.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Cancel</a>
        </div>
    </div>

    @unless($isCollected)<p class="text-sm" style="color: var(--text-secondary);">Fields marked <span style="color: var(--ds-crimson);">(required)</span> must be filled in before the deed can be saved.</p>@endunless

    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>@endif
    @if(session('info'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">{{ session('info') }}</div>@endif
    @if($errors->any())
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
        <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
    @endif

    <form method="POST" action="{{ $editing ? route('title-deeds.update', $deed->id) : route('title-deeds.store') }}" enctype="multipart/form-data" id="deed-form"
          class="rounded-md p-5 space-y-5" style="background: var(--surface); border: 1px solid var(--border);"
          x-data="titleDeedForm({
              property: @js($propertyInit),
              deal: @js($dealInit),
              owners: @js($ownersInit),
              listing: @js($listingInit),
              extraAgents: @js($extraAgentsInit),
              agentId: @js((string) $defaultAgentId),
              branchId: @js((string) $defaultBranchId),
              locationId: @js((string) $locationId),
              attorney: @js(old('attorney_firm', $deed?->attorney_firm ?? '')),
              agentIds: @js($agents->pluck('id')->map(fn ($i) => (string) $i)->values()),
              branchIds: @js($branches->pluck('id')->map(fn ($i) => (string) $i)->values()),
              locations: @js($locations),
              editing: @js($editing),
              serverDuplicate: @js($duplicate),
              urls: {
                  properties: @js(route('title-deeds.search.properties')),
                  suggestions: @js(url('/title-deeds/search/property')),
                  contacts: @js(route('title-deeds.search.contacts')),
                  deals: @js(route('title-deeds.search.deals')),
                  dealPrefill: @js(url('/title-deeds/search/deal')),
              },
          })">
        @csrf
        @if($editing) @method('PUT') @else <input type="hidden" name="_form_token" value="{{ $formToken }}"> @endif

        @if($isCollected)
            <div class="space-y-2">
                <div class="text-sm" style="color: var(--text-secondary);">This deed has been collected, so only the notes can change.</div>
                <div>
                    <label for="td-notes" class="{{ $lbl }}" style="color: var(--text-secondary);">Notes<span class="font-normal" style="color: var(--text-muted);"> (optional)</span></label>
                    <textarea id="td-notes" name="notes" rows="4" maxlength="5000" class="{{ $input }}" style="{{ $inputStyle }}">{{ old('notes', $deed->notes) }}</textarea>
                </div>
            </div>
        @else

        {{-- Deal (optional, new deeds only) --}}
        @if($canPickDeal)
        <div data-tour="rpt-tdf-deal">
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="td-deal-q">Deal<span class="font-normal" style="color: var(--text-muted);"> (optional)</span></label>
            <input type="hidden" name="deal_id" :value="deal ? deal.id : ''">
            <template x-if="deal">
                <div class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <a :href="deal.url" target="_blank" rel="noopener" class="no-underline hover:underline" style="color: var(--text-primary);" x-text="deal.label"></a>
                    <button type="button" class="text-xs font-semibold" style="color: var(--brand-icon);" @click="clearDeal()">Remove the deal</button>
                </div>
            </template>
            <div x-show="!deal" class="relative">
                <input id="td-deal-q" type="text" x-model="dq" @input.debounce.300ms="searchDeals()" autocomplete="off" placeholder="Search registered deals by deal number, property or buyer / seller"
                       class="{{ $input }}" style="{{ $inputStyle }}">
                <div x-show="dResults.length" x-cloak class="absolute z-20 left-0 right-0 mt-1 rounded-md overflow-hidden shadow-lg" style="background: var(--surface); border: 1px solid var(--border);">
                    <template x-for="r in dResults" :key="r.id">
                        <button type="button" class="w-full text-left px-3 py-2 text-sm hover:opacity-80" style="border-bottom: 1px solid var(--border); color: var(--text-primary);" @click="pickDeal(r)" x-text="r.label"></button>
                    </template>
                </div>
                <div x-show="dEmpty" x-cloak class="text-xs mt-1" style="color: var(--text-muted);">No registered deal found for that.</div>
            </div>
            <div x-show="!deal" class="text-xs mt-1" style="color: var(--text-muted);">Fills property, owners, agents and attorney.</div>
            <div x-show="dealHint" x-cloak class="text-xs mt-1" style="color: var(--text-secondary);" x-text="dealHint"></div>
        </div>
        @elseif($editing && $deed->deal_id)
            <input type="hidden" name="deal_id" value="{{ $deed->deal_id }}">
        @endif

        {{-- Property --}}
        <div data-tour="rpt-tdf-property">
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="td-property-q">Property<span class="font-normal" style="color: var(--ds-crimson);"> (required)</span></label>
            <input type="hidden" name="property_id" :value="property ? property.id : ''">
            <template x-if="property">
                <div class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <span x-text="property.address" style="color: var(--text-primary);"></span>
                    <button type="button" class="text-xs font-semibold" style="color: var(--brand-icon);" @click="clearProperty()">Change the property</button>
                </div>
            </template>
            <div x-show="!property" class="relative">
                <input id="td-property-q" type="text" x-model="pq" @input.debounce.300ms="searchProperties()" autocomplete="off" placeholder="Start typing the address"
                       class="{{ $input }}" style="{{ $inputStyle }}">
                <div x-show="pResults.length" x-cloak class="absolute z-20 left-0 right-0 mt-1 rounded-md overflow-hidden shadow-lg" style="background: var(--surface); border: 1px solid var(--border);">
                    <template x-for="r in pResults" :key="r.id">
                        <button type="button" class="w-full text-left px-3 py-2 text-sm hover:opacity-80" style="border-bottom: 1px solid var(--border); color: var(--text-primary);" @click="pickProperty(r)">
                            <span x-text="r.address"></span>
                            <span class="text-xs" style="color: var(--text-muted);" x-text="[r.ref, r.agent].filter(Boolean).join(' · ')"></span>
                        </button>
                    </template>
                </div>
                <div x-show="pEmpty" x-cloak class="text-xs mt-1" style="color: var(--text-muted);">No property found for that - try a street name or number.</div>
            </div>
            {{-- An OPEN deed for this property already: say so, with a link (a later deed is possible, so it is a warning, not a block) --}}
            <div x-show="existing.length" x-cloak class="mt-2 rounded-md px-3 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);" role="alert">
                A title deed for this property is already logged and not yet collected:
                <template x-for="d in existing" :key="d.id">
                    <span><template x-if="d.url"><a :href="d.url" target="_blank" rel="noopener" class="font-semibold underline" x-text="'#' + d.id + ' (' + d.status + ', received ' + d.received + ')'"></a></template><template x-if="!d.url"><span class="font-semibold" x-text="'#' + d.id + ' (' + d.status + ', received ' + d.received + ', logged by a colleague)'"></span></template></span>
                </template>.
            </div>
        </div>

        {{-- Owners --}}
        <div data-tour="rpt-tdf-owners">
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="td-owner-q">Owner(s)<span class="font-normal" style="color: var(--ds-crimson);"> (required)</span></label>
            <div class="flex flex-wrap gap-2 mb-2" x-show="owners.length">
                <template x-for="o in owners" :key="o.id">
                    <span class="inline-flex items-center gap-2 rounded-md px-2.5 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="hidden" name="owner_ids[]" :value="o.id">
                        <span x-text="o.name"></span>
                        <button type="button" class="text-xs" style="color: var(--ds-crimson);" :aria-label="'Remove ' + o.name" @click="removeOwner(o.id)">Remove</button>
                    </span>
                </template>
            </div>
            <div x-show="ownersNote" x-cloak class="text-xs mb-1" style="color: var(--text-secondary);" x-text="ownersNote"></div>
            <div class="relative">
                <input id="td-owner-q" type="text" x-model="cq" @input.debounce.300ms="searchContacts()" autocomplete="off" placeholder="Search your contacts to add an owner"
                       class="{{ $input }}" style="{{ $inputStyle }}">
                <div x-show="cResults.length" x-cloak class="absolute z-20 left-0 right-0 mt-1 rounded-md overflow-hidden shadow-lg" style="background: var(--surface); border: 1px solid var(--border);">
                    <template x-for="c in cResults" :key="c.id">
                        <button type="button" class="w-full text-left px-3 py-2 text-sm hover:opacity-80" style="border-bottom: 1px solid var(--border); color: var(--text-primary);" @click="addOwner(c)">
                            <span x-text="c.name"></span>
                            <span class="text-xs" style="color: var(--text-muted);" x-text="c.detail"></span>
                        </button>
                    </template>
                </div>
                <div x-show="cEmpty" x-cloak class="text-xs mt-1" style="color: var(--text-muted);">No contact found for that.</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Responsible agent: pre-filled VISIBLY from the deal / property, editable, and what is saved is exactly what is shown here --}}
            <div data-tour="rpt-tdf-agent">
                <label for="td-agent" class="{{ $lbl }}" style="color: var(--text-secondary);">Responsible agent</label>
                <select id="td-agent" name="agent_id" x-model="agentId" @change="touched('agentId')" class="{{ $input }}" :style="fieldStyle('agentId')" style="{{ $inputStyle }}">
                    @foreach($agents as $a)<option value="{{ $a->id }}">{{ $a->label }}</option>@endforeach
                </select>
                <div x-show="notes.agentId" x-cloak class="text-xs mt-1" style="color: var(--text-secondary);" x-text="notes.agentId"></div>
            </div>
            @if($branches->count() > 1)
            <div>
                <label for="td-branch" class="{{ $lbl }}" style="color: var(--text-secondary);">Branch</label>
                <select id="td-branch" name="branch_id" x-model="branchId" @change="touched('branchId')" class="{{ $input }}" :style="fieldStyle('branchId')" style="{{ $inputStyle }}">
                    @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
                <div x-show="notes.branchId" x-cloak class="text-xs mt-1" style="color: var(--text-secondary);" x-text="notes.branchId"></div>
            </div>
            @else
                <input type="hidden" name="branch_id" value="{{ $branches->first()?->id }}">
            @endif

            {{-- The deal's other agents --}}
            <div class="md:col-span-2" x-show="listing || extraAgents.length" x-cloak>
                <template x-if="listing"><div class="text-sm" style="color: var(--text-primary);"><input type="hidden" name="listing_agent_id" :value="listing.id"><span class="text-xs" style="color: var(--text-secondary);">Listing agent:</span> <span x-text="listing.name"></span></div></template>
                <div class="flex flex-wrap items-center gap-2 mt-1" x-show="extraAgents.length">
                    <span class="text-xs" style="color: var(--text-secondary);">Also told on collection:</span>
                    <template x-for="a in extraAgents" :key="a.id">
                        <span class="inline-flex items-center gap-2 rounded-md px-2.5 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <input type="hidden" name="additional_agent_ids[]" :value="a.id">
                            <span x-text="a.name"></span>
                            <button type="button" class="text-xs" style="color: var(--ds-crimson);" :aria-label="'Remove ' + a.name" @click="extraAgents = extraAgents.filter(x => x.id !== a.id)">Remove</button>
                        </span>
                    </template>
                </div>
            </div>

            <div data-tour="rpt-tdf-received">
                <label for="td-received" class="{{ $lbl }}" style="color: var(--text-secondary);">Date received<span class="font-normal" style="color: var(--ds-crimson);"> (required)</span></label>
                <input id="td-received" type="date" name="received_at" required max="{{ now()->toDateString() }}" value="{{ old('received_at', $deed?->received_at?->toDateString() ?? now()->toDateString()) }}" class="{{ $input }}" style="{{ $inputStyle }}">
            </div>
            <div data-tour="rpt-tdf-attorney">
                <label for="td-attorney" class="{{ $lbl }}" style="color: var(--text-secondary);">Attorney firm<span class="font-normal" style="color: var(--text-muted);"> (optional)</span></label>
                <input id="td-attorney" type="text" name="attorney_firm" maxlength="255" x-model="attorney" @input="touched('attorney')" class="{{ $input }}" :style="fieldStyle('attorney')" style="{{ $inputStyle }}">
                <div x-show="notes.attorney" x-cloak class="text-xs mt-1" style="color: var(--text-secondary);" x-text="notes.attorney"></div>
            </div>

            {{-- Where it is kept: the agency's own list --}}
            <div class="md:col-span-2" data-tour="rpt-tdf-location">
                <label for="td-location" class="{{ $lbl }}" style="color: var(--text-secondary);">Where it is kept<span class="font-normal" style="color: var(--ds-crimson);"> (required)</span></label>
                @if($noLocations)
                    <div class="rounded-md px-3 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
                        This agency has no storage locations yet.
                        @if($canManageLocations)
                            <a href="{{ route('corex.settings.title-deeds.locations') }}" class="font-semibold underline">Add storage locations</a>
                        @else
                            Ask an admin to add a storage location.
                        @endif
                    </div>
                    @if($legacyLocation)<div class="text-xs mt-1" style="color: var(--text-muted);">Legacy: {{ $legacyLocation }}</div>@endif
                @else
                    <select id="td-location" name="storage_location_id" x-model="locationId" @if(! $legacyLocation) required @endif class="{{ $input }}" style="{{ $inputStyle }}">
                        <option value="">{{ $legacyLocation ? 'Legacy: ' . $legacyLocation . ' (pick a location)' : 'Choose where it is kept' }}</option>
                        <template x-for="l in usableLocations()" :key="l.id"><option :value="String(l.id)" :selected="String(l.id) === locationId" x-text="l.name"></option></template>
                    </select>
                @endif
            </div>

            <div class="md:col-span-2" data-tour="rpt-tdf-notes">
                <label for="td-notes" class="{{ $lbl }}" style="color: var(--text-secondary);">Notes<span class="font-normal" style="color: var(--text-muted);"> (optional)</span></label>
                <textarea id="td-notes" name="notes" rows="3" maxlength="5000" class="{{ $input }}" style="{{ $inputStyle }}">{{ old('notes', $deed?->notes) }}</textarea>
            </div>

            @unless($editing)
            <div class="md:col-span-2" data-tour="rpt-tdf-scan">
                <label for="td-scan" class="{{ $lbl }}" style="color: var(--text-secondary);">Scan of the title deed<span class="font-normal" style="color: var(--text-muted);"> (optional)</span></label>
                <input id="td-scan" type="file" name="scan" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="block w-full text-sm" style="color: var(--text-secondary);">
            </div>
            @endunless
        </div>
        @endif

        <div class="flex items-center gap-3" data-tour="rpt-tdf-save">
            @if($isCollected)
                <button type="submit" class="corex-btn-primary">Save changes</button>
            @elseif($editing)
                <button type="submit" class="corex-btn-primary" @if($noLocations && ! $legacyLocation) disabled @endif>Save changes</button>
            @else
                {{-- A duplicate needs an explicit confirm through the app dialog; otherwise a plain submit. --}}
                <button type="submit" class="corex-btn-primary" x-show="!(existing.length || serverDuplicate)" @if($noLocations) disabled @endif>Log the deed</button>
                <span x-show="existing.length || serverDuplicate" x-cloak>
                    <x-confirm-submit title="Log another deed for this property" message="A title deed for this property is already logged and not yet collected. A property can legitimately get a later deed (for instance after a re-sale). Log another one?" confirm-label="Log another deed for this property" name="confirm_duplicate" value="1">Log another deed for this property</x-confirm-submit>
                </span>
            @endif
        </div>
    </form>
</div>

<script>
    function titleDeedForm(cfg) {
        const PRIORITY = { default: 0, property: 1, deal: 2, user: 3 };
        return {
            property: cfg.property, deal: cfg.deal, owners: cfg.owners || [], listing: cfg.listing, extraAgents: cfg.extraAgents || [],
            agentId: cfg.agentId, branchId: cfg.branchId, locationId: cfg.locationId, attorney: cfg.attorney,
            src: { agentId: cfg.editing ? 'user' : 'default', branchId: cfg.editing ? 'user' : 'default', attorney: cfg.editing || cfg.attorney ? 'user' : 'default' },
            notes: { agentId: '', branchId: '', attorney: '' }, ownersNote: '', dealHint: '',
            existing: cfg.serverDuplicate ? [{ id: cfg.serverDuplicate.id, status: cfg.serverDuplicate.status, received: cfg.serverDuplicate.received, url: cfg.serverDuplicate.url }] : [],
            serverDuplicate: !!cfg.serverDuplicate,
            pq: '', pResults: [], pEmpty: false, cq: '', cResults: [], cEmpty: false, dq: '', dResults: [], dEmpty: false,
            async getJson(url) {
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    return res.ok ? await res.json() : null;
                } catch (e) { return null; }
            },
            /* A field can be filled from the property, then the deal - never over something the person chose themselves. */
            setField(name, value, source, note) {
                if (PRIORITY[source] < PRIORITY[this.src[name]]) return false;
                this[name] = value; this.src[name] = source; this.notes[name] = note || '';
                return true;
            },
            touched(name) { this.src[name] = 'user'; this.notes[name] = ''; },
            fieldStyle(name) { return (this.src[name] === 'deal' || this.src[name] === 'property') ? 'outline: 2px solid var(--brand-icon); outline-offset: 1px;' : ''; },
            usableLocations() {
                const b = String(this.branchId || '');
                return cfg.locations.filter(l => !l.branch_id || String(l.branch_id) === b);
            },

            async searchProperties() {
                this.pEmpty = false;
                if (this.pq.trim().length < 2) { this.pResults = []; return; }
                const data = await this.getJson(cfg.urls.properties + '?q=' + encodeURIComponent(this.pq.trim()));
                this.pResults = data ? data.results : [];
                this.pEmpty = data !== null && this.pResults.length === 0;
            },
            async pickProperty(r) {
                this.property = { id: r.id, address: r.address };
                this.pResults = []; this.pq = ''; this.pEmpty = false;
                const s = await this.getJson(cfg.urls.suggestions + '/' + r.id + '/suggestions');
                if (!s) return;
                this.existing = s.existing || [];
                if (s.agent_id && cfg.agentIds.includes(String(s.agent_id))) this.setField('agentId', String(s.agent_id), 'property', 'Filled from the property: ' + (s.agent_name || 'its agent') + '. Change it if someone else is responsible.');
                if (s.branch_id && cfg.branchIds.includes(String(s.branch_id))) this.setField('branchId', String(s.branch_id), 'property', 'Filled from the property.');
                if (this.owners.length === 0 && s.owners && s.owners.length) { s.owners.forEach(o => this.addOwner(o, true)); this.ownersNote = "Filled from the property's seller / owner - remove or add as needed."; }
            },
            clearProperty() { this.property = null; this.existing = []; },

            async searchDeals() {
                this.dEmpty = false;
                if (this.dq.trim().length < 1) { this.dResults = []; return; }
                const data = await this.getJson(cfg.urls.deals + '?q=' + encodeURIComponent(this.dq.trim()));
                this.dResults = data ? data.results : [];
                this.dEmpty = data !== null && this.dResults.length === 0;
            },
            async pickDeal(r) {
                this.dResults = []; this.dq = ''; this.dEmpty = false;
                const p = await this.getJson(cfg.urls.dealPrefill + '/' + r.id + '/prefill');
                if (!p) return;
                this.deal = p.deal;
                const hints = [];
                if (p.property) { this.property = p.property; this.existing = []; const s = await this.getJson(cfg.urls.suggestions + '/' + p.property.id + '/suggestions'); this.existing = s ? (s.existing || []) : []; }
                else if (p.property_text) { this.pq = p.property_text; hints.push('The deal has no linked property record - search for "' + p.property_text + '" above and pick it.'); this.searchProperties(); }
                if (p.attorney_firm) this.setField('attorney', p.attorney_firm, 'deal', "Filled from the deal's transferring attorney.");
                if (p.branch_id && cfg.branchIds.includes(String(p.branch_id))) this.setField('branchId', String(p.branch_id), 'deal', 'Filled from the deal.');
                if (p.agent_id && cfg.agentIds.includes(String(p.agent_id))) this.setField('agentId', String(p.agent_id), 'deal', "Filled from the deal: " + p.agent_name + ', the selling agent (works with the buyer, the new owner). Change it if someone else is responsible.');
                this.listing = p.listing_agent || null;
                this.extraAgents = (p.additional_agents || []).filter(a => cfg.agentIds.includes(String(a.id)));
                if (this.owners.length === 0 && p.owners && p.owners.length) { p.owners.forEach(o => this.addOwner(o, true)); this.ownersNote = 'Filled from the buyers on the deal (the new owners) - remove or add as needed.'; }
                else if (p.buyer_text) hints.push('Buyer on the deal (not linked to a contact): ' + p.buyer_text + ' - search your contacts below to add them as the owner.');
                this.dealHint = hints.join(' ');
            },
            clearDeal() { this.deal = null; this.listing = null; this.extraAgents = []; this.dealHint = ''; },

            async searchContacts() {
                this.cEmpty = false;
                if (this.cq.trim().length < 2) { this.cResults = []; return; }
                const data = await this.getJson(cfg.urls.contacts + '?q=' + encodeURIComponent(this.cq.trim()));
                this.cResults = data ? data.results : [];
                this.cEmpty = data !== null && this.cResults.length === 0;
            },
            addOwner(c, silent) {
                if (!this.owners.some(o => o.id === c.id)) this.owners.push({ id: c.id, name: c.name, detail: c.detail || '' });
                if (!silent) this.ownersNote = '';
                this.cResults = []; this.cq = ''; this.cEmpty = false;
            },
            removeOwner(id) { this.owners = this.owners.filter(o => o.id !== id); },
        };
    }
</script>
@endsection
