{{-- Lead response (Johan, 2026-10-07; .ai/specs/lead-response-time.md) — shared by index / agent / branch.
     Built exactly like the other sections: every figure is a button that calls drill() on the parent
     buyersReport() component and opens the detail popup (the leads behind the number). Expects $leadResponse
     (LeadResponseService::report()) and the parent x-data. Figures come from the ONE LeadResponseService
     calculation — the popup lists the same per-lead results, so a number and its list cannot disagree. --}}
@php
    $lr = $leadResponse;
    $lrSvc = app(\App\Services\LeadResponse\LeadResponseService::class);
    $lrc = $lr['company'];
    $fmtMin = fn ($v) => $v === null ? '—' : $lrSvc->formatMinutes((int) $v);
@endphp
<div class="mb-6">
    <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Lead response</h2>
    <p class="text-[11px] mb-3" style="color: var(--text-muted);">
        Enquiries from portals, the website and shared links, in this period. Target: first contact within {{ $lr['target'] }} min,
        counting {{ $lrSvc->hoursSummary($lr['hours']) }}. Contact = the "Contacted" action, a message sent, a link shared, or feedback on an appointment — a note alone does not count.
        @if(($lrc['not_measured'] ?? 0) > 0) {{ number_format($lrc['not_measured']) }} earlier enquiries from before response tracking began are not measured and are left out. @endif
    </p>

    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
        @foreach([
            ['received', 'Leads received', $lrc['received']],
            ['in_target', 'Responded in target', $lrc['in_target']],
            ['late', 'Responded late', $lrc['late']],
            ['waiting', 'Not yet contacted', $lrc['waiting']],
            ['responded', 'Average response', $fmtMin($lrc['avg'])],
            ['responded', 'Median response', $fmtMin($lrc['median'])],
        ] as [$sub, $label, $val])
            <button type="button" @click="drill('lead_response', @js($label), null, '{{ $sub }}')"
                    class="group rounded-md px-3 py-3 text-left transition-colors"
                    style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;"
                    onmouseover="this.style.borderColor='var(--brand-icon, #0ea5e9)'; this.style.background='var(--surface-2)';"
                    onmouseout="this.style.borderColor='var(--border)'; this.style.background='var(--surface)';"
                    title="Click to see the leads">
                <div class="flex items-center justify-between">
                    <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
                    <span class="text-[11px] opacity-0 group-hover:opacity-100 transition-opacity" style="color: var(--brand-icon, #0ea5e9);">view &rarr;</span>
                </div>
                <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">{{ is_int($val) ? number_format($val) : $val }}</div>
            </button>
        @endforeach
    </div>
    @if(($lrc['overdue'] ?? 0) > 0)
        <p class="text-[11px] mb-4" style="color: var(--text-muted);">
            <button type="button" class="underline" @click="drill('lead_response', 'Not yet contacted — past target', null, 'overdue')">{{ number_format($lrc['overdue']) }}</button>
            of the {{ number_format($lrc['waiting']) }} not yet contacted are already past the target.
        </p>
    @endif

    <div class="rounded-md overflow-hidden mb-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Lead response by agent</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs ds-table">
                <thead>
                    <tr style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-3 py-2">Agent</th>
                        <th class="text-right px-3 py-2">Received</th>
                        <th class="text-right px-3 py-2">In target</th>
                        <th class="text-right px-3 py-2">Late</th>
                        <th class="text-right px-3 py-2">Not yet</th>
                        <th class="text-right px-3 py-2">Average</th>
                        <th class="text-right px-3 py-2">Median</th>
                    </tr>
                </thead>
                <tbody>
                    @php $lrRows = collect($report['agents'])->filter(fn ($a) => ($lr['agents'][(int) $a['user_id']]['received'] ?? 0) > 0); @endphp
                    @forelse($lrRows as $a)
                        @php $s = $lr['agents'][(int) $a['user_id']]; $uid = (int) $a['user_id']; @endphp
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-3 py-2 font-medium">{{ $a['name'] }}</td>
                            @foreach([['received', $s['received']], ['in_target', $s['in_target']], ['late', $s['late']], ['waiting', $s['waiting']], ['responded', $fmtMin($s['avg'])], ['responded', $fmtMin($s['median'])]] as [$sub, $val])
                                <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lead_response', @js($a['name'] . ' — leads'), {{ $uid }}, '{{ $sub }}')">{{ is_int($val) ? number_format($val) : $val }}</button></td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center" style="color: var(--text-muted);">No measured enquiries for these agents in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Lead response by source</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs ds-table">
                <thead>
                    <tr style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-3 py-2">Source</th>
                        <th class="text-right px-3 py-2">Received</th>
                        <th class="text-right px-3 py-2">In target</th>
                        <th class="text-right px-3 py-2">Late</th>
                        <th class="text-right px-3 py-2">Not yet</th>
                        <th class="text-right px-3 py-2">Average</th>
                        <th class="text-right px-3 py-2">Median</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(collect($lr['sources'])->filter(fn ($s) => $s['received'] > 0) as $portal => $s)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-3 py-2 font-medium">{{ $s['label'] }}</td>
                            @foreach([['received', $s['received']], ['in_target', $s['in_target']], ['late', $s['late']], ['waiting', $s['waiting']], ['responded', $fmtMin($s['avg'])], ['responded', $fmtMin($s['median'])]] as [$sub, $val])
                                <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lead_response', @js($s['label'] . ' — leads'), null, '{{ $sub }}', null, '{{ $portal }}')">{{ is_int($val) ? number_format($val) : $val }}</button></td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center" style="color: var(--text-muted);">No measured enquiries in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
