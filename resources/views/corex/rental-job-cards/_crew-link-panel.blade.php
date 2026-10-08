{{--
    .ai/specs/rental-work-orders.md §14.28 — "Share with crew": the no-login
    per-job link. Open cards only. The raw URL is flashed ONCE by the issuing
    request ($crewLinkUrl) and can never be shown again — afterwards the panel
    shows who made the link, when it dies and when it was last opened.

    Expects: $jobCard, $crewLink (newest unrevoked token or null), $crewLinksEnabled,
             session('crew_link_url'), session('crew_link_token').
--}}
@permission('rental_job_cards.share')
@if($isOpen)
@php
    $freshUrl = session('crew_link_url');
    $freshToken = session('crew_link_token');
    $crew = $jobCard->crew;
    $waNumber = \App\Support\WhatsAppNumberFormatter::forDeepLink($crew?->phone);
    $waText = rawurlencode('Job: ' . $jobCard->title . ' — ' . ($freshUrl ?? ''));
    $linkExpired = $crewLink && $crewLink->expires_at && $crewLink->expires_at->isPast();
    $linkLive = $crewLink && ! $linkExpired;
@endphp
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="jc-crew-link-box">
    <h2 class="text-sm font-semibold">Share with crew</h2>

    @if(! $crewLinksEnabled)
        <p class="text-xs" style="color: var(--text-muted);">Crew links are switched off for this agency (Settings → Rental Portal).</p>
    @else
        @if($freshUrl)
            <div class="space-y-2">
                <div class="flex gap-2">
                    <input type="text" readonly value="{{ $freshUrl }}" id="jc-crew-link-url" aria-label="Crew link" class="flex-1 min-w-0 rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);" onclick="this.select()">
                    <button type="button" class="corex-btn-primary text-xs" onclick="navigator.clipboard.writeText(document.getElementById('jc-crew-link-url').value).then(()=>{this.textContent='Copied'})">Copy</button>
                </div>
                <p class="text-xs" style="color: var(--ds-crimson);">Shown once — copy it now.</p>
                <a href="https://wa.me/{{ $waNumber }}?text={{ $waText }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs inline-block">Send by WhatsApp</a>
            </div>
        @endif

        @if($crewLink && ! $freshUrl)
            <div class="text-xs space-y-0.5">
                <div>{{ $linkLive ? 'Link active' : 'Link expired' }} — made by {{ $crewLink->createdByUser?->name ?? 'unknown' }}, {{ $crewLink->created_at?->format('Y-m-d H:i') }}</div>
                <div style="color: var(--text-muted);">{{ $crewLink->expires_at ? ($linkLive ? 'Valid until ' : 'Expired ') . $crewLink->expires_at->format('Y-m-d H:i') : 'No expiry' }} · Last opened: {{ $crewLink->last_used_at?->format('Y-m-d H:i') ?? 'never' }}</div>
            </div>
        @endif

        <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.email', $jobCard) }}" class="space-y-2">
            @csrf
            @if($freshToken)<input type="hidden" name="link_token" value="{{ $freshToken }}">@endif
            <input type="email" name="email" required maxlength="191" value="{{ old('email', $crew?->email) }}" placeholder="Email address" aria-label="Email the link to" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
            <button type="submit" class="corex-btn-outline text-xs w-full"
                @if(! $freshToken && $crewLink) data-confirm="This creates a new link and emails it. The old link stops working." data-confirm-danger data-confirm-label="This" @endif>
                {{ $freshToken ? 'Email this link' : 'Email a link' }}
            </button>
        </form>

        <div class="flex gap-2">
            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.issue', $jobCard) }}" class="flex-1"
                  @if($crewLink) data-confirm="Create a new link? The old link stops working." data-confirm-danger data-confirm-label="Create" @endif>
                @csrf
                <button type="submit" class="corex-btn-primary text-xs w-full">{{ $crewLink ? 'Re-issue link' : 'Generate link' }}</button>
            </form>
            @if($crewLink)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-link.revoke', $jobCard) }}" data-confirm="Revoke the link? It stops opening immediately." data-confirm-danger data-confirm-label="Revoke">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Revoke</button>
                </form>
            @endif
        </div>
    @endif
</div>
@endif
@endpermission
