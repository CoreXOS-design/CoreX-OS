@extends('layouts.corex')

{{--
    AT-91 — WhatsApp Outreach Summary board (agents × outreach states).
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (CoreX tokens; var(--token,#fallback) pattern).
    Spec: .ai/specs/whatsapp-outreach-summary.md
--}}

@php
    // Column definitions — plain-English header + tooltip (F.8). Keys match
    // Contact::OUTREACH_BOARD_STATES + the drill-through ?outreach_state values.
    $columns = [
        ['key' => 'pending',             'label' => 'Awaiting reply',      'tip' => 'Consent request sent on WhatsApp — awaiting their reply.', 'token' => '--ds-orange,#d97706'],
        ['key' => 'confirmed',           'label' => 'Confirmed',           'tip' => 'Replied yes — opted in to hear from us.',                 'token' => '--ds-emerald,#059669'],
        ['key' => 'opt_out_no_response', 'label' => 'No response — lapsed', 'tip' => 'No reply within the window — suppressed, but not an explicit opt-out.', 'token' => '--ds-amber,#b45309'],
        ['key' => 'opted_out',           'label' => 'Opted out',           'tip' => 'Replied no — explicitly opted out of marketing.',          'token' => '--ds-crimson,#c41e3a'],
    ];

    $cellUrl = function ($agentId, $state) {
        return route('corex.contacts.index', [
            'agent_id'       => $agentId === null ? 'unassigned' : $agentId,
            'outreach_state' => $state,
            'channel'        => 'whatsapp',
        ]);
    };
    $totalUrl = function ($agentId) {
        return route('corex.contacts.index', [
            'agent_id' => $agentId === null ? 'unassigned' : $agentId,
            'channel'  => 'whatsapp',
        ]);
    };
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">WhatsApp Outreach Summary</h1>
                <p class="text-sm text-white/60">
                    Where every agent's WhatsApp seller pitches stand. Each count is the number of
                    contacts pitched on WhatsApp who are now in that state — click any number to open
                    that exact list.
                </p>
            </div>
        </div>
    </div>

    @if(count($rows) === 0)
        {{-- Empty state — no WhatsApp pitches in the viewer's scope yet. --}}
        <div class="rounded-md p-8 text-center"
             style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
            <p class="text-base font-semibold" style="color:var(--text-primary,#0b2a4a);">No WhatsApp outreach yet</p>
            <p class="mt-1 text-sm" style="color:var(--text-secondary,#6b7280);">
                Once sellers are pitched on WhatsApp from the contact composer, they'll appear here grouped by responsible agent.
            </p>
            <a href="{{ route('corex.contacts.index') }}" class="corex-btn-outline text-sm mt-4 inline-flex">Go to Contacts</a>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="background:var(--surface-2,#f8fafc); border-bottom:1px solid var(--border,#e5e7eb);">
                            <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Agent</th>
                            @foreach($columns as $col)
                                <th class="text-right font-semibold px-4 py-3 whitespace-nowrap"
                                    style="color:var(--text-primary,#0b2a4a);"
                                    title="{{ $col['tip'] }}">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="inline-block w-2 h-2 rounded-full" style="background:var({{ $col['token'] }});"></span>
                                        {{ $col['label'] }}
                                    </span>
                                </th>
                            @endforeach
                            <th class="text-right font-semibold px-4 py-3 whitespace-nowrap"
                                style="color:var(--text-primary,#0b2a4a); border-left:1px solid var(--border,#e5e7eb);"
                                title="Every contact pitched on WhatsApp by this agent.">
                                Total contacted
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr style="border-bottom:1px solid var(--border,#eef2f6);">
                                <td class="px-4 py-3 font-medium whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">
                                    @if($row['agent_id'] === null)
                                        <span title="Contacts with a WhatsApp pitch but no responsible agent assigned.">{{ $row['agent_name'] }}</span>
                                    @else
                                        {{ $row['agent_name'] }}
                                    @endif
                                </td>

                                @foreach($columns as $col)
                                    @php $val = $row[$col['key']]; @endphp
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        @if($val > 0)
                                            <a href="{{ $cellUrl($row['agent_id'], $col['key']) }}"
                                               class="font-semibold underline-offset-2 hover:underline"
                                               style="color:var(--brand-link,#0e7490);"
                                               title="Open the {{ strtolower($col['label']) }} list for {{ $row['agent_name'] }}">{{ number_format($val) }}</a>
                                        @else
                                            <span style="color:var(--text-tertiary,#9ca3af);">0</span>
                                        @endif
                                    </td>
                                @endforeach

                                {{-- Total contacted (whole WhatsApp population for the agent). --}}
                                <td class="px-4 py-3 text-right tabular-nums" style="border-left:1px solid var(--border,#eef2f6);">
                                    @if($row['total'] > 0)
                                        <a href="{{ $totalUrl($row['agent_id']) }}"
                                           class="font-bold underline-offset-2 hover:underline"
                                           style="color:var(--text-primary,#0b2a4a);"
                                           title="Open every WhatsApp-pitched contact for {{ $row['agent_name'] }}">{{ number_format($row['total']) }}</a>
                                    @else
                                        <span style="color:var(--text-tertiary,#9ca3af);">0</span>
                                    @endif

                                    {{-- AT-91 §3.1 — the 'awaiting' leftover (clicked / no reply yet, or
                                         legacy send): not one of the 4 states, surfaced here so the row
                                         reconciles and nothing is silently dropped. --}}
                                    @if($row['awaiting'] > 0)
                                        <a href="{{ $cellUrl($row['agent_id'], 'awaiting') }}"
                                           class="block text-[0.6875rem] font-medium mt-0.5 underline-offset-2 hover:underline"
                                           style="color:var(--text-secondary,#6b7280);"
                                           title="Pitched on WhatsApp and engaged (e.g. clicked the link) but not yet replied yes or no — no consent decision recorded. Included in the total above.">
                                            +{{ number_format($row['awaiting']) }} awaiting reply
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--surface-2,#f8fafc); border-top:2px solid var(--border,#e5e7eb);">
                            <td class="px-4 py-3 font-bold whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">All agents</td>
                            @foreach($columns as $col)
                                <td class="px-4 py-3 text-right font-bold tabular-nums" style="color:var(--text-primary,#0b2a4a);">
                                    {{ number_format($totals[$col['key']]) }}
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-right font-bold tabular-nums"
                                style="color:var(--text-primary,#0b2a4a); border-left:1px solid var(--border,#e5e7eb);">
                                {{ number_format($totals['total']) }}
                                @if($hasAwaiting)
                                    <span class="block text-[0.6875rem] font-medium mt-0.5" style="color:var(--text-secondary,#6b7280);">
                                        +{{ number_format($totals['awaiting']) }} awaiting reply
                                    </span>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="text-xs" style="color:var(--text-tertiary,#9ca3af);">
            Counts respect your access: agents see their own pipeline, branch managers their branch, admins the whole agency.
            “Awaiting reply” (sub-figure on the total) means the contact clicked or engaged but hasn’t yet said yes or no.
        </p>
    @endif
</div>
@endsection
