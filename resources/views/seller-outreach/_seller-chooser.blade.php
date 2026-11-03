{{-- road-to-sold.md 23.30: the seller chooser at the top of the popup's Contact step (it was the Pitch screen). The sellers linked to the property; the one being contacted is
     selected; one seller is a single preselected line, more is a list and one click selects (the screen reloads for that seller). Inputs: $property, $contact. --}}
@php
    $__sellers = app(\App\Services\Prospecting\ComposeSellerService::class)->linkedSellers((int) $property->agency_id, (int) $property->id);
    $__carry = app(\App\Services\RoadToSold\PitchPathService::class)->carry(request());
@endphp
@if(count($__sellers) > 0)
<div class="rounded-md p-2" style="background: var(--surface); border: 1px solid var(--border);" data-qa="seller-chooser" data-count="{{ count($__sellers) }}">
    <div class="text-[10px] uppercase tracking-wider font-semibold px-2 pt-1" style="color: var(--text-muted);">{{ count($__sellers) > 1 ? 'Who to contact' : 'Contacting' }}</div>
    @foreach($__sellers as $__s)
        @php
            $__name = trim(($__s['first_name'] ?? '') . ' ' . ($__s['last_name'] ?? '')) ?: 'Seller';
            $__nums = collect($__s['phones'] ?? [])->pluck('value')->merge(collect($__s['emails'] ?? [])->pluck('value'))->filter()->take(2)->implode(' · ');
            $__on = (int) $__s['contact_id'] === (int) $contact->id;
        @endphp
        @if($__on || count($__sellers) === 1)
            <div class="flex items-center gap-2 px-2 py-1.5 text-sm" data-qa="seller-option" data-selected="1" style="color: var(--text-primary);">
                <span aria-hidden="true" style="color: var(--brand-button, #0ea5e9);">●</span><strong>{{ $__name }}</strong><span class="text-xs font-mono" style="color: var(--text-muted);">{{ $__nums ?: 'No contact number' }}</span>
            </div>
        @else
            <a href="{{ route('seller-outreach.composer.show', ['contact' => $__s['contact_id'], 'property_id' => $property->id] + $__carry) }}" class="flex items-center gap-2 px-2 py-1.5 text-sm no-underline" data-qa="seller-option" data-selected="0" style="color: var(--text-primary);">
                <span aria-hidden="true" style="color: var(--text-muted);">○</span><span>{{ $__name }}</span><span class="text-xs font-mono" style="color: var(--text-muted);">{{ $__nums ?: 'No contact number' }}</span>
            </a>
        @endif
    @endforeach
</div>
@endif
