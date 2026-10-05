{{--
    Extracted from properties/show.blade.php's own identity strip (2026-09-13
    restyle, option 2) so a second standalone page (the rental-inventory
    capture screen) can show the SAME header — Back arrow, thumbnail,
    address/status pills, price, filings, Compliance Status, Save Changes —
    without a second copy of this markup. Johan: "on inventory we need to
    show all the same menu items like on inspections, not just a blank
    page." .ai/specs/rental-inventory.md §13.10.

    Deliberately renders using whatever's ALREADY in scope rather than
    re-deriving anything itself — show.blade.php's own top-of-file PHP
    block (still in place there, unmoved) computes $backRoute/$backLabel/
    $thumb/$listingTypeLabel/$statusLabel/$brandPillStyle/$sbAddr/
    $hasRealAddr/$isMarketable/$cmpLabel/$cmpPillBg/$cmpPillFg and several
    of those ($thumb, $isMarketable) are read again FURTHER DOWN that same
    file, so moving their derivation into this partial would silently
    break those later reads (Blade @include does not leak variables back
    to its caller). Capture.blade.php computes the identical small
    derivation itself, immediately before including this partial — see
    that file's own comment for why that's a deliberate, contained
    duplication of a small PHP block rather than the markup Johan actually
    asked not to duplicate.

    Two params let a caller outside the SPA-style property page adapt the
    two ACTION controls, both optional and defaulting to show.blade.php's
    original behaviour:
      - $complianceMode: 'modal' (default, show.blade.php's own
        complianceModalOpen Alpine flag — a large modal defined elsewhere
        on that page, not reproduced here) | 'link' (a standalone page like
        capture.blade.php has no such modal in scope, so this just navigates
        to the property's Overview tab instead of opening one locally).
      - $showSaveButton: true (default) | false — capture.blade.php has no
        `prop-update-form` (it autosaves; there is nothing to submit), so it
        passes false rather than rendering a button with nowhere to submit.
--}}
@php
    $complianceMode = $complianceMode ?? 'modal';
    $showSaveButton = $showSaveButton ?? true;
