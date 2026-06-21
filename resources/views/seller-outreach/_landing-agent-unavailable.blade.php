{{-- Agent-Unavailable mode: original agent gone; fallback contact card. --}}

@php
    $card = $ld->contactCard;
    $cardPhone = $card->phone ?? $card->cell ?? null;
@endphp

<div class="p-5 rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
        Get in touch with our team
    </h2>
    <p class="text-sm mt-1" style="color: var(--text-secondary);">
        @if(($card->id ?? 0) > 0)
            {{ $card->name ?? 'Our team' }} is happy to help.
        @else
            We'll route your enquiry to the right person.
        @endif
    </p>

    @if(($card->id ?? 0) > 0)
        <div class="mt-3 space-y-1">
            <div class="font-medium" style="color: var(--text-primary);">{{ $card->name }}</div>
            @if($cardPhone)
                <a href="tel:{{ $cardPhone }}" class="block text-sm" style="color: var(--brand-button);">📞 {{ $cardPhone }}</a>
            @endif
            @if($card->outward_email ?? null)
                <a href="mailto:{{ $card->outward_email }}" class="block text-sm" style="color: var(--brand-button);">✉️ {{ $card->outward_email }}</a>
            @endif
        </div>
        @if($ld->agentWhatsappUrl !== '#')
            <a href="{{ $ld->agentWhatsappUrl }}" target="_blank" rel="noopener"
               class="inline-block mt-4 px-5 py-2 rounded text-sm font-semibold"
               style="background: #25D366; color: #fff;">
                💬 Message on WhatsApp
            </a>
        @endif
    @endif
</div>

@if($ld->townName)
<div class="p-5 rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="text-center">
        <div class="text-3xl font-bold" style="color: var(--brand-button);">{{ $ld->liveBuyerCount }}</div>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            active buyer{{ $ld->liveBuyerCount === 1 ? '' : 's' }} looking in {{ $ld->townName }}
        </div>
    </div>
</div>
@endif

<div class="text-center text-xs mb-4" style="color: var(--text-muted);">
    Use the callback form below if you'd like us to reach out — no pressure.
</div>
