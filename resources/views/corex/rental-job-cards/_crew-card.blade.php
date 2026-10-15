{{--
    AT-460 - "Crew": the one card for the crew - who, whether they were told (one line), Resend link · WhatsApp (equal buttons), assign / change crew, Scheduled / Due, Mark in progress.
    Everything that used to sit in four cards (who & when, crew told?, share with crew, ask crew to price) is here, every form on the route it always had. The less-used
    link tools (email a link to an address, generate / re-issue / revoke) and "ask the crew to price" are folded in this card, not separate cards.
    Receives what show.blade.php has in scope: $jobCard, $isOpen, $stage, $crews, $crewLink, $crewLinksEnabled, $crewStatus, $pricesOn, $openPriceRequest, $awaitingLines.
--}}
@php
    $ccCrew = $jobCard->crew;
    $ccTz = $screen['tz'];
    $ccCs = $crewStatus;
    $ccPressKey = (string) \Illuminate\Support\Str::uuid();
    $ccFreshUrl = session('crew_link_url');
    $ccFreshToken = session('crew_link_token');
    $ccWaNumber = \App\Support\WhatsAppNumberFormatter::forDeepLink($ccCrew?->phone);
    $ccWaText = rawurlencode('Job: ' . $jobCard->title . ' — ' . ($ccFreshUrl ?? ''));
    $ccLinkExpired = $crewLink && $crewLink->expires_at && $crewLink->expires_at->isPast();
    $ccLinkLive = $crewLink && ! $ccLinkExpired;
    $ccCanShare = $isOpen && $crewLinksEnabled && auth()->user()->hasPermission('rental_job_cards.share');
    $ccCanAsk = $isOpen && $pricesOn && auth()->user()->hasPermission('rental_job_cards.share');
@endphp
<section class="jc-card" id="jc-crew" data-crew-card>
    <h2>Crew</h2>

    <div class="text-sm">
        @if($ccCrew)
            <strong>{{ $ccCrew->name }}</strong>@if($ccCrew->trashed()) <span style="color: var(--text-muted);">(archived)</span>@endif
            @if($ccCrew->members->isNotEmpty())<div class="text-xs" style="color: var(--text-muted);">{{ $ccCrew->members->pluck('name')->implode(', ') }}</div>@endif
        @elseif($jobCard->assigned_user_id)
            {{-- 2026-10-05 - legacy only: a card assigned before crews existed. Read-only - never re-selectable, never written to again. --}}
            <span style="color: var(--text-muted);">Previously assigned: {{ $jobCard->assignedUser?->name ?? '—' }}</span>
        @else
            <span style="color: var(--text-muted);">No crew assigned yet</span>
        @endif
    </div>

    @if($ccCanShare)
        {{-- did the crew actually get told? One line in plain words; the buttons are the Resend and the WhatsApp --}}
        <div class="text-xs pt-2" data-crew-send-status>
            @if($ccCs['sent'])
                <p data-crew-sent><span class="ds-badge ds-badge-success">Crew told</span> {{ \App\Support\AppDate::dateTime($ccCs['sent']->sent_at, $ccTz) }} by {{ $ccCs['sent']->channelLabel() }}@if($ccCs['sent']->destination) to {{ $ccCs['sent']->destination }}@endif @if($ccCs['sent']->sentBy)&mdash; {{ $ccCs['sent']->sentBy->name }}@endif</p>
            @elseif($ccCs['not_sent'])
                <p class="font-semibold" style="color: var(--ds-crimson, #b3261e);" data-crew-not-sent>NOT sent &mdash; {{ $ccCs['not_sent']->error ?: 'the crew could not be reached.' }}</p>
            @else
                <p style="color: var(--text-muted);" data-crew-not-sent-yet>The crew has not been sent their job link yet.</p>
            @endif
        </div>

        @if($ccFreshUrl)
            <div class="space-y-1 pt-2">
                <div class="flex gap-2">
                    <input type="text" readonly value="{{ $ccFreshUrl }}" id="jc-crew-link-url" aria-label="Crew link" class="flex-1 min-w-0 rounded-md px-2 text-xs" style="border: 1px solid var(--border); height: 30px;" onclick="this.select()">
                    <button type="button" class="corex-btn-primary text-xs" onclick="navigator.clipboard.writeText(document.getElementById('jc-crew-link-url').value).then(()=>{this.textContent='Copied'})">Copy</button>
                </div>
                <p class="text-xs" style="color: var(--ds-crimson);">Shown once — copy it now.</p>
                <a href="https://wa.me/{{ $ccWaNumber }}?text={{ $ccWaText }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs inline-block">Send by WhatsApp</a>
            </div>
        @endif

        <div class="jc-btnrow-eq pt-2">
            <form id="jc-crew-send-form" data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.send', $jobCard) }}">
                @csrf
                <input type="hidden" name="press_key" value="{{ $ccPressKey }}">
                <button type="submit" class="corex-btn-primary text-xs" data-crew-send>{{ $ccCs['sent'] ? 'Resend link' : 'Send link' }}</button>
            </form>
            @if($ccCs['whatsapp'])
                <form method="POST" action="{{ route('corex.rental-job-cards.crew-link.whatsapp-open', [$jobCard, $ccCs['whatsapp']->id]) }}" target="_blank" data-crew-whatsapp-row>
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs" data-crew-whatsapp-open title="WhatsApp to {{ $ccCs['whatsapp']->destination }}">WhatsApp</button>
                </form>
            @endif
        </div>
        @if($ccCs['whatsapp'])
            <div class="text-xs pt-1" style="color: var(--text-muted);">
                WhatsApp to {{ $ccCs['whatsapp']->destination }}:
                @if($ccCs['whatsapp']->status === 'opened')
                    <form method="POST" action="{{ route('corex.rental-job-cards.crew-link.whatsapp-sent', [$jobCard, $ccCs['whatsapp']->id]) }}" class="inline">
                        @csrf
                        <button type="submit" class="underline" style="color: var(--brand-icon, #0ea5e9);" data-crew-whatsapp-mark-sent>Mark as sent</button>
                    </form>
                @else
                    &ldquo;Mark as sent&rdquo; appears after you open WhatsApp.
                @endif
            </div>
        @endif
    @endif

    @permission('rental_job_cards.create')
    @if($isOpen)
        <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.assign-crew', $jobCard) }}" class="flex gap-2 pt-3">
            @csrf
            <select name="rental_crew_id" required data-assign-crew-select aria-label="Crew" class="flex-1 min-w-0 rounded-md px-2 text-xs" style="border: 1px solid var(--border); height: 34px;">
                <option value="">Select crew…</option>
                @foreach($crews as $c)
                    <option value="{{ $c->id }}" @selected($jobCard->rental_crew_id === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-outline text-xs" style="min-height:34px;">{{ $jobCard->rental_crew_id ? 'Change' : 'Assign' }}</button>
        </form>
        @permission('rental_catalogue.manage')
        @feature('rental-crews')
        <a href="{{ route('corex.rental-crews.index') }}" class="text-xs underline" style="color: var(--text-muted);">Manage crews</a>
        @endfeature
        @endpermission

        @if($stage['can']['schedule'])
        <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.schedule', $jobCard) }}" class="pt-3 space-y-2">
            @csrf
            <div class="grid grid-cols-2 gap-2">
                <label class="text-xs"><span style="color: var(--text-muted); display:block;">Scheduled</span>
                    <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $jobCard->scheduleInputValue('scheduled_at')) }}" aria-label="Scheduled" class="w-full rounded-md px-2 text-xs" style="border: 1px solid var(--border); height: 34px;"></label>
                <label class="text-xs"><span style="color: var(--text-muted); display:block;">Due</span>
                    <input type="datetime-local" name="due_at" value="{{ old('due_at', $jobCard->scheduleInputValue('due_at')) }}" aria-label="Due" class="w-full rounded-md px-2 text-xs" style="border: 1px solid var(--border); height: 34px;"></label>
            </div>
            <button type="submit" class="corex-btn-outline text-xs w-full" style="min-height:34px;">Set</button>
        </form>
        @elseif(in_array($stage['key'], ['draft', 'quoted', 'owner_declined'], true))
            <p class="text-xs pt-3" style="color: var(--text-muted);" data-schedule-locked>Booking a date opens once the job is approved.</p>
        @endif
        @if($stage['can']['start'])
        <form id="jc-start-form" data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.start', $jobCard) }}" class="pt-2">
            @csrf
            <button type="submit" class="corex-btn-outline text-xs w-full" style="min-height:34px;">Mark in progress</button>
        </form>
        @endif
    @endif
    @endpermission

    @if($ccCanAsk)
    <details class="jc-fold pt-3" id="jc-price-request" @if($openPriceRequest) open @endif>
        <summary>Ask the crew to price this job</summary>
        <div class="pt-2 space-y-2">
            @if($openPriceRequest)
                <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309;" data-price-request-status="open">
                    <strong data-chip-pricing>Pricing requested</strong> {{ $openPriceRequest->requested_at?->format('Y-m-d H:i') }} — waiting for the crew.
                    @if($openPriceRequest->note)<div style="margin-top:2px;">"{{ $openPriceRequest->note }}"</div>@endif
                </div>
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.price-requests.close', [$jobCard, $openPriceRequest->id]) }}" data-confirm="Close this request without waiting for the crew?">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs w-full">Close request</button>
                </form>
            @elseif(! $jobCard->rental_crew_id)
                <p class="text-xs" style="color: var(--text-muted);" data-ask-disabled>Ask crew to price this job — <em>assign a crew to this job card first</em>; there is nobody to ask yet.</p>
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
        </div>
    </details>
    @endif

    @if($ccCanShare && $stage['can']['crew_link'])
    <details class="jc-fold pt-3" id="jc-crew-link-box" @if($ccFreshUrl) open @endif>
        <summary>Share with crew — more ways</summary>
        <div class="pt-2 space-y-2">
            @if($crewLink && ! $ccFreshUrl)
                <div class="text-xs space-y-0.5">
                    <div>{{ $ccLinkLive ? 'Link active' : 'Link expired' }} — made by {{ $crewLink->createdByUser?->name ?? 'unknown' }}, {{ $crewLink->created_at?->format('Y-m-d H:i') }}</div>
                    <div style="color: var(--text-muted);">{{ $crewLink->expires_at ? ($ccLinkLive ? 'Valid until ' : 'Expired ') . $crewLink->expires_at->format('Y-m-d H:i') : 'No expiry' }} · Last opened: {{ $crewLink->last_used_at?->format('Y-m-d H:i') ?? 'never' }}</div>
                </div>
            @endif

            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.email', $jobCard) }}" class="space-y-2">
                @csrf
                @if($ccFreshToken)<input type="hidden" name="link_token" value="{{ $ccFreshToken }}">@endif
                <input type="email" name="email" required maxlength="191" value="{{ old('email', $ccCrew?->email) }}" placeholder="Email address" aria-label="Email the link to" class="w-full rounded-md px-2 text-xs" style="border: 1px solid var(--border); height: 30px;">
                <button type="submit" class="corex-btn-outline text-xs w-full"
                    @if(! $ccFreshToken && $crewLink) data-confirm="This creates a new link and emails it. The old link stops working." data-confirm-danger data-confirm-label="This" @endif>
                    {{ $ccFreshToken ? 'Email this link' : 'Email a link' }}
                </button>
            </form>

            <div class="jc-btnrow-eq">
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.issue', $jobCard) }}"
                      @if($crewLink) data-confirm="Create a new link? The old link stops working." data-confirm-danger data-confirm-label="Create" @endif>
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">{{ $crewLink ? 'Re-issue link' : 'Generate link' }}</button>
                </form>
                @if($crewLink)
                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.revoke', $jobCard) }}" data-confirm="Revoke the link? It stops opening immediately." data-confirm-danger data-confirm-label="Revoke">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Revoke</button>
                    </form>
                @endif
            </div>
        </div>
    </details>
    @elseif($isOpen && ! $crewLinksEnabled && $stage['can']['crew_link'] && auth()->user()->hasPermission('rental_job_cards.share'))
        <p class="text-xs pt-3" style="color: var(--text-muted);">Crew links are switched off for this agency (Settings → Rental Portal).</p>
    @endif
</section>

@permission('rental_job_cards.cancel')
@if($isOpen)
<div class="px-1" data-cancel-job-card>
    <button type="button" onclick="document.getElementById('cancel-job-card-form').classList.toggle('hidden')" class="jc-cancel-link">Cancel job card</button>
    <form data-keep-scroll id="cancel-job-card-form" method="POST" action="{{ route('corex.rental-job-cards.cancel', $jobCard) }}" class="hidden space-y-2 pt-2">
        @csrf
        <textarea name="cancel_reason" required placeholder="Reason for cancellation" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
        <button type="submit" class="corex-btn-outline text-xs w-full" style="color: var(--ds-crimson);">Confirm cancel</button>
    </form>
</div>
@endif
@endpermission
