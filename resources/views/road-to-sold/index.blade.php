{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold')

@section('corex-content')
@php
    $rowsPage = $rows;
    $addresses = [];
    foreach ($rowsPage as $r) { $addresses[$r['tracked_property_id']] = $r['address']; }
    $pageConfig = [
        'choices'  => $outcomeChoices,
        'reopen'   => session('rts_reopen'),
        'oldOutcome' => old('outcome_definition_id'),
        'oldNote'  => old('note'),
        'oldDate'  => old('next_action_at'),
        'oldValuation' => (bool) old('valuation_booked'),
        'oldAppointment' => old('appointment_at'),
        'addresses' => $addresses,
        'history'   => $history,
    ];
    $money = fn ($v) => $v ? 'R ' . number_format((int) $v, 0, '.', ',') : '';
    $viewLabels = ['today' => 'Today\'s list', 'no_answer' => 'No answer', 'wrong_number' => 'Wrong number', 'no_owner' => 'No owner'];
    $statusLabels = ['open' => 'Open', 'worked' => 'Worked', 'missed' => 'Missed', 'all' => 'All'];
    $sortLabels = ['order' => 'List order', 'address' => 'Address', 'price' => 'Price', 'dom' => 'Days on market', 'last_outcome' => 'Last outcome'];
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $listView = in_array($filters['view'], ['today', 'no_owner'], true);
@endphp

<div class="w-full space-y-4 rts-page" x-data="rtsPage(@js($pageConfig))">
    @include('road-to-sold._controls')

    <div class="rounded-md px-6 py-4 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Road to Sold</h1>
            <div class="flex items-center gap-2 flex-wrap">
                @if($agents->count() > 1)
                    <form method="GET" class="flex items-center gap-2">
                        <label class="text-xs font-semibold" style="color: var(--text-muted);" for="rts-agent">Agent</label>
                        <select id="rts-agent" name="agent" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                            @foreach($agents as $a)
                                <option value="{{ $a->id }}" @selected($a->id === $agentId)>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('road-to-sold.report'))
                    <a href="{{ route('road-to-sold.report', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Report</a>
                @endif
                @if($canManageOutcomes)
                    <a href="{{ route('road-to-sold.settings.outcomes.index', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Outcomes</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if(session('rts_error'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('rts_error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif

    @include('road-to-sold._summary-bar')

    {{-- Filters --}}
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        @if(! $viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-view">Show</label>
            <select id="rts-view" name="view" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($viewLabels as $v => $l)<option value="{{ $v }}" @selected($filters['view'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-q">Search</label>
            <input id="rts-q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Address, suburb, owner or phone" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 14rem;">
        </div>
        @if($listView)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sel">Selection</label>
                <select id="rts-sel" name="selection" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All selections</option>
                    @foreach($selections as $k => $l)<option value="{{ $k }}" @selected($filters['selection'] === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-status">Status</label>
                <select id="rts-status" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    @foreach($statusLabels as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-date">Day</label>
                <input id="rts-date" type="date" name="date" value="{{ $date->toDateString() }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sort">Sort</label>
            <select id="rts-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($sortLabels as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <select name="dir" aria-label="Sort direction" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="asc" @selected($filters['dir'] === 'asc')>Ascending</option>
                <option value="desc" @selected($filters['dir'] === 'desc')>Descending</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filters['q'] !== '' || $filters['selection'] !== '' || $filters['status'] !== 'open' || $filters['view'] !== 'today')
            <a href="{{ route('road-to-sold.index', ($viewingOwn ? [] : ['agent' => $agentId]) + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
    </form>

    @if($listView && $counts['any'] > 0)
        <div class="flex items-center justify-between flex-wrap gap-3 text-xs" style="color: var(--text-primary);">
            <div>
                <strong>{{ $counts['worked'] }} of {{ $counts['target_items'] }}</strong> worked {{ $date->isToday() ? 'today' : 'on ' . $date->format('j M') }}
                @if($counts['added'] > 0)<span style="color: var(--text-muted);"> · {{ $counts['added_worked'] }} of {{ $counts['added'] }} added on top</span>@endif
            </div>
            @if($canAddToDay && $date->isToday() && count($addChoices))
                <form method="POST" action="{{ route('road-to-sold.add-to-day') }}" class="flex items-center gap-2">
                    @csrf
                    @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
                    <select name="selection" aria-label="Selection to add from" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
                        @foreach($addChoices as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                    <input type="number" name="count" min="1" max="{{ $addMax ?? 100 }}" value="5" aria-label="How many to add" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 5rem;">
                    <button type="submit" class="corex-btn-outline corex-btn-xs">Add to my day</button>
                </form>
            @endif
        </div>
    @endif

    {{-- The list --}}
    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Address</th>
                    <th class="px-3 py-2 text-right">Price</th>
                    <th class="px-3 py-2 text-right">Days on market</th>
                    <th class="px-3 py-2 text-left">Listing agency</th>
                    <th class="px-3 py-2 text-left">Why suggested</th>
                    <th class="px-3 py-2 text-left">Owner</th>
                    <th class="px-3 py-2 text-left">Last outcome</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rowsPage as $r)
                @php $lo = $r['last_outcome']; @endphp
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        <div class="font-semibold">{{ $r['street'] !== '' ? $r['street'] : $r['address'] }}</div>
                        @if($r['street'] !== '' && $r['suburb'])<div style="color: var(--text-muted);">{{ $r['suburb'] }}</div>@endif
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap" style="color: var(--text-primary);">{{ $money($r['price']) ?: '-' }}</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $r['dom'] !== null ? $r['dom'] : '-' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r['agency_name'] ?: '-' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r['why'] ?: '-' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @if($r['owner_name'])
                            <div class="font-semibold">{{ $r['owner_name'] }}</div>
                            @if($r['owner_phone'])<div style="color: var(--text-muted);">{{ $r['owner_phone'] }}</div>@endif
                        @elseif($r['find_owner_url'])
                            <a href="{{ $r['find_owner_url'] }}" class="corex-btn-outline corex-btn-xs">Find the owner</a>
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @if($lo)
                            <div title="{{ $lo['note'] }}">{{ $lo['label'] }}@if($lo['streak'] > 1) <span style="color: var(--text-muted);">({{ $lo['streak'] }} in a row)</span>@endif</div>
                            <div style="color: var(--text-muted);">{{ $lo['when']->format('j M') }}@if($lo['by']) · {{ $lo['by'] }}@endif</div>
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            @if(! empty($history[$r['tracked_property_id']]))
                                <button type="button" class="corex-btn-outline corex-btn-xs" data-tp="{{ $r['tracked_property_id'] }}" data-address="{{ $r['address'] }}"
                                        @click="openHistory($el.dataset)">History</button>
                            @endif
                            @if($viewingOwn && ($r['status'] === 'open' || in_array($filters['view'], ['no_answer', 'wrong_number'], true)))
                                <button type="button" class="corex-btn-primary corex-btn-xs"
                                        data-tp="{{ $r['tracked_property_id'] }}" data-item="{{ $r['list_item_id'] }}" data-address="{{ $r['address'] }}"
                                        @click="openContact($el.dataset)">Contacted client</button>
                            @elseif($r['status'] !== 'open')
                                <span style="color: var(--text-muted);">{{ ucfirst($r['status']) }}</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-6 py-12 text-center">
                        @php
                            $filtered = $filters['q'] !== '' || $filters['selection'] !== '' || $filters['status'] !== 'open';
                        @endphp
                        <div class="text-base font-semibold" style="color: var(--text-primary);">
                            @if($filtered)
                                Nothing matches this search.
                            @elseif($filters['view'] === 'no_answer')
                                No properties are waiting on an answer.
                            @elseif($filters['view'] === 'wrong_number')
                                No properties are on a wrong-number list.
                            @elseif($filters['view'] === 'no_owner')
                                Every property on this list has an owner on file.
                            @elseif($counts['any'] > 0)
                                Everything on this day's list is done.
                            @else
                                No list for {{ $date->isToday() ? 'today' : $date->format('j M') }}.
                            @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($capped)
        <div class="text-xs" style="color: var(--text-muted);">Showing the first {{ \App\Services\RoadToSold\CallListService::MAX_ROWS }}. Narrow the search to see the rest.</div>
    @endif
    {{ $rowsPage->links() }}

    {{-- Other work, not counted against today's target --}}
    @php
        $feedBlocks = [
            ['key' => 'buyers',   'title' => 'Buyers to follow up', 'empty' => 'No buyers are waiting for contact.',      'all' => route('command-center.buyers.pipeline')],
            ['key' => 'leads',    'title' => 'Enquiries waiting',   'empty' => 'No enquiries are waiting for a response.', 'all' => null],
            ['key' => 'mandates', 'title' => 'Mandates ending soon', 'empty' => 'No mandates end soon.',                      'all' => route('corex.properties.index')],
        ];
    @endphp
    @if(! empty($feeds))
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));">
        @foreach($feedBlocks as $fb)
            @php $feed = $feeds[$fb['key']] ?? ['count' => 0, 'items' => []]; @endphp
            <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-center justify-between px-4 py-3" style="border-bottom: 1px solid var(--border);">
                    <h2 class="text-xs font-bold uppercase tracking-widest" style="color: var(--text-muted);">{{ $fb['title'] }}</h2>
                    <span class="text-xs font-semibold" style="color: var(--text-primary);">{{ $feed['count'] }}</span>
                </div>
                @forelse($feed['items'] as $it)
                    <div class="px-4 py-2 text-xs" style="border-top: 1px solid var(--border);">
                        @if($it['url'])<a href="{{ $it['url'] }}" class="font-semibold" style="color: var(--text-primary);">{{ $it['title'] }}</a>
                        @else<span class="font-semibold" style="color: var(--text-primary);">{{ $it['title'] }}</span>@endif
                        <div style="color: var(--text-muted);">{{ $it['sub'] }}</div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-xs text-center" style="color: var(--text-muted);">{{ $fb['empty'] }}</div>
                @endforelse
                @if($fb['all'] && $feed['count'] > count($feed['items']))
                    <div class="px-4 py-2 text-xs" style="border-top: 1px solid var(--border);"><a href="{{ $fb['all'] }}" style="color: var(--text-primary);">See all {{ $feed['count'] }}</a></div>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    {{-- History popup --}}
    <div x-show="historyOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="historyOpen = false">
        <div class="w-full max-w-lg rounded-md p-5 space-y-3 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh;">
            <div>
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">History</h2>
                <div class="text-xs mt-1" style="color: var(--text-muted);" x-text="address"></div>
            </div>
            <template x-for="(h, i) in historyRows" :key="i">
                <div class="text-xs py-2" style="border-top: 1px solid var(--border); color: var(--text-primary);">
                    <div><span class="font-semibold" x-text="h.label"></span>
                        <span style="color: var(--text-muted);" x-text="' · ' + (h.kind === 'conversation' ? 'conversation' : 'attempt') + (h.valuation ? ' · evaluation booked' : '')"></span></div>
                    <div style="color: var(--text-muted);" x-text="h.when + (h.by ? ' · ' + h.by : '') + (h.next ? ' · back on ' + h.next : '')"></div>
                    <div x-show="h.note" x-text="h.note"></div>
                </div>
            </template>
            <div class="flex items-center justify-end">
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="historyOpen = false">Close</button>
            </div>
        </div>
    </div>

    {{-- Contacted client popup --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="open = false">
        <form method="POST" action="{{ route('road-to-sold.outcomes.record') }}" class="w-full max-w-lg rounded-md p-5 space-y-4 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh;">
            @csrf
            @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
            <input type="hidden" name="tracked_property_id" :value="tp">
            <input type="hidden" name="list_item_id" :value="item">

            <div>
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">Contacted client</h2>
                <div class="text-xs mt-1" style="color: var(--text-muted);" x-text="address"></div>
            </div>

            <div class="space-y-2">
                <template x-for="c in choices" :key="c.id">
                    <label class="flex items-start gap-2 text-xs cursor-pointer" style="color: var(--text-primary);">
                        <input type="radio" name="outcome_definition_id" :value="c.id" x-model="chosen" class="mt-0.5">
                        <span>
                            <span class="font-semibold" x-text="c.label"></span>
                            <span style="color: var(--text-muted);" x-text="' · ' + (c.kind === 'conversation' ? 'conversation' : 'attempt')"></span>
                        </span>
                    </label>
                </template>
            </div>

            <div class="text-xs" style="color: var(--text-muted);" x-show="current" x-text="current ? current.summary : ''"></div>

            <div x-show="current && current.needs_date">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-next">Bring it back on</label>
                <input id="rts-next" type="date" name="next_action_at" x-model="nextDate" :required="current && current.needs_date && !valuation" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
            </div>

            <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary);" x-show="current && current.offers_valuation">
                <input type="checkbox" name="valuation_booked" value="1" x-model="valuation">
                Evaluation booked
            </label>

            <div x-show="needsAppointment">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-appt">Evaluation date and time</label>
                <input id="rts-appt" type="datetime-local" name="appointment_at" x-model="appointmentAt" :required="needsAppointment" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-note">Note</label>
                <textarea id="rts-note" name="note" rows="3" maxlength="5000" x-model="note" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2">
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="open = false">Cancel</button>
                <button type="submit" class="corex-btn-primary corex-btn-xs" :disabled="!current">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function rtsPage(cfg) {
    return {
        open: false,
        historyOpen: false,
        historyRows: [],
        tp: '',
        item: '',
        address: '',
        choices: cfg.choices || [],
        chosen: '',
        nextDate: '',
        valuation: false,
        appointmentAt: '',
        note: '',
        get current() {
            return this.choices.find(c => String(c.id) === String(this.chosen)) || null;
        },
        get needsAppointment() {
            return !!this.current && (this.current.needs_appointment || (this.current.offers_valuation && this.valuation));
        },
        init() {
            const r = cfg.reopen;
            if (r && r.tp) {
                this.tp = r.tp;
                this.item = r.item || '';
                this.address = (cfg.addresses && cfg.addresses[r.tp]) || '';
                this.chosen = cfg.oldOutcome || '';
                this.nextDate = cfg.oldDate || '';
                this.valuation = !!cfg.oldValuation;
                this.appointmentAt = cfg.oldAppointment || '';
                this.note = cfg.oldNote || '';
                this.open = true;
            }
        },
        openHistory(ds) {
            this.address = ds.address || '';
            this.historyRows = (cfg.history && cfg.history[ds.tp]) || [];
            this.historyOpen = true;
        },
        openContact(ds) {
            this.tp = ds.tp;
            this.item = ds.item || '';
            this.address = ds.address || '';
            this.chosen = '';
            this.nextDate = '';
            this.valuation = false;
            this.appointmentAt = '';
            this.note = '';
            this.open = true;
        },
    };
}
</script>
@endsection
