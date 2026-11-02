{{-- AT-517 - Co-applicant line in the review header strip.
     Linked    -> "Co-applicant: <name>" with a link to the contact.
     Captured  -> the applicant typed a Co-applicant into the form but no contact is linked yet:
                  "Co-applicant captured: <name> - Create contact and link" (agent only, before a decision).
                  A duplicate match is offered back ("Use this contact" / "Create anyway") before anything is created.
     Needs: $rentalApplication, $viewerRole, and optionally $reviewLocked. --}}
@php
    $coBlock = (array) ($rentalApplication->co_applicant ?? []);
    $coCapturedName = trim((string) ($coBlock['full_name'] ?? ''));
    $coHasDetails = $coBlock !== [];
    $coDecided = in_array($rentalApplication->status, ['approved', 'declined', 'withdrawn'], true);
    $coDuplicates = session('co_applicant_duplicates');
@endphp
@if($rentalApplication->co_applicant_contact_id)
    @php $coContact = $rentalApplication->coApplicantContact; @endphp
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1 text-xs" style="color: var(--text-muted);" data-qa="co-applicant-linked">
        <span>Co-applicant:</span>
        @if($coContact)
            <a href="{{ route('corex.contacts.show', $coContact) }}" target="_blank" rel="noopener" class="underline font-semibold" style="color: var(--text-primary);">{{ $coContact->full_name }}</a>
        @else
            <span>(contact no longer available)</span>
        @endif
    </div>
@elseif($coHasDetails)
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1 text-xs" style="color: var(--text-muted);" data-qa="co-applicant-captured">
        <span>Co-applicant captured: <strong style="color: var(--text-primary);">{{ $coCapturedName !== '' ? $coCapturedName : 'name not given' }}</strong></span>
        @if($viewerRole === 'agent' && ! ($reviewLocked ?? false) && ! $coDecided && $coCapturedName !== '')
            <form method="POST" action="{{ route('corex.rental-applications.co-applicant.link', $rentalApplication) }}" class="inline">
                @csrf
                <button type="submit" class="corex-btn-outline" style="padding: 0.15rem 0.6rem; font-size: 0.7rem; line-height: 1.2;" data-qa="co-applicant-create-link">Create contact and link</button>
            </form>
        @endif
    </div>
    @if($viewerRole === 'agent' && $coDuplicates)
        <div class="mt-1 rounded-md px-3 py-2 text-xs space-y-1" style="border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary);" data-qa="co-applicant-duplicates">
            <p>This looks like it might already be a contact &mdash; link the existing person instead of creating a second record:</p>
            @foreach($coDuplicates['contacts'] as $dup)
                <div class="flex items-center justify-between gap-3">
                    <span>
                        <span style="color: var(--text-primary);">{{ $dup['name'] }}</span>
                        <span style="color: var(--text-muted);">{{ collect([$dup['phone'], $dup['email']])->filter()->implode(' · ') }}</span>
                    </span>
                    @if($dup['can_view'] && ($coDuplicates['mode'] ?? '') !== 'hard_block_request')
                        <form method="POST" action="{{ route('corex.rental-applications.co-applicant.link', $rentalApplication) }}">
                            @csrf
                            <input type="hidden" name="contact_id" value="{{ $dup['id'] }}">
                            <button type="submit" class="corex-btn-outline" style="padding: 0.15rem 0.6rem; font-size: 0.7rem;" data-qa="co-applicant-use-existing">Use this contact</button>
                        </form>
                    @endif
                </div>
            @endforeach
            @if($coDuplicates['can_create_anyway'] ?? false)
                <form method="POST" action="{{ route('corex.rental-applications.co-applicant.link', $rentalApplication) }}">
                    @csrf
                    <input type="hidden" name="create_anyway" value="1">
                    <button type="submit" class="corex-btn-primary" style="padding: 0.15rem 0.6rem; font-size: 0.7rem;" data-qa="co-applicant-create-anyway">Create anyway</button>
                </form>
            @endif
        </div>
    @endif
@endif
