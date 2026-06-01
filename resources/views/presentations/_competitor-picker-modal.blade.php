{{--
    Active Competition manual-picker modal. Teleported to body so the
    overlay sits above the review screen's stacking context. Filters
    pre-populated from CompetitorStockMatchService::buildCriteria (via
    the bootstrap fetch); results from searchForManualPicker, ranked by
    score DESC. Each row tick = POST to the existing toggle-competitor
    endpoint. On close, fetch competitor-data and re-render
    #competitor-stock-list in place — no page reload.

    The Alpine x-data is defined by window.competitorPicker(config) in
    the script at the bottom of this partial; the parent .review-card
    div on review.blade.php wires the config urls.

    The Level-1 family gate is enforced ENTIRELY on the backend
    (searchForManualPicker + toggleCompetitor). The agent CANNOT widen
    the family in this UI — there's no field for it — and any tampered
    request gets rejected.
--}}

<template x-teleport="body">
<div x-show="open" x-cloak
     class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
     x-transition.opacity
     @keydown.escape.window="closeModal()">
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="closeModal()"></div>

    <div class="relative rounded-md w-full max-w-5xl shadow-xl flex flex-col"
         style="background:var(--surface,#fff); border:1px solid var(--border); max-height:90vh;"
         @click.stop>

        {{-- Header --}}
        <div style="padding:14px 18px; border-bottom:1px solid var(--border); display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div>
                <h3 class="text-base font-bold" style="color:var(--text-primary);">Pick competing properties</h3>
                <p class="text-xs" style="color:var(--text-muted); margin-top:2px;">
                    Filtered to the same family as the subject (<span x-text="criteria?.family || '…'" style="font-weight:600;"></span>).
                    Widen price/beds to surface more; family + commercial exclusion stays locked.
                </p>
            </div>
            <button type="button" @click="closeModal()"
                    style="background:transparent;border:1px solid var(--border);color:var(--text-secondary);padding:6px 10px;border-radius:4px;font-size:12px;cursor:pointer;"
                    title="Close — section re-renders with your selection">
                Close
            </button>
        </div>

        {{-- Filters --}}
        <div style="padding:12px 18px; border-bottom:1px solid var(--border); display:grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap:10px; align-items:end;">
            <div style="grid-column: span 2;">
                <label style="display:block;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Suburb</label>
                <input type="text" x-model="filters.suburb"
                       @input.debounce.400ms="runSearch()"
                       class="w-full rounded-md text-xs px-2 py-1.5"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                       placeholder="Suburb name (LIKE)">
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Type</label>
                <select x-model="filters.property_type"
                        @change="runSearch()"
                        class="w-full rounded-md text-xs px-2 py-1.5"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All in family</option>
                    <template x-for="t in (criteria?.family_types || [])" :key="t">
                        <option :value="t" x-text="t"></option>
                    </template>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Price min</label>
                <input type="number" x-model.number="filters.price_min" min="0" step="50000"
                       @input.debounce.400ms="runSearch()"
                       class="w-full rounded-md text-xs px-2 py-1.5"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                       placeholder="R">
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Price max</label>
                <input type="number" x-model.number="filters.price_max" min="0" step="50000"
                       @input.debounce.400ms="runSearch()"
                       class="w-full rounded-md text-xs px-2 py-1.5"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                       placeholder="R">
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Beds min / max</label>
                <div style="display:flex;gap:4px;">
                    <input type="number" x-model.number="filters.beds_min" min="0" max="20"
                           @input.debounce.400ms="runSearch()"
                           class="w-full rounded-md text-xs px-2 py-1.5"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="min">
                    <input type="number" x-model.number="filters.beds_max" min="0" max="20"
                           @input.debounce.400ms="runSearch()"
                           class="w-full rounded-md text-xs px-2 py-1.5"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="max">
                </div>
            </div>
            <div style="grid-column: span 5;">
                <label style="display:block;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Search address / agent / agency</label>
                <input type="text" x-model="filters.search"
                       @input.debounce.400ms="runSearch()"
                       class="w-full rounded-md text-xs px-2 py-1.5"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <button type="button" @click="resetFilters()"
                        style="width:100%;background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary);padding:7px 10px;border-radius:4px;font-size:11px;cursor:pointer;"
                        title="Reset to the auto-picker defaults">
                    Reset
                </button>
            </div>
        </div>

        {{-- Results --}}
        <div style="flex:1; overflow-y:auto; padding:12px 18px;">
            <div x-show="loading" x-cloak style="text-align:center;padding:24px;color:var(--text-muted);font-size:12px;">
                Searching…
            </div>
            <div x-show="!loading && results.length === 0" x-cloak
                 style="text-align:center;padding:24px;color:var(--text-muted);font-size:12px;">
                No matches for these filters. Widen the price band or change suburb.
            </div>
            <div x-show="!loading && results.length > 0" x-cloak
                 style="font-size:11px;color:var(--text-muted);margin-bottom:8px;display:flex;justify-content:space-between;">
                <span x-text="results.length + ' result' + (results.length === 1 ? '' : 's') + ' — sorted by match score'"></span>
                <span x-text="includedCount() + ' ticked'"></span>
            </div>

            <div x-show="!loading && results.length > 0" x-cloak
                 style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;">
                <template x-for="row in results" :key="row.listing_id">
                    <div :class="'competitor-picker-card' + (row.is_included ? ' included' : '')"
                         style="border:1px solid var(--border);border-radius:6px;background:var(--surface);padding:10px;display:flex;flex-direction:column;gap:6px;position:relative;"
                         :style="row.is_included ? 'border-color:#10b981;background:color-mix(in srgb,#10b981 6%,var(--surface));' : ''">
                        <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                     x-text="row.address || ('Listing #' + row.listing_id)"
                                     :title="row.address || ''"></div>
                                <div style="font-size:10px;color:var(--text-muted);"
                                     x-text="row.suburb + ' · ' + row.property_type + ' · R ' + (row.price || 0).toLocaleString('en-ZA')"></div>
                                <div style="font-size:10px;color:var(--text-muted);"
                                     x-text="(row.bedrooms ?? '-') + ' bed · ' + (row.bathrooms ?? '-') + ' bath · ' + (row.garages ?? '-') + ' gar'"></div>
                                <div style="font-size:9px;color:var(--text-muted);"
                                     x-text="(row.agent_name || row.agency_name || '') + (row.portal_ref ? ' · ' + row.portal_ref : '')"></div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                                <span :style="'font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;color:' + tierFg(row.tier) + ';background:' + tierBg(row.tier) + ';'"
                                      x-text="row.score + '%'"></span>
                                <span style="font-size:9px;color:var(--text-muted);"
                                      x-text="row.level2_match === 'exact' ? 'exact-type' : 'family'"></span>
                            </div>
                        </div>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:11px;color:var(--text-secondary);padding-top:4px;border-top:1px solid var(--border);">
                            <input type="checkbox" :checked="row.is_included"
                                   @change="toggleRow(row, $event.target.checked)">
                            <span x-text="row.is_included ? 'Included in seller PDF' : 'Tick to include'"></span>
                        </label>
                    </div>
                </template>
            </div>
        </div>

        {{-- Footer --}}
        <div style="padding:10px 18px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
            <div style="font-size:11px;color:var(--text-muted);"
                 x-show="error" x-cloak x-text="error"></div>
            <div style="margin-left:auto;">
                <button type="button" @click="closeModal()"
                        class="prop-action-btn prop-action-btn-brand"
                        style="font-size:12px;padding:7px 14px;">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>
