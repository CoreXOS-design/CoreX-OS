{{--
    Audit fixes A2 / A3 (2026-10-19) - the agent's controls for the applicant's link.

      - "Re-issue link" : a fresh link (the old one stops working) plus, once the application has been submitted, a
        one-time code the agent reads to the applicant. For an applicant locked out of the return gate (no ID number or
        email on file, forgotten ID, locked out by wrong tries) or whose link has run out. RentalApplicationController::reissueLink().
      - "Take it back"  : only on a REOPENED application - the applicant never resubmitted, so the agent takes the file
        back as Returned. RentalApplicationController::cancelReopen().

    Include with ['application' => $rentalApplication]. Withdrawing a sent / in-progress / reopened file is the existing
    _record-withdrawn partial (its condition was widened in the same change).
--}}
@permission('rental_applications.create')
@php
    $reissued = session('reissued_link');
    $canReissue = $application->token && ! in_array($application->status, ['draft', 'declined', 'withdrawn'], true);
@endphp

@if($reissued)
    <div class="rounded-md p-4 text-xs space-y-1" data-qa="reissued-link" style="background: var(--ds-green-soft, #f0fdf4); border: 1px solid var(--ds-green, #16a34a); color: var(--text-primary);">
        <div><strong>New link</strong> (the old one no longer works; valid until {{ $reissued['expires'] }}):</div>
        <div class="break-all"><a href="{{ $reissued['url'] }}" target="_blank" style="color: var(--brand-icon, #2563eb);">{{ $reissued['url'] }}</a></div>
        @if(!empty($reissued['code']))
            <div class="pt-1">One-time code for the applicant (shown once - read it to them by phone or WhatsApp): <strong class="text-sm tracking-widest" data-qa="reissued-code">{{ $reissued['code'] }}</strong></div>
            <div style="color: var(--text-muted);">They enter it on the page that asks them to verify themselves. It works once.</div>
        @endif
    </div>
@endif

@if($canReissue || $application->status === 'reopened')
    <div class="rounded-md p-4 flex flex-wrap items-center gap-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="applicant-link-actions">
        @if($canReissue)
            <form method="POST" action="{{ route('corex.rental-applications.reissue-link', $application) }}"
                  data-confirm="Issue a fresh link? The applicant's current link will stop working{{ $application->recipientEmail() ? ' and the new one will be emailed to them' : '' }}." data-confirm-label="Re-issue link" class="inline">
                @csrf
                <button type="submit" class="corex-btn-outline text-xs" title="For an applicant who is locked out, or whose link has run out">Re-issue link / reset return gate</button>
            </form>
        @endif
        @if($application->status === 'reopened')
            <form method="POST" action="{{ route('corex.rental-applications.cancel-reopen', $application) }}"
                  data-confirm="Take this application back from the applicant? Whatever they have already typed stays on the file." data-confirm-label="Take it back" class="inline-flex items-center gap-2">
                @csrf
                <input type="text" name="note" maxlength="1000" placeholder="Why? (optional)" class="rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                <button type="submit" class="corex-btn-outline text-xs" data-qa="cancel-reopen" title="The applicant did not resubmit - take the file back as Returned">Take it back from the applicant</button>
            </form>
        @endif
    </div>
@endif
@endpermission
