{{-- Shared across index/agent/branch — expects $report, $money in scope. Tile clicks
     call the drill() Alpine method on the parent x-data component (buyersReport()). --}}
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
        @php
            $drillMetric = $key === 'lost_value' ? 'lost' : $key;
            $c = $comparison['company'][$key] ?? null;
        @endphp
        <button type="button" @click="drill('{{ $drillMetric }}', @js($label))"
                class="rounded-md px-3 py-3 text-left" style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;"
                title="Click to see the detail">
            <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
            <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">
                {{ $key === 'lost_value' ? $money($m[$key] ?? 0) : number_format((float) ($m[$key] ?? 0)) }}
            </div>
            <x-performance-delta :c="$c" :phrase="$comparisonMeta['phrase'] ?? ''" :money="$key === 'lost_value'" />
        </button>
    @endforeach
</div>
<p class="text-[11px] mb-6" style="color: var(--text-muted);">
    Emails/WhatsApps are a floor, not a true count — only messages sent through a connected device or mailbox are captured.
</p>
