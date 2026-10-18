{{-- The price action (confirm within the limit / send above it). Receives $jobCard, $stage and everything show.blade.php has in scope. --}}
            {{-- 4. Next steps — only when they apply, in order --}}
            {{-- 4. Quote to the owner. §14.21/§14.23 — offered on EVERY open card (Draft, Quoted, Approved, Scheduled, In progress): first send and every re-send (the next revision replaces the old one). It used to need a sent quote or a Draft/Quoted status, so scheduling a Draft card before its quote was sent hid the box for good. Completed/Cancelled cards are locked, so no box (the service refuses too). --}}
            @if($isOpen)
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="jc-quote-box">
                <h2 class="text-sm font-semibold">{{ $stage['quote']['title'] }}</h2>
                @if($currentQuote)
                    <p class="text-xs">
                        Last sent: <strong>Rev {{ $currentQuote->revision }}</strong>, R{{ number_format((float) $currentQuote->amount, 2) }}, {{ $currentQuote->created_at?->format('Y-m-d H:i') }}.
                    </p>
                    @if($quoteChanged)
                        <p class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;">
                            This job card has changed since Rev {{ $currentQuote->revision }} was sent. Re-send to update the owner.
                        </p>
                    @else
                        <p class="text-xs" style="color: var(--text-muted);">The owner has the latest version — nothing has changed since it was sent.</p>
                    @endif
                @endif
                @if($quoteRevisions->isNotEmpty())
                    <ul class="space-y-1 text-xs">
                        @foreach($quoteRevisions as $q)
                            <li class="flex flex-wrap items-center gap-x-2" @if($q->superseded_at) style="color: var(--text-muted);" @endif>
                                <span>Rev {{ $q->revision }} — R{{ number_format((float) $q->amount, 2) }} — {{ $q->quote_date?->format('Y-m-d') }}</span>
                                @if($q->superseded_at)
                                    <span class="ds-badge ds-badge-muted">Superseded</span>
                                @else
                                    <span class="ds-badge ds-badge-success">Current</span>
                                @endif
                                @if($q->document_storage_path)
                                    <a href="{{ route('corex.rental-job-cards.quotes.download', [$jobCard, $q->id]) }}" target="_blank" class="underline">View</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @permission('rental_job_cards.send_quote')
                @if(!$jobCard->property?->landlordContact())
                    <p class="text-xs" style="color: var(--ds-crimson);">No landlord linked — link a landlord before sending the quote.
                        @if($jobCard->property && !$jobCard->property->trashed())
                            <a href="{{ route('corex.properties.show', ['property' => $jobCard->property_id, 'tab' => 'contacts']) }}" class="underline">Link landlord</a>
                        @elseif($jobCard->property?->trashed())
                            (property archived)
                        @endif
                    </p>
                @elseif($jobCard->workOrder?->hasApprovedBaseline())
                    {{-- BUILD 2 (§17.7.1) — once the owner has approved an amount a re-send would drop that approval; extra work now goes to the owner as a variation instead, raised automatically. --}}
                    {{-- Reconciliation 7 Oct: this used to say "The owner has approved this job" even when the job was only COVERED by the owner's no-approval limit (nobody asked him). The words follow the basis, as the approval panel's own chip does. --}}
                    <p class="text-xs" style="color: var(--text-muted);">{{ $jobCard->workOrder->approval_basis === \App\Models\RentalWorkOrder::BASIS_OWNER_DECISION ? 'The owner has approved this job.' : ($jobCard->workOrder->approvalBasisLabel() ?: 'This job is approved') . ' — no quote to the owner is needed.' }} Any extra work is put to the owner automatically as a variation — see the approval panel above.</p>
                @else
                @if(! $stage['can']['price'])
                    <p class="text-xs" style="color: var(--text-muted);" data-price-locked>{{ ! $jobCard->acceptedLines()->exists() ? 'Add at least one line before the price can be confirmed.' : 'Price every line first - then the price can be confirmed here.' }}</p>
                @else
                <p class="text-xs" data-quote-intent="{{ $stage['quote']['intent'] }}">{{ $stage['quote']['note'] }}</p>
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.send-quote', $jobCard) }}"
                      data-confirm="{{ $stage['quote']['confirm'] }}" data-confirm-title="{{ $stage['quote']['title'] }}" data-confirm-label="{{ $stage['quote']['within'] ? 'Confirm price' : 'Send to owner' }}">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs" data-quote-button>{{ $stage['quote']['button'] }}</button>
                </form>
                @endif
                @endif
                @endpermission
            </div>
            @endif

