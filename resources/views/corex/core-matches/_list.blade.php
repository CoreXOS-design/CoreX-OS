{{-- Core Matches board — the contact list rows for $rows, grouped under an agent
     heading on Branch / Agency scope. Included by index.blade.php (first page) and
     _page.blade.php (each batch the list loads on scroll). The heading name is the
     contact's primary agent, or the agent of their latest qualifying search when
     the contact has none (controller: core_matches_agent_name); with the Agent
     filter on, every row sits under that agent. --}}
@php
    $stateDot = fn (?string $state) => match ($state) {
        'new'  => 'var(--brand-icon, #0ea5e9)',
        'warm' => 'var(--ds-green, #059669)',
        'cold' => 'var(--ds-amber, #f59e0b)',
        'lost' => 'var(--ds-crimson, #c41e3a)',
        'won'  => 'var(--ds-green, #059669)',
        default => null,
    };
    $attnStyle = 'color:var(--ds-amber, #f59e0b);';
    $filteredAgentName = $agentId !== null ? optional($agents->firstWhere('id', $agentId))->name : null;
    $prevAgent = null;
@endphp
@foreach($rows as $row)
@php
    $contact = $row['contact']; $matches = $row['matches']; $dot = $stateDot($contact->buyer_state);
    $agentName = $filteredAgentName ?? ($contact->core_matches_agent_name ?: 'Unassigned');
@endphp
@if($showContactAgent && $agentName !== $prevAgent)
@php $prevAgent = $agentName; @endphp
<div data-cm-group class="sticky top-0 z-10 px-3.5 py-1.5 text-[10px] font-semibold uppercase" style="letter-spacing:.06em; background:var(--surface-2); color:var(--text-secondary); border-bottom:1px solid var(--border);">{{ $agentName }}</div>
@endif
<button type="button" @click="pick({{ (int) $contact->id }})"
        @if($withTour && $loop->first) data-tour="re-core-matches-card" @endif
        class="list-row-hover w-full text-left flex items-center gap-2.5 px-3.5 py-2.5 border-0 cursor-pointer transition-colors"
        :style="sel === {{ (int) $contact->id }} ? 'background: var(--surface-2); box-shadow: inset 3px 0 0 var(--brand-icon, #0ea5e9); border-bottom: 1px solid var(--border);' : 'background: transparent; border-bottom: 1px solid var(--border);'">
    <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold flex-shrink-0"
         style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">{{ $contact->initials }}</div>
    <div class="min-w-0 flex-1">
        <div class="text-[13px] truncate" :class="sel === {{ (int) $contact->id }} ? 'font-semibold' : 'font-medium'" style="color:var(--text-primary);">{{ $contact->full_name }}</div>
        <div class="flex items-center justify-between gap-2 text-[11px]" style="color:var(--text-secondary);">
            <span class="truncate">
                @if($dot)<span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:{{ $dot }}; margin-right:5px; vertical-align:middle;"></span>{{ ucfirst($contact->buyer_state) }} · @endif{{ $matches->count() }} {{ Str::plural('search', $matches->count()) }}
            </span>
            @if($contact->last_contacted_at)
            <span class="whitespace-nowrap" title="Last contacted {{ $contact->last_contacted_at->format('d M Y, H:i') }}">{{ $contact->last_contacted_at->diffForHumans(null, true) }} ago</span>
            @else
            <span class="whitespace-nowrap font-medium" style="{{ $attnStyle }}">Never contacted</span>
            @endif
        </div>
    </div>
</button>
@endforeach
