{{-- Suburb Report — single-suburb typeahead picker.
     Lighter-weight than the multi-suburb wishlist chip picker (that
     component is built for selecting many suburbs into a form field; this
     one just needs to navigate to one suburb's report). Alpine-only, no
     extra JS dependency — the layout already loads Alpine via app.js.

     Props:
       currentSuburbName — optional, pre-fills the box when a report is
                            already showing (lets an agent see/change what
                            they're looking at without scrolling back up). --}}
@props(['currentSuburbName' => null])

<div x-data="suburbReportPicker()" class="relative" style="max-width: 28rem;">
    <label for="suburb-report-picker-input" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
        Jump to a suburb
    </label>
    <div class="relative">
        <input
            id="suburb-report-picker-input"
            type="text"
            x-model="query"
            @input.debounce.250ms="search()"
            @focus="if (results.length) open = true"
            @keydown.escape="open = false"
            @keydown.down.prevent="highlightNext()"
            @keydown.up.prevent="highlightPrev()"
            @keydown.enter.prevent="chooseHighlighted()"
            autocomplete="off"
            placeholder="{{ $currentSuburbName ?? 'Start typing a suburb name…' }}"
            class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        <div x-show="loading" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2 text-xs" style="color: var(--text-muted);">…</div>
    </div>

    <div x-show="open && results.length" x-cloak
         @click.outside="open = false"
         class="absolute z-20 mt-1 w-full rounded-md overflow-hidden"
         style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.15); max-height: 18rem; overflow-y: auto;">
        <template x-for="(r, idx) in results" :key="r.id">
            <a :href="'{{ url('corex/market-intelligence/suburb-report') }}/' + r.id"
               class="block px-3 py-2 text-sm"
               :style="idx === highlighted
                    ? 'background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--text-primary);'
                    : 'color: var(--text-primary);'"
               @mouseenter="highlighted = idx">
                <span x-text="r.name"></span>
                <span x-show="r.municipality" class="text-xs" style="color: var(--text-muted);" x-text="r.municipality ? ' — ' + r.municipality : ''"></span>
            </a>
        </template>
    </div>

    <div x-show="open && !loading && query.length >= 2 && results.length === 0" x-cloak
         class="absolute z-20 mt-1 w-full rounded-md px-3 py-2 text-sm"
         style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
        No suburb matches "<span x-text="query"></span>".
    </div>
</div>

<script>
function suburbReportPicker() {
    return {
        query: '',
        results: [],
        open: false,
        loading: false,
        highlighted: -1,
        search() {
            if (this.query.trim().length < 2) {
                this.results = [];
                this.open = false;
                return;
            }
            this.loading = true;
            fetch('{{ route('market-intelligence.suburb-report.suburbs') }}?q=' + encodeURIComponent(this.query))
                .then(r => r.json())
                .then(data => {
                    this.results = data;
                    this.open = true;
                    this.highlighted = -1;
                })
                .finally(() => { this.loading = false; });
        },
        highlightNext() {
            if (!this.results.length) return;
            this.highlighted = Math.min(this.highlighted + 1, this.results.length - 1);
        },
        highlightPrev() {
            if (!this.results.length) return;
            this.highlighted = Math.max(this.highlighted - 1, 0);
        },
        chooseHighlighted() {
            if (this.highlighted >= 0 && this.results[this.highlighted]) {
                window.location.href = '{{ url('corex/market-intelligence/suburb-report') }}/' + this.results[this.highlighted].id;
            }
        },
    };
}
</script>
