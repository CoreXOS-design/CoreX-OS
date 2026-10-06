{{--
    .ai/specs/rental-work-orders.md §17.4.4 / §17.5 — BUILD 1's panel on the job card:

      1. the Pricing panel — three optional percentage boxes (All lines % / Parts % / Labour %) with Apply. A box
         writes the card's markup and reprices every line that is not typed by hand or marked up on its own line;
         a blank box removes that markup. (rental_job_cards.price)
      2. "Ask crew to price this job" — with the office's note; disabled with the reason when no crew is assigned;
         can generate and email the crew's link in the same step. (rental_job_cards.share)
      3. "Added by crew — awaiting office" — the lines the crew sent. They are in NO total, quote, owner payload or
         tenant payload until the office presses Accept (and are never counted while awaiting or rejected).
         Per line: Accept (cost editable for someone who can see costs; selling filled by the §17.4.3 rules unless typed)
         or Reject (a reason the crew will read); and "Accept all". (rental_job_cards.price)

    Receives (from RentalJobCardController::show()): $jobCard, $isOpen, $pricesOn, $canPrice, $canViewCosts,
    $awaitingLines, $awaitingPhotos, $openPriceRequest, $submittedPriceRequest, $crewLinksEnabled, $crewLink.
    Cost, margin and the crew's raw figures are rendered ONLY for rental_job_cards.view_costs.
--}}
@if($pricesOn && $jobCard)
@php
    $pricingAgencyHasCrew = (bool) $jobCard->rental_crew_id;
    $canAsk = $isOpen && auth()->user()->hasPermission('rental_job_cards.share');
    $moneyFmt = fn ($v) => 'R' . number_format((float) $v, 2);
    $showPricingCard = ($canPrice && $isOpen) || $canAsk || $openPriceRequest || $submittedPriceRequest;
@endphp

