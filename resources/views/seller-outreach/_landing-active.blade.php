{{-- Active mode: property is current + agent is current. Full pitch. --}}

@php
    $card = $ld->contactCard;
    $property = $ld->property;
    $cardPhone = $card->phone ?? $card->cell ?? null;
@endphp

{{-- Agent business card --}}
<div class="p-5 rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Your contact</h2>
    <div class="mt-3 space-y-1">
        <div class="font-medium" style="color: var(--text-primary);">{{ $card->name ?? 'Our team' }}</div>
        @if($cardPhone)
            <a href="tel:{{ $cardPhone }}" class="block text-sm" style="color: var(--brand-button);">
                📞 {{ $cardPhone }}
            </a>
        @endif
        @if($card->outward_email ?? null)
            <a href="mailto:{{ $card->outward_email }}" class="block text-sm" style="color: var(--brand-button);">
                ✉️ {{ $card->outward_email }}
            </a>
        @endif
    </div>
    @if($ld->agentWhatsappUrl !== '#')
        <a href="{{ $ld->agentWhatsappUrl }}" target="_blank" rel="noopener"
           class="inline-block mt-4 px-5 py-2 rounded text-sm font-semibold"
           style="background: #25D366; color: #fff;">
            💬 Reply on WhatsApp
        </a>
    @endif
</div>

{{-- Property + live demand --}}
@if($property)
<div class="p-5 rounded-md mb-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Your property</h2>
    <div class="text-sm" style="color: var(--text-secondary);">
        @php
            // AT-266 — canonical street line (unit + complex + street), then suburb.
            $addr = $property->composeAddressFromParts();
        @endphp
        {{ $addr !== '' ? $addr : '(address)' }}{{ !empty($property->suburb) ? ', ' . $property->suburb : '' }}
    </div>
    @if($property->price)
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            Listed at R {{ number_format((float) $property->price, 0, '.', ',') }}
        </div>
    @endif

    <hr class="my-4" style="border-color: var(--border);">

    <div class="grid grid-cols-2 gap-4 text-center">
        <div>
            <div class="text-3xl font-bold" style="color: var(--brand-button);">{{ $ld->liveBuyerCount }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                active buyer{{ $ld->liveBuyerCount === 1 ? '' : 's' }} in {{ $ld->townName ?? 'your area' }}
            </div>
        </div>
        <div>
            <div class="text-3xl font-bold" style="color: var(--brand-button);">{{ $ld->liveMatchingBuyerCount }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                looking for properties like yours
            </div>
        </div>
    </div>
</div>
@endif

<div class="text-center text-xs mb-4" style="color: var(--text-muted);">
    These numbers update live — they reflect real buyers actively searching today.
</div>
