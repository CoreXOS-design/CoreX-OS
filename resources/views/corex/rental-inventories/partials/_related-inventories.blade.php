{{--
    .ai/specs/rental-inventory.md §0b — Johan, 2026-09-22: "it lives on a
    property... selecting inventory from the property we already know which
    property its for. done simple." One link, straight into the room-based
    capture surface — no second "Start Inventory" button here that resolves
    the lease and creates a record a different way (the property's own
    capture link already does that transparently via
    RentalInventory::resolveOrStartFor()). Included on the property, lease,
    and rental inspection detail screens — same partial, same link, not
    three re-implementations of "which property is this."

    Required var:
      $property — the property this section is for.
--}}
<div class="prop-section">
    <a href="{{ route('corex.properties.inventory.show', $property) }}" class="prop-section-toggle" style="display:flex;">
        <h3 class="prop-section-heading">
            <span class="prop-section-heading-text">Inventory</span>
        </h3>
        <svg class="prop-section-chevron" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
    </a>
</div>
