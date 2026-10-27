{{-- Road to Sold v3 block 2 - MY PROSPECTS (spec 22.4): every property the agent has touched, by stage, each with its NEXT ACTION and DUE DATE.
     Two columns (Get stock | Sell stock) of cards. A manager sees the same read-only, with the per-stage counts and the on-track bar (R9). --}}
@php
    $pf = $prospectFilters;
    $keepAgent = $viewingOwn ? [] : ['agent' => $agentId];
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $stageMeta = \App\Services\RoadToSold\ProspectStages::ALL;
    $groups = ['get_stock' => 'Get stock', 'sell_stock' => 'Sell stock'];
    $byGroup = collect($prospectRows->items())->groupBy('group');
    $dueWords = fn (array $r) => match ($r['due_state']) {
        'overdue' => 'Overdue - was due ' . \Illuminate\Support\Carbon::parse($r['next_action_at'])->format('D j M'),
        'today'   => 'Due today',
        'later'   => 'Due ' . \Illuminate\Support\Carbon::parse($r['next_action_at'])->format('D j M'),
        default   => 'No date set',
    };
    $moneyFmt = fn ($v) => $v ? 'R ' . number_format((int) $v, 0, '.', ',') : null;
@endphp
<div class="rts-col" x-data="rtsProspectActions(@js($parkReasons))" data-qa="block-prospects">

    {{-- Stage strip: where everything sits; a stage is a filter --}}
    <div class="flex items-center gap-2 flex-wrap text-xs {{ $stageCounts ? 'hidden' : '' }}" data-qa="stage-strip">
        @foreach($stageMeta as $key => $meta)
            @if(($stageStrip[$key] ?? 0) > 0)
                <a href="{{ route('road-to-sold.index', ['block' => 'prospects', 'p_stage' => $key] + $keepAgent + \App\Services\RoadToSold\Track::params($track)) }}" class="rts-chip" style="{{ $pf['stage'] === $key ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : '' }}">{{ $meta['label'] }} {{ $stageStrip[$key] }}</a>
            @endif
        @endforeach
        @if(array_sum($stageStrip) === 0)<span style="color: var(--text-muted);">Nothing here yet.</span>@endif
    </div>

    @if($stageCounts)
        {{-- R9: the manager's per-stage figures, one small tile per stage: how many now, how many entered this month, average days, stalled, and the recipe step's own on-track state. --}}
        <div data-qa="stage-counts" style="display: grid; gap: .4rem; grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr));">
            @foreach($stageCounts as $sc)
                @php $st = $sc['step_state']; $edge = match ($st) { 'achieved', 'ahead', 'on_track' => 'var(--ds-green, #059669)', 'needs_a_push' => 'var(--ds-amber, #f59e0b)', 'behind' => 'var(--ds-red, #f87171)', default => 'var(--border)' }; @endphp
                <div class="rounded-md px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid {{ $edge }}; color: var(--text-primary);" title="{{ $sc['stalled'] > 0 ? $sc['stalled'] . ' stalled' : '' }}">
                    <div class="font-semibold">{{ $sc['label'] }}</div>
                    <div><strong>{{ $sc['now'] }}</strong> now &middot; {{ $sc['entered'] }} in{{ $sc['avg_days'] !== null ? ' &middot; ' . $sc['avg_days'] . 'd' : '' }}@if($sc['stalled'] > 0) &middot; <span class="rts-due-overdue">{{ $sc['stalled'] }} stalled</span>@endif</div>
                    <div style="color: var(--text-muted);">On track: {{ $st ? ucfirst(str_replace('_', ' ', $st)) : '-' }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Filters: stage, due, suburb; search; sort by due --}}
    <form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="prospect-filters">
        <input type="hidden" name="block" value="prospects">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        @if(! $viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-q">Search</label>
            <input id="p-q" type="text" name="p_q" value="{{ $pf['q'] }}" placeholder="Address, suburb, owner, phone or next action" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 17rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-stage">Stage</label>
            <select id="p-stage" name="p_stage" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">All stages</option>
                @foreach($groups as $g => $gl)
                    <optgroup label="{{ $gl }}">
                        @foreach(\App\Services\RoadToSold\ProspectStages::inGroup($g) as $k)<option value="{{ $k }}" @selected($pf['stage'] === $k)>{{ $stageMeta[$k]['label'] }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
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
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="p-sort">Sort</label>
            <select id="p-sort" name="p_sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['due' => 'Due date (soonest first)', 'stage' => 'Stage', 'address' => 'Address', 'days_in_stage' => 'Days in stage', 'last_outcome' => 'Last outcome'] as $v => $l)<option value="{{ $v }}" @selected($pf['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($pf['q'] !== '' || $pf['stage'] !== '' || $pf['due'] !== '' || $pf['suburb'] !== '' || $pf['sort'] !== 'due')
            <a href="{{ route('road-to-sold.index', ['block' => 'prospects'] + $keepAgent + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
    </form>

    @if($prospectRows->total() === 0)
        <div class="rounded-md px-6 py-12 text-center" style="background: var(--surface); border: 1px solid var(--border);" data-qa="prospects-empty">
            <div class="text-base font-semibold" style="color: var(--text-primary);">
                @if($pf['q'] !== '' || $pf['stage'] !== '' || $pf['due'] !== '' || $pf['suburb'] !== '')Nothing matches this search.@else No prospects yet - they start when you work today's intake.@endif
            </div>
            @if($pf['q'] === '' && $pf['stage'] === '' && $pf['due'] === '' && $pf['suburb'] === '')
                <a href="{{ route('road-to-sold.index', $keepAgent + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-primary corex-btn-xs mt-4 inline-flex">Go to today's intake</a>
            @endif
        </div>
    @else
        @php $shown = collect($groups)->filter(fn ($gl, $g) => ($byGroup[$g] ?? collect())->isNotEmpty()); @endphp
        <div class="rts-grow" data-qa="prospect-board">
          <div class="rts-board" style="grid-template-columns: repeat({{ max(1, $shown->count()) }}, minmax(0, 1fr));">
            @foreach($shown as $g => $gl)
                <section aria-label="{{ $gl }}" class="space-y-1">
                    <h2 class="text-xs font-bold uppercase tracking-widest" style="color: var(--text-muted);">{{ $gl }} <span class="rts-chip">{{ $byGroup[$g]->count() }}</span></h2>
                    <div class="rts-cards">
                    @foreach($byGroup[$g] as $r)
                        <article class="rts-card {{ $r['due_state'] === 'overdue' ? 'is-overdue' : '' }}" data-prospect="{{ $r['id'] }}" data-stage="{{ $r['stage'] }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold truncate" style="color: var(--text-primary);" title="{{ $r['address'] }}">{{ $r['address'] }}@if($r['suburb'])<span class="font-normal" style="color: var(--text-muted);"> &middot; {{ $r['suburb'] }}</span>@endif</div>
                                    <div class="text-[11px] truncate" style="color: var(--text-muted);">
                                        @if($r['owner_name']){{ $r['owner_name'] }}@if($r['owner_phone']) &middot; {{ $r['owner_phone'] }}@endif @else Owner not known yet @endif
                                        @if($moneyFmt($r['price'])) &middot; {{ $moneyFmt($r['price']) }}@endif
                                    </div>
                                </div>
                                <span class="rts-chip shrink-0" title="{{ $r['days_in_stage'] !== null ? $r['days_in_stage'] . ' days at this stage' : '' }}{{ $r['reason'] ? ' - started: ' . $r['reason'] : '' }}">{{ $r['stage_label'] }}</span>
                            </div>
                            <div class="mt-1 text-xs" style="color: var(--text-primary);" data-qa="next-action">
                                <span class="font-semibold">Next:</span> {{ $r['next_action'] ?: 'Decide what happens next' }}
                                <span class="rts-due-{{ $r['due_state'] }}" data-due="{{ $r['due_state'] }}">&middot; {{ $dueWords($r) }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-1 flex-wrap">
                                @if($canWriteProspects)
                                    <button type="button" class="corex-btn-primary corex-btn-xs" data-qa="prospect-step"
                                            data-step-url="{{ route('road-to-sold.prospects.step', [$r['id'], $r['primary']['key']]) }}" data-step-label="{{ $r['primary']['label'] }} - {{ $r['address'] }}"
                                            @click="$dispatch('rts-step', $el.dataset)">{{ $r['primary']['label'] }}</button>
                                    <button type="button" class="corex-btn-outline corex-btn-xs" data-tp="{{ $r['tracked_property_id'] }}" data-address="{{ $r['address'] }}" @click="openContact($el.dataset)">Record a result</button>
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="openNext({{ $r['id'] }}, @js($r['next_action']), @js($r['next_action_at']))">Next action</button>
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="openPark({{ $r['id'] }}, @js($r['address']))">Set aside</button>
                                @endif
                                <button type="button" class="corex-btn-outline corex-btn-xs"
                                        data-step-url="{{ route('road-to-sold.prospects.history', [$r['id'], 'embed' => 1]) }}" data-step-label="History - {{ $r['address'] }}"
                                        @click="$dispatch('rts-step', $el.dataset)">History</button>
                            </div>
                        </article>
                    @endforeach
                    </div>
                </section>
            @endforeach
          </div>
        </div>
        {{ $prospectRows->links() }}
    @endif

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
function rtsProspectActions(reasons) {
    return {
        reasons: reasons, id: null, address: '', label: '', at: '', reason: '', until: '', nextOpen: false, parkOpen: false,
        openNext(id, label, at) { this.id = id; this.label = label || ''; this.at = at || ''; this.nextOpen = true; },
        openPark(id, address) { this.id = id; this.address = address; this.reason = ''; this.until = ''; this.parkOpen = true; },
        pickReason() {
            var r = this.reasons.find(x => x.key === this.reason);
            if (r && r.days) { var d = new Date(); d.setDate(d.getDate() + r.days); this.until = d.toISOString().slice(0, 10); }
        },
    };
}
</script>
