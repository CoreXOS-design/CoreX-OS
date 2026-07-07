{{-- Properties step aux-partial: inline management of the property dropdown
     lists. $propertyGroups = ['property_type'=>Collection, ...] --}}
<div class="px-6 py-5 space-y-6">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your property lists</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">The dropdown options CoreX uses when you capture a property. Add your own — built-in defaults stay put.</p>
    </div>

    @include('agency-setup.steps._collection', [
        'collectionKey' => 'property_type', 'collectionLabel' => 'Property types',
        'collectionPlaceholder' => 'e.g. Freehold House', 'items' => $propertyGroups['property_type'] ?? collect(),
    ])
    @include('agency-setup.steps._collection', [
        'collectionKey' => 'property_status', 'collectionLabel' => 'Listing statuses',
        'collectionPlaceholder' => 'e.g. Under Offer', 'items' => $propertyGroups['property_status'] ?? collect(),
    ])
    @include('agency-setup.steps._collection', [
        'collectionKey' => 'mandate_type', 'collectionLabel' => 'Mandate types',
        'collectionPlaceholder' => 'e.g. Sole Mandate', 'items' => $propertyGroups['mandate_type'] ?? collect(),
    ])
    @include('agency-setup.steps._collection', [
        'collectionKey' => 'condition_level', 'collectionLabel' => 'Condition levels',
        'collectionPlaceholder' => 'e.g. Renovated', 'items' => $propertyGroups['condition_level'] ?? collect(),
    ])
</div>
