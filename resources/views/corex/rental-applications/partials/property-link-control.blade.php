{{--
    "Link / change the property" on the application the agent is still capturing (the page that opens right
    after "Rental application created. Review it, then send."). Johan's walk: an application made WITHOUT a
    property could only ever get a free-text address — the property picker lived on the review screen, which
    only exists after the applicant has submitted. Same search + link endpoints as the review screen
    (corex.rental-applications.search-properties / .review.link-property — one implementation of the rules:
    agent-visible rental stock only, audited, locked once the application is under assessment when the agency
    keeps that lock). Done with fetch, not a reload, so anything typed in the form below is not lost; the new
    updated_at goes into the form's own staleness field so its next save is not refused as "changed elsewhere".
--}}
@php
    $linkedProperty = $rentalApplication->property;
    $linkedLabel = $linkedProperty?->buildDisplayAddress();
    $linkLocked = \App\Models\RentalApplicationQualifyingSetting::isPropertyLinkLockedFor($rentalApplication);
@endphp
<div class="space-y-2" data-qa="application-property-link"
     x-data="rentalApplicationPropertyLink({{ Js::from([
         'searchUrl' => route('corex.rental-applications.search-properties'),
         'linkUrl' => route('corex.rental-applications.review.link-property', $rentalApplication),
         'label' => $linkedLabel,
         'locked' => $linkLocked,
     ]) }})">
    <div class="text-sm" style="color: var(--text-primary);">
        <span class="text-xs" style="color: var(--text-muted);">Property:</span>
        <strong x-show="label" x-cloak x-text="label" data-qa="linked-property-label">{{ $linkedLabel }}</strong>
        <span x-show="!label" x-cloak style="color: var(--text-muted);">No property linked yet.</span>
    </div>

    @if($linkLocked)
        <p class="text-xs" style="color: var(--text-muted);">The property can't be changed once the application is with the authoriser.</p>
    @else
        <template x-if="!searching">
            <span class="inline-flex items-center gap-2">
                <button type="button" class="corex-btn-outline text-xs" data-qa="link-property-open" @click="searching = true">
                    <span x-text="label ? 'Change property' : 'Link a property'"></span>
                </button>
                <button type="button" class="underline text-xs" style="color: var(--ds-red, #dc2626);" x-show="label" x-cloak @click="clear()">Clear</button>
            </span>
        </template>
        <template x-if="searching">
            <div class="relative" style="max-width: 24rem;">
                <input type="text" x-model="query" @input.debounce.300ms="search()" @keydown.escape="searching = false"
                       placeholder="Search rental properties…" class="w-full rounded-md px-2 py-1.5 text-sm" style="border: 1px solid var(--border);" data-qa="link-property-search">
                <button type="button" class="text-xs underline ml-1" style="color: var(--text-muted);" @click="searching = false">Cancel</button>
                <div class="absolute z-10 mt-1 w-full rounded-md max-h-72 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border);" x-show="results.length" x-cloak>
                    <template x-for="p in results" :key="p.id">
                        <button type="button" @click="select(p)" class="block w-full text-left px-2 py-1.5 text-xs hover:bg-slate-50">
                            <div class="flex items-center gap-1.5">
                                <span x-text="p.label"></span>
                                <span x-show="p.status" x-text="p.status" class="text-[10px] px-1 py-0.5 rounded" style="background:var(--surface-2); color: var(--text-muted);"></span>
                            </div>
                            <div style="color: var(--text-muted);" x-text="[p.ref ? ('Ref: ' + p.ref) : '', p.agent].filter(Boolean).join(' · ')"></div>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    @endif
    <p class="text-xs" x-show="message" x-cloak x-text="message" :style="failed ? 'color: var(--ds-red, #dc2626);' : 'color: var(--ds-green, #16a34a);'"></p>
</div>

<script>
function rentalApplicationPropertyLink({ searchUrl, linkUrl, label, locked }) {
    return {
        label: label || null,
        searching: false, query: '', results: [], message: '', failed: false,
        async search() {
            if (this.query.length < 2) { this.results = []; return; }
            const res = await fetch(searchUrl + '?q=' + encodeURIComponent(this.query), { headers: { 'Accept': 'application/json' } });
            this.results = await res.json();
        },
        select(p) { this.save(p.id); },
        async clear() {
            if (!(await window.corexConfirm({ title: 'Clear the linked property', message: 'Clear the linked property from this application?', confirmText: 'Clear' }))) { return; }
            this.save('');
        },
        async save(propertyId) {
            this.message = ''; this.failed = false;
            try {
                const res = await fetch(linkUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ property_id: propertyId === '' ? null : propertyId }),
                });
                if (!res.ok) { throw new Error('refused'); }
                const data = await res.json();
                this.label = data.label || null;
                this.searching = false; this.query = ''; this.results = [];
                const stale = document.querySelector('input[name="expected_updated_at"]');
                if (stale && data.updated_at) { stale.value = data.updated_at; }
                this.message = this.label ? 'Property linked.' : 'Property link cleared.';
            } catch (e) {
                this.failed = true;
                this.message = "That property couldn't be linked (it may not be yours to link, or the application is locked). Nothing changed.";
            }
        },
    };
}
</script>
