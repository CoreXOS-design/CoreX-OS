{{--
    .ai/specs/rental-work-orders.md §14.29 — the "Crew link" panel on the Rental Crews
    edit page: ONE standing link per crew (the crew page — the jobs booked for THIS
    crew, on their phone, no login). Generate / regenerate, email, WhatsApp, revoke,
    and the link's own log. Only the link's hash is stored, so the URL is shown
    exactly once, right after it is generated.
--}}
@permission('rental_job_cards.share')
@php
    $panel = app(\App\Services\Rentals\RentalCrewScheduleService::class)->linkPanelFor($crew);
    $newUrl = session('crew_link_url');
    $stateStyle = match ($panel['state']) {
        'live' => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
        'never' => 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);',
        default => 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;',
    };
    $eventLabels = [
        'issued' => 'Link created', 'regenerated' => 'Link regenerated', 'emailed' => 'Link emailed', 'revoked' => 'Link revoked',
        'opened' => 'Crew page opened', 'job_opened' => 'Job opened', 'action' => 'Crew action',
    ];
    $crewLinksOn = \App\Models\RentalPortalSetting::crewLinksEnabledFor($crew->agency_id);
@endphp
<div id="crew-link-panel" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold">Crew link</h2>
        <span class="text-xs rounded-full px-2.5 py-0.5 font-semibold" style="{{ $stateStyle }}" data-crew-link-state="{{ $panel['state'] }}">{{ $panel['label'] }}</span>
    </div>
    <p class="text-xs" style="color: var(--text-muted);">
        One link for the whole crew. They open it on their phone — no login — and see only the jobs booked for this crew: where, when, what to load, with their tasks, photos and sign-off.
    </p>

    @if(! $crewLinksOn)
        <p class="text-xs" style="color:#991b1b;">Crew links are switched off for your agency (Settings → Rental Portal), so no crew link works right now.</p>
    @endif
    @if(! $crew->is_active)
        <p class="text-xs" style="color:#991b1b;">This crew is switched off — a link only works for an active crew.</p>
    @endif

    @if($panel['state'] !== 'never')
        <div class="text-xs space-y-0.5" style="color: var(--text-muted);" data-crew-link-status>
            <div>Created {{ $panel['issued_at']?->format('j M Y H:i') }}@if($panel['issued_by']) by {{ $panel['issued_by'] }}@endif</div>
            <div>@if($panel['expires_at']) Valid until {{ $panel['expires_at']->format('j M Y') }} @else Stands until it is revoked @endif</div>
            <div>@if($panel['last_used_at']) Last used {{ $panel['last_used_at']->format('j M Y H:i') }} @else Not opened yet @endif · opened {{ $panel['open_count'] }} {{ $panel['open_count'] === 1 ? 'time' : 'times' }}</div>
        </div>
    @endif

    @if($newUrl)
        <div class="rounded-md p-3 space-y-2" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)); border: 1px solid var(--brand-icon, #0ea5e9);" data-crew-link-new>
            <div class="text-xs font-semibold">Copy this link now — it is shown only once.</div>
            <div class="flex gap-2">
                <input id="crew-link-url" type="text" readonly value="{{ $newUrl }}" class="prop-input w-full text-xs" onclick="this.select()">
                <button type="button" class="corex-btn-outline text-xs" id="crew-link-copy">Copy</button>
            </div>
            <div class="flex flex-wrap gap-2 items-end">
                <a class="corex-btn-outline text-xs" target="_blank" rel="noopener"
                   href="https://wa.me/?text={{ rawurlencode('Hi ' . $crew->name . ' — your jobs page: ' . $newUrl) }}">Send on WhatsApp</a>
                <form method="POST" action="{{ route('corex.rental-crews.link.email', $crew) }}" class="flex flex-wrap gap-2 items-end">
                    @csrf
                    <input type="hidden" name="link_url" value="{{ $newUrl }}">
                    <input type="email" name="to" value="{{ old('to', $crew->email) }}" placeholder="Email address" aria-label="Email the link to" maxlength="191" required class="prop-input text-xs" style="min-width:200px;">
                    <button type="submit" class="corex-btn-primary text-xs">Email this link</button>
                </form>
            </div>
        </div>
        <script>
            (function () {
                var btn = document.getElementById('crew-link-copy'), input = document.getElementById('crew-link-url');
                if (!btn || !input) { return; }
                btn.addEventListener('click', function () {
                    function done() { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 2000); }
                    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(input.value).then(done, function () { input.select(); document.execCommand('copy'); done(); }); }
                    else { input.select(); document.execCommand('copy'); done(); }
                });
            })();
        </script>
    @endif

    @if($crew->is_active && $crewLinksOn)
        <form method="POST" action="{{ route('corex.rental-crews.link.issue', $crew) }}" class="flex flex-wrap items-end gap-2"
              @if($panel['state'] === 'live') data-confirm="Regenerate the link? The current link stops working straight away, so the crew must be given the new one." data-confirm-danger data-confirm-label="Regenerate" @endif>
            @csrf
            @if($crew->email)
                {{-- Never pre-filled into a text box: pressing Create / Regenerate must not quietly email anyone. --}}
                <label class="flex items-center gap-2 text-xs" style="color: var(--text-muted);">
                    <input type="checkbox" name="email_to" value="{{ $crew->email }}" class="rounded" data-crew-link-email-crew>
                    Also email the new link to {{ $crew->email }}
                </label>
            @endif
            <button type="submit" class="corex-btn-primary text-xs" data-crew-link-generate>{{ $panel['state'] === 'live' ? 'Regenerate link' : 'Create link' }}</button>
        </form>
        <p class="text-xs" style="color: var(--text-muted);">The new link is shown once, right after it is created — copy it, send it on WhatsApp, or email it to any address. Regenerating replaces the old link — it stops working on its very next use.</p>
    @endif

    @if($panel['state'] === 'live')
        <form method="POST" action="{{ route('corex.rental-crews.link.revoke', $crew) }}" data-confirm="Revoke the link? It stops working straight away." data-confirm-danger data-confirm-label="Revoke">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-xs" style="color:#991b1b;" data-crew-link-revoke>Revoke link</button>
        </form>
    @endif

    <div class="pt-2" style="border-top: 1px solid var(--border);">
        <div class="flex items-center justify-between">
            <h3 class="text-xs font-semibold">Link log</h3>
            @if($panel['events']->isNotEmpty())<a href="{{ route('corex.rental-crews.link.events', $crew) }}" class="text-xs underline" style="color: var(--brand-icon, #0ea5e9);">Full log</a>@endif
        </div>
        @forelse($panel['events'] as $ev)
            <div class="text-xs flex justify-between gap-2" style="padding:4px 0; border-top:1px solid var(--border);" data-crew-link-event="{{ $ev->event }}">
                <div class="min-w-0">
                    <strong>{{ $eventLabels[$ev->event] ?? $ev->event }}</strong>
                    @if($ev->note)<span style="color: var(--text-muted);"> — {{ $ev->note }}</span>@endif
                    @if($ev->actor)<span style="color: var(--text-muted);"> ({{ $ev->actor->name }})</span>@endif
                </div>
                <div class="flex-shrink-0" style="color: var(--text-muted);">{{ $ev->created_at?->format('j M H:i') }}</div>
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">Nothing yet — the log fills as the link is created and used.</p>
        @endforelse
    </div>
</div>
@endpermission
