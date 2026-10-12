{{--
    .ai/specs/rentals-faults-work-orders.md section 21.3 - "First to look at this": who the property (or its owner) says looks at a fault first, then the agent's
    exactly TWO choices - "Send <name> to inspect" and "Send to owner to authorise" (Johan's ruling, 12 Oct 2026). The call-out notice and the two buttons are NOT
    written here: they are cc4's ONE shared block, partials/contractor-inspect-choice (spec section 20.7), so the wording and the buttons exist in one version only.
    This file adds only the heading, where the rule came from and the "then ..." lines, and tells the shared block what the buttons do. Nothing is ever sent from
    here by itself; both buttons post to one route that goes through App\Services\Rentals\FirstResponderDispatcher (the fault flow's seam).
    Expects: $faultReport, $firstResponders (Collection from Property::firstRespondersFor()), $canAct (bool: may press the buttons).
--}}
@php
    $flFirst = $firstResponders->first();
    $flRest = $firstResponders->slice(1);
    $flNotices = [];
    if ($flFirst) {
        $flProvider = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->find($flFirst['supplier_id']);
        // the shared block needs an entry for the chosen supplier even when there is no call-out text to show (it carries the name for the first button)
        $flNotices = [(string) $flFirst['supplier_id'] => ['name' => $flFirst['name'], 'text' => (string) ($flProvider?->calloutFeeNotice() ?? ''), 'status' => (string) ($flProvider?->calloutFeeStatus() ?? '')]];
    }
@endphp
@if($flFirst)
<div class="rounded-md p-4 space-y-3" style="background: color-mix(in srgb, var(--ds-blue, #2563eb) 7%, var(--surface)); border: 1px solid var(--ds-blue, #2563eb);" data-first-look-block>
    <div class="text-sm">
        <strong>First to look at this:</strong>
        <span data-first-look-name>{{ $flFirst['name'] }}</span>
        <span class="text-xs" style="color: var(--text-muted);">({{ $flFirst['kind_label'] }})</span>@if($flFirst['callout_line']) <span class="text-xs" data-first-look-callout>- {{ $flFirst['callout_line'] }}</span>@endif
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            {{ $flFirst['source_label'] }} &middot; {{ $flFirst['tell_label'] }}.
            @if($flRest->isNotEmpty())
                Then: {{ $flRest->map(fn ($r) => $r['name'] . ' (' . $r['kind_label'] . ')')->implode(', then ') }}.
            @endif
        </div>
    </div>

    @if($canAct)
        <form id="first-look-form" method="POST" action="{{ route('corex.rental-fault-reports.first-responder', $faultReport) }}" data-first-look-form>
            @csrf
            <input type="hidden" name="supplier_id" value="{{ $flFirst['supplier_id'] }}">
            <input type="hidden" name="choice" value="">
        </form>
        @include('partials.contractor-inspect-choice', [
            'notices' => $flNotices, 'model' => "'" . $flFirst['supplier_id'] . "'", 'buttonsDisabled' => 'false',
            'inspectAttrs' => '@click="const f = document.getElementById(\'first-look-form\'); f.elements[\'choice\'].value = \'' . \App\Services\Rentals\FirstResponderDispatcher::CHOICE_INSPECT . '\'; f.submit()"',
            'authoriseAttrs' => '@click="const f = document.getElementById(\'first-look-form\'); f.elements[\'choice\'].value = \'' . \App\Services\Rentals\FirstResponderDispatcher::CHOICE_OWNER_AUTHORISE . '\'; f.submit()"',
        ])
    @else
        @include('partials.contractor-inspect-choice', ['notices' => $flNotices, 'model' => "'" . $flFirst['supplier_id'] . "'"])
    @endif
</div>
@endif
