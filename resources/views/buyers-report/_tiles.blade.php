{{-- Shared across index/agent/branch — expects $report, $money in scope. Tile clicks
     call the drill() Alpine method on the parent x-data component (buyersReport()).
     Johan (2026-08-20, live review): tiles "read as flat boxes — nothing signals
     they are clickable" — hover state + cursor + a subtle affordance icon added.
     Also: "You have a value that is 0 across the board?" — lost_value shows
     "Not captured" (not R0) when nothing was ever captured.
     Johan (2026-08-20, lost-section redesign): "the agent who lost it is
     critical — lost, real losses / auto losses, then click real losses and
     shows agent summary of losses and clicking that shows actual buyers
     lost." The Buyers lost tile is no longer one number with a caption — it
     is TWO independently-clickable numbers (Real, primary; Auto, secondary),
     each drilling straight into a per-agent summary for that subtype
     (drill()'s 4th arg), because the split IS the tile face — there is no
     separate flat list beneath it any more. Appointments: a fixed COUNT(*)
     query has no NULL-vs-zero ambiguity the way SUM() did for lost_value —
     a genuine zero here IS a genuine zero, so it renders plainly, same as
     buyers_won/emails/WhatsApps. --}}
@php $m = $report['company']; @endphp
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
        @if($key === 'lost')
            @php $lostC = $comparison['company']['lost'] ?? null; @endphp
            <div class="rounded-md px-3 py-3 text-left" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
                <div class="flex items-end gap-4 mt-0.5">
                    <button type="button" @click="drill('lost', 'Real losses', null, 'real')"
                            class="group text-left" style="cursor: pointer;" title="Click to see the detail">
                        <div class="text-lg font-semibold" style="color: var(--text-primary);">{{ number_format((float) ($m['lost_real'] ?? 0)) }}</div>
                        <div class="text-[10px]" style="color: var(--text-muted);">
                            Real <span class="opacity-0 group-hover:opacity-100 transition-opacity" style="color: var(--brand-icon, #0ea5e9);">view &rarr;</span>
                        </div>
                    </button>
                    <button type="button" @click="drill('lost', 'Auto losses (no activity)', null, 'auto')"
                            class="group text-left" style="cursor: pointer;" title="Click to see the detail">
                        <div class="text-base font-semibold" style="color: var(--text-muted);">{{ number_format((float) ($m['lost_auto'] ?? 0)) }}</div>
                        <div class="text-[10px]" style="color: var(--text-muted);">
                            Auto <span class="opacity-0 group-hover:opacity-100 transition-opacity" style="color: var(--brand-icon, #0ea5e9);">view &rarr;</span>
                        </div>
                    </button>
                </div>
                <x-performance-delta :c="$lostC" :phrase="$comparisonMeta['phrase'] ?? ''" />
            </div>
        @else
            @php
                $drillMetric = $key === 'lost_value' ? 'lost' : $key;
                $c = $comparison['company'][$key] ?? null;
                $notCaptured = $key === 'lost_value' && empty($m['lost_value_captured']);
            @endphp
            <button type="button" @click="drill('{{ $drillMetric }}', @js($label){{ $key === 'lost_value' ? ", null, 'real'" : '' }})"
                    class="group rounded-md px-3 py-3 text-left transition-colors"
                    style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;"
                    onmouseover="this.style.borderColor='var(--brand-icon, #0ea5e9)'; this.style.background='var(--surface-2)';"
                    onmouseout="this.style.borderColor='var(--border)'; this.style.background='var(--surface)';"
                    title="Click to see the detail">
                <div class="flex items-center justify-between">
                    <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
                    <span class="text-[11px] opacity-0 group-hover:opacity-100 transition-opacity" style="color: var(--brand-icon, #0ea5e9);">view &rarr;</span>
                </div>
                <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">
                    @if($notCaptured)
                        <span style="color: var(--text-muted); font-size: 0.95rem;">Not captured</span>
                    @else
                        {{ $key === 'lost_value' ? $money($m[$key] ?? 0) : number_format((float) ($m[$key] ?? 0)) }}
                    @endif
                </div>
                @if($key === 'lost_value')
                    <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">from real losses only</div>
                @endif
                @if(!empty($type) && in_array($key, ['buyers_won', 'appointments', 'comms_email', 'comms_whatsapp'], true))
                    <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">all types</div>
                @endif
                @if(!$notCaptured)
                    <x-performance-delta :c="$c" :phrase="$comparisonMeta['phrase'] ?? ''" :money="$key === 'lost_value'" />
                @endif
            </button>
        @endif
    @endforeach
</div>
<p class="text-[11px] mb-6" style="color: var(--text-muted);">
    Emails/WhatsApps are a floor, not a true count — only messages sent through a connected device or mailbox are captured.
</p>
