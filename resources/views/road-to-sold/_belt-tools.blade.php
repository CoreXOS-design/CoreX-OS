{{-- Page layout v2 (23.31): the open belt's heading line, right side - the standing line about the agent's Road to Sold (what a listing needs from MIC, Pause, Start / pause history, Agents).
     Inherits the variables of _belt-tiles ($canAct, $viewingOwn, $agentName, $aState, $activation..., $perListing). --}}
@if(! empty($perListing) || $canAct || count($roster ?? []) > 0 || count($activationHistory ?? []) > 0)
            <div class="rts-belt-tools" style="position: relative;" x-data="{ pause: false, roster: false, hist: false }" @keydown.escape.window="pause = false; roster = false; hist = false" @click.outside="pause = false; roster = false; hist = false">
                @if(! empty($perListing))
                    <span class="rts-perlist" title="{{ $perListing['text'] }}" data-qa="rts-per-listing">{{ $perListing['short'] ?? $perListing['text'] }}</span>
                @endif
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
