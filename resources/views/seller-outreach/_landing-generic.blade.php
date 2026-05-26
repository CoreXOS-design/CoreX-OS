{{-- Generic mode: property archived/sold — show only area demand. --}}

@php
    $card = $ld->contactCard;
    $cardPhone = $card->phone ?? $card->cell ?? null;
@endphp

{{-- Agent business card --}}
<div class="p-5 rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Your contact</h2>
    <div class="mt-3 space-y-1">
        <div class="font-medium" style="color: var(--text-primary);">{{ $card->name ?? 'Our team' }}</div>
        @if($cardPhone)
            <a href="tel:{{ $cardPhone }}" class="block text-sm" style="color: var(--brand-button);">📞 {{ $cardPhone }}</a>
        @endif
        @if($card->email ?? null)
            <a href="mailto:{{ $card->email }}" class="block text-sm" style="color: var(--brand-button);">✉️ {{ $card->email }}</a>
        @endif
    </div>
    @if($ld->agentWhatsappUrl !== '#')
        <a href="{{ $ld->agentWhatsappUrl }}" target="_blank" rel="noopener"
           class="inline-block mt-4 px-5 py-2 rounded text-sm font-semibold"
           style="background: #25D366; color: #fff;">
            💬 Get in touch on WhatsApp
        </a>
    @endif
</div>

{{-- Area demand --}}
@if($ld->townName)
<div class="p-5 rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
        We're active in {{ $ld->townName }}
    </h2>
    <div class="mt-4 text-center">
        <div class="text-3xl font-bold" style="color: var(--brand-button);">{{ $ld->liveBuyerCount }}</div>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            active buyer{{ $ld->liveBuyerCount === 1 ? '' : 's' }} looking in {{ $ld->townName }} right now
        </div>
    </div>
</div>
@endif

<div class="text-center text-xs mb-4" style="color: var(--text-muted);">
    Whether you're thinking of selling now or in the future — we'd love to keep in touch.
</div>
