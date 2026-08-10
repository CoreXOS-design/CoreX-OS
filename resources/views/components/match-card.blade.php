@props(['property', 'match', 'contact', 'feedback' => null])

{{-- Shared rich match card — the SAME component used by the Core Matches
     results screen (corex/contacts/match-results.blade.php) and the
     buyer-detail Wishlists tab's inline accordion, so both surfaces stay
     visually and functionally consistent. Self-contained Alpine scope (its
     own note-toggle + hide-reason modal) — drops into any host page without
     requiring shared parent state.

     Agent-facing surface: View Property (opens in a new tab) + Hide/Unhide
     only. NO "Convert to Deal" — that button threw an SQL error and deal
     creation is BM/admin-only per spec; this button is removed from every
     agent match surface. The underlying deal-creation flow/backend that
     BM/admin use elsewhere is untouched. --}}
@php
    $isHidden = $match->isPropertyHidden($property->id);
    $views = $match->propertyViewCount($property->id);
    $thumb = $property->thumbFor(
        $property->gallery_images_json[0]
        ?? $property->dawn_images_json[0]
        ?? $property->noon_images_json[0]
        ?? $property->dusk_images_json[0]
        ?? null
    );
    $statusVariant = match($property->status) {
        'active'    => 'ds-badge-success',
        'sold'      => 'ds-badge-info',
        'withdrawn' => 'ds-badge-warning',
        default     => 'ds-badge-default',
    };
    $score = (int) ($property->match_score ?? 0);
    $tier  = $property->match_tier ?? \App\Services\Matching\MatchingService::tierFor($score);
    $scoreVariant = match($tier) {
        'strong' => 'ds-badge-success',
        'good'   => 'ds-badge-info',
        default  => 'ds-badge-warning',
    };
    $scoreLabel = match($tier) {
        'strong' => 'Strong',
        'good'   => 'Good',
        default  => 'Fair',
    };

    $fb = $feedback;
    $reactionMeta = [
        'interested'     => ['label' => 'Interested', 'variant' => 'ds-badge-success'],
        'not_interested' => ['label' => 'Not for me', 'variant' => 'ds-badge-warning'],
    ];
    $fbMeta = $fb && isset($reactionMeta[$fb->reaction]) ? $reactionMeta[$fb->reaction] : null;

    // Own vs communal/complex pool — Property::poolTokens() is the single
    // source both this badge and the matching engine read, so they can never
    // disagree about which kind of pool a property has.
    $poolTokens = $property->poolTokens();
    $poolLabel  = in_array('pool_communal', $poolTokens, true) ? 'Communal Pool'
        : (in_array('pool_own', $poolTokens, true) ? 'Pool' : null);

    // Match-card v2 — Seller + Access popover. AGENT-ONLY: this whole block
    // (seller identity, phone, access_notes) must NEVER reach the client-
    // facing wishlist link (shared/match.blade.php) or the buyer portal
    // (buyer-portal/_property-card.blade.php) — neither file includes this
    // component or reads these fields; see the privacy proof in the commit.
    // Property::sellerOwnerContact() is the SAME canonical seller-resolution
    // method AT-105 already uses for FICA filing — not a new lookup.
    $seller = $property->sellerOwnerContact();
    $sellerPhoneRow = $seller?->primaryPhone;
    $sellerWaLink = $seller && $seller->phone
        ? 'https://wa.me/' . \App\Support\WhatsAppNumberFormatter::forDeepLink($seller->phone, $sellerPhoneRow?->dial_code ?? '+27')
        : null;
    $sellerTelLink = $seller && $seller->phone ? 'tel:' . preg_replace('/\s+/', '', $seller->phone) : null;
@endphp

