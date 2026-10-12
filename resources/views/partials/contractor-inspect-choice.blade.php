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
       $buttonsDisabled = Alpine expression that disables both (default: nothing chosen). --}}
@php
    $inspectAttrs = $inspectAttrs ?? null;
    $authoriseAttrs = $authoriseAttrs ?? null;
    $buttonsDisabled = $buttonsDisabled ?? '!' . $model;
    $hasButtons = $inspectAttrs !== null && $authoriseAttrs !== null;
@endphp
<div x-data="{ notices: @js($notices) }" x-show="notices[String({{ $model }})]" x-cloak class="space-y-2" data-callout-block>
    <p class="text-xs font-semibold rounded-md px-3 py-2" style="border: 1px solid var(--ds-amber, #f59e0b); background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, var(--surface));"
       x-show="notices[String({{ $model }})] && notices[String({{ $model }})].text" data-callout-notice
       :data-callout-notice-status="notices[String({{ $model }})] ? notices[String({{ $model }})].status : ''"
       x-text="notices[String({{ $model }})] && notices[String({{ $model }})].text ? notices[String({{ $model }})].text : ''"></p>
    @if($hasButtons)
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" data-inspect-choice>
            <button type="button" class="corex-btn-primary text-xs w-full" :disabled="{{ $buttonsDisabled }}" data-send-to-inspect {!! $inspectAttrs !!}
                    x-text="'Send ' + (notices[String({{ $model }})] ? notices[String({{ $model }})].name : '') + ' to inspect'"></button>
            <button type="button" class="corex-btn-outline text-xs w-full" :disabled="{{ $buttonsDisabled }}" data-send-to-owner-to-authorise {!! $authoriseAttrs !!}>Send to owner to authorise</button>
        </div>
    @endif
</div>
