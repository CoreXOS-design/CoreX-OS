{{--
    AT-294 — per-recipient e-sign email send status + Resend (single source of truth).
    Reuses route docuperfect.signatures.resendEmail; the controller routes a completed
    recipient to resendCompletionEmail (re-sends the stored signed PDF) and a still-pending
    recipient to resendInvitationEmail (re-delivers the SAME token — no regeneration).

    Params:
      $document — App\Models\Docuperfect\Document (for the route binding)
      $requests — Collection<SignatureRequest> (the template's recipients)
--}}
@foreach($requests->where('party_role', '!=', 'agent') as $rr)
    @php
        $rrFailed = ($rr->invite_send_status ?? null) === 'failed' || ($rr->completion_send_status ?? null) === 'failed';
        $rrErr = ($rr->invite_send_status ?? null) === 'failed' ? $rr->invite_send_error : $rr->completion_send_error;
    @endphp
    @if($rrFailed)
        <span class="text-[10px] font-semibold text-right" style="color: var(--ds-crimson);" title="{{ $rrErr }}">&#9888; {{ $rr->signer_name }} — send failed</span>
    @endif
    @if($rr->signer_email && in_array($rr->status, ['pending', 'viewed', 'partially_signed', 'completed']))
        <form method="POST" action="{{ route('docuperfect.signatures.resendEmail', ['document' => $document->id, 'signatureRequest' => $rr->id]) }}" class="inline">
            @csrf
            <button type="submit" class="text-xs font-semibold hover:underline transition-colors duration-150"
                    style="color: {{ $rrFailed ? 'var(--ds-crimson)' : 'var(--brand-icon)' }};"
                    onclick="return confirm('Resend the {{ $rr->status === 'completed' ? 'signed document' : 'signing invitation' }} to {{ $rr->signer_name }}?')">
                {{ $rrFailed ? 'Resend (failed)' : 'Resend' }} &#8594; {{ \Illuminate\Support\Str::limit($rr->signer_name, 14) }}
            </button>
        </form>
    @endif
@endforeach