<div class="rounded-md overflow-hidden flex items-stretch flex-wrap md:flex-nowrap transition-opacity"
     x-data="{ noteOpen: false, hideModalOpen: false, hideReason: '', sellerPopoverOpen: false, addressCopied: false }"
     style="background: var(--surface); border: 1px solid var(--border); {{ $isHidden ? 'opacity:.45; filter:grayscale(.85);' : '' }}"
     @if($isHidden) title="Hidden from this match — click Unhide to restore" @endif>

    {{-- Thumbnail --}}
    <div class="relative flex-shrink-0 overflow-hidden" style="width: 140px; min-height: 100px; background: var(--surface-2);">
        @if($thumb)
        <img src="{{ $thumb }}" alt="{{ $property->title }}" class="absolute inset-0 w-full h-full object-cover">
        @else
        <div class="absolute inset-0 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-8 h-8 opacity-30" style="color: var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
        </div>
        @endif
        @if($isHidden)
        <div class="absolute inset-0 flex items-center justify-center" style="background: rgba(0,0,0,0.5);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
        </div>
        @endif
    </div>

    {{-- Main content --}}
    <div class="flex-1 min-w-0 px-5 py-4 flex flex-col gap-2 justify-between">

        <div>
            <div class="flex items-center gap-2 flex-wrap mb-1.5">
                @if($score > 0)
                <span class="ds-badge {{ $scoreVariant }}" title="{{ $scoreLabel }} match">
                    {{ $score }}% · {{ $scoreLabel }}
                </span>
                @endif
                <span class="ds-badge {{ $statusVariant }}">{{ ucfirst(str_replace('_', ' ', (string) $property->status)) }}</span>
                @if($isHidden)
                @php $hiddenReason = $match->hiddenReasonFor($property->id); @endphp
                <span class="ds-badge ds-badge-warning" @if($hiddenReason) title="Reason: {{ $hiddenReason }}" @endif>Hidden</span>
                @endif
                @if($fbMeta)
                <span class="ds-badge {{ $fbMeta['variant'] }}" title="Client reaction">
                    {{ $fbMeta['label'] }}
                </span>
                @if(!empty($fb->note))
                <button type="button"
                        @click="noteOpen = !noteOpen"
                        class="inline-flex items-center gap-1 text-xs font-semibold rounded-md px-2 py-0.5"
                        style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);"
                        title="Read client's note">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>
                    Note
                </button>
                @endif
                @endif
            </div>
            @if($isHidden && !empty($match->hiddenReasonFor($property->id)))
            <div class="rounded-md p-2 mb-2 text-xs"
                 style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                <span class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Hidden reason</span>
                <div class="whitespace-pre-wrap leading-relaxed mt-0.5" style="color: var(--text-primary);">{{ $match->hiddenReasonFor($property->id) }}</div>
            </div>
            @endif
            @if($fbMeta && !empty($fb->note))
            <div x-show="noteOpen" x-cloak x-transition
                 class="rounded-md p-3 mb-2 text-xs"
                 style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <div class="flex items-start justify-between gap-2 mb-1">
                    <span class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                        Client note · {{ $fb->updated_at?->diffForHumans() }}
                    </span>
                    <button type="button" @click="noteOpen = false" class="text-xs font-bold" style="color: var(--text-muted);">✕</button>
                </div>
                <div class="whitespace-pre-wrap leading-relaxed">{{ $fb->note }}</div>
            </div>
            @endif
            {{-- Agent-facing ONLY — the full street address, as the PRIMARY header (agents
                 recognise the real address far better than the generic portal title —
                 Johan's call 2026-08-10). The client-facing wishlist share link
                 (resources/views/shared/match.blade.php) and the buyer portal
                 (resources/views/buyer-portal/_property-card.blade.php) are separate blade
                 files with their own markup and must NEVER be given this component or this
                 field; both intentionally show suburb/city only. --}}
            <div class="flex items-start gap-1 mb-1">
                <div class="text-sm font-semibold leading-snug flex-1 min-w-0" style="color: var(--text-primary);">
                    {{ $property->address ?: ($property->title ?: 'Untitled Property') }}
                </div>

                {{-- Copy-address — tiny inline icon, no new row. --}}
                @if($property->address)
                <button type="button"
                        @click.stop="navigator.clipboard.writeText(@js($property->address)).then(() => { addressCopied = true; setTimeout(() => addressCopied = false, 1500); })"
                        class="flex-shrink-0 p-0.5 rounded"
                        style="color: var(--text-muted);"
                        :title="addressCopied ? 'Copied!' : 'Copy address'">
                    <svg x-show="!addressCopied" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" /></svg>
                    <svg x-show="addressCopied" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                </button>
                @endif

                {{-- Seller + Access popover trigger — INTERNAL/agent-only. Compact icon
                     + small anchored popover so the tile never grows; see the @php
                     block above for the privacy boundary this data must stay behind. --}}
                @if($seller || $property->access_notes)
                <div class="relative flex-shrink-0" @click.outside="sellerPopoverOpen = false">
                    <button type="button" @click.stop="sellerPopoverOpen = !sellerPopoverOpen"
                            class="p-0.5 rounded" style="color: var(--text-muted);"
                            title="Seller + access details (internal only)">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                    </button>

                    <div x-show="sellerPopoverOpen" x-cloak x-transition.opacity
                         class="absolute left-0 mt-1 z-50 w-64 rounded-md p-3 text-xs"
                         style="background:var(--surface); border:1px solid var(--border); box-shadow:0 8px 30px rgba(0,0,0,0.18);">
                        <div class="mb-2 pb-2" style="border-bottom: 1px solid var(--border);">
                            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Seller</div>
                            @if($seller)
                            <div class="font-semibold mb-1" style="color: var(--text-primary);">{{ $seller->full_name }}</div>
                            @if($seller->phone)
                            <div class="flex items-center gap-2">
                                <a href="{{ $sellerTelLink }}" class="inline-flex items-center gap-1 no-underline" style="color: var(--brand-icon);" title="Call {{ $seller->phone }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h.75a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                    Call
                                </a>
                                @if($sellerWaLink)
                                <a href="{{ $sellerWaLink }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 no-underline" style="color: var(--brand-icon);" title="WhatsApp {{ $seller->phone }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>
                                    WhatsApp
                                </a>
                                @endif
                                <span style="color: var(--text-muted);">{{ $seller->phone }}</span>
                            </div>
                            @else
                            <div style="color: var(--text-muted);">No phone on file</div>
                            @endif
                            @else
                            <div style="color: var(--text-muted);">No seller linked to this property</div>
                            @endif
                        </div>
                        <div>
                            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Access</div>
                            <div class="whitespace-pre-wrap leading-relaxed" style="color: var(--text-primary);">{{ $property->access_notes ?: 'No access notes captured.' }}</div>
                        </div>
                    </div>
                </div>
                @endif
            </div>
            @if($property->address && $property->title)
            <div class="text-xs mb-1" style="color: var(--text-secondary);">{{ $property->title }}</div>
            @endif
            <div class="flex items-center gap-3 text-xs flex-wrap" style="color: var(--text-muted);">
                <span class="font-semibold text-sm" style="color: var(--brand-icon);">{{ $property->formattedPrice() }}</span>
                @if($property->suburb)<span>{{ $property->suburb }}</span>@endif
                @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                @if($v)<span>{{ $v }} {{ $l }}</span>@endif
                @endforeach
                @if($property->size_m2)
                <span>{{ number_format($property->size_m2) }} m²</span>
                @endif
                @if($poolLabel)
                <span class="inline-flex items-center gap-1" title="{{ $poolLabel === 'Communal Pool' ? 'Communal/complex pool — not exclusive to this unit' : 'Own pool on this property' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2 15c2.5 0 2.5-3 5-3s2.5 3 5 3 2.5-3 5-3 2.5 3 5 3M2 19c2.5 0 2.5-3 5-3s2.5 3 5 3 2.5-3 5-3 2.5 3 5 3" /><circle cx="12" cy="7" r="1.5" /></svg>
                    {{ $poolLabel }}
                </span>
                @endif
            </div>
            @if($property->agent)
            <div class="text-xs mt-1" style="color: var(--text-muted);">Agent: {{ $property->agent->name }}</div>
            @endif
        </div>

        {{-- Bottom: client view counter --}}
        <div class="flex items-center gap-2 pt-2" style="border-top: 1px solid var(--border);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"
                 style="color: {{ $views > 0 ? 'var(--brand-icon)' : 'var(--text-muted)' }};"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
            <span class="text-xs" style="color: var(--text-muted);">
                @if($views > 0)
                    Viewed by client
                    <strong style="color: var(--brand-icon);">{{ number_format($views) }} {{ $views === 1 ? 'time' : 'times' }}</strong>
                @else
                    Not yet viewed by client
                @endif
            </span>
        </div>
    </div>

    {{-- Action buttons — View Property + Share (public listing link) + Hide/Unhide. --}}
    <div class="flex flex-col gap-2 justify-center px-4 py-4 flex-shrink-0 w-full md:w-auto" style="border-left: 1px solid var(--border);">
        <a href="{{ route('corex.properties.show', $property) }}" target="_blank" rel="noopener noreferrer" class="corex-btn-outline">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
            View Property
        </a>

        {{-- Reuses the property page's OWN share mechanism verbatim (copy link /
             WhatsApp / email, Property::public_url) — no new URL scheme, no new
             share logic. Self-gated inside the partial by the properties.share
             permission + a shareable status, so it silently renders nothing for
             an agent without that permission or a non-shareable listing, exactly
             like the property page itself. --}}
        @include('corex.properties.partials.share-actions', ['property' => $property])

        <form method="POST" action="{{ route('corex.contacts.matches.toggleHide', [$contact, $match, $property]) }}" x-ref="hideForm">
            @csrf
            @unless($isHidden)
            <input type="hidden" name="reason" value="">
            @endunless
            <button type="{{ $isHidden ? 'submit' : 'button' }}" class="corex-btn-outline w-full"
                    @unless($isHidden) @click="hideModalOpen = true" @endunless>
                @if($isHidden)
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                Unhide
                @else
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                Hide
                @endif
            </button>
        </form>
    </div>

    {{-- Hide-reason modal — self-contained per-card, no shared parent state. --}}
    @unless($isHidden)
    <div x-show="hideModalOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="hideModalOpen = false">
        <div class="w-full max-w-md rounded-md p-5"
             style="background: var(--surface); border: 1px solid var(--border);"
             @click.outside="hideModalOpen = false">
            <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Hide "{{ $property->title ?: 'this property' }}"?</h3>
            <p class="text-xs mb-3" style="color: var(--text-muted);">This removes it from the client's view. Give a reason (min 3 characters).</p>
            <textarea x-model="hideReason" rows="3" placeholder="Why is this being hidden?"
                      class="w-full rounded-md px-3 py-2 text-sm mb-3"
                      style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="hideModalOpen = false" class="corex-btn-outline">Cancel</button>
                <button type="button"
                        @click="$refs.hideForm.querySelector('input[name=reason]').value = hideReason.trim(); hideModalOpen = false; $refs.hideForm.submit();"
                        :disabled="hideReason.trim().length < 3"
                        class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                    Hide property
                </button>
            </div>
        </div>
    </div>
    @endunless
</div>
