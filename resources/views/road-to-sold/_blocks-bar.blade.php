{{-- Road to Sold v3 (spec 22.2): the three blocks - Today's intake, My prospects, Parked - plus My progress, and the pop-up that every step opens in
     (22.9). The page does not scroll: the header and this bar stay, the open block fills the rest and scrolls inside itself. --}}
@php
    $tabParams = fn (string $b) => array_filter(['block' => $b === 'intake' ? null : $b, 'agent' => $viewingOwn ? null : $agentId] + \App\Services\RoadToSold\Track::params($track));
    $agentName = $agents->firstWhere('id', $agentId)?->name;
    $newOpen = max(0, (int) ($counts['target_items'] ?? 0) - (int) ($counts['worked'] ?? 0));
    $followOpen = max(0, (int) ($counts['followups'] ?? 0) - (int) ($counts['followups_worked'] ?? 0));
    $tabs = [
        'intake'    => ["Today's intake", $newOpen . ' new' . ($followOpen > 0 ? ' · ' . $followOpen . ' follow-up' . ($followOpen === 1 ? '' : 's') : '')],
        'prospects' => ['My prospects', (string) $blockCounts['prospects']],
        'parked'    => ['Parked', (string) $blockCounts['parked']],
        'progress'  => ['My progress', null],
    ];
    if (! $viewingOwn) { $tabs['prospects'][0] = 'Prospects'; $tabs['parked'][0] = 'Parked'; $tabs['progress'][0] = 'Progress'; }
