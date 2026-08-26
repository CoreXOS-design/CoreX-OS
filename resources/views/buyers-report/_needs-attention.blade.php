{{-- Shared across index/agent/branch — expects $attention, $stateLabel, $money in scope.
     Johan (2026-08-20, live review): "a wall of names" — 400+ rows rendered
     before any tile. Every group now shows the top 10 only, with a "view all"
     toggle for the rest (already loaded server-side, just hidden). --}}
@php $topN = 10; @endphp
<div class="rounded-md mb-8" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Needs Attention</h2>
    </div>

    {{-- Group 1: cold/lost buyers, longest-stuck first --}}
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);" x-data="{ showAll: false }">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Cold &amp; Lost buyers — longest-stuck first</div>
        @if(empty($attention['attention']))
            <p class="text-xs" style="color: var(--text-muted);">Nothing here right now.</p>
        @else
            <div class="space-y-1">
                @foreach($attention['attention'] as $row)
                    <div @if($loop->iteration > $topN) x-show="showAll" @endif
                         class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                        <span>
                            <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                            <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="ds-badge {{ $row['state'] === 'lost' ? 'ds-badge-danger' : 'ds-badge-warning' }}">{{ $stateLabel($row['state']) }}</span>
                            <span style="color: var(--text-muted);">{{ $row['days_in_state'] !== null ? $row['days_in_state'] . 'd' : '—' }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
            @if(count($attention['attention']) > $topN)
                <button type="button" @click="showAll = !showAll" class="text-xs mt-2 underline" style="color: var(--brand-icon, #0ea5e9);"
                        x-text="showAll ? 'Show fewer' : 'View all {{ count($attention['attention']) }}'"></button>
            @endif
        @endif
    </div>

    {{-- Group 2: manually parked — separate, so it's not mistaken for neglect --}}
    @if(!empty($attention['parked']))
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);" x-data="{ showAll: false }">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Parked on purpose — not neglected</div>
        <div class="space-y-1">
            @foreach($attention['parked'] as $row)
                <div @if($loop->iteration > $topN) x-show="showAll" @endif
                     class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2); opacity: 0.8;">
                    <span>
                        <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                        <span style="color: var(--text-muted);"> — Parked by {{ $row['agent_name'] }}</span>
                    </span>
                    <span style="color: var(--text-muted);">{{ $row['days_in_state'] !== null ? $row['days_in_state'] . 'd' : '—' }}</span>
                </div>
            @endforeach
        </div>
        @if(count($attention['parked']) > $topN)
            <button type="button" @click="showAll = !showAll" class="text-xs mt-2 underline" style="color: var(--brand-icon, #0ea5e9);"
                    x-text="showAll ? 'Show fewer' : 'View all {{ count($attention['parked']) }}'"></button>
        @endif
    </div>
    @endif

    {{-- Group 3: viewings held with no feedback captured --}}
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);" x-data="{ showAll: false }">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Viewed, no feedback captured</div>
        @if(empty($attention['no_feedback']))
            <p class="text-xs" style="color: var(--text-muted);">Nothing here right now.</p>
        @else
            <div class="space-y-1">
                @foreach($attention['no_feedback'] as $row)
                    <div @if($loop->iteration > $topN) x-show="showAll" @endif
                         class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                        <span>
                            <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                            <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }}</span>
                        </span>
                        <span style="color: var(--text-muted);">{{ $row['days_ago'] }}d ago</span>
                    </div>
                @endforeach
            </div>
            @if(count($attention['no_feedback']) > $topN)
                <button type="button" @click="showAll = !showAll" class="text-xs mt-2 underline" style="color: var(--brand-icon, #0ea5e9);"
                        x-text="showAll ? 'Show fewer' : 'View all {{ count($attention['no_feedback']) }}'"></button>
            @endif
        @endif
    </div>

    {{-- Group 4: recent losses, reason + value + latest note --}}
    <div class="px-4 py-3" x-data="{ showAll: false }">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Recently Lost — reason &amp; latest note</div>
        @if(empty($attention['recent_losses']))
            <p class="text-xs" style="color: var(--text-muted);">No losses recorded for this cohort.</p>
        @else
            <div class="space-y-1">
                @foreach($attention['recent_losses'] as $row)
                    <div @if($loop->iteration > $topN) x-show="showAll" @endif
                         class="text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                        <div class="flex items-center justify-between">
                            <span>
                                <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                                <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }} · {{ $row['reason'] }}</span>
                            </span>
                            <span style="color: var(--text-muted);">{{ $row['value'] > 0 ? $money($row['value']) : ($row['value_captured'] ?? true ? '—' : 'Not captured') }}</span>
                        </div>
                        @if(!empty($row['latest_note']))
                            <div class="mt-1 pl-0.5" style="color: var(--text-secondary, #4b5563); border-left: 2px solid var(--border); padding-left: 6px;">
                                “{{ \Illuminate\Support\Str::limit($row['latest_note'], 140) }}”
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            @if(count($attention['recent_losses']) > $topN)
                <button type="button" @click="showAll = !showAll" class="text-xs mt-2 underline" style="color: var(--brand-icon, #0ea5e9);"
                        x-text="showAll ? 'Show fewer' : 'View all {{ count($attention['recent_losses']) }}'"></button>
            @endif
        @endif
    </div>
</div>
