{{-- Lead response (Johan, 2026-10-07; .ai/specs/lead-response-time.md) — Performance & ROI report. Same ONE
     calculation as the Buyers Report (LeadResponseService), drawn with this page's own tile style and its own
     drill() modal. Every figure is clickable and lists the leads behind it. Expects $leadResponse, $report. --}}
@php
    $lr = $leadResponse;
    $lrSvc = app(\App\Services\LeadResponse\LeadResponseService::class);
    $lrc = $lr['company'];
    $fmtMin = fn ($v) => $v === null ? '—' : $lrSvc->formatMinutes((int) $v);
@endphp
<div>
    <h2 class="text-xs font-bold uppercase tracking-widest mb-1" style="color:var(--text-muted);">Lead response <span class="normal-case font-normal">· {{ $report['period']['label'] ?? '' }}</span></h2>
    <p class="text-[11px] mb-2" style="color:var(--text-muted);">
        Enquiries from portals, the website and shared links. Target: first contact within {{ $lr['target'] }} min, counting {{ $lrSvc->hoursSummary($lr['hours']) }}.
        Contact = the "Contacted" action, a message sent, a link shared, or feedback on an appointment — a note alone does not count.
        @if(($lrc['not_measured'] ?? 0) > 0) {{ number_format($lrc['not_measured']) }} earlier enquiries (before response tracking began) are not measured. @endif
    </p>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-2">
        @foreach([
            ['received', 'Leads received', $lrc['received']],
            ['in_target', 'Responded in target', $lrc['in_target']],
            ['late', 'Responded late', $lrc['late']],
            ['waiting', 'Not yet contacted', $lrc['waiting']],
            ['responded', 'Average response', $fmtMin($lrc['avg'])],
            ['responded', 'Median response', $fmtMin($lrc['median'])],
        ] as [$sub, $label, $val])
            <button type="button" @click="drill('lead_response', 'company', null, @js($label), 'subtype={{ $sub }}')"
                    class="rounded p-3 text-left" style="background:var(--surface-2); border:1px solid var(--border); cursor:pointer;" title="Click to see the leads">
                <div class="text-xl font-bold" style="color:var(--text-primary);">{{ is_int($val) ? number_format($val) : $val }}</div>
                <div class="text-[11px]" style="color:var(--text-muted);">{{ $label }}</div>
            </button>
        @endforeach
    </div>
    @if(($lrc['overdue'] ?? 0) > 0)
        <p class="text-[11px] mb-3" style="color:var(--text-muted);">
            <button type="button" class="underline" @click="drill('lead_response', 'company', null, 'Not yet contacted — past target', 'subtype=overdue')">{{ number_format($lrc['overdue']) }}</button>
            of the {{ number_format($lrc['waiting']) }} not yet contacted are already past the target.
        </p>
    @endif

    <div class="overflow-x-auto rounded mb-3" style="border:1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="background:var(--surface-2); color:var(--text-muted);">
                    <th class="text-left px-3 py-2">Agent</th>
                    <th class="text-right px-3 py-2">Received</th><th class="text-right px-3 py-2">In target</th><th class="text-right px-3 py-2">Late</th>
                    <th class="text-right px-3 py-2">Not yet</th><th class="text-right px-3 py-2">Average</th><th class="text-right px-3 py-2">Median</th>
                </tr>
            </thead>
            <tbody>
                @forelse(collect($report['agents'])->filter(fn ($a) => ($lr['agents'][(int) $a['user_id']]['received'] ?? 0) > 0) as $a)
                    @php $s = $lr['agents'][(int) $a['user_id']]; $uid = (int) $a['user_id']; @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-3 py-2 font-medium">{{ $a['name'] }}</td>
                        @foreach([['received', $s['received']], ['in_target', $s['in_target']], ['late', $s['late']], ['waiting', $s['waiting']], ['responded', $fmtMin($s['avg'])], ['responded', $fmtMin($s['median'])]] as [$sub, $val])
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lead_response', 'agent', {{ $uid }}, @js($a['name'] . ' — leads'), 'subtype={{ $sub }}')">{{ is_int($val) ? number_format($val) : $val }}</button></td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center" style="color:var(--text-muted);">No measured enquiries for these agents in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="background:var(--surface-2); color:var(--text-muted);">
                    <th class="text-left px-3 py-2">Source</th>
                    <th class="text-right px-3 py-2">Received</th><th class="text-right px-3 py-2">In target</th><th class="text-right px-3 py-2">Late</th>
                    <th class="text-right px-3 py-2">Not yet</th><th class="text-right px-3 py-2">Average</th><th class="text-right px-3 py-2">Median</th>
                </tr>
            </thead>
            <tbody>
                @forelse(collect($lr['sources'])->filter(fn ($s) => $s['received'] > 0) as $portal => $s)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-3 py-2 font-medium">{{ $s['label'] }}</td>
                        @foreach([['received', $s['received']], ['in_target', $s['in_target']], ['late', $s['late']], ['waiting', $s['waiting']], ['responded', $fmtMin($s['avg'])], ['responded', $fmtMin($s['median'])]] as [$sub, $val])
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lead_response', 'company', null, @js($s['label'] . ' — leads'), 'subtype={{ $sub }}&source={{ $portal }}')">{{ is_int($val) ? number_format($val) : $val }}</button></td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center" style="color:var(--text-muted);">No measured enquiries in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
