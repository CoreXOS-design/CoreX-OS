{{-- Market Intelligence / Prospecting Setup step aux-partial: towns + suburbs,
     property types, bedroom segments, and price bands (sale + rental) — the
     exact same config settings.prospecting.index configures, delegating to the
     SAME canonical CRUD controllers via the wizard's generic collection routes.
     $micTowns (each with ->suburbs), $micPropertyTypes, $micBedroomSegments,
     $micPriceBandsSale, $micPriceBandsRental. --}}
<div class="px-6 py-5 space-y-8">

    {{-- Towns + suburbs --}}
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Towns &amp; suburbs</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">The areas you work. Add a town, then add its suburbs underneath — Market Intelligence groups every listing and buyer by these.</p>

        @if ($errors->any())
            <div class="rounded-md px-3 py-2 text-sm mt-3"
                 style="background: color-mix(in srgb, var(--ds-crimson,#e11d48) 10%, transparent); color: var(--text-primary,#0f172a); border:1px solid color-mix(in srgb, var(--ds-crimson,#e11d48) 30%, transparent);">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => 'mic_town']) }}"
              class="flex flex-wrap items-end gap-2 mt-3">
            @csrf
            <div class="flex-1 min-w-[10rem]">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Town name</label>
                <input type="text" name="name" required maxlength="100" placeholder="e.g. Margate"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <div class="w-40">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Region (optional)</label>
                <input type="text" name="region" maxlength="100" placeholder="e.g. KZN South Coast"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <button type="submit" class="rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap"
                    style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569);">
                + Add town
            </button>
        </form>

        <div class="space-y-3 mt-4">
            @forelse ($micTowns as $town)
                <div class="rounded-md p-3" style="border:1px solid var(--border,#e5e7eb);">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">
                            {{ $town->name }}
                            @if ($town->region)
                                <span class="text-xs font-normal" style="color:var(--text-muted);">— {{ $town->region }}</span>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('corex.agency-setup.collection.remove', ['collection' => 'mic_town', 'id' => $town->id]) }}" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs font-semibold"
                                    style="background:none;border:none;cursor:pointer;color:var(--ds-crimson,#e11d48);">
                                Archive
                            </button>
                        </form>
                    </div>

                    {{-- Suburbs under this town --}}
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        @forelse ($town->suburbs as $suburb)
                            <span class="inline-flex items-center gap-1.5 rounded-full pl-3 pr-1.5 py-1 text-xs"
                                  style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                                {{ $suburb->suburb_name }}
                                <form method="POST" action="{{ route('corex.agency-setup.collection.remove', ['collection' => 'mic_suburb', 'id' => $suburb->id]) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Remove" aria-label="Remove {{ $suburb->suburb_name }}"
                                            class="inline-flex items-center justify-center w-4 h-4 rounded-full"
                                            style="background:none;border:none;cursor:pointer;color:var(--text-muted,#94a3b8);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </form>
                            </span>
                        @empty
                            <span class="text-xs italic" style="color:var(--text-muted,#94a3b8);">No suburbs yet.</span>
                        @endforelse
                    </div>
                    <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => 'mic_suburb']) }}" class="flex items-center gap-2 mt-2">
                        @csrf
                        <input type="hidden" name="town_id" value="{{ $town->id }}">
                        <input type="text" name="suburb_name" maxlength="150" placeholder="Add a suburb…"
                               class="flex-1 rounded-md px-2.5 py-1.5 text-xs"
                               style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                        <button type="submit" class="rounded-md px-2.5 py-1.5 text-xs font-medium whitespace-nowrap"
                                style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569);">
                            + Add
                        </button>
                    </form>
                </div>
            @empty
                <div class="rounded-md py-6 px-4 text-center text-sm" style="color:var(--text-muted); border:1px dashed var(--border,#e5e7eb);">
                    No towns yet. Add your first area above.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Property types --}}
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Property types</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">The kinds of property Market Intelligence should track and match against — house, flat, vacant land, and so on.</p>
        <div class="mt-3">
            @include('agency-setup.steps._collection', [
                'collectionKey' => 'mic_property_type', 'collectionLabel' => 'Property types',
                'collectionPlaceholder' => 'e.g. Vacant Land', 'items' => $micPropertyTypes,
            ])
        </div>
    </div>

    {{-- Bedroom segments --}}
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Bedroom segments</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Bands agents and buyers filter by — e.g. "2 Bed" covers 2–2, "3+ Bed" covers 3 and up (leave max blank for unbounded).</p>

        <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => 'mic_bedroom_segment']) }}"
              class="flex flex-wrap items-end gap-2 mt-3">
            @csrf
            <div class="flex-1 min-w-[8rem]">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Label</label>
                <input type="text" name="name" required maxlength="50" placeholder="e.g. 3 Bed"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <div class="w-24">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Min beds</label>
                <input type="number" name="beds_min" required min="0" max="20" value="0"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <div class="w-24">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Max beds</label>
                <input type="number" name="beds_max" min="0" max="20" placeholder="∞"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
            </div>
            <button type="submit" class="rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap"
                    style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569);">
                + Add segment
            </button>
        </form>

        <div class="flex flex-wrap gap-2 mt-3">
            @forelse ($micBedroomSegments as $segment)
                <span class="inline-flex items-center gap-1.5 rounded-full pl-3 pr-1.5 py-1 text-xs"
                      style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    {{ $segment->name }} <span style="color:var(--text-muted);">({{ $segment->beds_min }}{{ $segment->beds_max !== null ? '–' . $segment->beds_max : '+' }})</span>
                    <form method="POST" action="{{ route('corex.agency-setup.collection.remove', ['collection' => 'mic_bedroom_segment', 'id' => $segment->id]) }}" class="inline">
                        @csrf @method('DELETE')
                        <button type="submit" title="Remove" class="inline-flex items-center justify-center w-4 h-4 rounded-full"
                                style="background:none;border:none;cursor:pointer;color:var(--text-muted,#94a3b8);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </form>
                </span>
            @empty
                <span class="text-xs italic" style="color:var(--text-muted,#94a3b8);">None yet — add your first above.</span>
            @endforelse
        </div>
    </div>

    {{-- Price bands --}}
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Price bands</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Separate bands for Sale and Rental stock — leave max blank for unbounded (top band).</p>

        @foreach (['sale' => ['label' => 'Sale', 'items' => $micPriceBandsSale], 'rental' => ['label' => 'Rental', 'items' => $micPriceBandsRental]] as $listingType => $group)
            <div class="mt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">{{ $group['label'] }}</h3>
                <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => 'mic_price_band']) }}"
                      class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="listing_type" value="{{ $listingType }}">
                    <div class="flex-1 min-w-[8rem]">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Label</label>
                        <input type="text" name="name" required maxlength="100" placeholder="e.g. Entry Level"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    </div>
                    <div class="w-36">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Min price (R)</label>
                        <input type="number" name="price_min" required min="0" value="0"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    </div>
                    <div class="w-36">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Max price (R)</label>
                        <input type="number" name="price_max" min="0" placeholder="∞"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    </div>
                    <button type="submit" class="rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap"
                            style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569);">
                        + Add band
                    </button>
                </form>
                <div class="flex flex-wrap gap-2 mt-3">
                    @forelse ($group['items'] as $band)
                        <span class="inline-flex items-center gap-1.5 rounded-full pl-3 pr-1.5 py-1 text-xs"
                              style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                            {{ $band->name }} <span style="color:var(--text-muted);">(R{{ number_format($band->price_min) }}{{ $band->price_max !== null ? '–R' . number_format($band->price_max) : '+' }})</span>
                            <form method="POST" action="{{ route('corex.agency-setup.collection.remove', ['collection' => 'mic_price_band', 'id' => $band->id]) }}" class="inline">
                                @csrf @method('DELETE')
                                <button type="submit" title="Remove" class="inline-flex items-center justify-center w-4 h-4 rounded-full"
                                        style="background:none;border:none;cursor:pointer;color:var(--text-muted,#94a3b8);">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </form>
                        </span>
                    @empty
                        <span class="text-xs italic" style="color:var(--text-muted,#94a3b8);">None yet — add your first above.</span>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-[11px] italic" style="color:var(--text-muted,#94a3b8);">Archiving is reversible — nothing here is hard-deleted. You can add, edit, and reorder all of this later from Settings → Operations → Prospecting Setup.</p>
</div>
