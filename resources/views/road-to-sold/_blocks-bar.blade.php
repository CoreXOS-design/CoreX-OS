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
    .rts-block-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: .75rem; }
    .rts-block-body.is-fit { overflow: hidden; }
    /* only the block body gives way when the window is short: the header rows keep their height (they used to shrink and slide under the sticky banner) */
    .rts-fit > * { flex-shrink: 0; }
    .rts-fit > .rts-block-body { flex: 1 1 0; min-height: 0; }
    /* a flex child with overflow set shrinks to NOTHING when its siblings need the room - that hid the intake table (27 Oct); nothing shrinks except the one list that is meant to */
    .rts-block-body > *, .rts-col > * { flex-shrink: 0; }
    .rts-block-body > .rts-grow, .rts-col > .rts-grow { flex: 1 1 0; min-height: 6rem; overflow: auto; }
    .rts-col { display: flex; flex-direction: column; gap: .75rem; flex: 1 1 0; min-height: 0; }
    .rts-clamp2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.25; }
    .rts-nw { white-space: nowrap; max-width: 15rem; overflow: hidden; text-overflow: ellipsis; }
    .rts-top .rts-long { display: none; }
    .rts-top .grid { grid-template-columns: repeat(auto-fit, minmax(6.2rem, 1fr)) !important; }
    .rts-block-body form select, .rts-block-body form input[type=text], .rts-block-body form input[type=date] { padding-top: .3rem !important; padding-bottom: .3rem !important; }
    .rts-block-body > form { gap: .5rem !important; }
    .rts-grow thead th { position: sticky; top: 0; z-index: 1; background: var(--surface); }
    .rts-grow table td { padding-top: .35rem; padding-bottom: .35rem; }
    /* what to do next + where the month stands share one row */
    .rts-top { display: flex; gap: .75rem; align-items: stretch; }
    .rts-top > :first-child { flex: 0 0 22rem; min-width: 0; }
    .rts-top > :last-child { flex: 1 1 0; min-width: 0; }
    .rts-top > :only-child { flex: 1 1 0; }
    .rts-top .grid { gap: .5rem !important; grid-auto-rows: auto !important; }
    .rts-top .grid > button { padding: .35rem .6rem !important; }
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
</style>

<div class="flex items-center gap-2 flex-wrap rts-tabs" role="tablist" data-qa="rts-blocks">
    @foreach($tabs as $key => [$label, $badge])
        <a href="{{ route('road-to-sold.index', $tabParams($key)) }}" role="tab" aria-selected="{{ $block === $key ? 'true' : 'false' }}" class="{{ $block === $key ? 'is-on' : '' }}" data-block="{{ $key }}">{{ $label }}@if($badge !== null)<small>{{ $badge }}</small>@endif</a>
    @endforeach
    @if($block === 'intake' && $listView && $counts['any'] > 0 && $canAddToDay && $date->isToday() && count($addChoices))
        <form method="POST" action="{{ route('road-to-sold.add-to-day') }}" class="flex items-center gap-1 ml-auto text-xs" data-qa="add-to-day">
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
</div>

{{-- The step pop-up: the real CoreX screen in an iframe (embed mode, same origin only). Done / X / Escape close it and reload the page so the card shows the result. --}}
<div x-data="rtsStepModal()" x-cloak @rts-step.window="open($event.detail)" @message.window="onMessage($event)" @keydown.escape.window="close()">
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
