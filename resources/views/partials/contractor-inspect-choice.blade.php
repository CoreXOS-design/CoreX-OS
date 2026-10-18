{{-- .ai/specs/rentals-faults-work-orders.md §20.7 - THE shared "contractor chosen to go and look at a fault" block. ONE partial, used by the fault page,
     the work order page and cc5's default contractor / caretaker - never a second copy.
     It shows (1) the call-out notice for the chosen supplier (fee / not known; nothing for "no fee") and (2) when the caller wires them, the agent's
     ONLY two choices as two equal, aligned buttons: "Send <name> to inspect" and "Send to owner to authorise". Same wording for every contractor.

     Expects:
       $notices  = AgencyServiceProvider::noticeMap(...)  (id => name / text / status)
       $model    = the enclosing Alpine variable that holds the chosen supplier id (string)
     Optional - the buttons appear ONLY when the caller supplies what they do (this partial never decides behaviour; the fault flow does):
       $inspectAttrs   = HTML attributes for the "Send <name> to inspect" button   (e.g. '@click="..."' or 'formaction="..." formmethod="post"')
       $authoriseAttrs = HTML attributes for the "Send to owner to authorise" button
       $buttonsDisabled = Alpine expression that disables both (default: nothing chosen).
       $inspectExtraDisabled / $authoriseExtraDisabled = Alpine expressions that disable only that button (default false).
       $thirdButtonView = a view that renders the THIRD button of the same row ("Record the owner's decision"): same height and width as the other two.
     With buttons, the block is always visible (the owner can be asked with nobody picked; "Send to inspect" needs a pick - the caller adds `!model` to $inspectExtraDisabled).
     A notice entry may carry `inspect_allowed: false` (section 22: the call-out fee is not covered by the owner's permission, or not known): that disables ONLY the
     "Send <name> to inspect" button - its text then says "The owner must authorise this first." - while "Send to owner to authorise" stays available. --}}
@php
    $inspectAttrs = $inspectAttrs ?? null;
    $authoriseAttrs = $authoriseAttrs ?? null;
    $buttonsDisabled = $buttonsDisabled ?? '!' . $model;
    $inspectExtraDisabled = $inspectExtraDisabled ?? 'false';     // Alpine expression: a reason ONLY the inspect button is off (e.g. no permission)
    $authoriseExtraDisabled = $authoriseExtraDisabled ?? 'false'; // ... ONLY the "to owner" button is off (e.g. already sent)
    $hasButtons = $inspectAttrs !== null && $authoriseAttrs !== null;
@endphp
<div x-data="{ notices: @js($notices) }" @unless($hasButtons) x-show="notices[String({{ $model }})]" x-cloak @endunless class="space-y-2" data-callout-block>
    @if($hasButtons)
        {{-- nobody picked yet: say so in one line (the first button stays off until someone is) --}}
        <p class="text-xs font-semibold" style="color: var(--text-muted);" x-show="!{{ $model }}" x-cloak data-pick-first-line>Pick who should go first</p>
    @endif
    <p class="text-xs font-semibold rounded-md px-3 py-2" style="border: 1px solid var(--ds-amber, #f59e0b); background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, var(--surface));"
       x-show="notices[String({{ $model }})] && notices[String({{ $model }})].text" x-cloak data-callout-notice
       :data-callout-notice-status="notices[String({{ $model }})] ? notices[String({{ $model }})].status : ''"
       x-text="notices[String({{ $model }})] && notices[String({{ $model }})].text ? notices[String({{ $model }})].text : ''"></p>
    @if($hasButtons)
        @php
            $inspectOff = '!!(' . $buttonsDisabled . ' || ' . $inspectExtraDisabled . ' || (notices[String(' . $model . ')] && notices[String(' . $model . ')].inspect_allowed === false))';
            $authoriseOff = '!!(' . $buttonsDisabled . ' || ' . $authoriseExtraDisabled . ')';
        @endphp
        {{-- ALL the buttons of the row are equal: same height, same width (three columns from sm up, stacked full-width below it) --}}
        <div class="grid grid-cols-1 {{ ! empty($thirdButtonView) ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} gap-2" data-inspect-choice>
            <button type="button" class="corex-btn-primary text-xs w-full" :disabled="{{ $inspectOff }}" :style="{{ $inspectOff }} ? 'opacity:.45; cursor:not-allowed;' : ''" data-send-to-inspect {!! $inspectAttrs !!}
                    x-text="notices[String({{ $model }})] ? 'Send ' + notices[String({{ $model }})].name + ' to inspect' : 'Send to inspect'"></button>
            <button type="button" class="corex-btn-outline text-xs w-full" :disabled="{{ $authoriseOff }}" :style="{{ $authoriseOff }} ? 'opacity:.45; cursor:not-allowed;' : ''" data-send-to-owner-to-authorise {!! $authoriseAttrs !!}>Send to owner to authorise</button>
            @if(! empty($thirdButtonView))@include($thirdButtonView)@endif
        </div>
    @endif
</div>
