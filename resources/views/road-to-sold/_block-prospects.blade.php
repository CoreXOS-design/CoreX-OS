{{-- Road to Sold v3 block 2 - MY PROSPECTS = THE PRODUCTION LINE (spec 22.24): 18 stations in 5 phases across the top, every active property as a chip
     under its station (address, days there, next action, due date), the month's flow between phases on the phase boundaries. A station click filters
     the chips; a chip opens the property's card with the whole line drawn and the property's place lit. Everything but the chip area stays put. --}}
@php
    $pf = $prospectFilters;
    $agentName = $agents->firstWhere('id', $agentId)?->name;
    $keepAgent = $viewingOwn ? [] : ['agent' => $agentId];
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $stageMeta = \App\Services\RoadToSold\ProspectStages::ALL;
    $moneyFmt = fn ($v) => $v ? 'R ' . number_format((int) $v, 0, '.', ',') : null;
    $base = ['block' => 'prospects'] + $keepAgent + \App\Services\RoadToSold\Track::params($track);
    $keep = array_filter(['p_q' => $pf['q'], 'p_due' => $pf['due'], 'p_suburb' => $pf['suburb'], 'p_sort' => $pf['sort'] !== 'due' ? $pf['sort'] : null, 'p_who' => $lineWho !== 'agent' ? $lineWho : null], fn ($v) => $v !== null && $v !== '');
    $lineUrl = fn (array $over = []) => route('road-to-sold.index', array_filter($base + $keep + $over, fn ($v) => $v !== null && $v !== ''));
    $searching = $pf['q'] !== '' || $pf['due'] !== '' || $pf['suburb'] !== '';
    $byStage = collect($lineChips)->groupBy('stage');
    $stationList = collect($stageMeta)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'short' => \App\Services\RoadToSold\ProspectStages::short($k), 'phase' => \App\Services\RoadToSold\ProspectStages::phaseOf($k)])->values()->all();
    $cards = collect($lineChips)->mapWithKeys(fn ($c) => [$c['id'] => [
        'id' => $c['id'], 'tp' => $c['tracked_property_id'], 'address' => $c['address'], 'suburb' => $c['suburb'], 'owner' => $c['owner_name'], 'phone' => $c['owner_phone'],
        'price' => $moneyFmt($c['price']), 'stage' => $c['stage'], 'stage_label' => $c['stage_label'], 'agent' => $c['agent_name'], 'next' => $c['next_action'],
        'due_state' => $c['due_state'], 'due_words' => $c['due_words'], 'next_at' => $c['next_action_at'], 'days' => $c['days_in_stage'], 'reason' => $c['reason'], 'journey' => (object) $c['journey'],
        'step_url' => route('road-to-sold.prospects.step', [$c['id'], $c['primary']['key']]), 'step_label' => $c['primary']['label'],
        'history_url' => route('road-to-sold.prospects.history', [$c['id'], 'embed' => 1]),
    ]]);
