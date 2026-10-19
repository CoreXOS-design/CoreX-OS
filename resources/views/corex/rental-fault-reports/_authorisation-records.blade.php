{{-- .ai/specs/rentals-faults-work-orders.md section 22.1 - the AUTHORISATION RECORDS of this fault, oldest first: who authorised, on what basis, at what stage, when. Never edited;
     a newer record replaces an older one (both stay). Expects $authorisations (RentalAuthorisationService::rowsFor). --}}
@php $arSvc = app(\App\Services\Rentals\RentalAuthorisationService::class); @endphp
@php $ohSvcRec = app(\App\Services\Rentals\RentalOwnerHoldService::class); $ownerHolds = $ownerHolds ?? collect(); @endphp
@if($authorisations->isNotEmpty() || $ownerHolds->isNotEmpty())
<div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);" data-authorisation-records>
    <h2 class="text-sm font-semibold wo-embedded-title">Authorisation records</h2>
    <ul class="space-y-2 text-xs">
        @foreach($authorisations as $ar)
            <li @if($ar->superseded_at) style="opacity:.65" @endif data-authorisation-row="{{ $ar->id }}" data-authorisation-basis="{{ $ar->basis }}">
                <span class="ds-badge ds-badge-muted">{{ \App\Services\Rentals\RentalAuthorisationService::pointLabel($ar->point) }}</span>
                <strong>{{ $ar->isAuthorised() ? 'Authorised' : 'Declined' }}</strong> &mdash; {{ $arSvc->recordTextFor($ar) }}
                @if($ar->note && $ar->basis === \App\Models\RentalAuthorisation::BASIS_OWNER_INSTRUCTION)<em>&ldquo;{{ $ar->note }}&rdquo;</em>@endif
                @if($ar->proof_path)<a href="{{ $ar->proof_path }}" target="_blank" rel="noopener" class="underline">Proof</a>@endif
                <span style="color: var(--text-muted);">
                    &middot; job was: {{ $arSvc->stageWords($ar) ?: '-' }}
                    @if($ar->owner_told_at) &middot; owner told {{ $ar->owner_told_at->copy()->setTimezone(\App\Support\AppDate::agencyTimezone($faultReport->agency))->format('j M Y H:i') }}@endif
                    @if($ar->superseded_at) &middot; <em>replaced by a newer record</em>@else &middot; <em>this is the one that stands</em>@endif
                </span>
            </li>
        @endforeach
        {{-- section 22.7 - the owner's "Stop this" entries (and how each was lifted) sit with the records --}}
        @foreach($ownerHolds as $oh)
            <li data-owner-stop-row="{{ $oh->id }}">
                <span class="ds-badge ds-badge-danger">Stopped by owner</span> {{ $ohSvcRec->stopLine($oh) }}
                @if($oh->resumed_at) <br><span style="color: var(--text-muted);">{{ $ohSvcRec->resumeLine($oh) }}</span>@else <span style="color: var(--text-muted);">&middot; <em>still on hold</em></span>@endif
            </li>
        @endforeach
    </ul>
</div>
@endif
