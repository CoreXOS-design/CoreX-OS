@extends('layouts.corex-app')

@section('title', 'Buyers Report')

@section('corex-content')
@php
    // 2026-08-20 (Johan) — first pass: Needs Attention list + tiles + per-agent
    // table, real data throughout. Tile-click modal, agent/branch drill-down
    // pages, and period comparison wired into the UI are the second pass —
    // deliberately not here yet, so there's something real to look at today.
    $m = $report['company'];
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Hot', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
@endphp

<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold" style="color: var(--text-primary);">Buyers Report</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                {{ match($scope->level) { 'own' => 'Your buyers', 'branch' => 'Your branch', default => 'Whole agency' } }}
                · {{ ucfirst(str_replace('_', ' ', $preset)) }}
            </p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <select name="period" onchange="this.form.submit()" class="text-xs rounded-md px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach($presets as $p)
                    <option value="{{ $p }}" @selected($preset === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- ══════ NEEDS ATTENTION — problems first ══════ --}}
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

    {{-- ══════ THE NUMBERS ══════ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        @foreach([
            ['buyers', 'Buyers held'],
            ['buyers_added', 'Buyers added'],
            ['buyers_won', 'Buyers won'],
            ['appointments', 'Appointments'],
            ['comms_email', 'Emails'],
            ['comms_whatsapp', 'WhatsApps'],
            ['lost', 'Buyers lost'],
            ['lost_value', 'Value lost'],
        ] as [$key, $label])
            <div class="rounded-md px-3 py-3" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
                <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">
                    {{ $key === 'lost_value' ? $money($m[$key] ?? 0) : number_format((float) ($m[$key] ?? 0)) }}
                </div>
            </div>
        @endforeach
    </div>
    <p class="text-[11px] mb-6" style="color: var(--text-muted);">
        Emails/WhatsApps are a floor, not a true count — only messages sent through a connected device or mailbox are captured.
    </p>

    {{-- ══════ PER-AGENT TABLE ══════ --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">By agent</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-3 py-2">Agent</th>
                        <th class="text-left px-3 py-2">Branch</th>
                        <th class="text-right px-3 py-2">Held</th>
                        <th class="text-right px-3 py-2">Added</th>
                        <th class="text-right px-3 py-2">Won</th>
                        <th class="text-right px-3 py-2">Appts</th>
                        <th class="text-right px-3 py-2">Lost</th>
                        <th class="text-right px-3 py-2">Value lost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['agents'] as $a)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $a['name'] }}</td>
                            <td class="px-3 py-2" style="color: var(--text-muted);">{{ $a['branch_label'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $a['metrics']['buyers'] ?? 0 }}</td>
                            <td class="px-3 py-2 text-right">{{ $a['metrics']['buyers_added'] ?? 0 }}</td>
                            <td class="px-3 py-2 text-right">{{ $a['metrics']['buyers_won'] ?? 0 }}</td>
                            <td class="px-3 py-2 text-right">{{ $a['metrics']['appointments'] ?? 0 }}</td>
                            <td class="px-3 py-2 text-right">{{ $a['metrics']['lost'] ?? 0 }}</td>
                            <td class="px-3 py-2 text-right">{{ $money($a['metrics']['lost_value'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center" style="color: var(--text-muted);">No agents in this scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
