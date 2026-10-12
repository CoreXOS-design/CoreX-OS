{{-- Properties step aux-partial (AT-450 round 2): the agency's own title deed storage locations.
     $titleDeedLocations. Add / remove go through the wizard collection routes, which call the same controller the
     Settings -> Title Deeds -> Storage locations screen uses. --}}
<div class="px-6 py-5 space-y-3">
    <h2 class="text-sm font-bold" style="color:var(--text-primary);">Where title deeds are kept</h2>
    @include('agency-setup.steps._collection', [
        'collectionKey' => 'title_deed_location', 'collectionLabel' => 'Storage locations',
        'collectionPlaceholder' => 'e.g. Safe 2, Strongroom', 'items' => $titleDeedLocations ?? collect(),
    ])
</div>