</template>

<script>
if (typeof window.competitorPicker !== 'function') {
    window.competitorPicker = function (config) {
        return {
            open: false,
            loading: false,
            error: null,
            criteria: null,           // bootstrap criteria from buildCriteria
            results: [],
            filters: {
                suburb: '',
                property_type: '',
                price_min: null,
                price_max: null,
                beds_min: null,
                beds_max: null,
                search: '',
            },

            tierFg(tier) {
                return { perfect:'#10b981', strong:'#0ea5e9', approximate:'#a16207' }[tier] || '#475569';
            },
            tierBg(tier) {
                return { perfect:'#ecfdf5', strong:'#eff6ff', approximate:'#fefce8' }[tier] || '#f1f5f9';
            },

            includedCount() {
                return this.results.filter(r => r.is_included).length;
            },

            async openModal() {
                this.open = true;
                this.error = null;
                // Reset filters to a clean slate so the first fetch
                // returns the auto-picker defaults (suburb/family/etc.
                // are baked into the backend buildCriteria; the modal
                // doesn't need to echo them as initial filter values
                // because suburb=null means "use criteria.suburb").
                this.filters = {
                    suburb: '',
                    property_type: '',
                    price_min: null,
                    price_max: null,
                    beds_min: null,
                    beds_max: null,
                    search: '',
                };
                await this.runSearch();
            },

            closeModal() {
                this.open = false;
                // Refresh the review screen's Active Competition section
                // in place. Same shape AnalysisDataService produces.
                this.refreshSection();
            },

            resetFilters() {
                this.filters = {
                    suburb: '',
                    property_type: '',
                    price_min: null,
                    price_max: null,
                    beds_min: null,
                    beds_max: null,
                    search: '',
                };
                this.runSearch();
            },

            async runSearch() {
                this.loading = true;
                this.error = null;
                try {
                    const params = new URLSearchParams();
                    for (const [k, v] of Object.entries(this.filters)) {
                        if (v !== null && v !== '' && v !== undefined) params.set(k, String(v));
                    }
                    params.set('limit', '200');
                    const r = await fetch(config.searchUrl + '?' + params.toString(), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!r.ok) {
                        const data = await r.json().catch(() => null);
                        this.error = (data?.message) || ('Search failed (' + r.status + ').');
                        this.results = [];
                        return;
                    }
                    const data = await r.json();
                    this.criteria = data.criteria || null;
                    this.results  = data.results  || [];
                } catch (e) {
                    this.error = 'Search failed: ' + e.message;
                    this.results = [];
                } finally {
                    this.loading = false;
                }
            },

            async toggleRow(row, wantIncluded) {
                const prev = row.is_included;
                row.is_included = wantIncluded;            // optimistic
                const url = config.toggleTpl.replace('__LISTING_ID__', String(row.listing_id));
                const body = new FormData();
                body.append('_token', config.csrf);
                body.append('included', wantIncluded ? '1' : '0');
                try {
                    const r = await fetch(url, {
                        method: 'POST',
                        headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' },
                        body, credentials: 'same-origin',
                    });
                    const data = await r.json().catch(() => null);
                    if (!r.ok || !data?.ok) {
                        row.is_included = prev;            // rollback
                        this.error = (data?.message) || 'Could not save — please retry.';
                    }
                } catch (e) {
                    row.is_included = prev;
                    this.error = 'Network error saving toggle.';
                }
            },

            async refreshSection() {
                try {
                    const r = await fetch(config.dataUrl, {
                        headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!r.ok) return;
                    const data = await r.json();
                    const visible = (data.visible || []).map(m => Object.assign({}, m, { is_included: true }));
                    const total   = (data.matches || []).length;
                    const summary = 'showing ' + visible.length + ' of ' + total + ' scored';
                    if (typeof window.renderCompetitorStockList === 'function') {
                        window.renderCompetitorStockList(visible, summary);
                    }
                    // CMA map — refresh the orange-diamond competition
                    // layer in place. Newly-ticked rows appear; unticked
                    // rows disappear. Honest plotted/unplotted caption
                    // updates via the renderer.
                    if (typeof window.refreshCompetitionMarkers === 'function') {
                        window.refreshCompetitionMarkers(visible);
                    }
                    // Keep the source-of-truth window var in sync so the
                    // initial queueMicrotask read on next paint sees the
                    // post-modal state.
                    window.COMPETITOR_VISIBLE = visible;
                } catch (e) { /* silent — modal already closed, agent can refresh manually */ }
            },
        };
    };
}
</script>
