{{--
    AT-91 — WhatsApp Outreach Summary board body (agents × outreach states).
    Extracted to a partial (2026-06-26) so it can be reused as Tab 2 of the
    unified Outreach & Canvassing board without duplicating the matrix markup.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (CoreX tokens; var(--token,#fallback) pattern).
    Receives: $rows, $totals, $hasAwaiting.
    Optional: $embedded (bool, default false) — when true the partial is rendered
    inside another page (Tab 2 of Outreach & Canvassing) that already carries its
    own page header, so this partial's own branded header block is suppressed to
    avoid a duplicate blue banner mid-page.
--}}
@php
    $embedded = $embedded ?? false;
    // Column definitions — plain-English header + tooltip (F.8). Keys match
    // Contact::OUTREACH_BOARD_STATES + the drill-through ?outreach_state values.
    $columns = [
        ['key' => 'pending',             'label' => 'Awaiting reply',      'tip' => 'Consent request sent on WhatsApp — awaiting their reply.', 'token' => '--ds-orange,#ea580c'],
        ['key' => 'confirmed',           'label' => 'Confirmed',           'tip' => 'Replied yes — opted in to hear from us.',                 'token' => '--ds-green,#059669'],
        ['key' => 'opt_out_no_response', 'label' => 'No response — lapsed', 'tip' => 'No reply within the window — suppressed, but not an explicit opt-out.', 'token' => '--ds-amber,#f59e0b'],
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

<div class="w-full space-y-5">

    {{-- Page header — suppressed when embedded (the host page carries its own). --}}
    @unless($embedded)
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="os-intro">
                <h1 class="text-xl font-bold text-white leading-tight">WhatsApp Outreach Summary</h1>
                <p class="text-sm text-white/60">
                    Where every agent's WhatsApp seller pitches stand. Each count is the number of
                    contacts pitched on WhatsApp who are now in that state — click any number to open
                    that exact list.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>
    @endunless

    @if(count($rows) === 0)
        {{-- Empty state — no WhatsApp pitches in the viewer's scope yet. --}}
        <div class="rounded-md py-12 px-6 text-center"
             style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary,#111827);">No WhatsApp outreach yet</h3>
            <p class="text-sm mb-4" style="color:var(--text-muted,#9ca3af);">
                Once sellers are pitched on WhatsApp from the contact composer, they'll appear here grouped by responsible agent.
            </p>
            <a href="{{ route('corex.contacts.index') }}" class="corex-btn-outline text-sm inline-flex">Go to Contacts</a>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-tour="os-board">
                    <thead>
                        <tr data-tour="os-columns" style="background:var(--surface-2,#f8fafc); border-bottom:1px solid var(--border,#e5e7eb);">
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
                                data-tour="os-total"
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
                                               style="color:var(--brand-icon,#0ea5e9);"
                                               title="Open the {{ strtolower($col['label']) }} list for {{ $row['agent_name'] }}">{{ number_format($val) }}</a>
                                        @else
                                            <span style="color:var(--text-muted,#9ca3af);">0</span>
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
                                        <span style="color:var(--text-muted,#9ca3af);">0</span>
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

        <p class="text-xs" style="color:var(--text-muted,#9ca3af);">
            Counts respect your access: agents see their own pipeline, branch managers their branch, admins the whole agency.
            “Awaiting reply” (sub-figure on the total) means the contact clicked or engaged but hasn’t yet said yes or no.
        </p>
    @endif
</div>
