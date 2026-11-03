{{--
    Road to Sold v4 - the belt (.ai/specs/road-to-sold.md 23.13). One tile per built station in belt order, "done today / target" or the count at the
    station, a red figure for what needs attention; click = the list at that station. Above it, until the agent has pressed Start, the Start card.
    A thin line under the tiles holds Pause / Resume and, for managers, the "Not started / Paused" panel.

    Inputs: $belt (BeltService::tiles), $activation (ActivationService::state), $startRequired, $roster, $beltBase, $canPauseOthers, $viewingOwn, $agentId, $agents, $track, $block.
--}}
@php
    $aState = $activation['state'] ?? 'active';
    $agentName = $agents->firstWhere('id', $agentId)?->name ?? 'This agent';
    $canAct = ($activationReady ?? false) && ($viewingOwn || ($canPauseOthers ?? false));
    $curStage = (string) request('p_stage', '');
    $curStation = (string) request('station', '');
    $lit = fn (array $t) => ($t['key'] === 'loaded' && $block === 'intake')
        || ($block === 'station' && $curStation === $t['key']);
    $postBase = $viewingOwn ? [] : ['agent' => $agentId];
@endphp
<style>
    .rts-belt-row { display: grid; grid-template-columns: repeat({{ max(10, count($belt)) }}, minmax(0, 1fr)); gap: 3px; }
    .rts-tile { display: flex; flex-direction: column; justify-content: flex-start; gap: 0; min-width: 0; height: 100%; padding: 4px 4px 4px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary); }
    .rts-tile:hover { border-color: var(--brand-icon); }
    /* page layout v2 (23.31): four lines on every tile, each line its own link with room to click; the three belts' headings */
    .rts-tile.m4 a.ln { padding: 2px 3px; margin: 0 -3px; border-radius: 3px; }
    .rts-tile.m4 a.ln:hover { background: var(--surface-2, rgba(128,128,128,.15)); text-decoration: none; color: var(--text-primary); }
    .rts-belts { display: flex; flex-direction: column; gap: 2px; }
    .rts-bhead { display: flex; align-items: center; gap: 10px; min-height: 22px; min-width: 0; font-size: .75rem; color: var(--text-secondary); }
    .rts-bhead .rts-bname { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; font-size: .8125rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--text-primary); text-decoration: none; white-space: nowrap; }
    .rts-bhead .rts-bname .car { color: var(--text-muted); font-size: .75rem; }
    .rts-bhead .rts-bsub { flex-shrink: 0; color: var(--text-muted); white-space: nowrap; }
    .rts-bhead .rts-bcount { margin-left: auto; flex-shrink: 0; white-space: nowrap; color: var(--text-secondary); }
    .rts-bhead .rts-bcount strong { color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .rts-bhead .rts-bcount strong.bad { color: var(--ds-red, #f87171); }
    .rts-bhead.is-shut { height: 24px; padding: 0 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); text-decoration: none; }
    .rts-bhead.is-shut:hover { border-color: var(--brand-icon); }
    .rts-belt-tools { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1 1 0; justify-content: flex-end; }
    .rts-belt-tools .rts-perlist { flex: 1 1 0; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: right; color: var(--text-secondary); }
    .rts-belt-tools > button { flex-shrink: 0; white-space: nowrap; }
    .rts-tile.is-lit { border-color: var(--brand-icon); background: color-mix(in srgb, var(--brand-icon) 10%, var(--surface)); }
    .rts-tile .nm { font-size: .625rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-decoration: none; line-height: 1.2; }
    .rts-tile .nm .full { display: none; }
    @media (min-width: 1700px) { .rts-tile .nm .full { display: inline; } .rts-tile .nm .short { display: none; } }
    /* the conveyor (23.24): three lines on every tile, the same size and place on all of them - 1 big, 2 and 3 small; line 3 keeps its place when there is nothing late */
    .rts-tile .ln { display: block; font-size: .625rem; line-height: 1.25; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-secondary); text-decoration: none; }
    .rts-tile a.ln:hover { text-decoration: underline; color: var(--text-primary); }
    .rts-tile .ln.l1 { font-size: .625rem; color: var(--text-muted); line-height: 1.2; }
    .rts-tile .ln.l1 .n { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); }
    .rts-tile .ln.bad { color: var(--ds-red, #f87171); font-weight: 700; }
    .rts-tile .ln.ghost { visibility: hidden; }
    .rts-tile .ln.tgt { color: var(--text-muted); font-size: .5625rem; }
    .rts-tilewrap .rts-cmp { position: absolute; bottom: 5px; right: 4px; width: 16px; height: 16px; padding: 0; line-height: 14px; font-size: .625rem; text-align: center; cursor: pointer; color: var(--text-muted); background: none; border: 0; border-radius: 3px; }
    .rts-tilewrap .rts-cmp:hover { color: var(--text-primary); background: var(--surface-2, rgba(128,128,128,.15)); }
    .rts-cmp-pop { width: 17rem; }
    .rts-src { margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--border); }
    .rts-src-row { display: flex; justify-content: space-between; gap: 8px; font-size: .75rem; padding: 1px 0; color: var(--text-secondary); }
    .rts-src-row strong { color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .rts-cmp-row { padding: 6px 0; }
    .rts-belt-line { display: flex; align-items: center; gap: 12px; min-height: 22px; font-size: .75rem; color: var(--text-secondary); }
    .rts-pop { position: absolute; z-index: 40; top: 26px; right: 0; width: 20rem; max-width: 92vw; padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); box-shadow: 0 6px 18px rgba(0,0,0,.18); }
    .rts-agents { left: 0; right: auto; width: 34rem; max-height: 22rem; overflow-y: auto; }
</style>

<div data-qa="belt" class="rts-belt" style="display: flex; flex-direction: column; gap: 4px;">
    @if($aState === 'not_started')
        <div data-qa="belt-start-card" class="rounded-md px-4 py-3 flex items-center justify-between gap-4 flex-wrap" style="background: color-mix(in srgb, var(--brand-icon) 8%, var(--surface)); border: 1px solid var(--border);">
            <div style="min-width: 0;">
                @if($viewingOwn)
                    <div class="text-sm font-bold" style="color: var(--text-primary);">Start my Road to Sold</div>
                    <div class="text-xs" style="color: var(--text-secondary);">Press Start and your first list of stock arrives on the next working day.</div>
                @else
                    <div class="text-sm font-bold" style="color: var(--text-primary);">{{ $agentName }} has not started Road to Sold</div>
                    <div class="text-xs" style="color: var(--text-secondary);">Nothing loads for them until they press Start{{ $canAct ? ' (or you start it for them)' : '' }}.</div>
                @endif
            </div>
            @if($canAct)
                <form method="POST" action="{{ route('road-to-sold.activation.start', $postBase + \App\Services\RoadToSold\Track::params($track)) }}">
                    @csrf
                    @unless($viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endunless
                    <button type="submit" class="corex-btn-primary" data-qa="belt-start">{{ $viewingOwn ? 'Start my Road to Sold' : 'Start for ' . $agentName }}</button>
                </form>
            @endif
        </div>
    @else
        @php $firstList = $activation['first_list_date'] ?? null; @endphp
        @if($aState === 'active' && $firstList && $firstList->copy()->startOfDay()->gt(\Illuminate\Support\Carbon::today()))
            {{-- a standing line, not a one-time banner: it stays until the day the first list loads --}}
            <div data-qa="belt-first-list" class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--brand-icon) 8%, var(--surface)); border: 1px solid var(--border); color: var(--text-primary);">
                <strong>{{ $viewingOwn ? 'Your' : $agentName . '\'s' }} first list arrives on {{ $firstList->format('l j M') }}.</strong> Nothing loads before then; the tiles fill as it does.
            </div>
        @endif
        @if($aState === 'paused')
            <div data-qa="belt-paused" class="rounded-md px-3 py-2 text-xs flex items-center justify-between gap-3 flex-wrap" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, var(--surface)); border: 1px solid var(--ds-amber, #f59e0b); color: var(--text-primary);">
                <span><strong>{{ $viewingOwn ? 'Your Road to Sold is paused' : $agentName . ' is paused' }}</strong> until {{ $activation['paused_until']?->format('D j M Y') }}@if($activation['pause_reason']) ({{ $activation['pause_reason'] }})@endif. No lists load and these days do not count against targets.</span>
                @if($canAct)
                    <form method="POST" action="{{ route('road-to-sold.activation.resume', $postBase + \App\Services\RoadToSold\Track::params($track)) }}">
                        @csrf
                        @unless($viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endunless
                        <button type="submit" class="corex-btn-outline corex-btn-xs" data-qa="belt-resume">{{ $viewingOwn ? 'Resume now' : 'Resume for ' . $agentName }}</button>
                    </form>
                @endif
            </div>
        @endif

        @include('road-to-sold._belt-strips')
    @endif
</div>

<script>
// the compare popover behind a belt tile: me / my branch / the agency (spec 23.12), loaded when it is first opened
function beltCompare(url) {
    return {
        open: false, loading: false, failed: false, scopes: [], sources: [], loaded: false,
        toggle() {
            this.open = !this.open;
            if (!this.open || this.loaded) { return; }
            this.loading = true; this.failed = false;
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => { if (!r.ok) { throw new Error('bad'); } return r.json(); })
                .then((d) => { this.scopes = d.scopes || []; this.sources = d.sources || []; this.loaded = true; })
                .catch(() => { this.failed = true; })
                .finally(() => { this.loading = false; });
        },
    };
}
</script>
