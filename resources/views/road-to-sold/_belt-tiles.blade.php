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
        || ($block === 'station' && $curStation === $t['key'])
        || ($block === 'prospects' && $t['stage'] !== null && $curStage === $t['stage']);
    $postBase = $viewingOwn ? [] : ['agent' => $agentId];
@endphp
<style>
    .rts-belt-row { display: grid; grid-template-columns: repeat({{ max(1, count($belt)) }}, minmax(0, 1fr)); gap: 6px; }
    .rts-tile { display: flex; flex-direction: column; justify-content: center; gap: 1px; min-width: 0; padding: 5px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary); text-decoration: none; }
    .rts-tile:hover { border-color: var(--brand-icon); }
    .rts-tile.is-lit { border-color: var(--brand-icon); background: color-mix(in srgb, var(--brand-icon) 10%, var(--surface)); }
    .rts-tile .nm { font-size: .625rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rts-tile .nm .full { display: none; }
    @media (min-width: 1700px) { .rts-tile .nm .full { display: inline; } .rts-tile .nm .short { display: none; } }
    .rts-tile .fg { font-size: 1.05rem; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rts-tile .fg .bad { font-size: .6875rem; font-weight: 700; margin-left: 6px; color: var(--ds-red, #f87171); }
    .rts-tile .sub { font-size: .625rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rts-belt-line { display: flex; align-items: center; gap: 12px; min-height: 22px; font-size: .75rem; color: var(--text-secondary); }
    .rts-pop { position: absolute; z-index: 40; top: 26px; right: 0; width: 20rem; max-width: 92vw; padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); box-shadow: 0 6px 18px rgba(0,0,0,.18); }
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
            <div class="rts-belt-row" data-qa="belt-tiles" role="list">
                @foreach($belt as $t)
                    <a href="{{ route('road-to-sold.index', $t['url']) }}" role="listitem" data-qa="belt-tile-{{ $t['key'] }}" class="rts-tile {{ $lit($t) ? 'is-lit' : '' }}"
                       title="{{ $t['label'] }}: {{ $t['target'] !== null ? $t['count'] . ' of ' . $t['target'] . ' today' : $t['count'] . ' now' }}{{ $t['alert'] > 0 ? '. ' . $t['alert'] . ' need attention' : '' }}{{ $t['note'] ? '. ' . $t['note'] : '' }}">
                        <span class="nm"><span class="short">{{ $t['short'] }}</span><span class="full">{{ $t['label'] }}</span></span>
                        <span class="fg" data-qa="belt-figure">{{ $t['figure'] }}@if($t['alert'] > 0)<span class="bad" data-qa="belt-alert">!{{ $t['alert'] }}</span>@endif</span>
                        @if($t['note'])<span class="sub">{{ $t['note'] }}</span>@endif
                    </a>
                @endforeach
            </div>
        @endif

        @if($canAct || count($roster ?? []) > 0)
            <div class="rts-belt-line" style="position: relative;" x-data="{ pause: false, roster: false }" @keydown.escape.window="pause = false; roster = false" @click.outside="pause = false; roster = false">
                @if($canAct && $aState !== 'paused')
                    <button type="button" class="underline" style="background: none; border: 0; padding: 0; cursor: pointer; color: var(--text-secondary);" @click="pause = !pause; roster = false" data-qa="belt-pause-open">{{ $viewingOwn ? 'Pause my Road to Sold' : 'Pause ' . $agentName }}</button>
                @endif
                @if(count($roster ?? []) > 0)
                    @php $ns = collect($roster)->where('state', 'not_started')->count(); $ps = collect($roster)->where('state', 'paused')->count(); @endphp
                    <button type="button" class="underline" style="background: none; border: 0; padding: 0; cursor: pointer; color: var(--text-secondary);" @click="roster = !roster; pause = false" data-qa="belt-roster-open">
                        Not started {{ $ns }} · Paused {{ $ps }}
                    </button>
                @endif

                <div x-show="pause" x-cloak class="rts-pop" data-qa="belt-pause-form" style="left: 0; right: auto;">
                    <form method="POST" action="{{ route('road-to-sold.activation.pause', $postBase + \App\Services\RoadToSold\Track::params($track)) }}" class="flex flex-col gap-2">
                        @csrf
                        @unless($viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endunless
                        <div class="text-xs font-semibold" style="color: var(--text-primary);">Pause {{ $viewingOwn ? 'my' : $agentName . '\'s' }} Road to Sold</div>
                        <label class="text-xs" style="color: var(--text-muted);" for="rts-pause-until">Paused until (the next working day after it is the first day back)</label>
                        <input id="rts-pause-until" type="date" name="until" required min="{{ now()->toDateString() }}" class="rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <label class="text-xs" style="color: var(--text-muted);" for="rts-pause-reason">Why (training, leave, other)</label>
                        <input id="rts-pause-reason" type="text" name="reason" maxlength="120" class="rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <div class="flex justify-end gap-2"><button type="button" class="corex-btn-outline corex-btn-xs" @click="pause = false">Cancel</button><button type="submit" class="corex-btn-primary corex-btn-xs">Pause</button></div>
                    </form>
                </div>

                @if(count($roster ?? []) > 0)
                    <div x-show="roster" x-cloak class="rts-pop" data-qa="belt-roster" style="left: 0; right: auto; width: 26rem; max-height: 18rem; overflow-y: auto;">
                        <div class="text-xs font-semibold mb-2" style="color: var(--text-primary);">Not started / Paused</div>
                        @foreach($roster as $r)
                            <div class="flex items-center justify-between gap-2 py-1 text-xs" style="border-top: 1px solid var(--border);" data-qa="roster-row">
                                <span style="min-width: 0;">
                                    <strong>{{ $r['name'] }}</strong>
                                    <span style="color: var(--text-muted);">
                                        @if($r['state'] === 'paused') paused until {{ $r['until'] }}@if($r['reason']) - {{ $r['reason'] }}@endif @if($r['by']) (set by {{ $r['by'] }})@endif
                                        @else has not started @endif
                                    </span>
                                </span>
                                @if($canPauseOthers ?? false)
                                    <form method="POST" action="{{ route($r['state'] === 'paused' ? 'road-to-sold.activation.resume' : 'road-to-sold.activation.start', \App\Services\RoadToSold\Track::params($track)) }}">
                                        @csrf<input type="hidden" name="agent" value="{{ $r['id'] }}">
                                        <button type="submit" class="corex-btn-outline corex-btn-xs">{{ $r['state'] === 'paused' ? 'Resume' : 'Start for them' }}</button>
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
