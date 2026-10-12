{{--
    .ai/specs/rentals-faults-work-orders.md section 21.3 - "First to look at this": who the property (or its owner) says looks at a fault first, and the agent's
    exactly TWO choices - "Send <name> to inspect" and "Send to owner to authorise" (Johan's ruling, 12 Oct 2026) - as two equal-sized, aligned buttons. ONE
    partial: this is the only place those words and buttons exist; the call-out notice (partials/callout-notice, cc4) is included here, not copied. Nothing
    is ever sent from here by itself, and the buttons' behaviour is the fault flow's (App\Services\Rentals\FirstResponderDispatcher).
    Expects: $faultReport, $firstResponders (Collection from Property::firstRespondersFor()), $canAct (bool: may press the buttons).
--}}
@php
    $flFirst = $firstResponders->first();
    $flRest = $firstResponders->slice(1);
    $flNotices = [];
    if ($flFirst && method_exists(\App\Models\DealV2\AgencyServiceProvider::class, 'noticeMap') && view()->exists('partials.callout-notice')) {
        $flNotices = \App\Models\DealV2\AgencyServiceProvider::noticeMap(\App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->whereIn('id', [$flFirst['supplier_id']])->get());
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

    @if($flNotices && isset($flNotices[(string) $flFirst['supplier_id']]))
        @include('partials.callout-notice', ['notices' => $flNotices, 'model' => "'" . $flFirst['supplier_id'] . "'"])
    @endif

    @if($canAct)
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" data-first-look-actions>
            <form method="POST" action="{{ route('corex.rental-fault-reports.first-responder', $faultReport) }}">
                @csrf
                <input type="hidden" name="supplier_id" value="{{ $flFirst['supplier_id'] }}">
                <input type="hidden" name="choice" value="{{ \App\Services\Rentals\FirstResponderDispatcher::CHOICE_INSPECT }}">
                <button type="submit" class="corex-btn-primary text-xs w-full" data-first-look-inspect>Send {{ $flFirst['name'] }} to inspect</button>
            </form>
            <form method="POST" action="{{ route('corex.rental-fault-reports.first-responder', $faultReport) }}">
                @csrf
                <input type="hidden" name="supplier_id" value="{{ $flFirst['supplier_id'] }}">
                <input type="hidden" name="choice" value="{{ \App\Services\Rentals\FirstResponderDispatcher::CHOICE_OWNER_AUTHORISE }}">
                <button type="submit" class="corex-btn-outline text-xs w-full" data-first-look-owner>Send to owner to authorise</button>
            </form>
        </div>
    @endif
</div>
@endif
