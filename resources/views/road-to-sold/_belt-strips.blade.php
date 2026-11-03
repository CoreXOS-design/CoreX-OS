{{--
    Page layout v2 (road-to-sold.md 23.31): the three belts - Get stock, Maintain stock, Sell stock - exactly ONE open; the other two are one-line strips
    ("MAINTAIN STOCK · 3 stations · N to do · N late") that open on a click (?belt=, remembered per user). The open belt's heading line carries, on the right, the standing
    line (what a listing needs from MIC, Pause, Agents) and its counts. Every tile has four lines - label, N to do, N moved on today, N late - each its own link to the
    station list with that filter. Maintain / Sell tiles come from config('road-to-sold-belt.belts'); only Get stock tiles have the compare caret.
    Inherits from _belt-tiles: $belts, $beltOpen, $lit, $postBase, $track, $block, $curStation.
--}}
@if(! empty($belts))
    @php $switchBase = collect(request()->query())->except(['page', 'belt'])->all(); @endphp
    <div class="rts-belts" data-qa="belts" data-open="{{ $beltOpen }}">
        @foreach($belts as $bt)
            @php
                $isGet = $bt['key'] === 'get';
                $counts = '<strong data-qa="belt-todo-' . $bt['key'] . '">' . $bt['todo'] . '</strong> to do · <strong class="' . ($bt['late'] > 0 ? 'bad' : '') . '" data-qa="belt-late-' . $bt['key'] . '">' . $bt['late'] . '</strong> late';
            @endphp
            @if(! $bt['open'])
                <a href="{{ route('road-to-sold.index', $switchBase + ['belt' => $bt['key']]) }}" class="rts-bhead is-shut" data-qa="belt-head-{{ $bt['key'] }}" aria-expanded="false" title="Open {{ $bt['label'] }}">
                    <span class="rts-bname"><span class="car">&#9656;</span>{{ $bt['label'] }}</span>
                    <span class="rts-bsub">{{ $bt['stations'] }} stations</span>
                    <span class="rts-bcount">{!! $counts !!}</span>
                </a>
            @else
                <div class="rts-bhead is-open" data-qa="belt-head-{{ $bt['key'] }}" aria-expanded="true">
                    <span class="rts-bname"><span class="car">&#9662;</span>{{ $bt['label'] }}</span>
                    <span class="rts-bsub">{{ $bt['stations'] }} stations</span>
                    @include('road-to-sold._belt-tools')
                    <span class="rts-bcount">{!! $counts !!}</span>
                </div>
                <div class="rts-belt-row" data-qa="{{ $isGet ? 'belt-tiles' : 'belt-tiles-' . $bt['key'] }}" @if($isGet) data-tour="smk-rts-belt" @endif role="list">
                    @foreach($bt['tiles'] as $i => $t)
                        @php
                            $ls = collect($t['lines']);
                            $tip = $t['label'] . ': ' . $ls->pluck('text')->implode(', ') . (($t['kind'] ?? '') === 'arrival' ? ' (' . $t['count'] . ' there now)' : '') . (! empty($t['gap']) ? ' · ' . $t['gap'] : '');
                            $l1 = $ls->first();
                            [$n1, $w1] = array_pad(explode(' ', $l1['text'], 2), 2, '');
                        @endphp
                        <div class="rts-tilewrap" role="listitem" style="position: relative; min-width: 0;"
                             @if($isGet) x-data="beltCompare('{{ route('road-to-sold.belt.scopes', ['station' => $t['key']] + $postBase + \App\Services\RoadToSold\Track::params($track)) }}')" @keydown.escape.window="open = false" @click.outside="open = false" @endif>
                            <div class="rts-tile m4 {{ $isGet && $lit($t) ? 'is-lit' : (! $isGet && $block === 'station' && $curStation === $t['key'] ? 'is-lit' : '') }}" data-kind="{{ $t['kind'] }}" title="{{ $tip }}{{ ! empty($t['tgt_line']) ? ' · ' . $t['tgt_line'] : '' }}">
                                <a href="{{ route('road-to-sold.index', $t['url']) }}" class="nm" data-qa="belt-name-{{ $t['key'] }}"><span class="short">{{ $t['short'] }}</span><span class="full">{{ $t['label'] }}</span></a>
                                <a href="{{ route('road-to-sold.index', $l1['url']) }}" data-qa="belt-tile-{{ $t['key'] }}" class="ln l1"><span class="n" data-qa="belt-figure">{{ $n1 }}</span> {{ $w1 }}</a>
                                @foreach($ls->slice(1) as $ln)
                                    @if($ln['url'] === null)
                                        <span class="ln {{ $ln['bad'] ? 'bad' : '' }}" data-qa="belt-line-{{ $t['key'] }}-{{ $ln['key'] }}">{{ $ln['text'] }}</span>
                                    @else
                                        <a href="{{ route('road-to-sold.index', $ln['url']) }}" class="ln {{ $ln['bad'] ? 'bad' : '' }}" data-qa="belt-line-{{ $t['key'] }}-{{ $ln['key'] }}">{{ $ln['text'] }}</a>
                                    @endif
                                @endforeach
                            </div>
                            @if($isGet)
                                <button type="button" class="rts-cmp" data-qa="belt-compare-{{ $t['key'] }}" aria-label="Compare {{ $t['label'] }}: me, my branch, the agency" :aria-expanded="open ? 'true' : 'false'" @click.prevent="toggle()">&#9662;</button>
                                <div x-show="open" x-cloak class="rts-pop rts-cmp-pop" data-qa="belt-compare-pop-{{ $t['key'] }}" style="{{ $i >= count($bt['tiles']) / 2 ? 'right: 0; left: auto;' : 'left: 0; right: auto;' }} top: 100%;">
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
                                                <span x-show="s.rate_next != null" x-text="' · ' + s.rate_next + '% go on' + (s.estimate ? ' (est.)' : '')"></span>
                                                <span x-show="s.derived != null" x-text="' · should be ' + s.derived"></span>
                                            </div>
                                        </div>
                                    </template>
                                    <div class="rts-src" x-show="sources.length" data-qa="compare-sources">
                                        <div class="text-xs" style="font-weight: 700; color: var(--text-primary);">Where today's stock came from</div>
                                        <template x-for="r in sources" :key="r.kind"><div class="rts-src-row"><span x-text="r.label"></span><strong x-text="r.count"></strong></div></template>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
@endif
