{{--
    PPRA Inspection Pack Phase F — .ai/specs/ppra-inspection-pack.md §6.8a.
    Shared sample picker, reused by items k/l/m (Phases G/H/I). One
    instance per mode; wraps <x-modal> so the trigger button lives outside
    this component and just dispatches 'open-modal' with :name.
--}}
@props([
    'mode',      // 'deal' | 'rental' | 'listing'
    'name',      // unique modal name for this instance on the page
    'title',     // e.g. "Choose sales sample"
])

@php
    $searchUrl = route('admin.ppra-inspection-pack.sample-picker.search', $mode);
    $mostRecentUrl = route('admin.ppra-inspection-pack.sample-picker.most-recent', $mode);
    $storeUrl = route('admin.ppra-inspection-pack.sample-picker.store', $mode);
@endphp

<x-modal :name="$name" max-width="2xl">
    <div
        x-data="ppraSamplePicker(@js($searchUrl), @js($mostRecentUrl), @js($storeUrl))"
        x-init="init()"
        x-on:open-modal.window="$event.detail == '{{ $name }}' && search()"
        class="flex flex-col"
        style="max-height: 85vh;"
    >
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <div>
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">{{ $title }}</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="`Pick up to ${sampleSize} — ${selected.length} selected`"></p>
            </div>
            <button type="button" class="text-xs" style="color:var(--text-muted);" x-on:click="$dispatch('close-modal', '{{ $name }}')">Close</button>
        </div>

        <div class="px-5 py-3 flex flex-wrap items-end gap-2" style="border-bottom:1px solid var(--border);">
            <div class="flex-1 min-w-[180px]">
                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Search</label>
                <input type="text" x-model.debounce.400ms="filters.search" x-on:input="page = 1; search()"
                    placeholder="Address, agent, or contact name"
                    class="w-full rounded-md text-sm px-2.5 py-1.5" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="text-xs font-semibold" style="color:var(--text-secondary);">From</label>
                <input type="date" x-model="filters.date_from" x-on:change="page = 1; search()"
                    class="rounded-md text-sm px-2.5 py-1.5" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="text-xs font-semibold" style="color:var(--text-secondary);">To</label>
                <input type="date" x-model="filters.date_to" x-on:change="page = 1; search()"
                    class="rounded-md text-sm px-2.5 py-1.5" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <button type="button" class="corex-btn-outline text-xs" x-on:click="selectMostRecent()">
                <span x-text="'Select ' + sampleSize + ' most recent'"></span>
            </button>
        </div>

        <div class="overflow-y-auto flex-1" style="min-height:240px;">
            <template x-if="loading">
                <div class="px-5 py-8 text-center text-xs" style="color:var(--text-muted);">Loading…</div>
            </template>
            <template x-if="!loading && results.length === 0">
                <div class="px-5 py-8 text-center text-xs" style="color:var(--text-muted);">No records match this filter.</div>
            </template>
            <table class="w-full text-sm" x-show="!loading && results.length > 0">
                <tbody>
                    <template x-for="row in results" :key="row.id">
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-5 py-2 w-8">
                                <input type="checkbox" :checked="selected.includes(row.id)"
                                    :disabled="!selected.includes(row.id) && selected.length >= sampleSize"
                                    x-on:change="toggle(row.id)">
                            </td>
                            <td class="px-2 py-2">
                                <div style="color:var(--text-primary);" x-text="row.label"></div>
                                <div class="text-xs" style="color:var(--text-muted);" x-text="row.sub_label"></div>
                            </td>
                            <td class="px-2 py-2 text-xs text-right" style="color:var(--text-muted);" x-text="row.date"></td>
                            <td class="px-2 py-2 text-xs text-right" style="color:var(--text-muted);" x-text="row.status"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 flex items-center justify-between" style="border-top:1px solid var(--border);">
            <div class="flex items-center gap-2 text-xs" style="color:var(--text-muted);">
                <button type="button" class="corex-btn-outline text-xs" x-show="page > 1" x-on:click="page--; search()">Prev</button>
                <span x-text="`Page ${page} of ${lastPage} (${total} total)`"></span>
                <button type="button" class="corex-btn-outline text-xs" x-show="page < lastPage" x-on:click="page++; search()">Next</button>
            </div>
            <div class="flex items-center gap-2">
                <template x-if="saveError"><span class="text-xs" style="color:var(--ds-crimson,#c41e3a);" x-text="saveError"></span></template>
                <button type="button" class="corex-btn-primary text-xs" x-on:click="confirm()" x-bind:disabled="saving">
                    <span x-show="!saving">Confirm selection</span>
                    <span x-show="saving">Saving…</span>
                </button>
            </div>
        </div>
    </div>
</x-modal>

@once
<script>
function ppraSamplePicker(searchUrl, mostRecentUrl, storeUrl) {
    return {
        filters: { search: '', date_from: '', date_to: '' },
        results: [], selected: [], sampleSize: 5,
        page: 1, lastPage: 1, total: 0, loading: false, saving: false, saveError: '',
        init() { this.search(); },
        search() {
            this.loading = true;
            const qs = new URLSearchParams({ ...this.filters, page: this.page }).toString();
            fetch(`${searchUrl}?${qs}`, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    this.results = data.data;
                    this.lastPage = data.last_page;
                    this.total = data.total;
                    this.sampleSize = data.sample_size;
                    this.selected = data.selected_ids || [];
                })
                .finally(() => { this.loading = false; });
        },
        selectMostRecent() {
            fetch(mostRecentUrl, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => { this.selected = data.ids; });
        },
        toggle(id) {
            if (this.selected.includes(id)) {
                this.selected = this.selected.filter(i => i !== id);
            } else if (this.selected.length < this.sampleSize) {
                this.selected = [...this.selected, id];
            }
        },
        confirm() {
            this.saving = true; this.saveError = '';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch(storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ ids: this.selected }),
            })
                .then(async r => {
                    const data = await r.json();
                    if (!r.ok) throw new Error(data.message || 'Save failed.');
                    return data;
                })
                .then(() => { this.$dispatch('ppra-sample-picker-saved', { selected: this.selected }); })
                .catch(e => { this.saveError = e.message; })
                .finally(() => { this.saving = false; });
        },
    };
}
</script>
@endonce