@endphp
<style>
    .rts-fit { display: flex; flex-direction: column; gap: .75rem; height: 100%; min-height: 0; }
    .rts-block-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: .5rem; }
    .rts-block-body.is-fit { overflow: hidden; }
    /* only the block body gives way when the window is short: the header rows keep their height (they used to shrink and slide under the sticky banner) */
    .rts-fit > * { flex-shrink: 0; }
    /* the page does not scroll any more, so the banner needs no stickiness - and its pulled-up margin made it overlap the row below */
    .rts-fit > .rts-sticky { position: static !important; margin-top: 0 !important; }
    .rts-fit > .rts-block-body { flex: 1 1 0; min-height: 0; }
    /* a flex child with overflow set shrinks to NOTHING when its siblings need the room - that hid the intake table (27 Oct); nothing shrinks except the one list that is meant to */
    .rts-block-body > *, .rts-col > * { flex-shrink: 0; }
    .rts-block-body > .rts-grow, .rts-col > .rts-grow { flex: 1 1 0; min-height: 6rem; overflow: auto; }
    .rts-col { display: flex; flex-direction: column; gap: .5rem; flex: 1 1 0; min-height: 0; }
    .rts-clamp2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.25; }
    .rts-nw { white-space: nowrap; max-width: 15rem; overflow: hidden; text-overflow: ellipsis; }
    .rts-top .rts-long { display: none; }
    .rts-top .grid { grid-template-columns: repeat(auto-fit, minmax(6.2rem, 1fr)) !important; }
    .rts-block-body form select, .rts-block-body form input[type=text], .rts-block-body form input[type=date] { padding-top: .3rem !important; padding-bottom: .3rem !important; }
    .rts-block-body > form { gap: .5rem !important; }
    .rts-grow thead th { position: sticky; top: 0; z-index: 1; background: var(--surface); }
    .rts-grow table td { padding-top: .35rem; padding-bottom: .35rem; }
    .rts-grow table th, .rts-grow table td { padding-left: .5rem; padding-right: .5rem; }
    /* one line per cell (the reason may take two): a row is ~44px, so five rows fit */
    .rts-grow th { white-space: nowrap; }
    .rts-grow td:last-child { max-width: none !important; overflow: visible !important; }
    .rts-grow td:not([data-qa="intake-why"]) { white-space: nowrap; max-width: 12rem; overflow: hidden; text-overflow: ellipsis; }
    /* what to do next + where the month stands share one row */
    .rts-top { display: flex; gap: .75rem; align-items: stretch; }
    .rts-top > :first-child { flex: 0 0 22rem; min-width: 0; }
    .rts-top > :last-child { flex: 1 1 0; min-width: 0; }
    .rts-top > :only-child { flex: 1 1 0; }
    .rts-top .grid { gap: .5rem !important; grid-auto-rows: auto !important; }
    .rts-top .grid > button { padding: .25rem .5rem !important; }
    .rts-top .grid .text-lg { font-size: 1rem; line-height: 1.25rem; }
    .rts-tabs a { padding: .45rem .9rem; border-radius: 6px; font-size: .8125rem; font-weight: 600; border: 1px solid var(--border); background: var(--surface); color: var(--text-primary); white-space: nowrap; }
    .rts-tabs a.is-on { background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); color: #fff; }
    .rts-tabs a small { font-weight: 500; margin-left: .35rem; opacity: .85; }
    .rts-chip { display: inline-block; font-size: .6875rem; font-weight: 700; padding: .05rem .4rem; border-radius: 999px; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 18%, transparent); color: var(--text-primary); }
    .rts-due-overdue { color: var(--ds-crimson, #dc2626); font-weight: 700; }
    .rts-due-today { color: var(--ds-amber, #d97706); font-weight: 700; }
    .rts-card { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: .6rem .75rem; }
    .rts-card.is-overdue { border-left: 4px solid var(--ds-crimson, #dc2626); }
    .rts-board { display: grid; gap: .75rem; align-items: start; }
    .rts-cards { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); }
    .rts-card { padding: .4rem .6rem; }
    @media (max-width: 900px) { .rts-board { grid-template-columns: minmax(0, 1fr) !important; } }

    /* THE PRODUCTION LINE (spec 22.24): 18 stations on one grid, the line pinned at the top of the one scrolling region, chips under their station */
    .rts-linebox { padding: 0; }
    .rts-grid18 { display: grid; grid-template-columns: repeat(18, minmax(0, 1fr)); column-gap: .25rem; }
    .rts-line { position: sticky; top: 0; z-index: 3; background: var(--surface); padding: .2rem 0 .3rem; border-bottom: 1px solid var(--border); }
    .rts-phase { display: flex; align-items: center; justify-content: space-between; gap: .25rem; min-width: 0; padding: 0 .2rem .1rem; border-bottom: 2px solid var(--brand-icon, #0ea5e9); font-size: .625rem; font-weight: 800; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; overflow: hidden; }
    .rts-phase-name { overflow: hidden; text-overflow: ellipsis; }
    .rts-flow { display: inline-flex; gap: .3rem; font-size: .6875rem; font-weight: 700; text-transform: none; letter-spacing: 0; flex-shrink: 0; }
    .rts-flow a, .rts-flow span { color: var(--text-primary); text-decoration: none; padding: 0 .2rem; border-radius: 4px; }
    .rts-flow span { color: var(--text-muted); font-weight: 600; }
    .rts-flow a.rts-out { color: var(--ds-crimson, #dc2626); }
    .rts-flow a:hover, .rts-flow a.is-on { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 22%, transparent); }
    .rts-stations { margin-top: .25rem; }
    .rts-st { display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: .1rem; min-width: 0; padding: .15rem .1rem .2rem; border: 1px solid var(--border); border-top-width: 4px; border-radius: 6px; background: var(--surface); color: var(--text-primary); text-decoration: none; }
    .rts-st:hover { border-color: var(--brand-icon, #0ea5e9); }
    .rts-st.is-on { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 20%, var(--surface)); border-color: var(--brand-icon, #0ea5e9); box-shadow: 0 0 0 1px var(--brand-icon, #0ea5e9); }
    .rts-st.is-empty .n { color: var(--text-muted); font-weight: 600; }
    .rts-st .nm { font-size: .5625rem; font-weight: 600; line-height: 1.1; text-align: center; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rts-st .rts-st-full { display: none; white-space: normal; display: none; }
    .rts-st .n { position: relative; font-size: 1rem; font-weight: 800; line-height: 1.1; white-space: nowrap; }
    .rts-st .bad { margin-left: .2rem; padding: 0 .25rem; border-radius: 999px; font-size: .625rem; font-weight: 800; color: #fff; background: var(--ds-crimson, #dc2626); vertical-align: middle; }
    @media (min-width: 1700px) {
        .rts-st .nm { font-size: .625rem; }
        .rts-st .rts-st-short { display: none; }
        .rts-st .rts-st-full { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .rts-st .nm { white-space: normal; min-height: 2.2em; }
    }
    .rts-wide { display: grid; gap: .35rem; padding-top: .35rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr)); }
    .rts-gh { grid-column: 1 / -1; display: flex; align-items: baseline; gap: .6rem; margin-top: .25rem; padding: .1rem .25rem; border-bottom: 1px solid var(--border); font-size: .75rem; font-weight: 800; color: var(--text-primary); }
    .rts-gh:first-child { margin-top: 0; }
    .rts-gh-late { font-size: .625rem; font-weight: 800; color: var(--ds-crimson, #dc2626); }
    .rts-ch { display: flex; flex-direction: column; gap: .05rem; width: 100%; min-width: 0; text-align: left; padding: .15rem .25rem .2rem; border: 1px solid var(--border); border-left-width: 3px; border-radius: 5px; background: var(--surface); color: var(--text-primary); font-size: .625rem; line-height: 1.15; cursor: pointer; }
    .rts-ch:hover, .rts-ch:focus-visible { border-color: var(--brand-icon, #0ea5e9); outline: none; }
    .rts-ch.is-overdue { border-left-color: var(--ds-crimson, #dc2626); }
    .rts-ch .a { font-weight: 700; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; }
    .rts-ch .x { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rts-ch .rts-sub { display: none; }
    .rts-wide .rts-ch { font-size: .75rem; padding: .3rem .5rem; }
    .rts-wide .rts-ch .rts-sub { display: inline; font-weight: 400; color: var(--text-muted); }
    .rts-wide .rts-ch .a { display: block; -webkit-line-clamp: unset; white-space: nowrap; text-overflow: ellipsis; }
    .rts-flowlist { padding-top: .5rem; }
    .rts-flowlist th, .rts-flowlist td { padding: .15rem .5rem .15rem 0; text-align: left; }
    /* the card's own line: a dot per station, ticked where the property has been, lit where it is */
    .rts-miniline { padding: .25rem 0; }
    .rts-mini { display: flex; flex-direction: column; align-items: center; gap: .15rem; min-width: 0; font-size: .625rem; line-height: 1.1; color: var(--text-muted); text-align: center; }
    .rts-mini .dot { width: .6rem; height: .6rem; border-radius: 999px; border: 2px solid var(--border); background: var(--surface); }
    .rts-mini .nm { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rts-mini.is-done .dot { background: var(--ds-green, #059669); border-color: var(--ds-green, #059669); }
    .rts-mini.is-done { color: var(--text-primary); }
    .rts-mini.is-here .dot { width: .9rem; height: .9rem; background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent); }
    .rts-mini.is-here { color: var(--text-primary); font-weight: 800; }
    @media (max-width: 900px) { .rts-inner { min-width: 52rem; } }
</style>

<div class="flex items-center gap-2 flex-wrap rts-tabs" role="tablist" data-qa="rts-blocks">
    @foreach($tabs as $key => [$label, $badge])
        <a href="{{ route('road-to-sold.index', $tabParams($key)) }}" role="tab" aria-selected="{{ $block === $key ? 'true' : 'false' }}" class="{{ $block === $key ? 'is-on' : '' }}" data-block="{{ $key }}">{{ $label }}@if($badge !== null)<small>{{ $badge }}</small>@endif</a>
    @endforeach
    @if($block === 'intake' && $listView && $counts['any'] > 0 && $canAddToDay && $date->isToday() && count($addChoices))
        <form method="POST" action="{{ route('road-to-sold.add-to-day') }}" class="flex items-center gap-1 ml-4 text-xs" data-qa="add-to-day">
                @csrf
                @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
                <select name="selection" aria-label="Selection to add from" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
                    @foreach($addChoices as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </select>
                <input type="number" name="count" min="1" max="{{ $addMax ?? 100 }}" value="5" aria-label="How many to add" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 5rem;">
                <button type="submit" class="corex-btn-outline corex-btn-xs">Add to my day</button>
            </form>
    @endif
    @unless($viewingOwn)
        <span class="text-xs ml-2" style="color: var(--text-muted);" data-qa="read-only">Looking at {{ $agentName }} - read only</span>
    @endunless
    {{-- Report / Outcomes / Recipe / Settings sit on the tabs row (22.22): the page header stays on one line at 1366 wide. --}}
    <div class="flex items-center gap-2 ml-auto" data-qa="rts-tools">
            @if(\Illuminate\Support\Facades\Route::has('road-to-sold.report'))
                <a href="{{ route('road-to-sold.report', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Report</a>
            @endif
            @if($canManageOutcomes)
                <a href="{{ route('road-to-sold.settings.outcomes.index', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Outcomes</a>
            @endif
            @if($canManageRecipe && \Illuminate\Support\Facades\Route::has('road-to-sold.settings.recipe.index'))
                <a href="{{ route('road-to-sold.settings.recipe.index', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Recipe</a>
            @endif
            {{-- .ai/specs/real-estate-menu-tabs.md - the "Road to Sold settings" menu item moved here (it is also on the Settings page).
                 Same check the menu item had; sales track only, as the menu item only ever opened the sales settings. --}}
            @if($track === \App\Models\RoadToSold\RtsSetting::TRACK_SALES && \Illuminate\Support\Facades\Route::has('road-to-sold.settings.index')
                && auth()->user()?->hasAnyPermission(['road_to_sold.manage_settings', 'road_to_sold.manage_areas', 'road_to_sold.manage_targets']))
                <a href="{{ route('road-to-sold.settings.index') }}" class="corex-btn-outline corex-btn-xs">Settings</a>
            @endif
    </div>
</div>

{{-- The step pop-up: the real CoreX screen in an iframe (embed mode, same origin only). Done / X / Escape close it and reload the page so the card shows the result. --}}
<div x-data="rtsStepModal()" x-cloak @rts-step.window="open($event.detail)" @message.window="onMessage($event)" @click.window="link($event)" @keydown.escape.window="close()">
    <div x-show="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" role="dialog" aria-modal="true" :aria-label="label" data-qa="step-modal">
        <div class="flex flex-col w-full rounded-md overflow-hidden" style="max-width: 72rem; height: 90vh; background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 py-2" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-sm font-bold" style="color: var(--text-primary);" x-text="label"></h2>
                <button type="button" class="corex-btn-outline corex-btn-xs" x-ref="closeBtn" @click="close()" aria-label="Close and go back to Road to Sold">Close</button>
            </div>
            <iframe x-ref="frame" class="flex-1 w-full" style="border: 0; background: var(--bg);" title="Road to Sold step"></iframe>
        </div>
    </div>
</div>
<script>
function rtsStepModal() {
    return {
        isOpen: false, label: '', opener: null, touched: false,
        open(d) {
            if (!d || !d.stepUrl) { return; }
            this.label = d.stepLabel || 'Road to Sold step';
            this.opener = document.activeElement;
            this.isOpen = true; this.touched = false;
            this.$nextTick(() => { this.$refs.frame.src = d.stepUrl; this.$refs.closeBtn.focus(); });
        },
        // a link marked data-rts-popup opens its screen in the pop-up (embed mode) instead of leaving Road to Sold
        link(e) {
            var a = e.target && e.target.closest ? e.target.closest('a[data-rts-popup]') : null;
            if (!a || e.button || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
            var u = new URL(a.href, window.location.href);
            if (u.origin !== window.location.origin) { return; }
            e.preventDefault();
            u.searchParams.set('embed', '1');
            this.open({ stepUrl: u.toString(), stepLabel: a.dataset.stepLabel || (a.textContent || '').trim() });
        },
        onMessage(e) {
            if (e.origin !== window.location.origin || !e.data || e.data.rts !== 'done') { return; }
            this.close(true);
        },
        close(reload) {
            if (!this.isOpen) { return; }
            this.isOpen = false;
            this.$refs.frame.src = 'about:blank';
            if (this.opener && this.opener.focus) { this.opener.focus(); }
            // closing returns to Road to Sold with the card refreshed (the step may have moved the prospect)
            window.location.reload();
        },
    };
}
</script>
