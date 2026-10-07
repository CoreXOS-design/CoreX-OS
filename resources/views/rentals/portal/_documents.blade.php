{{-- .ai/specs/rental-portal-access.md §19 — the Documents area, one panel for the tenant AND the owner. Reads
     /api/v1/client/rentals[/landlord]/documents (the person's OWN list only); View / Download are authorised file routes. --}}
<div class="card" data-portal-documents>
    <h2>My documents</h2>
    <p class="muted" style="margin:-4px 0 10px;" x-text="activeRole === 'landlord'
        ? 'Signed lease agreements and inspection reports for your properties, and anything your agent has shared.'
        : 'Your signed lease agreement, inspection reports once they have been sent to you, and anything your agent has shared.'"></p>

    <div x-show="docs.meta && docs.meta.all_total > 0">
        <input type="text" placeholder="Search documents or property" x-model.debounce.350ms="docs.q" @input.debounce.350ms="docs.page = 1; loadDocuments()" aria-label="Search documents">
        <div style="display:flex; gap:6px; margin-top:8px;">
            <select x-model="docs.type" @change="docs.page = 1; loadDocuments()" aria-label="Type" style="flex:1">
                <option value="">All types</option>
                <template x-for="(label, key) in (docs.meta ? docs.meta.kinds : {})" :key="key"><option :value="key" x-text="label"></option></template>
            </select>
            <select x-model="docs.sort" @change="docs.dir = docs.sort === 'date' ? 'desc' : 'asc'; docs.page = 1; loadDocuments()" aria-label="Sort by" style="flex:1">
                <option value="date">Newest first</option>
                <option value="name">Name A–Z</option>
                <option value="type">Type</option>
            </select>
        </div>
        <div style="display:flex; gap:6px; margin-top:8px;">
            <input type="date" x-model="docs.from" @change="docs.page = 1; loadDocuments()" aria-label="From date" style="flex:1">
            <input type="date" x-model="docs.to" @change="docs.page = 1; loadDocuments()" aria-label="To date" style="flex:1">
        </div>
    </div>

    <template x-for="d in docs.rows" :key="d.id">
        <div class="list-item" data-portal-document>
            <div class="row">
                <strong x-text="d.name"></strong>
                <span class="badge" x-text="d.subtype ? (d.subtype + ' · ' + d.type) : d.type"></span>
            </div>
            <div class="muted" x-text="[d.belongs_to.lease_label, d.date ? new Date(d.date).toLocaleDateString(undefined, {day:'numeric', month:'short', year:'numeric'}) : null].filter(Boolean).join(' · ')"></div>
            <div style="display:flex; gap:14px; margin-top:6px;">
                <a class="link" :href="d.view_url" target="_blank" rel="noopener">View</a>
                <a class="link" :href="d.download_url">Download</a>
            </div>
        </div>
    </template>

    <p class="muted" x-show="docs.loaded && !docs.rows.length && docs.meta && docs.meta.all_total === 0" data-portal-documents-empty>
        Nothing here yet. Your signed lease agreement and inspection reports appear here as soon as they are signed and sent to you.
    </p>
    <p class="muted" x-show="docs.loaded && !docs.rows.length && docs.meta && docs.meta.all_total > 0">No documents match your search.
        <a class="link" href="#" @click.prevent="docs.q = ''; docs.type = ''; docs.from = ''; docs.to = ''; docs.page = 1; loadDocuments()">Clear filters</a></p>
    <p class="error" x-show="docs.error" x-text="docs.error"></p>

    <div class="row" style="margin-top:10px;" x-show="docs.meta && docs.meta.pages > 1">
        <a class="link" href="#" @click.prevent="if (docs.page > 1) { docs.page--; loadDocuments(); }" x-show="docs.page > 1">← Previous</a>
        <span class="muted" x-text="'Page ' + docs.page + ' of ' + (docs.meta ? docs.meta.pages : 1)"></span>
        <a class="link" href="#" @click.prevent="if (docs.meta && docs.page < docs.meta.pages) { docs.page++; loadDocuments(); }" x-show="docs.meta && docs.page < docs.meta.pages">Next →</a>
    </div>
</div>
