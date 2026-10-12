{{-- AT-452 round 2 (§19.5) - whether and how the crew was told about this job; shown inside the crew panel and, on a card that is still in the
     draft / quote stages (where that panel is hidden), on its own so a NOT-sent crew link is never invisible. Expects $jobCard. --}}
        {{-- did the crew actually get told? Says so in plain words, with a Send that needs no typed address, and a WhatsApp
             that is only "sent" after the agent opened it. The "Email a link" form below stays as the Resend. --}}
        @php
            $cs = app(\App\Services\Rentals\RentalContractorJobService::class)->crewStatusFor($jobCard);
            $crewPressKey = (string) \Illuminate\Support\Str::uuid();
        @endphp
        <div class="space-y-1 text-xs" data-crew-send-status>
            @if($cs['sent'])
                <p data-crew-sent><span class="ds-badge ds-badge-success">Crew told</span> {{ $cs['sent']->sent_at?->format('j M Y H:i') }} by {{ $cs['sent']->channelLabel() }}@if($cs['sent']->destination) to {{ $cs['sent']->destination }}@endif @if($cs['sent']->sentBy)&mdash; {{ $cs['sent']->sentBy->name }}@endif</p>
            @elseif($cs['not_sent'])
                <p class="font-semibold" style="color: var(--ds-crimson, #b3261e);" data-crew-not-sent>NOT sent &mdash; {{ $cs['not_sent']->error ?: 'the crew could not be reached.' }}</p>
            @else
                <p style="color: var(--text-muted);" data-crew-not-sent-yet>The crew has not been sent their job link yet.</p>
            @endif
            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.send', $jobCard) }}">
                @csrf
                <input type="hidden" name="press_key" value="{{ $crewPressKey }}">
                <button type="submit" class="corex-btn-primary text-xs w-full" data-crew-send>{{ $cs['sent'] ? 'Send the crew link again' : 'Send the crew their link now' }}</button>
            </form>
            @if($cs['whatsapp'])
                <div class="flex flex-wrap items-center gap-2" data-crew-whatsapp-row>
                    <span>WhatsApp to {{ $cs['whatsapp']->destination }}:</span>
                    <form method="POST" action="{{ route('corex.rental-job-cards.crew-link.whatsapp-open', [$jobCard, $cs['whatsapp']->id]) }}" target="_blank">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs" data-crew-whatsapp-open>{{ $cs['whatsapp']->status === 'opened' ? 'Open WhatsApp again' : 'Open WhatsApp and send' }}</button>
                    </form>
                    @if($cs['whatsapp']->status === 'opened')
                        <form method="POST" action="{{ route('corex.rental-job-cards.crew-link.whatsapp-sent', [$jobCard, $cs['whatsapp']->id]) }}">
                            @csrf
                            <button type="submit" class="corex-btn-primary text-xs" data-crew-whatsapp-mark-sent>Mark as sent</button>
                        </form>
                    @else
                        <span style="color: var(--text-muted);">&ldquo;Mark as sent&rdquo; appears after you open WhatsApp.</span>
                    @endif
                </div>
            @endif
        </div>

