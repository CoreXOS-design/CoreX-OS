{{--
    AT-460 - "Money & approval": the ONE card for the price and who has authorised it. Owner's limits (repairs / emergency; the link to the owner's fault settings only when a limit
    is not set), the work order price (incl), what is authorised and by whom/when, the single verdict sentence about the owner's limit (this is the ONLY place it is written),
    and ONE button - the same action as the Next bar when that is the next step, otherwise the owner-decision button. Below it the shared "Approve the cost" working parts
    (compact: no second status, no second sentence, no second buttons - see _cost-decision $cdCompact) and the extra-work panel.
    Receives what show.blade.php has in scope: $jobCard, $stage, $screen, $ownerSettings, $pricesOn, $currentQuote, $quoteRevisions, $quoteChanged, $isOpen.
--}}
@php
    $mcWo = $jobCard->workOrder;
    $mcQuote = $stage['quote'];
    $mcRepair = $ownerSettings?->repairLimit();
    $mcEmerg = $ownerSettings?->emergencyLimit();
    $mcLimitStatus = $ownerSettings ? match ($ownerSettings->status()) {
        'confirmed_by_owner' => 'Confirmed by the owner',
        'captured_by_agent' => 'Recorded by ' . ($ownerSettings->capturedBy() ?: 'an agent') . ' - not yet confirmed by the owner',
        'disputed' => 'The owner does not agree with these',
        default => 'Not set yet',
    } : null;
    $mcMoney = fn ($v) => 'R' . number_format((float) $v, 2);
    $mcNb = $screen['next']['button'] ?? null;
    $mcNbIsMoney = $mcNb && (($mcNb['kind'] === 'form' && $mcNb['form'] === 'jc-send-quote-form') || ($mcNb['kind'] === 'go' && ($mcNb['target'] ?? '') === '#jc-money'));
    $mcLandlord = $jobCard->property?->landlordContact();
    $mcBaseline = (bool) $mcWo?->hasApprovedBaseline();
    $mcCanSendQuote = $isOpen && auth()->user()->hasPermission('rental_job_cards.send_quote');
    $mcQuoteFormOn = $mcCanSendQuote && $mcLandlord && ! $mcBaseline && $stage['can']['price'];
    $mcCanRecord = auth()->user()->hasPermission('rental_work_orders.record_approval');
    $mcAuthorisedAlready = $stage['authorised'] || ($mcWo && app(\App\Services\Rentals\RentalAuthorisationService::class)->hasCostAuthority($mcWo));
    $mcNeedsDecision = $isOpen && $mcWo && ! $mcAuthorisedAlready && app(\App\Services\Rentals\RentalAuthorisationService::class)->costBlockReason($mcWo) === null;
    // the one verdict sentence about the owner's limit (amounts are in the rows above it)
    $mcVerdict = null;
    // the pre-authorisation warning only: once the cost is authorised the sentence is stale and goes (AT-460)
    if ($pricesOn && $isOpen && $mcQuote['amount'] > 0 && ($screen['authorised']['key'] ?? '') !== 'authorised') {
        $mcVerdict = $mcRepair === null
            ? ["The owner has not set a repair limit - the owner must approve this.", 'var(--ds-crimson)']
            : ($mcQuote['within'] ? ["Inside the owner's repair limit - you can authorise it.", 'var(--ds-green)'] : ["Above the owner's repair limit - the owner must approve it.", 'var(--ds-crimson)']);
    }
