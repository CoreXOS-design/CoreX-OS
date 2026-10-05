{{--
    Layer 3 — the approval banner at the top of the syndication panel.
    .ai/specs/syndication-approval-gate.md §5.2, §5.3, §6.4.

    Renders NOTHING when the agency has not switched the feature on, so the
    panel is byte-for-byte its old self for every other agency.

    Self-contained x-data so it works BOTH inline on the property page and
    inside the Properties-index modal (which re-binds with Alpine.initTree).

    Requires in scope: $property. Everything else is derived.
--}}
@php
    $approvalSvc   = app(\App\Services\Syndication\SyndicationApprovalService::class);
    // The panel computes this once and passes it in as $synApprovalState; the
    // fallback is for any other caller that includes the banner on its own.
    $approvalState = $approvalState ?? $synApprovalState ?? $approvalSvc->stateFor($property);
    $canApprove    = auth()->user() ? $approvalSvc->canApprove(auth()->user(), $property) : false;
    $stateClass    = \App\Services\Syndication\SyndicationApprovalState::class;
    $isReadOnly    = (bool) auth()->user()?->is_assistant;
@endphp

@if(! $approvalState->isSilent())
<div class="p-3 rounded-md mb-3"
     style="background:var(--surface-2); border:1px solid var(--border);"
     x-data="syndicationApproval({
        propertyId: {{ (int) $property->id }},
        csrfToken: '{{ csrf_token() }}',
        badge: '{{ $approvalState->badge }}',
        urls: {
            request: '{{ route('corex.properties.syndication-approval.request', $property->id) }}',
            cancel:  '{{ route('corex.properties.syndication-approval.cancel', $property->id) }}',
            approve: '{{ route('corex.properties.syndication-approval.approve', $property->id) }}',
            reject:  '{{ route('corex.properties.syndication-approval.reject', $property->id) }}',
            revoke:  '{{ route('corex.properties.syndication-approval.revoke', $property->id) }}',
        },
     })" @click.stop>

    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                @include('corex.properties.partials._syndication-approval-badge', ['approvalState' => $approvalState])
                <span class="text-sm font-semibold" style="color:var(--text-primary);">
                    @switch($approvalState->badge)
                        @case($stateClass::BADGE_APPROVED)   Approved — this listing can go to the portals @break
                        @case($stateClass::BADGE_AWAITING)   Waiting for approval @break
                        @case($stateClass::BADGE_REJECTED)   Not approved yet @break
                        @case($stateClass::BADGE_NEEDS)      This listing needs approval before it can go to a portal @break
                        @default                             Finish this listing's compliance first
                    @endswitch
                </span>
            </div>

            <div class="text-xs mt-1" style="color:var(--text-secondary);">
                @switch($approvalState->badge)
                    @case($stateClass::BADGE_APPROVED)
                        Approved{{ $approvalState->approvedByName ? ' by ' . $approvalState->approvedByName : '' }}{{ $approvalState->approvedAt ? ' on ' . $approvalState->approvedAt->format('d M Y') : '' }}.
                        @break
                    @case($stateClass::BADGE_AWAITING)
                        Sent{{ $approvalState->requestedByName ? ' by ' . $approvalState->requestedByName : '' }}{{ $approvalState->requestedAt ? ' on ' . $approvalState->requestedAt->format('d M Y H:i') : '' }}@if(!empty($approvalState->approverNames)) — with {{ implode(' or ', $approvalState->approverNames) }}@endif.
                        @break
                    @case($stateClass::BADGE_REJECTED)
                        {{ $approvalState->lastDecisionNote ?: 'No reason was recorded.' }}
                        @break
                    @case($stateClass::BADGE_NEEDS)
                        @if(!empty($approvalState->approverNames))
                            {{ implode(' or ', $approvalState->approverNames) }} must approve it. The portal controls below stay locked until then.
                        @else
                            Your agency admin must approve it. The portal controls below stay locked until then.
                        @endif
                        @break
                    @default
                        The "Send for approval" button appears here as soon as compliance is complete.
                @endswitch
            </div>

            <div class="text-xs mt-1" x-show="message" x-cloak style="color:var(--ds-emerald, #10b981);" x-text="message"></div>
            <div class="text-xs mt-1" x-show="errorMsg" x-cloak style="color:var(--ds-crimson, #dc2626);" x-text="errorMsg"></div>
        </div>

        @unless($isReadOnly)
        <div class="flex items-center gap-2 flex-shrink-0">
            {{-- Agent: send / cancel --}}
            @if($approvalState->canRequest)
                <button type="button" @click.stop="open = !open" :disabled="loading"
                        class="corex-btn-primary text-xs px-3 py-1.5">Send for approval</button>
            @elseif($approvalState->badge === $stateClass::BADGE_AWAITING)
                <button type="button" @click.stop="post(urls.cancel)" :disabled="loading"
                        class="text-xs px-3 py-1.5 rounded-md"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">Cancel request</button>
            @endif

            {{-- Approver: approve / reject / revoke --}}
            @if($canApprove)
                @if($approvalState->badge === $stateClass::BADGE_APPROVED)
                    <button type="button" @click.stop="openReason = 'revoke'" :disabled="loading"
                            class="text-xs px-3 py-1.5 rounded-md"
                            style="background:var(--surface); border:1px solid var(--border); color:var(--ds-crimson, #dc2626);">Withdraw approval</button>
                @elseif($approvalState->complianceComplete)
                    <button type="button" @click.stop="post(urls.approve)" :disabled="loading"
                            class="corex-btn-primary text-xs px-3 py-1.5">Approve</button>
                    @if($approvalState->badge === $stateClass::BADGE_AWAITING)
                    <button type="button" @click.stop="openReason = 'reject'" :disabled="loading"
                            class="text-xs px-3 py-1.5 rounded-md"
                            style="background:var(--surface); border:1px solid var(--border); color:var(--ds-crimson, #dc2626);">Reject</button>
                    @endif
                @endif
            @endif
        </div>
        @endunless
    </div>

    {{-- Agent's optional note, shown after pressing Send for approval --}}
    <div class="mt-3" x-show="open" x-cloak>
        <textarea x-model="note" rows="2" maxlength="2000"
                  placeholder="Anything the approver should know (optional)"
                  class="w-full rounded-md px-3 py-2 text-sm"
                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
        <div class="flex justify-end gap-2 mt-2">
            <button type="button" @click.stop="open = false"
                    class="text-xs px-3 py-1.5 rounded-md"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
            <button type="button" @click.stop="post(urls.request, { note })" :disabled="loading"
                    class="corex-btn-primary text-xs px-3 py-1.5">Send</button>
        </div>
    </div>

    {{-- Approver's reason — REQUIRED for both reject and withdraw --}}
    <div class="mt-3" x-show="openReason" x-cloak>
        <textarea x-model="reason" rows="2" maxlength="2000"
                  :placeholder="openReason === 'reject' ? 'Tell the agent what to fix (required)' : 'Why is this approval being withdrawn? (required)'"
                  class="w-full rounded-md px-3 py-2 text-sm"
                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
        <div class="flex justify-end gap-2 mt-2">
            <button type="button" @click.stop="openReason = ''; reason = '';"
                    class="text-xs px-3 py-1.5 rounded-md"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
            <button type="button" :disabled="loading || reason.trim().length === 0"
                    @click.stop="post(openReason === 'reject' ? urls.reject : urls.revoke, { reason })"
                    class="corex-btn-primary text-xs px-3 py-1.5"
                    x-text="openReason === 'reject' ? 'Reject listing' : 'Withdraw approval'"></button>
        </div>
    </div>
</div>
@endif
