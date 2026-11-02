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
    .rts-belt-row { display: grid; grid-template-columns: repeat({{ max(1, count($belt)) }}, minmax(0, 1fr)); gap: 6px; }
    .rts-tile { display: flex; flex-direction: column; justify-content: center; gap: 1px; min-width: 0; padding: 5px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary); text-decoration: none; }
    .rts-tile:hover { border-color: var(--brand-icon); }
    .rts-tile.is-lit { border-color: var(--brand-icon); background: color-mix(in srgb, var(--brand-icon) 10%, var(--surface)); }
    .rts-tile .nm { font-size: .625rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rts-tile .nm .full { display: none; }
    @media (min-width: 1700px) { .rts-tile .nm .full { display: inline; } .rts-tile .nm .short { display: none; } }
    .rts-tile .fg { font-size: 1.05rem; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rts-tile .fg .bad { font-size: .6875rem; font-weight: 700; margin-left: 6px; color: var(--ds-red, #f87171); }
    .rts-tile .sub { font-size: .625rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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

        @if(count($belt) > 0)
            <div class="rts-belt-row" data-qa="belt-tiles" data-tour="smk-rts-belt" role="list">
                @foreach($belt as $i => $t)
                    <div class="rts-tilewrap" x-data="beltCompare('{{ route('road-to-sold.belt.scopes', ['station' => $t['key']] + $postBase + \App\Services\RoadToSold\Track::params($track)) }}')" @keydown.escape.window="open = false" @click.outside="open = false" style="position: relative; min-width: 0;" role="listitem">
                        <a href="{{ route('road-to-sold.index', $t['url']) }}" data-qa="belt-tile-{{ $t['key'] }}" class="rts-tile {{ $lit($t) ? 'is-lit' : '' }}" style="height: 100%;"
                           title="{{ $t['label'] }}: {{ $t['target'] !== null ? ($t['done'] ?? $t['count']) . ' of ' . $t['target'] . ' ' . ($t['period'] ?? '') : $t['count'] . ' now' }}{{ $t['alert'] > 0 ? '. ' . $t['alert'] . ' need attention' : '' }}{{ $t['note'] ? '. ' . $t['note'] : '' }}">
                            <span class="nm"><span class="short">{{ $t['short'] }}</span><span class="full">{{ $t['label'] }}</span></span>
                            <span class="fg" data-qa="belt-figure">{{ $t['figure'] }}@if($t['alert'] > 0)<span class="bad" data-qa="belt-alert">!{{ $t['alert'] }}</span>@endif</span>
                            @if($t['note'])<span class="sub">{{ $t['note'] }}</span>@endif
                        </a>
                        <button type="button" class="rts-cmp" data-qa="belt-compare-{{ $t['key'] }}" aria-label="Compare {{ $t['label'] }}: me, my branch, the agency" :aria-expanded="open ? 'true' : 'false'" @click.prevent="toggle()">&#9662;</button>
                        <div x-show="open" x-cloak class="rts-pop rts-cmp-pop" data-qa="belt-compare-pop-{{ $t['key'] }}" style="{{ $i >= count($belt) / 2 ? 'right: 0; left: auto;' : 'left: 0; right: auto;' }} top: 100%;">
                            <div class="text-xs font-semibold mb-1" style="color: var(--text-primary);">{{ $t['label'] }} - compare</div>
                            <template x-if="loading"><div class="text-xs" style="color: var(--text-muted);">Loading...</div></template>
                            <template x-if="failed"><div class="text-xs" style="color: var(--ds-red, #f87171);">Could not load the comparison. Try again.</div></template>
                            <template x-for="(s, idx) in scopes" :key="s.scope">
                                <div class="rts-cmp-row" :style="idx > 0 ? 'border-top: 1px solid var(--border);' : ''" data-qa="compare-row">
                                    <div class="text-xs" style="font-weight: 700; color: var(--text-primary);"><span x-text="s.label"></span><span x-show="s.scope !== 'me'" style="font-weight: 400; color: var(--text-muted);" x-text="' · ' + s.agents + (s.agents === 1 ? ' agent' : ' agents')"></span></div>
                                    <div class="text-xs" style="color: var(--text-secondary);">
                                        <span x-text="s.now + ' now'"></span>
                                        <span x-show="s.reached !== null" x-text="' · ' + s.reached + ' reached this month'"></span>
                                        <span x-show="s.target !== null" x-text="' · ' + s.done + ' / ' + s.target + ' ' + (s.period || '')"></span>
                                    </div>
                                </div>
                            </template>
                            <div class="rts-src" x-show="sources.length" data-qa="compare-sources">
                                <div class="text-xs" style="font-weight: 700; color: var(--text-primary);">Where today's stock came from</div>
                                <template x-for="r in sources" :key="r.kind"><div class="rts-src-row"><span x-text="r.label"></span><strong x-text="r.count"></strong></div></template>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($canAct || count($roster ?? []) > 0 || count($activationHistory ?? []) > 0)
            <div class="rts-belt-line" style="position: relative;" x-data="{ pause: false, roster: false, hist: false }" @keydown.escape.window="pause = false; roster = false; hist = false" @click.outside="pause = false; roster = false; hist = false">
                @if($canAct && $aState !== 'paused')
                    <button type="button" class="underline" style="background: none; border: 0; padding: 0; cursor: pointer; color: var(--text-secondary);" @click="pause = !pause; roster = false" data-qa="belt-pause-open">{{ $viewingOwn ? 'Pause my Road to Sold' : 'Pause ' . $agentName }}</button>
                @endif
                @if(count($activationHistory ?? []) > 0)
                    <button type="button" class="underline" style="background: none; border: 0; padding: 0; cursor: pointer; color: var(--text-secondary);" @click="hist = !hist; pause = false; roster = false" data-qa="belt-history-open">Start / pause history</button>
                    <div x-show="hist" x-cloak class="rts-pop" data-qa="belt-history" style="left: 0; right: auto; width: 24rem; max-height: 16rem; overflow-y: auto;">
                        <div class="text-xs font-semibold mb-1" style="color: var(--text-primary);">{{ $viewingOwn ? 'My' : $agentName . '\'s' }} Start / pause history</div>
                        @foreach($activationHistory as $h)
                            <div class="py-1 text-xs" style="border-top: 1px solid var(--border);" data-qa="history-row">
                                <div style="font-weight: 700; color: var(--text-primary);">{{ $h['detail'] }}</div>
                                <div style="color: var(--text-muted);">{{ $h['when'] }}@if($h['by']) · by {{ $h['by'] }}@endif</div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if(count($roster ?? []) > 0)
                    @php $ns = collect($roster)->where('pressed', false)->count(); $ps = collect($roster)->where('state', 'paused')->count(); @endphp
                    <button type="button" class="underline" style="background: none; border: 0; padding: 0; cursor: pointer; color: var(--text-secondary);" @click="roster = !roster; pause = false; hist = false" data-qa="belt-roster-open">
                        Agents · {{ $ns }} not started · {{ $ps }} paused
                    </button>
                @endif

                <div x-show="pause" x-cloak class="rts-pop" data-qa="belt-pause-form" style="left: 0; right: auto;">
                    <form method="POST" action="{{ route('road-to-sold.activation.pause', $postBase + \App\Services\RoadToSold\Track::params($track)) }}" class="flex flex-col gap-2">
                        @csrf
                        @unless($viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endunless
                        <div class="text-xs font-semibold" style="color: var(--text-primary);">Pause {{ $viewingOwn ? 'my' : $agentName . '\'s' }} Road to Sold</div>
                        <label class="text-xs" style="color: var(--text-muted);" for="rts-pause-until">Paused until (the next working day after it is the first day back)</label>
                        <x-dmy-date id="rts-pause-until" name="until" :required="true" :min="now()->toDateString()" />
                        <label class="text-xs" style="color: var(--text-muted);" for="rts-pause-reason">Why (training, leave, other)</label>
                        <input id="rts-pause-reason" type="text" name="reason" maxlength="120" class="rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <div class="flex justify-end gap-2"><button type="button" class="corex-btn-outline corex-btn-xs" @click="pause = false">Cancel</button><button type="submit" class="corex-btn-primary corex-btn-xs">Pause</button></div>
                    </form>
                </div>

                @if(count($roster ?? []) > 0)
                    <div x-show="roster" x-cloak class="rts-pop rts-agents" data-qa="belt-roster" x-data="{ pausing: null }">
                        <div class="text-xs font-semibold" style="color: var(--text-primary);">Agents</div>
                        @if($startRequired ?? false)
                            <div class="text-xs mb-2" style="color: var(--text-muted);" data-qa="agents-start-line">Agents press Start my Road to Sold before their lists load - or start them from this list.</div>
                        @endif
                        @foreach($roster as $r)
                            @php $paused = $r['state'] === 'paused'; @endphp
                            <div class="py-1 text-xs" style="border-top: 1px solid var(--border);" data-qa="roster-row" data-agent="{{ $r['id'] }}">
                                <div class="flex items-center justify-between gap-2">
                                    <span style="min-width: 0;">
                                        <strong>{{ $r['name'] }}</strong>
                                        <span style="color: var(--text-muted);" data-qa="roster-state">
                                            @if($paused) paused until {{ $r['until'] }}@if($r['reason']) - {{ $r['reason'] }}@endif @if($r['by']) (set by {{ $r['by'] }})@endif
                                            @elseif($r['state'] === 'not_started') has not started
                                            @elseif(! $r['pressed']) working - has not pressed Start
                                            @else started {{ $r['started'] }}
                                            @endif
                                        </span>
                                    </span>
                                    @if($canPauseOthers ?? false)
                                        <span class="flex items-center gap-1" style="flex-shrink: 0;">
                                            @if(! $r['pressed'])
                                                <form method="POST" action="{{ route('road-to-sold.activation.start', \App\Services\RoadToSold\Track::params($track)) }}">@csrf<input type="hidden" name="agent" value="{{ $r['id'] }}"><button type="submit" class="corex-btn-outline corex-btn-xs" data-qa="roster-start">Start</button></form>
                                            @endif
                                            @if($paused)
                                                <form method="POST" action="{{ route('road-to-sold.activation.resume', \App\Services\RoadToSold\Track::params($track)) }}">@csrf<input type="hidden" name="agent" value="{{ $r['id'] }}"><button type="submit" class="corex-btn-outline corex-btn-xs" data-qa="roster-resume">Resume</button></form>
                                            @elseif($r['state'] === 'active')
                                                <button type="button" class="corex-btn-outline corex-btn-xs" @click="pausing = pausing === {{ $r['id'] }} ? null : {{ $r['id'] }}" data-qa="roster-pause">Pause</button>
                                            @endif
                                        </span>
                                    @endif
                                </div>
                                @if(($canPauseOthers ?? false) && $r['state'] === 'active')
                                    <form x-show="pausing === {{ $r['id'] }}" x-cloak method="POST" action="{{ route('road-to-sold.activation.pause', \App\Services\RoadToSold\Track::params($track)) }}" class="rts-agents-pause flex items-center gap-2 mt-1" data-qa="roster-pause-form">
                                        @csrf<input type="hidden" name="agent" value="{{ $r['id'] }}">
                                        <label class="text-xs" style="color: var(--text-muted);">Until</label>
                                        <x-dmy-date name="until" :required="true" :min="now()->toDateString()" />
                                        <input type="text" name="reason" maxlength="120" placeholder="Why (training, leave, other)" class="rounded-md px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); min-width: 0; flex: 1 1 8rem;">
                                        <button type="submit" class="corex-btn-primary corex-btn-xs">Pause</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
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
