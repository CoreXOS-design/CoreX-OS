{{-- Shared across index/agent/branch — expects $attention, $stateLabel, $money in scope. --}}
<div class="rounded-md mb-8" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Needs Attention</h2>
    </div>

    {{-- Group 1: cold/lost buyers, longest-stuck first --}}
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Cold &amp; Lost buyers — longest-stuck first</div>
        @if(empty($attention['attention']))
            <p class="text-xs" style="color: var(--text-muted);">Nothing here right now.</p>
        @else
            <div class="space-y-1">
                @foreach($attention['attention'] as $row)
                    <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
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
        @endif
    </div>

    {{-- Group 2: manually parked — separate, so it's not mistaken for neglect --}}
    @if(!empty($attention['parked']))
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Parked on purpose — not neglected</div>
        <div class="space-y-1">
            @foreach($attention['parked'] as $row)
                <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2); opacity: 0.8;">
                    <span>
                        <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                        <span style="color: var(--text-muted);"> — Parked by {{ $row['agent_name'] }}</span>
                    </span>
                    <span style="color: var(--text-muted);">{{ $row['days_in_state'] !== null ? $row['days_in_state'] . 'd' : '—' }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Group 3: viewings held with no feedback captured --}}
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Viewed, no feedback captured</div>
        @if(empty($attention['no_feedback']))
            <p class="text-xs" style="color: var(--text-muted);">Nothing here right now.</p>
        @else
            <div class="space-y-1">
                @foreach($attention['no_feedback'] as $row)
                    <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                        <span>
                            <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                            <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }}</span>
                        </span>
                        <span style="color: var(--text-muted);">{{ $row['days_ago'] }}d ago</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Group 4: recent losses, reason + value --}}
    <div class="px-4 py-3">
        <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Recently Lost — reason &amp; value</div>
        @if(empty($attention['recent_losses']))
            <p class="text-xs" style="color: var(--text-muted);">No losses recorded for this cohort.</p>
        @else
            <div class="space-y-1">
                @foreach($attention['recent_losses'] as $row)
                    <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                        <span>
                            <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                            <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }} · {{ $row['reason'] }}</span>
                        </span>
                        <span style="color: var(--text-muted);">{{ $row['value'] > 0 ? $money($row['value']) : '—' }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