@endphp
<section class="jc-card" id="jc-money" data-money-card>
    <h2>Money &amp; approval</h2>

    <dl class="wo-facts">
        @if($ownerSettings)
            <dt>Owner's limits</dt>
            <dd data-cost-owner-limits>
                <span data-limit-repair>Repairs: {{ $mcRepair !== null ? 'up to ' . $mcMoney($mcRepair) : 'not set - ask the owner' }}</span><br>
                <span data-limit-emergency>Emergencies: {{ $mcEmerg !== null ? 'up to ' . $mcMoney($mcEmerg) : 'not set - ask the owner' }}</span>
                <div class="text-xs" style="color: var(--text-muted);" data-limit-status>{{ $mcLimitStatus }}@if(($mcRepair === null || $mcEmerg === null) && $jobCard->property_id) &middot; <a class="underline" href="{{ route('corex.properties.show', ['property' => $jobCard->property_id, 'tab' => 'rental']) }}#owner-fault-settings-panel" data-owner-fault-settings-link>Owner's fault settings</a>@endif</div>
            </dd>
        @endif
        @if($pricesOn)
            <dt>Work order price (incl)</dt>
            <dd data-money-price><strong>{{ $mcMoney($mcQuote['amount']) }}</strong>
                @if($currentQuote && $quoteChanged)<span class="ds-badge" style="background: color-mix(in srgb, #f59e0b 18%, transparent); color: #b45309;">Changed since sent</span>@endif
                @if($mcVerdict)<div class="text-xs" style="color: {{ $mcVerdict[1] }};" data-owner-limit-verdict>{{ $mcVerdict[0] }}</div>@endif
            </dd>
        @endif
        <dt>Authorised</dt>
        <dd data-money-authorised data-authorised-state="{{ $screen['authorised']['key'] }}">{{ $screen['authorised']['text'] }}
            @if($mcWo?->openVariation())<div><span class="ds-badge ds-badge-warning" data-variation-waiting>Extra work waiting for the owner</span></div>@endif</dd>
        @if($currentQuote)
            <dt>Quote</dt>
            <dd>Rev {{ $currentQuote->revision }} sent {{ \App\Support\AppDate::dateTime($currentQuote->created_at, $screen['tz']) }}, {{ $mcMoney($currentQuote->amount) }}
                @if($currentQuote->document_storage_path) · <a href="{{ route('corex.rental-job-cards.quotes.download', [$jobCard, $currentQuote->id]) }}" target="_blank" class="underline">View</a>@endif
                @if($quoteRevisions->count() > 1)
                    <details class="jc-fold"><summary>All revisions ({{ $quoteRevisions->count() }})</summary>
                        <ul class="space-y-1 text-xs pt-1">
                            @foreach($quoteRevisions as $q)
                                <li @if($q->superseded_at) style="color: var(--text-muted);" @endif>Rev {{ $q->revision }} — {{ $mcMoney($q->amount) }} — {{ $q->quote_date?->format('Y-m-d') }}
                                    <span class="ds-badge {{ $q->superseded_at ? 'ds-badge-muted' : 'ds-badge-success' }}">{{ $q->superseded_at ? 'Superseded' : 'Current' }}</span>
                                    @if($q->document_storage_path)<a href="{{ route('corex.rental-job-cards.quotes.download', [$jobCard, $q->id]) }}" target="_blank" class="underline">View</a>@endif</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </dd>
        @endif
    </dl>

    @if($isOpen)
    <div id="jc-quote-box" class="pt-3 space-y-2">
        @if($currentQuote && $quoteChanged)
            <p class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;" data-quote-changed-note>This job card has changed since Rev {{ $currentQuote->revision }} was sent. Re-send to update the owner.</p>
        @endif
        @permission('rental_job_cards.send_quote')
            @if(! $mcLandlord)
                <p class="text-xs" style="color: var(--ds-crimson);">No landlord linked — link a landlord before sending the quote.
                    @if($jobCard->property && ! $jobCard->property->trashed())
                        <a href="{{ route('corex.properties.show', ['property' => $jobCard->property_id, 'tab' => 'contacts']) }}" class="underline">Link landlord</a>
                    @elseif($jobCard->property?->trashed())
                        (property archived)
                    @endif
                </p>
            @elseif($mcBaseline)
                {{-- BUILD 2 (§17.7.1) - once the owner has approved an amount a re-send would drop that approval; extra work now goes to the owner as a variation, raised automatically. --}}
                <p class="text-xs" style="color: var(--text-muted);">{{ $mcWo->approval_basis === \App\Models\RentalWorkOrder::BASIS_OWNER_DECISION ? 'The owner has approved this job.' : (($mcWo->approvalBasisLabel() ?: 'This job is approved') . ' — no quote to the owner is needed.') }} Extra work goes to the owner as a variation.</p>
            @elseif(! $stage['can']['price'])
                <p class="text-xs" style="color: var(--text-muted);" data-price-locked>{{ ! $jobCard->acceptedLines()->exists() ? 'Add at least one line before the price can be confirmed.' : 'Price every line first - then the price can be confirmed here.' }}</p>
            @endif
        @endpermission

        {{-- the form every "send the quote" button posts to - the Next bar's too (button form="jc-send-quote-form") --}}
        @if($mcQuoteFormOn)
            <form id="jc-send-quote-form" data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.send-quote', $jobCard) }}"
                  data-confirm="{{ $mcQuote['confirm'] }}" data-confirm-title="{{ $mcQuote['title'] }}" data-confirm-label="{{ $mcQuote['within'] ? 'Confirm price' : 'Send to owner' }}">
                @csrf
            </form>
        @endif

        {{-- ONE button --}}
        @if($mcNbIsMoney)
            @if($mcNb['kind'] === 'form')
                <button type="submit" form="{{ $mcNb['form'] }}" class="corex-btn-primary text-xs w-full" data-quote-button data-quote-intent="{{ $mcQuote['intent'] }}" style="min-height:34px;">{{ $mcNb['label'] }}</button>
            @else
                <a href="#jc-money" class="corex-btn-primary text-xs w-full" data-jc-go data-jc-click="{{ $mcNb['click'] }}" style="min-height:34px; display:inline-flex; align-items:center; justify-content:center;">{{ $mcNb['label'] }}</a>
            @endif
        @elseif($mcNeedsDecision && $mcCanRecord)
            <a href="#jc-money" class="corex-btn-primary text-xs w-full" data-jc-go data-jc-click="[data-cost-owner-decision-open]" style="min-height:34px; display:inline-flex; align-items:center; justify-content:center;">Record the owner's decision</a>
        @elseif($mcQuoteFormOn && ($quoteChanged ?? false))
            <button type="submit" form="jc-send-quote-form" class="corex-btn-primary text-xs w-full" data-quote-button data-quote-intent="{{ $mcQuote['intent'] }}" style="min-height:34px;">{{ $mcQuote['button'] }}</button>
        @endif
        {{-- the price can still be (re-)sent whenever it could before - as a quiet link when it is not the one button above --}}
        @if($mcQuoteFormOn && ! ($mcNbIsMoney && $mcNb['kind'] === 'form') && ! ($quoteChanged ?? false))
            <button type="submit" form="jc-send-quote-form" class="text-xs underline" style="color: var(--text-muted);" data-quote-button data-quote-intent="{{ $mcQuote['intent'] }}">{{ $mcQuote['button'] }}</button>
        @endif
    </div>
    @endif

    @if($mcWo)
        <div class="jc-money-embed pt-2" id="jc-approval-panel">
            @include('corex.rental-work-orders._cost-decision', ['workOrder' => $mcWo, 'isOpen' => $isOpen, 'cdCompact' => true, 'cdHideLimits' => true])
        </div>
        <div class="jc-money-embed pt-2">@include('corex.rental-work-orders._variation-panel', ['workOrder' => $mcWo])</div>
    @endif
</section>