@if($showPricingCard)
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="jc-pricing-box" data-pricing-panel>
    <h2 class="text-sm font-semibold">Pricing</h2>

    @if($errors->has('pricing'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">{{ $errors->first('pricing') }}</div>
    @endif

    @if($canPrice && $isOpen)
    {{-- §17.4.4 — card-level markup. The crew works on cost; these boxes decide what the owner is charged. --}}
    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.markup', $jobCard) }}" class="space-y-2" data-markup-form>
        @csrf
        <p class="text-xs" style="color: var(--text-muted);">Add a percentage on top of cost for the whole job. A line you priced by hand, or gave its own markup, is left alone.</p>
        <div class="grid grid-cols-3 gap-2">
            <label class="text-xs space-y-1"><span style="color: var(--text-muted);">All lines %</span>
                <input type="number" name="markup_all_percent" step="0.01" min="0" max="1000" value="{{ old('markup_all_percent', $jobCard->markup_all_percent !== null ? rtrim(rtrim(number_format((float) $jobCard->markup_all_percent, 2, '.', ''), '0'), '.') : '') }}" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></label>
            <label class="text-xs space-y-1"><span style="color: var(--text-muted);">Parts %</span>
                <input type="number" name="markup_parts_percent" step="0.01" min="0" max="1000" value="{{ old('markup_parts_percent', $jobCard->markup_parts_percent !== null ? rtrim(rtrim(number_format((float) $jobCard->markup_parts_percent, 2, '.', ''), '0'), '.') : '') }}" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></label>
            <label class="text-xs space-y-1"><span style="color: var(--text-muted);">Labour %</span>
                <input type="number" name="markup_labour_percent" step="0.01" min="0" max="1000" value="{{ old('markup_labour_percent', $jobCard->markup_labour_percent !== null ? rtrim(rtrim(number_format((float) $jobCard->markup_labour_percent, 2, '.', ''), '0'), '.') : '') }}" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></label>
        </div>
        <button type="submit" class="corex-btn-outline text-xs w-full">Apply</button>
    </form>
    @endif

    @if($openPriceRequest)
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;" data-price-request-status="open">
            <strong>Pricing requested</strong> {{ $openPriceRequest->requested_at?->format('Y-m-d H:i') }} — waiting for the crew.
            @if($openPriceRequest->note)<div style="margin-top:2px;">"{{ $openPriceRequest->note }}"</div>@endif
        </div>
    @elseif($awaitingLines->isNotEmpty())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);" data-price-request-status="submitted">
            <strong>Priced by crew — awaiting you</strong>
        </div>
    @endif

    @if($canAsk)
        @if(! $pricingAgencyHasCrew)
            <p class="text-xs" style="color: var(--text-muted);" data-ask-disabled>Ask crew to price this job — <em>assign a crew to this job card first</em>; there is nobody to ask yet.</p>
        @elseif($openPriceRequest)
            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.price-requests.close', [$jobCard, $openPriceRequest->id]) }}" onsubmit="return confirm('Close this request without waiting for the crew?');">
                @csrf
                <button type="submit" class="corex-btn-outline text-xs w-full">Close request</button>
            </form>
        @else
            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.price-requests.store', $jobCard) }}" class="space-y-2" data-ask-form>
                @csrf
                <textarea name="note" rows="2" maxlength="1000" placeholder="Note to the crew (optional) — e.g. &quot;please price the whole bathroom, not just the tap&quot;" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">{{ old('note') }}</textarea>
                @if($crewLinksEnabled && ! ($crewLink && ! ($crewLink->expires_at && $crewLink->expires_at->isPast())))
                    <label class="flex items-start gap-2 text-xs">
                        <input type="checkbox" name="generate_link" value="1" checked class="mt-0.5">
                        <span>The crew has no live link yet — create it now{{ $jobCard->crew?->email ? ' and email it to ' . $jobCard->crew->email : ' (it is shown once so you can send it)' }}.</span>
                    </label>
                @endif
                <button type="submit" class="corex-btn-primary text-xs w-full">Ask crew to price this job</button>
            </form>
        @endif
    @endif
</div>
@endif

{{-- §17.5.3 step 3 — what the crew sent. In no total, quote or owner/tenant payload until accepted. --}}
@if($awaitingLines->isNotEmpty())
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid #f59e0b;" id="jc-crew-awaiting" data-crew-awaiting>
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold">Added by crew — awaiting office <span class="ds-badge ds-badge-muted">{{ $awaitingLines->count() }}</span></h2>
        @if($canPrice && $isOpen)
            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-lines.accept-all', $jobCard) }}" onsubmit="return confirm('Accept every line the crew sent and price them by the rules?');">
                @csrf
                <button type="submit" class="corex-btn-outline text-xs">Accept all</button>
            </form>
        @endif
    </div>
    <p class="text-xs" style="color: var(--text-muted);">These are not counted anywhere yet — not in the total, the quote or what the owner sees — until you accept them.</p>

    @foreach($awaitingLines as $l)
        <div class="rounded-md p-2 space-y-2 text-xs" style="border: 1px solid var(--border);" data-awaiting-line="{{ $l->id }}">
            <div class="flex justify-between gap-2">
                <div>
                    <strong>{{ $l->description }}</strong>
                    <div style="color: var(--text-muted);">{{ ucfirst($l->type) }} · {{ rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.') }}{{ $l->unit ? ' ' . $l->unit : '' }}@if($l->origin === 'crew_extra') · extra @endif</div>
                </div>
                @if($canViewCosts)
                    <div class="text-right" style="white-space:nowrap;">{{ $l->unit_cost !== null ? $moneyFmt($l->unit_cost) . ' each' : 'no cost' }}@if($l->cost_total !== null)<div style="color: var(--text-muted);">{{ $moneyFmt($l->cost_total) }}</div>@endif</div>
                @endif
            </div>
            @if($l->crew_note)<div>“{{ $l->crew_note }}”</div>@endif
            <div style="color: var(--text-muted);">{{ $l->crew_added_by_label }}{{ $l->crew_added_at ? ' · ' . $l->crew_added_at->format('Y-m-d H:i') : '' }}</div>
            @if(($awaitingPhotos[$l->id] ?? collect())->isNotEmpty())
                <div class="flex gap-1 flex-wrap">@foreach($awaitingPhotos[$l->id] as $ph)<a href="{{ $ph->storage_path }}" target="_blank"><img src="{{ $ph->storage_path }}" class="rounded" style="width:56px; height:56px; object-fit:cover;" alt=""></a>@endforeach</div>
            @endif

            @if($canPrice && $isOpen)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-lines.accept', [$jobCard, $l->id]) }}" class="flex items-end gap-2 flex-wrap">
                    @csrf
                    @if($canViewCosts)
                        <label class="space-y-1"><span style="color: var(--text-muted);">Cost each</span>
                            <input type="number" name="unit_cost" step="0.01" min="0" value="{{ $l->unit_cost !== null ? number_format((float) $l->unit_cost, 2, '.', '') : '' }}" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border); width: 90px;"></label>
                    @endif
                    <label class="space-y-1"><span style="color: var(--text-muted);">Selling each</span>
                        <input type="number" name="unit_price" step="0.01" min="0" placeholder="auto" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border); width: 90px;"></label>
                    <button type="submit" class="corex-btn-primary text-xs">Accept</button>
                </form>
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-lines.reject', [$jobCard, $l->id]) }}" class="flex items-end gap-2 flex-wrap">
                    @csrf
                    <input type="text" name="reason" required maxlength="1000" placeholder="Why not? (the crew will see this)" class="flex-1 rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border); min-width: 140px;">
                    <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Reject</button>
                </form>
            @else
                <div style="color: var(--text-muted);">Someone who can price job cards needs to accept or reject this line.</div>
            @endif
        </div>
    @endforeach
</div>
@endif
@endif
