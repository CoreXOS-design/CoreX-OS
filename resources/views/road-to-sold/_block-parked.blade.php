{{-- Road to Sold v3 block 3 - PARKED (spec 22.4): dead stock the agent has set aside, with the reason and the day it comes back. A manager sees the pile
     across the agents in their breadth, grouped by reason with counts, read-only. --}}
@php
    $kf = $parkedFilters;
    $keepAgent = $viewingOwn ? [] : ['agent' => $agentId];
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $reasonLink = fn (string $reason) => route('road-to-sold.index', ['block' => 'parked', 'k_reason' => $reason, 'who' => $parkedEveryone ? 'all' : null] + $keepAgent + \App\Services\RoadToSold\Track::params($track));
@endphp
<div class="space-y-3" data-qa="block-parked">
    @if($parkedByReason !== [])
        <div class="flex items-center gap-2 flex-wrap text-xs" data-qa="parked-by-reason">
            @foreach($parkedByReason as $br)
                <a href="{{ $reasonLink($br['reason']) }}" class="rts-chip" style="{{ $kf['reason'] === $br['reason'] ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : '' }}">{{ $br['reason'] }} {{ $br['count'] }}</a>
            @endforeach
        </div>
    @endif

    <form method="GET" class="flex items-end gap-3 flex-wrap">
        <input type="hidden" name="block" value="parked">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        @if(! $viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="k-q">Search</label>
            <input id="k-q" type="text" name="k_q" value="{{ $kf['q'] }}" placeholder="Address, owner, reason or note" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 16rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="k-revisit">Comes back</label>
            <select id="k-revisit" name="k_revisit" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">Any time</option>
                @foreach(['due' => 'Due now', 'month' => 'Within 30 days', 'later' => 'Later'] as $v => $l)<option value="{{ $v }}" @selected($kf['revisit'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="k-sort">Sort</label>
            <select id="k-sort" name="k_sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['revisit' => 'Comes back (soonest first)', 'parked' => 'Set aside on', 'address' => 'Address', 'reason' => 'Reason'] as $v => $l)<option value="{{ $v }}" @selected($kf['sort'] === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        @if($agents->count() > 1)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="k-who">Whose</label>
                <select id="k-who" name="who" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">{{ $viewingOwn ? 'Mine' : ($agents->firstWhere('id', $agentId)?->name ?? 'This agent') }}</option>
                    <option value="all" @selected($parkedEveryone)>Everyone I can see</option>
                </select>
            </div>
        @endif
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($kf['q'] !== '' || $kf['reason'] !== '' || $kf['revisit'] !== '' || $kf['sort'] !== 'revisit')
            <a href="{{ route('road-to-sold.index', ['block' => 'parked', 'who' => $parkedEveryone ? 'all' : null] + $keepAgent + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead><tr style="color: var(--text-muted);">
                <th class="px-3 py-2 text-left">Why it was set aside</th><th class="px-3 py-2 text-left">Property</th><th class="px-3 py-2 text-left">Owner</th>
                @if($parkedEveryone)<th class="px-3 py-2 text-left">Agent</th>@endif
                <th class="px-3 py-2 text-left">Set aside</th><th class="px-3 py-2 text-left">Comes back</th><th class="px-3 py-2 text-left">Was at</th><th class="px-3 py-2 text-right"></th>
            </tr></thead>
            <tbody>
            @forelse($parkedRows as $r)
                @php $due = $r['parked_until'] && $r['parked_until'] <= now()->toDateString(); @endphp
                <tr style="border-top: 1px solid var(--border); color: var(--text-primary);" data-parked="{{ $r['id'] }}">
                    <td class="px-3 py-2 font-semibold">{{ $r['park_reason'] ?: 'No reason given' }}@if($r['park_note'])<div class="font-normal" style="color: var(--text-muted);">{{ $r['park_note'] }}</div>@endif</td>
                    <td class="px-3 py-2"><div class="font-semibold">{{ $r['address'] }}</div>@if($r['suburb'])<div style="color: var(--text-muted);">{{ $r['suburb'] }}</div>@endif</td>
                    <td class="px-3 py-2">{{ $r['owner_name'] ?: '-' }}@if($r['owner_phone'])<div style="color: var(--text-muted);">{{ $r['owner_phone'] }}</div>@endif</td>
                    @if($parkedEveryone)<td class="px-3 py-2">{{ $r['agent_name'] }}</td>@endif
                    <td class="px-3 py-2">{{ $r['parked_at'] ? \Illuminate\Support\Carbon::parse($r['parked_at'])->format('j M Y') : '-' }}</td>
                    <td class="px-3 py-2 {{ $due ? 'rts-due-today' : '' }}">{{ $r['parked_until'] ? \Illuminate\Support\Carbon::parse($r['parked_until'])->format('D j M Y') : '-' }}{{ $due ? ' - due now' : '' }}</td>
                    <td class="px-3 py-2">{{ $r['parked_from_stage'] ?: '-' }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button type="button" class="corex-btn-outline corex-btn-xs" data-step-url="{{ route('road-to-sold.prospects.history', [$r['id'], 'embed' => 1]) }}" data-step-label="History - {{ $r['address'] }}" @click="$dispatch('rts-step', $el.dataset)">History</button>
                            @if($canWriteProspects && ! $parkedEveryone)
                                <form method="POST" action="{{ route('road-to-sold.prospects.unpark', $r['id']) }}">@csrf
                                    @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
                                    <button type="submit" class="corex-btn-primary corex-btn-xs" data-qa="bring-back">Bring back now</button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $parkedEveryone ? 8 : 7 }}" class="px-6 py-12 text-center text-base font-semibold" style="color: var(--text-primary);" data-qa="parked-empty">
                    {{ ($kf['q'] !== '' || $kf['reason'] !== '' || $kf['revisit'] !== '') ? 'Nothing matches this search.' : 'Nothing is parked. Properties you set aside - with the reason and the day to look again - wait here.' }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $parkedRows->links() }}
</div>
