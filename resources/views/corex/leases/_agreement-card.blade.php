{{--
    .ai/specs/leases.md §15.13 (Build L3a) — the Lease Hub's "Agreement" card: where the agency's own lease
    agreement for this lease stands, who signs it and in what order (the agent first, then the tenant(s), then the
    landlord(s) — always), and what the agent can do next. Only rendered for a lease that has an agreement at all.

    $agreementCard comes from LeaseSigningLauncher::cardFor(). There is no "void and start again": cancelling is
    e-sign's own cancel; after a declined, cancelled or expired agreement the card offers "Prepare again".
--}}
@if(!empty($agreementCard))
    @php
        $card = $agreementCard;
        $chipStyle = match ($card['status']) {
            'signed', 'signed_on_paper' => 'background: var(--ds-green, #16a34a); color: #fff;',
            'declined', 'voided', 'expired' => 'background: var(--ds-crimson, #dc2626); color: #fff;',
            default => 'background: var(--brand-button, #0ea5e9); color: #fff;',
        };
    @endphp
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="agreement-card">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">Lease agreement</h2>
            <span class="rounded-full px-3 py-1 text-xs" style="{{ $chipStyle }}" data-qa="agreement-status">{{ $card['label'] }}</span>
        </div>

        @if($card['agreement_name'])
            <p class="text-xs" style="color: var(--text-muted);">{{ $card['agreement_name'] }}</p>
        @endif

        @if($card['status'] === 'signed_on_paper')
            <p class="text-xs" style="color: var(--text-muted);">A signed paper copy is filed against this lease and the property.</p>
        @elseif(!empty($card['signers']))
            <ol class="space-y-1 text-xs" data-qa="agreement-signers">
                @foreach($card['signers'] as $i => $signer)
                    <li class="flex items-center justify-between rounded px-2 py-1" style="background: var(--surface-2);">
                        <span>{{ $i + 1 }}. {{ $signer['name'] }} <span style="color: var(--text-muted);">({{ ucfirst($signer['role']) }})</span></span>
                        <span style="color: var(--text-muted);">{{ $signer['status'] }}{{ $signer['last_reminder'] ? ' · reminder ' . $signer['last_reminder'] : '' }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        @if($card['status'] === 'prepared' && !$card['continue_url'] && $card['owner_name'])
            <p class="text-xs" style="color: var(--text-muted);" data-qa="agreement-owner">Owned by {{ $card['owner_name'] }} — only they can open it.</p>
        @endif

        @if(in_array($card['status'], ['declined', 'voided', 'expired'], true) && $card['failure_note'])
            <p class="text-xs" style="color: var(--text-muted);">{{ $card['failure_note'] }}</p>
        @endif

        @if(session('capture_gaps'))
            {{-- "Prepare again" (or any re-check) found something still missing: each item links to where it is fixed. --}}
            <div class="rounded p-2 text-xs space-y-1" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-qa="agreement-gaps">
                <p class="font-medium">Still needed before the agreement can be prepared:</p>
                <ul class="list-disc pl-4">
                    @foreach(session('capture_gaps') as $gap)
                        <li>{{ $gap['label'] }}@if(!empty($gap['fix_url'])) — <a href="{{ $gap['fix_url'] }}" class="underline" target="_blank" rel="noopener">fix it</a>@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @if($card['continue_url'])
                <a href="{{ $card['continue_url'] }}" class="corex-btn-primary text-xs" data-qa="agreement-continue">Continue to the agreement</a>
            @endif
            @if($card['approve_url'])
                <a href="{{ $card['approve_url'] }}" class="corex-btn-primary text-xs">Approve the signed agreement</a>
            @endif
            @if($card['open_url'])
                <a href="{{ $card['open_url'] }}" class="corex-btn-outline text-xs">Open in e-sign</a>
            @endif
            @if($card['signed_copy_url'])
                <a href="{{ $card['signed_copy_url'] }}" class="corex-btn-outline text-xs" data-qa="agreement-signed-copy">Download signed copy</a>
            @endif
            @if($card['certificate_url'])
                <a href="{{ $card['certificate_url'] }}" class="corex-btn-outline text-xs" data-qa="agreement-certificate">Download signing certificate</a>
            @endif
            @if($card['can_prepare_again'])
                <form method="POST" action="{{ route('corex.leases.signing.prepare-again', $lease) }}">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs" data-qa="agreement-prepare-again">Prepare again</button>
                </form>
            @endif
        </div>
    </div>
@endif
