{{-- AT-534 — shared by every Campaigns screen: the page-local control styles (token-based, Properties-page pattern) + the tab bar.
     Validation messages for the page's default error bag render here, under the tabs. --}}
<style>
    .cmp-input { width: 100%; border-radius: 6px; font-size: 0.8125rem; padding: 6px 8px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); transition: all 300ms; }
    .cmp-input:focus { outline: none; border-color: var(--brand-icon, #0ea5e9); }
    .cmp-label { display: block; font-size: 0.6875rem; font-weight: 600; margin-bottom: 3px; color: var(--text-secondary); }
    .cmp-th { text-align: left; padding: 8px 12px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); white-space: nowrap; }
    .cmp-th a { color: inherit; display: inline-flex; align-items: center; gap: 3px; }
    .cmp-th a:hover { color: var(--text-secondary); }
    .cmp-td { padding: 7px 12px; font-size: 0.8125rem; color: var(--text-primary); vertical-align: top; }
    .cmp-muted { color: var(--text-muted); }
    .cmp-row { border-top: 1px solid var(--border); }
    .cmp-row:hover { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent); }
    .cmp-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .cmp-err { font-size: 0.75rem; color: var(--ds-crimson); margin-top: 3px; }
    .cmp-link { color: var(--brand-icon, #0ea5e9); }
    .cmp-link:hover { text-decoration: underline; }
</style>

@include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\CampaignsTabs::class, 'hubFlush' => true])

@if($errors->any())
    <div class="rounded-md px-4 py-2 mt-2 text-sm flex-shrink-0"
         style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif
