{{--
    .ai/specs/rental-work-orders.md §17.42 — the crew's HOME link as the office reads it, one line:
    "Home link: emailed 15 Oct · opened 3 times · Resend · Regenerate". Used on the Rental Crews list and
    on the crew record's panel, so the two can never say different things.
    Expects: $crew (RentalCrew), $ls (RentalCrewScheduleService::linkStatusesFor() row),
             $withRegenerate (bool — the panel has its own Create/Regenerate form, so it passes false).
    Only a hash of the link is stored, so Resend mints a FURTHER link for this send; the one the crew already
    holds keeps working. Regenerate is the one that kills it.
--}}
@php
    $live = $ls['state'] === 'live';
    $sentText = $ls['sent_at']
        ? ($ls['sent_via'] === 'whatsapp' ? 'WhatsApp opened ' : 'emailed ') . $ls['sent_at']->format('j M')
        : 'not sent yet';
    $openedText = $ls['open_count'] > 0 ? 'opened ' . $ls['open_count'] . ($ls['open_count'] === 1 ? ' time' : ' times') : 'not opened yet';
    $canAct = $crew->is_active && \App\Models\RentalPortalSetting::crewLinksEnabledFor($crew->agency_id);
    $linkBtn = 'font-size:12px; text-decoration:underline; background:none; border:0; padding:0; cursor:pointer;';
@endphp
<div data-crew-home-link="{{ $ls['state'] }}">
    @if($live)
        <div style="color: var(--text-muted);" data-crew-home-link-summary>Home link: {{ $sentText }} · {{ $openedText }}</div>
        @if($canAct)
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1" style="margin-top:2px;">
                <span style="color: var(--text-muted);">Resend:</span>
                @if($crew->email)
                    <form method="POST" action="{{ route('corex.rental-crews.link.resend', $crew) }}" class="inline">
                        @csrf<input type="hidden" name="channel" value="email">
                        <button type="submit" style="{{ $linkBtn }} color: var(--brand-icon, #0ea5e9);" data-crew-home-link-resend="email" title="Email the home link to {{ $crew->email }}">Email</button>
                    </form>
                @else
                    <span style="color: var(--text-muted);" title="Add an email address to the crew to email its link">Email (no address)</span>
                @endif
                @if(strlen(\App\Support\WhatsAppNumberFormatter::forDeepLink((string) $crew->phone)) >= 10)
                    <form method="POST" action="{{ route('corex.rental-crews.link.resend', $crew) }}" class="inline" target="_blank">
                        @csrf<input type="hidden" name="channel" value="whatsapp">
                        <button type="submit" style="{{ $linkBtn }} color: var(--brand-icon, #0ea5e9);" data-crew-home-link-resend="whatsapp" title="Open WhatsApp to {{ $crew->phone }} with the link ready">WhatsApp</button>
                    </form>
                @else
                    <span style="color: var(--text-muted);" title="Add a mobile number to the crew to use WhatsApp">WhatsApp (no mobile)</span>
                @endif
                @if($withRegenerate)
                    <span style="color: var(--text-muted);">·</span>
                    <form method="POST" action="{{ route('corex.rental-crews.link.issue', $crew) }}" class="inline"
                          data-confirm="Regenerate the home link? The current link stops working straight away, so the crew must be given the new one." data-confirm-danger data-confirm-label="Regenerate">
                        @csrf
                        <button type="submit" style="{{ $linkBtn }} color:#991b1b;" data-crew-home-link-regenerate>Regenerate</button>
                    </form>
                @endif
            </div>
        @endif
    @elseif($ls['state'] === 'never')
        <div style="color: var(--text-muted);">Home link: none yet</div>
        @if($canAct && $withRegenerate)
            <form method="POST" action="{{ route('corex.rental-crews.link.issue', $crew) }}" class="inline" style="margin-top:2px; display:inline-block;">
                @csrf
                <button type="submit" style="{{ $linkBtn }} color: var(--brand-icon, #0ea5e9);" data-crew-home-link-create>Create link</button>
            </form>
        @endif
    @else
        <div style="color:#991b1b;">Home link: {{ strtolower($ls['label']) }}</div>
        @if($canAct && $withRegenerate)
            <form method="POST" action="{{ route('corex.rental-crews.link.issue', $crew) }}" class="inline" style="margin-top:2px; display:inline-block;">
                @csrf
                <button type="submit" style="{{ $linkBtn }} color: var(--brand-icon, #0ea5e9);" data-crew-home-link-create>Create new link</button>
            </form>
        @endif
    @endif
</div>
