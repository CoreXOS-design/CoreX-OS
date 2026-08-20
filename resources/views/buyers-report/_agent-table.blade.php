{{-- Shared between index and branch pages — expects $report, $money in scope,
     and drill() on the parent x-data component. --}}
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
                        <td class="px-3 py-2 font-medium">
                            <a href="{{ route('buyers-report.agent', $a['user_id']) }}" style="color: var(--brand, #3b82f6);">{{ $a['name'] }}</a>
                        </td>
                        <td class="px-3 py-2" style="color: var(--text-muted);">
                            @if($a['branch_id'])
                                <a href="{{ route('buyers-report.branch', $a['branch_id']) }}" style="color: var(--brand, #3b82f6);">{{ $a['branch_label'] }}</a>
                            @else
                                {{ $a['branch_label'] }}
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('buyers', @js($a['name'] . ' — Buyers held'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['buyers'] ?? 0 }}</button></td>
                        <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('buyers_added', @js($a['name'] . ' — Buyers added'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['buyers_added'] ?? 0 }}</button></td>
                        <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('buyers_won', @js($a['name'] . ' — Buyers won'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['buyers_won'] ?? 0 }}</button></td>
                        <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('appointments', @js($a['name'] . ' — Appointments'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['appointments'] ?? 0 }}</button></td>
                        <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lost', @js($a['name'] . ' — Buyers lost'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['lost'] ?? 0 }}</button></td>
                        <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lost', @js($a['name'] . ' — Buyers lost'), {{ (int) $a['user_id'] }})">{{ empty($a['metrics']['lost_value_captured']) ? 'Not captured' : $money($a['metrics']['lost_value'] ?? 0) }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-6 text-center" style="color: var(--text-muted);">No agents in this scope.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
