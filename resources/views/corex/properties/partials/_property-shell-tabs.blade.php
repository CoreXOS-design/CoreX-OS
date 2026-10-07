{{--
    Extracted from properties/show.blade.php's own tab bar (2026-09-27,
    rental-inventory §13.10) so the standalone inventory capture page can
    show the identical tab bar, with Inventory active, without a second
    copy of this markup. Johan: "on inventory we need to show all the same
    menu items like on inspections."

    Two modes, since show.blade.php's own tabs are a client-side Alpine SPA
    switch (no navigation — every panel already lives on that same page)
    while a standalone page like capture.blade.php has none of those other
    panels to switch to:

      - $mode = 'spa' (default) — show.blade.php's own original behaviour,
        byte-for-byte: each tab is a <button @click="activeTab = '...'">,
        active state driven by the enclosing Alpine `activeTab`.
      - $mode = 'link' — every tab except $activeTabKey is a real
        <a href="{{ route('corex.properties.show', $property) }}?tab=...">
        (a full page navigation, per Johan: "clicking any other tab goes to
        that tab of the property"); $activeTabKey itself renders as a
        non-clickable, actively-styled indicator, since it names the page
        already being viewed.

    Required in both modes: $property, $isNew, $allDriveDocs, $coreMatches.
    Required only in 'link' mode: $activeTabKey (e.g. 'inventory').
--}}
@php
    $mode = $mode ?? 'spa';
    $activeTabKey = $activeTabKey ?? null;
    // 'spa' mode reacts LIVE to an in-progress, unsaved listing_type edit
    // (AT-402 — a brand-new property picking Rental before its first
    // save). 'link' mode never has such an edit in progress (it's a
    // separate, already-settled page), so a plain server-side boolean is
    // enough — no Alpine x-init/listener needed.
    $isRentalListingStatic = strtolower($property->listing_type ?? '') === 'rental';
    $tabs = [
        ['key'=>'overview',  'label'=>'Overview'],
        ['key'=>'info',      'label'=>'Info'],
        ['key'=>'auction',   'label'=>'Auction'],
        ['key'=>'gallery',   'label'=>'Gallery'],
        ['key'=>'rental',    'label'=>'Rental'],
        ['key'=>'rental-images', 'label'=>'Rental Images'],
        ['key'=>'inspections', 'label'=>'Inspections'],
        ['key'=>'inventory', 'label'=>'Inventory'],
        ['key'=>'contacts',  'label'=>'Contacts'],
        ['key'=>'notes',     'label'=>'Notes'],
        ['key'=>'history',   'label'=>'History'],
        ['key'=>'drive',        'label'=>'Drive'],
        ['key'=>'intelligence', 'label'=>'Intelligence'],
        ['key'=>'core-matches', 'label'=>'Core Matches'],
    ];
@endphp
<div class="flex overflow-x-auto sticky top-0 z-10" style="border-bottom:1px solid var(--border); background:var(--surface);"
     @if($mode === 'spa')
     {{-- AT-402 - the Rental tab follows a live listing_type change on a brand-new property. --}}
     x-data="{ isRentalListing: document.querySelector('[name=listing_type]')?.value === 'rental' }"
     x-init="document.querySelector('[name=listing_type]')?.addEventListener('change', e => {
         isRentalListing = e.target.value === 'rental';
         if (!isRentalListing && activeTab === 'rental') activeTab = 'info';
     })"
     @endif>
    @foreach($tabs as $tab)
    @if($tab['key'] === 'core-matches' && (!\App\Models\PerformanceSetting::get('matches_enabled', 1) || !\App\Models\PerformanceSetting::get('matches_show_on_properties', 1) || !auth()->user()->hasPermission('access_core_matches')))
        @continue
    @endif
    {{-- AT-432 — Auction tab only for a property that is on auction. --}}
    @if($tab['key'] === 'auction' && ($isNew || ! $property->isAuction()))
        @continue
    @endif
    @if($tab['key'] === 'rental-images' && ($isNew || strtolower($property->listing_type ?? '') !== 'rental'))
        @continue
    @endif
    @if($tab['key'] === 'inspections' && ($isNew || strtolower($property->listing_type ?? '') !== 'rental'))
        @continue
    @endif
    {{-- .ai/specs/rental-inventory.md §0a — Johan, 2026-09-22: "it
         should be on properties, not only rental properties.
         inspections are rentals only, not sales." Unlike Inspections
         above, never gated on listing_type — every settled property
         gets this tab, sale or rental alike. --}}
    @if($tab['key'] === 'inventory' && $isNew)
        @continue
    @endif
    @if($tab['key'] === 'rental' && !($isNew || $property->listing_type_pending) && strtolower($property->listing_type ?? '') !== 'rental')
        @continue
    @endif
    {{-- 'link' mode has no in-progress, unsaved listing_type edit to react
         to live — a rental tab that doesn't already qualify above simply
         isn't rendered, matching the settled-property case 'spa' mode's
         own x-show would converge to anyway. --}}
    @if($mode === 'link' && $tab['key'] === 'rental' && !$isRentalListingStatic && !($isNew || $property->listing_type_pending))
        @continue
    @endif
    @php
        $badgeCount = match(true) {
            !$isNew && $tab['key'] === 'contacts' => $property->contacts->count(),
            !$isNew && $tab['key'] === 'notes' => $property->notes->count(),
            !$isNew && $tab['key'] === 'drive' => $allDriveDocs->count(),
            !$isNew && $tab['key'] === 'core-matches' => $coreMatches->count(),
            default => 0,
        };
    @endphp
    @if($mode === 'link')
        @if($tab['key'] === $activeTabKey)
        <span data-prop-tab="{{ $tab['key'] }}" aria-current="page"
              style="color:var(--brand-icon); border-color:var(--brand-icon); background:color-mix(in srgb, var(--brand-icon) 6%, transparent); border-bottom-width:2px;"
              class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0">
            {{ $tab['label'] }}
            @if($badgeCount)
            <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $badgeCount }}</span>
            @endif
        </span>
        @else
        <a href="{{ route('corex.properties.show', $property) }}?tab={{ $tab['key'] }}"
           data-prop-tab="{{ $tab['key'] }}"
           style="color:var(--text-secondary); border-color:transparent; background:transparent; border-bottom-width:2px;"
           class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0 no-underline transition-colors duration-150">
            {{ $tab['label'] }}
            @if($badgeCount)
            <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $badgeCount }}</span>
            @endif
        </a>
        @endif
    @else
        <button type="button"
                data-prop-tab="{{ $tab['key'] }}" data-tour="prop-tab-{{ $tab['key'] }}"
                @click="activeTab = '{{ $tab['key'] }}'"
                @if($tab['key'] === 'rental') x-show="isRentalListing" x-cloak @endif
                :class="'border-b-2'"
                :style="activeTab === '{{ $tab['key'] }}' ? 'color:var(--brand-icon); border-color:var(--brand-icon); background:color-mix(in srgb, var(--brand-icon) 6%, transparent);' : 'color:var(--text-secondary); border-color:transparent; background:transparent;'"
                class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0 transition-colors duration-150 outline-none focus:outline-none">
            {{ $tab['label'] }}
            @if($badgeCount)
            <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $badgeCount }}</span>
            @endif
        </button>
    @endif
    @endforeach
</div>
