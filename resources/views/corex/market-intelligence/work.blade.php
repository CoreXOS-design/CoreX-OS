{{--
    F.2 Work mode — top bar + stats strip + split pane (filter rail + listings).

    F.2 keeps the legacy listing rows inside the right column via the
    index_legacy_body_listings_only partial so the page is functional
    end-to-end. F.3 replaces that include with the new _listing-row partial.

    Spec: build-f-market-intelligence-redesign-spec.md §8.
--}}

@include('corex.market-intelligence._top-bar')
@include('corex.market-intelligence._stats-strip')

<div class="mi-split"
     style="display: grid; grid-template-columns: 200px 1fr; min-height: calc(100vh - 200px); align-items: stretch;">

    @include('corex.market-intelligence._filter-rail')

    <main class="mi-main" style="min-width: 0; overflow-x: hidden; padding: 12px 16px;">
        {{-- F.2 result-count header strip. F.3 may add the sort selector here. --}}
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                <strong style="color: var(--text-primary);">{{ number_format($listings->total()) }}</strong>
                {{ \Illuminate\Support\Str::plural('listing', $listings->total()) }}
                @if(request('action_preset'))
                    matching preset <em>{{ str_replace('_', ' ', request('action_preset')) }}</em>
                @endif
            </div>
        </div>

        {{-- F.2: legacy listings table temporarily inside the new shell.
             F.3 replaces this include with the new _listing-row partial. --}}
        @include('prospecting.index_legacy_body_listings_only')
    </main>
</div>

<style>
    /* Mobile fallback — collapse the rail on narrow viewports.
       Full mobile redesign ships in Build G; F.2 just keeps the page usable. */
    @media (max-width: 768px) {
        .mi-split {
            grid-template-columns: 1fr !important;
        }
        .mi-filter-rail {
            display: none;
        }
        .mi-stats-row {
            grid-template-columns: repeat(5, minmax(120px, 1fr)) !important;
            overflow-x: auto !important;
        }
    }
    [x-cloak] { display: none !important; }
</style>