@endphp
<div class="prop-identity-strip flex-shrink-0 rounded-md px-3 py-2 flex items-center gap-3 flex-wrap"
     style="background:var(--surface); border:1px solid var(--border);">
    <a href="{{ route($backRoute) }}"
       class="corex-btn-outline text-xs no-underline inline-flex items-center flex-shrink-0"
       style="padding-left:0.5rem; padding-right:0.5rem;"
       title="{{ $backLabel }}" aria-label="{{ $backLabel }}">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        <span class="sr-only">{{ $backLabel }}</span>
    </a>

    <div class="hidden lg:flex items-center gap-3 min-w-0 flex-1">
        @if($thumb)
            <img src="{{ $thumb }}" alt="" class="w-10 h-10 rounded object-cover flex-shrink-0">
        @else
            <div class="w-10 h-10 rounded flex items-center justify-center flex-shrink-0" style="background:var(--surface-2);">
                <svg class="w-5 h-5" style="color:var(--text-muted);opacity:.4;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
            </div>
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-x-2 min-w-0">
                @if($hasRealAddr)
                    <span class="text-sm font-bold leading-snug truncate" style="color:var(--text-primary);" title="{{ $sbAddr }}">{{ $sbAddr }}</span>
                    @if($property->title)
                    <span class="text-xs truncate" style="color:var(--text-muted);" title="{{ $property->title }}">{{ $property->title }}</span>
                    @endif
                @else
                    <span class="text-sm font-bold leading-snug truncate" style="color:var(--text-primary);" title="{{ $property->title }}">{{ $property->title ?: 'New Property' }}</span>
                @endif
            </div>
            <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold" style="{{ $brandPillStyle }}">{{ $listingTypeLabel }}</span>
                <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold" style="{{ $brandPillStyle }}">{{ $statusLabel }}</span>
                @if(!empty($property->status_label))
                    <span class="ds-badge ds-badge-warning" title="Special label on the listing's status — e.g. price reduced, or an offer received but the property is still for sale.">{{ $property->status_label }}</span>
                @endif
                @if($property->isPublished())
                    <span class="ds-badge ds-badge-success">Published</span>
                @endif

                @if(!$isNew && $property->filings->isNotEmpty())
                    @foreach($property->filings as $filing)
                        <span class="ds-badge ds-badge-info"
                              title="Physically filed as {{ $filing->full_reference }} ({{ $filing->document_type }}){{ $filing->expiry_date ? ' — mandate expires ' . $filing->expiry_date->format('d M Y') : '' }}. Read live from the Filing Register.">
                            {{ $filing->document_type }} · {{ $filing->full_reference }}
                        </span>
                    @endforeach
                @endif
                @if(!$isNew)
                    <span class="text-sm font-bold ml-1" style="color:var(--brand-default);">{{ $property->formattedDisplayPrice() }}</span>
                @endif
            </div>
        </div>
    </div>
    <div class="flex-1 lg:hidden"></div>

    @if(!$isNew)
    <div class="flex items-center gap-2 flex-shrink-0 ml-auto">
        @if($complianceMode === 'modal')
        <button type="button" @click="complianceModalOpen = true"
                class="prop-action-btn prop-action-btn-neutral"
                title="View compliance gates and go-live status">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>
            Compliance Status
            <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded" style="background:{{ $cmpPillBg }}; color:{{ $cmpPillFg }};">{{ $cmpLabel }}</span>
        </button>
        @else
        <a href="{{ route('corex.properties.show', $property) }}?tab=overview"
           class="prop-action-btn prop-action-btn-neutral no-underline"
           title="View compliance gates and go-live status — opens on the property's Overview tab">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>
            Compliance Status
            <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded" style="background:{{ $cmpPillBg }}; color:{{ $cmpPillFg }};">{{ $cmpLabel }}</span>
        </a>
        @endif

        {{-- Layer 3 — the approval tag and the "Send for approval" button, right
             beside Compliance Status because that is the moment they matter: the
             button exists ONLY once compliance is done (Johan, spec D5). Renders
             nothing at all unless the agency switched the gate on.
             .ai/specs/syndication-approval-gate.md §5.2 / §6.4

             Lives HERE rather than in show.blade.php because Staging extracted this
             header into a partial (rental-inventory §13.10) — the two changes
             collided on the same lines and this is the merge of both.

             NULL-COALESCED ON PURPOSE: this partial is also included by the
             standalone inventory capture page, which never computes these two
             variables. `?? null` keeps that page rendering exactly as before
             instead of failing on an undefined variable. --}}
        @php($synState = $synApprovalState ?? null)
        @if($synState && ! $synState->isSilent())
        <div class="flex items-center gap-2"
             x-data="syndicationApproval({
                propertyId: {{ (int) $property->id }},
                csrfToken: '{{ csrf_token() }}',
                badge: '{{ $synState->badge }}',
                urls: {
                    request: '{{ route('corex.properties.syndication-approval.request', $property->id) }}',
                    cancel:  '{{ route('corex.properties.syndication-approval.cancel', $property->id) }}',
                    approve: '{{ route('corex.properties.syndication-approval.approve', $property->id) }}',
                    reject:  '{{ route('corex.properties.syndication-approval.reject', $property->id) }}',
                    revoke:  '{{ route('corex.properties.syndication-approval.revoke', $property->id) }}',
                },
             })">
            @include('corex.properties.partials._syndication-approval-badge', ['approvalState' => $synState])

            @unless(auth()->user()?->is_assistant)
                @if($synState->canRequest)
                <button type="button" @click="post(urls.request)" :disabled="loading"
                        class="prop-action-btn prop-action-btn-neutral"
                        title="Send this listing to your approver — they get an email straight away">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.126A59.768 59.768 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.876L5.999 12Zm0 0h7.5"/></svg>
                    <span x-text="loading ? 'Sending…' : 'Send for approval'">Send for approval</span>
                </button>
                @elseif(($synApprovalCanApprove ?? false) && $synState->badge === \App\Services\Syndication\SyndicationApprovalState::BADGE_AWAITING)
                <button type="button" @click="post(urls.approve)" :disabled="loading"
                        class="prop-action-btn prop-action-btn-success"
                        title="Approve this listing for syndication">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <span x-text="loading ? 'Approving…' : 'Approve'">Approve</span>
                </button>
                @endif
            @endunless

            <span class="text-[11px]" x-show="errorMsg" x-cloak style="color:var(--ds-crimson, #dc2626);" x-text="errorMsg"></span>
        </div>
        @endif

        @if($showSaveButton)
        <button type="submit" form="prop-update-form" data-prop-save data-tour="prop-save"
                class="prop-action-btn prop-action-btn-success">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            <span class="prop-save-label">Save Changes</span>
        </button>
        @endif
        @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
    </div>
    @endif
</div>
