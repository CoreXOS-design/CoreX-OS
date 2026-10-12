@extends('layouts.corex-app')

@section('corex-content')
@php
    $editing = (bool) $deed;
    $isCollected = $editing && $deed->isCollected();
    $input = 'w-full rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<div class="w-full max-w-3xl space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $editing ? 'Edit title deed' : 'Log a title deed' }}</h1>
            <a href="{{ $editing ? route('title-deeds.show', $deed->id) : route('title-deeds.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Cancel</a>
        </div>
    </div>

    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>@endif
    @if($errors->any())
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
        <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
    @endif

    <form method="POST" action="{{ $editing ? route('title-deeds.update', $deed->id) : route('title-deeds.store') }}"
          class="rounded-md p-5 space-y-5" style="background: var(--surface); border: 1px solid var(--border);"
          x-data="titleDeedForm({
              property: @js($propertyInit),
              owners: @js($ownersInit),
              propertySearchUrl: @js(route('title-deeds.search.properties')),
              suggestionsUrl: @js(url('/title-deeds/search/property')),
              contactSearchUrl: @js(route('title-deeds.search.contacts')),
              locked: @js($isCollected),
          })">
        @csrf
        @if($editing) @method('PUT') @endif

        @if($isCollected)
            <div class="space-y-2">
                <div class="text-sm" style="color: var(--text-secondary);">This deed has been collected, so only the notes can change.</div>
                <div>
                    <label for="td-notes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                    <textarea id="td-notes" name="notes" rows="4" maxlength="5000" class="{{ $input }}" style="{{ $inputStyle }}">{{ old('notes', $deed->notes) }}</textarea>
                </div>
            </div>
        @else
        {{-- Property --}}
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);" for="td-property-q">Property</label>
            <input type="hidden" name="property_id" :value="property ? property.id : ''">
            <template x-if="property">
                <div class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <span x-text="property.address" style="color: var(--text-primary);"></span>
                    <button type="button" class="text-xs font-semibold" style="color: var(--brand-icon);" @click="clearProperty()">Change</button>
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
        </div>

        {{-- Owners --}}
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);" for="td-owner-q">Owner(s)</label>
            <div class="flex flex-wrap gap-2 mb-2" x-show="owners.length">
                <template x-for="o in owners" :key="o.id">
                    <span class="inline-flex items-center gap-2 rounded-md px-2.5 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="hidden" name="owner_ids[]" :value="o.id">
                        <span x-text="o.name"></span>
                        <button type="button" class="text-xs" style="color: var(--ds-crimson);" :aria-label="'Remove ' + o.name" @click="removeOwner(o.id)">&times;</button>
                    </span>
                </template>
            </div>
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
            <div>
                <label for="td-agent" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Responsible agent</label>
                <select id="td-agent" name="agent_id" x-model="agentId" class="{{ $input }}" style="{{ $inputStyle }}">
                    @foreach($agents as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                </select>
            </div>
            @if($branches->count() > 1)
            <div>
                <label for="td-branch" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
                <select id="td-branch" name="branch_id" x-model="branchId" class="{{ $input }}" style="{{ $inputStyle }}">
                    @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
            </div>
            @else
                <input type="hidden" name="branch_id" value="{{ $branches->first()?->id }}">
            @endif
            <div>
                <label for="td-received" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Date received</label>
                <input id="td-received" type="date" name="received_at" required max="{{ now()->toDateString() }}" value="{{ old('received_at', $deed?->received_at?->toDateString() ?? now()->toDateString()) }}" class="{{ $input }}" style="{{ $inputStyle }}">
            </div>
            <div>
                <label for="td-attorney" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Attorney firm</label>
                <input id="td-attorney" type="text" name="attorney_firm" maxlength="255" value="{{ old('attorney_firm', $deed?->attorney_firm) }}" class="{{ $input }}" style="{{ $inputStyle }}">
            </div>
            <div class="md:col-span-2">
                <label for="td-location" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Where it is kept</label>
                <input id="td-location" type="text" name="storage_location" maxlength="255" value="{{ old('storage_location', $deed?->storage_location) }}" class="{{ $input }}" style="{{ $inputStyle }}">
            </div>
            <div class="md:col-span-2">
                <label for="td-notes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                <textarea id="td-notes" name="notes" rows="3" maxlength="5000" class="{{ $input }}" style="{{ $inputStyle }}">{{ old('notes', $deed?->notes) }}</textarea>
            </div>
        </div>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">{{ $editing ? 'Save changes' : 'Log the deed' }}</button>
        </div>
    </form>
</div>

<script>
    function titleDeedForm(cfg) {
        return {
            property: cfg.property, owners: cfg.owners || [],
            agentId: @js((string) $defaultAgentId), branchId: @js((string) $defaultBranchId),
            pq: '', pResults: [], pEmpty: false, cq: '', cResults: [], cEmpty: false,
            async getJson(url) {
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    return res.ok ? await res.json() : null;
                } catch (e) { return null; }
            },
            async searchProperties() {
                this.pEmpty = false;
                if (this.pq.trim().length < 2) { this.pResults = []; return; }
                const data = await this.getJson(cfg.propertySearchUrl + '?q=' + encodeURIComponent(this.pq.trim()));
                this.pResults = data ? data.results : [];
                this.pEmpty = data !== null && this.pResults.length === 0;
            },
            async pickProperty(r) {
                this.property = { id: r.id, address: r.address };
                this.pResults = []; this.pq = ''; this.pEmpty = false;
                const s = await this.getJson(cfg.suggestionsUrl + '/' + r.id + '/suggestions');
                if (!s) return;
                if (s.agent_id) this.agentId = String(s.agent_id);
                if (s.branch_id) this.branchId = String(s.branch_id);
                if (this.owners.length === 0 && s.owners) s.owners.forEach(o => this.addOwner(o));
            },
            clearProperty() { this.property = null; },
            async searchContacts() {
                this.cEmpty = false;
                if (this.cq.trim().length < 2) { this.cResults = []; return; }
                const data = await this.getJson(cfg.contactSearchUrl + '?q=' + encodeURIComponent(this.cq.trim()));
                this.cResults = data ? data.results : [];
                this.cEmpty = data !== null && this.cResults.length === 0;
            },
            addOwner(c) {
                if (!this.owners.some(o => o.id === c.id)) this.owners.push({ id: c.id, name: c.name, detail: c.detail || '' });
                this.cResults = []; this.cq = ''; this.cEmpty = false;
            },
            removeOwner(id) { this.owners = this.owners.filter(o => o.id !== id); },
        };
    }
</script>
@endsection