@endphp
<div class="rts-col" x-data="rtsProspectActions(@js($parkReasons), @js($cards), @js($stationList))" data-qa="block-prospects">

    {{-- Search / filters / sort; the station is chosen on the line itself --}}
    <form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="prospect-filters">
        <input type="hidden" name="block" value="prospects">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        @if(! $viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endif
        @if($pf['stage'] !== '')<input type="hidden" name="p_stage" value="{{ $pf['stage'] }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-q">Search</label>
            <input id="p-q" type="text" name="p_q" value="{{ $pf['q'] }}" placeholder="Address, suburb, owner, phone or next action" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 17rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-due">Due</label>
            <select id="p-due" name="p_due" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">Any time</option>
                @foreach(['overdue' => 'Overdue', 'today' => 'Today', 'week' => 'This week', 'later' => 'Later', 'none' => 'No date'] as $v => $l)<option value="{{ $v }}" @selected($pf['due'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        @if(count($suburbChoices) > 1)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-sub">Suburb</label>
                <select id="p-sub" name="p_suburb" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All suburbs</option>
                    @foreach($suburbChoices as $sub)<option value="{{ $sub }}" @selected($pf['suburb'] === $sub)>{{ $sub }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-sort">Sort each station by</label>
            <select id="p-sort" name="p_sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['due' => 'Due date (soonest first)', 'days_in_stage' => 'Days at the station', 'address' => 'Address'] as $v => $l)<option value="{{ $v }}" @selected($pf['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        @if(count($lineWhoOptions) > 1)
            <div data-qa="line-who">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-who">Show</label>
                <select id="p-who" name="p_who" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="agent" @selected($lineWho === 'agent')>{{ $viewingOwn ? 'My properties' : $agentName }}</option>
                    @if(isset($lineWhoOptions['branch']))<option value="branch" @selected($lineWho === 'branch')>Whole branch - {{ $lineWhoOptions['branch'] }}</option>@endif
                    @if(isset($lineWhoOptions['agency']))<option value="agency" @selected($lineWho === 'agency')>{{ $lineWhoOptions['agency'] }}</option>@endif
                </select>
            </div>
        @endif
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($searching || $pf['stage'] !== '' || $pf['sort'] !== 'due' || $flowView)
            <a href="{{ route('road-to-sold.index', array_filter($base + ['p_who' => $lineWho !== 'agent' ? $lineWho : null])) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
        @if($pf['stage'] !== '')
            <span class="text-xs" style="color: var(--text-muted);" data-qa="station-note">Showing <strong style="color: var(--text-primary);">{{ $stageMeta[$pf['stage']]['label'] ?? $pf['stage'] }}</strong> only - <a href="{{ $lineUrl(['p_stage' => null]) }}" class="underline" data-qa="all-stations">All stations</a></span>
        @endif
        @if($lineWho !== 'agent')
            <span class="text-xs ml-auto" style="color: var(--text-muted);" data-qa="line-scope">{{ $lineWho === 'branch' ? 'Whole branch - ' . $lineWhoOptions['branch'] : 'Whole agency' }} - read only</span>
        @elseif($pf['stage'] === '')
            <span class="text-xs ml-auto" style="color: var(--text-muted);" data-qa="line-hint">Click a station to see just those properties, or a property for its card.</span>
        @endif
    </form>

    @php $nothing = $lineTotal === 0 && array_sum(array_column($linePhases, 'total')) === 0; @endphp
    <div class="rts-grow rts-linebox" data-qa="prospect-board">
      <div class="rts-inner">
        {{-- THE LINE: pinned while the chips scroll under it --}}
        <div class="rts-line" data-qa="production-line">
            <div class="rts-grid18 rts-phases">
                @foreach($linePhases as $ph)
                    <div class="rts-phase" style="grid-column: span {{ count($ph['stations']) }};" data-phase="{{ $ph['key'] }}">
                        <span class="rts-phase-name" title="{{ $ph['label'] }}: {{ $ph['total'] }} {{ $ph['total'] === 1 ? 'property' : 'properties' }} now">{{ $ph['label'] }}</span>
                        <span class="rts-flow" data-qa="flow-{{ $ph['key'] }}">
                            @php
                                $movedTitle = $ph['last'] ? $ph['moved'] . ' registered this month' : $ph['moved'] . ' moved on from ' . $ph['label'] . ' this month';
                                $outTitle = $ph['out'] . ' fell out of ' . $ph['label'] . ' this month (set aside, not selling or lost)';
                            @endphp
                            @if($ph['moved'] > 0)<a href="{{ $lineUrl(['p_flow' => 'moved', 'p_flow_phase' => $ph['key']]) }}" title="{{ $movedTitle }} - click to see them" data-flow="moved" class="{{ $flowView && $flowView['kind'] === 'moved' && $flowView['phase'] === $ph['key'] ? 'is-on' : '' }}">{{ $ph['last'] ? '✓' : '→' }}{{ $ph['moved'] }}</a>@endif
                            @if($ph['out'] > 0)<a href="{{ $lineUrl(['p_flow' => 'out', 'p_flow_phase' => $ph['key']]) }}" title="{{ $outTitle }} - click to see them" data-flow="out" class="rts-out {{ $flowView && $flowView['kind'] === 'out' && $flowView['phase'] === $ph['key'] ? 'is-on' : '' }}">✕{{ $ph['out'] }}</a>@endif
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="rts-grid18 rts-stations">
                @foreach($linePhases as $ph)
                    @foreach($ph['stations'] as $st)
                        @php
                            $on = $pf['stage'] === $st['stage'];
                            $edge = match ($st['step_state']) { 'achieved', 'ahead', 'on_track' => 'var(--ds-green, #059669)', 'needs_a_push' => 'var(--ds-amber, #f59e0b)', 'behind' => 'var(--ds-red, #f87171)', default => 'var(--border)' };
                            $tip = $st['label'] . ': ' . $st['now'] . ($st['now'] === 1 ? ' property' : ' properties') . ' now, ' . $st['entered'] . ' entered this month'
                                . ($st['avg_days'] !== null ? ', ' . rtrim(rtrim(number_format($st['avg_days'], 1), '0'), '.') . ' days here on average' : '')
                                . ($st['behind'] > 0 ? '. Needs attention: ' . $st['overdue'] . ' overdue, ' . $st['stalled'] . ' with no movement for a while' : '')
                                . '. ' . ($st['step_state'] ? 'On track: ' . ucfirst(str_replace('_', ' ', $st['step_state'])) : 'No target set for this step') . '. Click to show only these.';
                        @endphp
                        <a href="{{ $lineUrl(['p_stage' => $on ? null : $st['stage']]) }}" class="rts-st {{ $on ? 'is-on' : '' }} {{ $st['now'] === 0 ? 'is-empty' : '' }}" style="border-top-color: {{ $edge }};" title="{{ $tip }}" data-station="{{ $st['stage'] }}" data-count="{{ $st['now'] }}" aria-pressed="{{ $on ? 'true' : 'false' }}">
                            <span class="nm"><span class="rts-st-short">{{ $st['short'] }}</span><span class="rts-st-full">{{ $st['label'] }}</span></span>
                            <span class="n">{{ $st['now'] }}@if($st['behind'] > 0)<span class="bad" data-qa="station-behind" title="{{ $st['overdue'] }} overdue, {{ $st['stalled'] }} stalled">!{{ $st['behind'] }}</span>@endif</span>
                        </a>
                    @endforeach
                @endforeach
            </div>
        </div>

        {{-- UNDER THE LINE --}}
        @if($flowView)
            <div class="rts-flowlist" data-qa="flow-list">
                <div class="text-xs mb-1" style="color: var(--text-primary);"><strong>{{ $flowView['kind'] === 'moved' ? ($flowView['phase'] === 'close' ? 'Registered this month' : 'Moved on from ' . $flowView['label'] . ' this month') : 'Fell out of ' . $flowView['label'] . ' this month' }}</strong> ({{ count($flowView['rows']) }}) - <a href="{{ $lineUrl(['p_flow' => null, 'p_flow_phase' => null]) }}" class="underline" data-qa="back-to-line">Back to the line</a></div>
                @if(count($flowView['rows']) === 0)
                    <div class="text-xs" style="color: var(--text-muted);">Nobody this month.</div>
                @else
                    <table class="w-full text-xs"><thead><tr style="color: var(--text-muted);"><th class="text-left">Property</th><th class="text-left">Owner</th><th class="text-left">Agent</th><th class="text-left">Where it is now</th><th class="text-left">When</th></tr></thead>
                    <tbody>
                    @foreach($flowView['rows'] as $fr)
                        <tr data-flow-row="{{ $fr['id'] }}" style="border-top: 1px solid var(--border);"><td class="py-1">{{ $fr['address'] }}@if($fr['suburb'])<span style="color: var(--text-muted);"> &middot; {{ $fr['suburb'] }}</span>@endif</td><td>{{ $fr['owner'] ?: 'Owner not known yet' }}</td><td>{{ $fr['agent'] }}</td><td>{{ $fr['now'] }}</td><td>{{ $fr['when'] ? \Illuminate\Support\Carbon::parse($fr['when'])->format('j M') : '' }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        @elseif($nothing)
            <div class="rounded-md px-6 py-8 text-center" style="background: var(--surface); border: 1px solid var(--border);" data-qa="prospects-empty">
                <div class="text-base font-semibold" style="color: var(--text-primary);">No prospects yet - they start when you work today's intake.</div>
                <a href="{{ route('road-to-sold.index', $keepAgent + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-primary corex-btn-xs mt-4 inline-flex">Go to today's intake</a>
            </div>
        @elseif($lineTotal === 0)
            <div class="rounded-md px-6 py-8 text-center text-sm font-semibold" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" data-qa="prospects-empty">Nothing matches this search.</div>
        @else
            <div class="{{ $pf['stage'] !== '' ? 'rts-wide' : 'rts-grid18 rts-cols' }}" data-qa="chips">
                @php $chipGroups = $pf['stage'] !== '' ? [$pf['stage'] => $byStage[$pf['stage']] ?? collect()] : collect(array_keys($stageMeta))->mapWithKeys(fn ($k) => [$k => $byStage[$k] ?? collect()])->all(); @endphp
                @foreach($chipGroups as $stageKey => $group)
                    @if($pf['stage'] === '')<div class="rts-col18" data-chips-for="{{ $stageKey }}">@endif
                    @foreach($group as $r)
                        <button type="button" class="rts-ch {{ $r['due_state'] === 'overdue' ? 'is-overdue' : '' }}" data-prospect="{{ $r['id'] }}" data-stage="{{ $r['stage'] }}" data-due="{{ $r['due_state'] }}"
                                title="{{ $r['address'] }}{{ $r['suburb'] ? ', ' . $r['suburb'] : '' }} - {{ $r['stage_label'] }}, {{ $r['days_in_stage'] ?? 0 }} {{ ($r['days_in_stage'] ?? 0) === 1 ? 'day' : 'days' }} here. Next: {{ $r['next_action'] ?: 'decide what happens next' }} ({{ $r['due_words'] }}). Click for the full card."
                                @click="openCard({{ $r['id'] }})">
                            <span class="a">{{ $r['address'] }}@if($pf['stage'] !== '' && $r['suburb'])<span class="rts-sub"> &middot; {{ $r['suburb'] }}</span>@endif</span>
                            <span class="x" data-qa="next-action">{{ $r['next_action'] ?: 'Decide what happens next' }}</span>
                            <span class="x rts-due-{{ $r['due_state'] }}" data-due="{{ $r['due_state'] }}">{{ $pf['stage'] !== '' ? $r['due_words'] : $r['due_compact'] }}<span class="rts-days" style="color: var(--text-muted);"> &middot; {{ $r['days_in_stage'] ?? 0 }}d</span></span>
                            @if($pf['stage'] !== '')<span class="x rts-sub">{{ $r['owner_name'] ?: 'Owner not known yet' }}@if($moneyFmt($r['price'])) &middot; {{ $moneyFmt($r['price']) }}@endif</span>@endif
                        </button>
                    @endforeach
                    @if($pf['stage'] === '')</div>@endif
                @endforeach
            </div>
            @if($lineLimited)<div class="text-xs mt-1" style="color: var(--text-muted);" data-qa="line-limited">Showing the first {{ count($lineChips) }} of {{ $lineTotal }} - narrow the search to see the rest.</div>@endif
        @endif
      </div>
    </div>

    {{-- The property's card: the whole line with its place lit, then where it is and what happens next --}}
    <template x-if="card">
        <div class="fixed inset-0 z-40 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @click.self="cardId = null" @keydown.escape.window="cardId = null" data-qa="prospect-card" role="dialog" aria-modal="true">
            <div class="w-full rounded-md p-4 space-y-3" style="max-width: 58rem; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-bold truncate" x-text="card.address + (card.suburb ? ' - ' + card.suburb : '')"></div>
                        <div class="text-xs" style="color: var(--text-muted);" x-text="(card.owner ? card.owner + (card.phone ? ' - ' + card.phone : '') : 'Owner not known yet') + (card.price ? ' - ' + card.price : '') + (card.agent ? ' - ' + card.agent : '')"></div>
                    </div>
                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="cardId = null" aria-label="Close the card">Close</button>
                </div>
                <div class="rts-grid18 rts-miniline" data-qa="card-line">
                    <template x-for="s in stations" :key="s.key">
                        <div class="rts-mini" :class="{ 'is-here': s.key === card.stage, 'is-done': s.key !== card.stage && !!card.journey[s.key] }" :title="s.label + (card.journey[s.key] ? ' - reached ' + card.journey[s.key] : '')" :data-mini="s.key">
                            <span class="dot"></span><span class="nm" x-text="s.short"></span>
                        </div>
                    </template>
                </div>
                <div class="text-sm rounded-md px-3 py-2" style="background: var(--bg-muted, rgba(127,127,127,.08));" data-qa="card-here">
                    <strong>Here:</strong> <span x-text="card.stage_label"></span>
                    <span style="color: var(--text-muted);" x-text="card.days !== null ? '(' + card.days + (card.days === 1 ? ' day' : ' days') + ')' : ''"></span>
                    &rarr; <strong>next:</strong> <span x-text="card.next || 'decide what happens next'"></span>,
                    <span :class="'rts-due-' + card.due_state" x-text="card.due_words.charAt(0).toLowerCase() + card.due_words.slice(1)"></span>
                </div>
                <div class="text-xs" style="color: var(--text-muted);" x-show="card.reason" x-text="card.reason ? 'Started: ' + card.reason : ''"></div>
                <div class="flex items-center gap-1 flex-wrap">
                    @if($canWriteProspects)
                        <button type="button" class="corex-btn-primary corex-btn-xs" data-qa="prospect-step" :data-step-url="card.step_url" :data-step-label="card.step_label + ' - ' + card.address"
                                @click="cardId = null; $dispatch('rts-step', $el.dataset)" x-text="card.step_label"></button>
                        <button type="button" class="corex-btn-outline corex-btn-xs" :data-tp="card.tp" :data-address="card.address" @click="cardId = null; openContact($el.dataset)">Record a result</button>
                        <button type="button" class="corex-btn-outline corex-btn-xs" @click="openNext(card.id, card.next, card.next_at)">Next action</button>
                        <button type="button" class="corex-btn-outline corex-btn-xs" data-qa="prospect-move" @click="openMove()">Move to...</button>
                        <button type="button" class="corex-btn-outline corex-btn-xs" @click="openPark(card.id, card.address)">Set aside</button>
                    @endif
                    <button type="button" class="corex-btn-outline corex-btn-xs" :data-step-url="card.history_url" :data-step-label="'History - ' + card.address" @click="cardId = null; $dispatch('rts-step', $el.dataset)">History</button>
                    @if($canRelease ?? false)
                        <button type="button" class="corex-btn-outline corex-btn-xs" data-qa="prospect-release" @click="$dispatch('rts-release', { id: card.id, address: card.address }); cardId = null">Release</button>
                    @endif
                </div>
            </div>
        </div>
    </template>

    {{-- Move to a later station by hand (only for work CoreX has no screen for): what happened, the next action and its date are all required --}}
    <div x-show="moveOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="moveOpen = false" data-qa="move-modal">
        <form method="POST" :action="'{{ url('road-to-sold/prospects') }}/' + (card ? card.id : 0) + '/capture'" class="w-full max-w-md rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf
            @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Move to a later station</h2>
            <p class="text-xs" style="color: var(--text-muted);">Use this only for work CoreX does not see. When you do the work in CoreX, the property moves by itself.</p>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="mv-stage">It has reached</label>
                <select id="mv-stage" name="stage" required class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
                    <option value="">Pick a station</option>
                    <template x-for="s in laterStations" :key="s.key"><option :value="s.key" x-text="s.label"></option></template>
                </select></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="mv-note">What happened?</label>
                <input id="mv-note" name="note" required maxlength="255" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="mv-next">What will you do next?</label>
                <input id="mv-next" name="next_label" required maxlength="120" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="mv-at">When</label>
                <input id="mv-at" type="date" name="next_at" required class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div class="flex items-center justify-end gap-2"><button type="button" class="corex-btn-outline corex-btn-xs" @click="moveOpen = false">Cancel</button><button type="submit" class="corex-btn-primary corex-btn-xs">Save</button></div>
        </form>
    </div>

    {{-- Change next action --}}
    <div x-show="nextOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="nextOpen = false">
        <form method="POST" :action="'{{ url('road-to-sold/prospects') }}/' + id + '/next-action'" class="w-full max-w-md rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf @method('PUT')
            @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Next action</h2>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="na-label">What will you do next?</label>
                <input id="na-label" name="label" x-model="label" maxlength="120" required class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="na-at">When</label>
                <input id="na-at" type="date" name="at" x-model="at" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div class="flex items-center justify-end gap-2"><button type="button" class="corex-btn-outline corex-btn-xs" @click="nextOpen = false">Cancel</button><button type="submit" class="corex-btn-primary corex-btn-xs">Save</button></div>
        </form>
    </div>

    {{-- Set aside (park) --}}
    <div x-show="parkOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="parkOpen = false">
        <form method="POST" :action="'{{ url('road-to-sold/prospects') }}/' + id + '/park'" class="w-full max-w-md rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf
            @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Set aside</h2>
            <div class="text-xs" style="color: var(--text-muted);" x-text="address"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="pk-reason">Why</label>
                <select id="pk-reason" name="reason" x-model="reason" @change="pickReason()" required class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
                    <option value="">Pick a reason</option>
                    <template x-for="r in reasons" :key="r.key"><option :value="r.key" x-text="r.label"></option></template>
                </select></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="pk-until">Bring it back on</label>
                <input id="pk-until" type="date" name="until" x-model="until" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="pk-note">Note</label>
                <input id="pk-note" name="note" maxlength="255" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
            <div class="flex items-center justify-end gap-2"><button type="button" class="corex-btn-outline corex-btn-xs" @click="parkOpen = false">Cancel</button><button type="submit" class="corex-btn-primary corex-btn-xs">Set aside</button></div>
        </form>
    </div>
</div>
<script>
function rtsProspectActions(reasons, cards, stations) {
    return {
        reasons: reasons, cards: cards, stations: stations, cardId: null, moveOpen: false,
        id: null, address: '', label: '', at: '', reason: '', until: '', nextOpen: false, parkOpen: false,
        get card() { return this.cardId !== null ? (this.cards[this.cardId] || null) : null; },
        get laterStations() {
            var c = this.card; if (!c) return [];
            var i = this.stations.findIndex(s => s.key === c.stage);
            return this.stations.slice(i + 1);
        },
        openCard(id) { this.cardId = id; },
        openMove() { this.moveOpen = true; },
        openNext(id, label, at) { this.id = id; this.label = label || ''; this.at = at || ''; this.nextOpen = true; },
        openPark(id, address) { this.id = id; this.address = address; this.reason = ''; this.until = ''; this.parkOpen = true; },
        pickReason() {
            var r = this.reasons.find(x => x.key === this.reason);
            if (r && r.days) { var d = new Date(); d.setDate(d.getDate() + r.days); this.until = d.toISOString().slice(0, 10); }
        },
    };
}
</script>
