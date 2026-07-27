{{-- AT-60 — Capture an address to START A NEW PROPERTY. This is a
     property-creation aid: it persists to the contact's structured
     property-address columns and transfers onto a new Property via
     "Use for property". It is INDEPENDENT of the contact's residential
     address (the free-text field on the Info tab) and never writes to it.

     Extracted as its own partial (pre-existing bug fix, found while verifying
     Phase 4 in a real browser — NOT a Phase 4 change): part of the same
     class of Blade-compiler defect as _assigned-agents.blade.php /
     _linked-properties.blade.php — show.blade.php silently lost this @if's
     opening tag past a certain size. Splitting it out resolves it; no logic
     changed here. --}}
@if(session('held_address_warning'))
    @php $heldWarn = session('held_address_warning'); @endphp
    <div class="rounded-md p-4 mb-4" role="alert"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, transparent);">
        <div class="flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div class="text-sm" style="color:var(--text-primary);">
                <strong>HFC already has this property on its books</strong> — {{ $heldWarn['label'] ?? '' }}.
                @if(!empty($heldWarn['address'])) <span style="color:var(--text-secondary);">({{ $heldWarn['address'] }})</span>@endif
                <div class="mt-1 text-xs" style="color:var(--text-secondary);">
                    Check the existing record before canvassing the owner —
                    @if(!empty($heldWarn['property_url']))<a href="{{ $heldWarn['property_url'] }}" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open the property record</a>@elseif(!empty($heldWarn['tracked_url']))<a href="{{ $heldWarn['tracked_url'] }}" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open property intel</a>@endif.
                </div>
            </div>
        </div>
    </div>
@endif
