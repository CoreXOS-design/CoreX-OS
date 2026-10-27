{{--
    RMCP show page - "Board approval" panel (.ai/specs/compliance.md "RMCP board approval letter").
    One voice, one next action per state. Needs: $version, $board (RmcpBoardApprovalService::panel), $myBoardLine (or null).
--}}
@php
    $canManage = auth()->user()->hasPermission('manage_rmcp_board_approval');
    $canApprove = auth()->user()->hasPermission('approve_rmcp');
    $letter = $board['letter'];
    $members = $board['members'];
    $eventWords = [
        'sent' => 'Letter sent to the board', 'resent' => 'Letter sent again', 'superseded' => 'Replaced by a newer letter',
        'cancelled' => 'Letter cancelled', 'code_sent' => 'Code emailed', 'code_verified' => 'Code confirmed', 'code_failed' => 'Wrong code entered',
        'viewed' => 'Opened the letter', 'signed' => 'Signed', 'letter_completed' => 'Everyone has signed',
        'pdf_filed' => 'Signed letter PDF filed', 'approved' => 'RMCP approved',
    ];
    $badge = fn ($s) => match ($s) { 'signed' => 'ds-badge-success', 'viewed' => 'ds-badge-warning', default => 'ds-badge-default' };
    $word = fn ($s) => match ($s) { 'signed' => 'Signed', 'viewed' => 'Opened', 'sent' => 'Sent', default => 'Not sent yet' };
@endphp
<div id="board-approval" class="mb-4 rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Board approval</h2>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">The board signs one approval letter for this version. When everyone has signed, the RMCP can be approved.</p>
        </div>
        @if($myBoardLine)
            <a href="{{ route('compliance.rmcp.board.sign', $version) }}" class="corex-btn-primary text-xs">Sign the letter</a>
        @endif
    </div>

    @if($version->canBeEdited())
        {{-- ── Which state are we in? ── --}}
        @if($board['stale'] && $letter)
            <div class="mt-3 px-3 py-2 text-xs rounded-md" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent); color: var(--text-primary);">
                The RMCP was changed after this letter was sent. The board must sign the text as it is now, so send a new letter.
            </div>
        @endif

        @if($letter && $letter->isOpen())
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-xs" style="color: var(--text-secondary);">
                    <thead><tr class="text-left" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="py-1.5 pr-3">Name</th><th class="py-1.5 pr-3">Title</th><th class="py-1.5 pr-3">Status</th><th class="py-1.5 pr-3">Dates</th><th class="py-1.5"></th>
                    </tr></thead>
                    <tbody>
                    @foreach($letter->signatures as $sig)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="py-2 pr-3" style="color: var(--text-primary);">{{ $sig->member_name }}<div style="color: var(--text-muted);">{{ $sig->member_email }}</div></td>
                            <td class="py-2 pr-3">{{ $sig->member_title }}</td>
                            <td class="py-2 pr-3"><span class="ds-badge {{ $badge($sig->status) }}">{{ $word($sig->status) }}</span>
                                @if($sig->last_send_error && ! $sig->isSigned())<div style="color: var(--ds-crimson);">The email could not be sent.</div>@endif</td>
                            <td class="py-2 pr-3" style="color: var(--text-muted);">
                                @if($sig->sent_at)Sent {{ $sig->sent_at->format('d M H:i') }}<br>@endif
                                @if($sig->viewed_at)Opened {{ $sig->viewed_at->format('d M H:i') }}<br>@endif
                                @if($sig->signed_at)Signed {{ $sig->signed_at->format('d M H:i') }}@endif
                            </td>
                            <td class="py-2 text-right">
                                @if($canManage && ! $sig->isSigned())
                                    <form method="POST" action="{{ route('compliance.rmcp.board.resend', [$version, $sig]) }}">@csrf
                                        <button type="submit" class="corex-btn-outline text-xs">{{ $sig->sent_at ? 'Send again' : 'Send' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @php $waiting = $letter->signatures->where('status', '!=', 'signed')->pluck('member_name')->implode(', '); @endphp
            <p class="mt-3 text-xs" style="color: var(--text-primary);"><strong>Next:</strong> waiting for {{ $waiting }} to sign. You can send their email again from this table.</p>
            @if($canManage)
                <form method="POST" action="{{ route('compliance.rmcp.board.cancel', [$version, $letter]) }}" class="mt-2" onsubmit="return confirm('Cancel this letter? Nothing is deleted, and you can send a new one.');">@csrf
                    <button type="submit" class="text-xs underline" style="color: var(--text-muted);">Cancel this letter</button>
                </form>
            @endif

        @elseif($letter && $letter->isComplete() && $board['usable'])
            <div class="mt-4 px-3 py-3 text-sm rounded-md" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">
                Everyone has signed ({{ $letter->signatures->pluck('member_name')->implode(', ') }}) on {{ $letter->completed_at?->format('d M Y') }}.
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('compliance.rmcp.board.pdf', [$version, $letter]) }}" class="corex-btn-outline text-xs">Download the signed letter</a>
                @if($canApprove)<a href="{{ route('compliance.rmcp.approve.form', $version) }}" class="corex-btn-primary text-xs">Next: Approve RMCP</a>@endif
            </div>

        @elseif($letter && $letter->isComplete())
            <div class="mt-4 px-3 py-2 text-xs rounded-md" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent); color: var(--text-primary);">
                {{ $board['problem'] }}
            </div>
            @if($canManage && ! $board['stale'])
                <form method="POST" action="{{ route('compliance.rmcp.board.pdf.create', [$version, $letter]) }}" class="mt-3">@csrf
                    <button type="submit" class="corex-btn-primary text-xs">Create the PDF again</button>
                </form>
            @endif
        @endif

        {{-- ── Send a (new) letter: none yet, or the RMCP changed after the last one ── --}}
        @if(! $letter || $board['stale'])
            @if($canManage)
                @include('compliance.rmcp._board-send-form')
            @else
                <p class="mt-3 text-xs" style="color: var(--text-muted);">The board has not been asked to sign yet. Someone with the "Manage RMCP board approval" permission can send the letter.</p>
            @endif
        @endif
    @elseif($board['linked'])
        {{-- Approved: show the letter this version was approved with. --}}
        <div class="mt-3 text-xs" style="color: var(--text-secondary);">
            Approved with the board letter signed by {{ $board['linked']->signatures->pluck('member_name')->implode(', ') }}
            ({{ $board['linked']->completed_at?->format('d M Y') }}).
        </div>
        @if($board['linked']->signed_pdf_path)
            <a href="{{ route('compliance.rmcp.board.pdf', [$version, $board['linked']]) }}" class="corex-btn-outline text-xs mt-2 inline-block">Download the signed letter</a>
        @endif
    @else
        <p class="mt-3 text-xs" style="color: var(--text-muted);">This version was approved with an uploaded board document.</p>
    @endif

    @if($board['log']->isNotEmpty())
        <details class="mt-4 text-xs" style="color: var(--text-muted);">
            <summary class="cursor-pointer">Activity</summary>
            <ul class="mt-2 space-y-1">
                @foreach($board['log'] as $row)
                    <li>{{ $row->created_at->format('d M Y H:i') }} &middot; {{ $eventWords[$row->event_type] ?? $row->event_type }}@if($row->actor_label) &middot; {{ $row->actor_label }}@endif</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
