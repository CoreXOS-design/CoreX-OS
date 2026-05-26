{{-- props: $context (null until a property is selected) --}}

@if($context)
<div class="space-y-4">

    {{-- Live demand panel --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-[10px] uppercase tracking-wider font-semibold mb-3" style="color: var(--text-muted);">
            Live demand facts
        </h3>

        <div class="space-y-3">
            <div>
                <div class="text-xs" style="color: var(--text-muted);">
                    Buyers in {{ $context->mergeFields['property_town'] ?? 'the area' }}
                </div>
                <div class="text-2xl font-bold" style="color: var(--text-primary);">
                    {{ $context->mergeFields['buyer_count'] ?? '0' }}
                </div>
            </div>

            <div>
                <div class="text-xs" style="color: var(--text-muted);">Matching this specific property</div>
                <div class="text-2xl font-bold" style="color: var(--text-primary);">
                    {{ $context->mergeFields['matching_buyer_count'] ?? '0' }}
                </div>
                @php
                    $beds = $context->mergeFields['property_beds'] ?? '';
                    $type = $context->mergeFields['property_type'] ?? 'property';
                @endphp
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                    {{ $type }}{{ $beds !== '' ? ', ' . $beds . ' bed' . ($beds !== '1' ? 's' : '') : '' }}, similar price band
                </div>
            </div>
        </div>
    </div>

    {{-- Property summary --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">
            Property
        </h3>
        <div class="text-sm" style="color: var(--text-primary);">
            {{ $context->mergeFields['property_address'] ?? '(no address)' }}
        </div>
        @if(!empty($context->mergeFields['property_suburb']))
            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $context->mergeFields['property_suburb'] }}</div>
        @endif
    </div>

    {{-- Tracking --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">
            Tracking
        </h3>
        <div class="text-xs" style="color: var(--text-secondary);">
            A unique tracking link is generated on send. Every click is logged against this contact.
        </div>
    </div>

    {{-- PPRA defensibility reassurance --}}
    <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); color: var(--text-muted);">
        <div class="font-semibold mb-1" style="color: var(--text-secondary);">PPRA defensibility</div>
        <p>Every send records the exact body and claimed numbers at the moment you click. Even if the underlying data changes later, the snapshot proves what was claimed.</p>
    </div>

</div>
@else
<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
    <div class="text-xs">Select a property to see the live demand facts that will be merged into the pitch.</div>
</div>
@endif
