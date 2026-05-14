{{--
  Core Matches / Wishlist form partial.
  Usage:
    @include('corex.contacts._match-form', ['contact' => $contact, 'match' => null])
    @include('corex.contacts._match-form', ['contact' => $contact, 'match' => $match])

  Required outer-scope variables:
    $contact          App\Models\Contact
    $matchCategories  Collection (->name)
    $matchTypes       Collection (->name)
    $featureOptions   string[] feature tokens (e.g. pool, pet_friendly)

  Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 6.1
  History: Prompt 03 extracted as a verbatim refactor. Prompt 04 added is_primary,
  property_types (multi), bedrooms_max, deal_breakers (chip), refactored features
  to chip selectors, wired up edit-mode pre-population for every field.
--}}
@php
    $isEdit = isset($match) && $match instanceof \App\Models\ContactMatch;
    $siblingCount = $contact->matches()->when($isEdit, fn ($q) => $q->where('id', '!=', $match->id))->count();
    $val = fn (string $field, $default = '') => old($field, $isEdit ? ($match->{$field} ?? $default) : $default);

    $selectedPropertyTypes  = old('property_types',           $isEdit ? $match->propertyTypeList()       : []);
    $selectedSuburbs        = old('suburbs',                  $isEdit ? ($match->suburbs ?? [])           : []);
    $selectedMustHaves      = old('must_have_features',       $isEdit ? ($match->must_have_features ?? []) : []);
    $selectedNiceToHaves    = old('nice_to_have_features',    $isEdit ? ($match->nice_to_have_features ?? []) : []);
    $selectedDealBreakers   = old('deal_breakers',            $isEdit ? ($match->deal_breakers ?? [])     : []);
    $initialListingType     = old('listing_type', $isEdit ? $match->listing_type : 'sale');
    $featureLabel = fn (string $token) => \Illuminate\Support\Str::headline(str_replace('_', ' ', $token));

    // Caller may override the form-submit URL by passing $formAction. Default
    // routes to the corex.contacts.matches.* endpoints (the Contact-page surface).
    // The buyer-pipeline Wishlists tab passes its own routes to keep the spec D8
    // permission gate route-scoped.
    $formAction = $formAction
        ?? ($isEdit
            ? route('corex.contacts.matches.update', [$contact, $match])
            : route('corex.contacts.matches.store', $contact));
@endphp

                <form method="POST" action="{{ $formAction }}"
                      x-data="{ listingType: @js($initialListingType) }"
                      class="space-y-5">
                    @csrf
                    @if($isEdit) @method('PUT') @endif

                    {{-- Listing type toggle --}}
                    <div>
                        <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Listing Type</label>
                        <input type="hidden" name="listing_type" :value="listingType">
                        <div class="inline-flex rounded-md p-0.5 gap-0.5" style="background:var(--surface); border:1px solid var(--border);">
                            <button type="button"
                                    @click="listingType = 'sale'"
                                    :class="listingType === 'sale' ? 'text-white' : ''"
                                    :style="listingType === 'sale' ? 'background:var(--brand-button, #0ea5e9);' : 'color:var(--text-secondary);'"
                                    class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all duration-150">
                                For Sale
                            </button>
                            <button type="button"
                                    @click="listingType = 'rental'"
                                    :class="listingType === 'rental' ? 'text-white' : ''"
                                    :style="listingType === 'rental' ? 'background:var(--brand-button, #0ea5e9);' : 'color:var(--text-secondary);'"
                                    class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all duration-150">
                                Rental
                            </button>
                        </div>
                    </div>

                    {{-- Optional label for this wishlist --}}
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Wishlist Name <span class="font-normal" style="color:var(--text-muted);">(optional)</span></label>
                        <input type="text" name="name" value="{{ $val('name') }}" placeholder='e.g. "3-bed Margate sale"' maxlength="120"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>

                    {{-- Primary flag — only render when there are siblings to demote OR in edit mode --}}
                    @if($siblingCount > 0 || $isEdit)
                    <div class="flex items-start gap-2 rounded-md p-3" style="background:var(--surface); border:1px solid var(--border);">
                        <input type="checkbox" id="match_is_primary" name="is_primary" value="1"
                               @checked(old('is_primary', $isEdit ? (bool) $match->is_primary : false))
                               class="mt-0.5">
                        <div class="flex-1">
                            <label for="match_is_primary" class="block text-xs font-semibold cursor-pointer" style="color:var(--text-primary);">Primary wishlist</label>
                            <p class="text-[10px] mt-0.5" style="color:var(--text-muted);">The primary wishlist is used for prospecting demand counts and default surfaces.</p>
                        </div>
                    </div>
                    @endif

                    {{-- Row 1: Category + Property Types (multi-chip) + Suburbs (multi-chip) --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Category</label>
                            <select name="category" class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— Any —</option>
                                @foreach($matchCategories as $cat)
                                <option value="{{ $cat->name }}" @selected($val('category') === $cat->name)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Property Types: multi-select chips (spec D2). Legacy property_type also submitted as a hidden mirror of the first chip. --}}
                        <div x-data="{ selected: @js(array_values($selectedPropertyTypes)) }">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Property Types <span class="font-normal" style="color:var(--text-muted);">(one or more)</span></label>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($matchTypes as $type)
                                <button type="button"
                                        @click="selected.includes('{{ $type->name }}') ? selected = selected.filter(v => v !== '{{ $type->name }}') : selected.push('{{ $type->name }}')"
                                        :class="selected.includes('{{ $type->name }}') ? 'text-white' : ''"
                                        :style="selected.includes('{{ $type->name }}') ? 'background:var(--brand-button, #0ea5e9); border-color:var(--brand-button, #0ea5e9);' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);'"
                                        class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition-all duration-150">
                                    {{ $type->name }}
                                </button>
                                @endforeach
                            </div>
                            <template x-for="t in selected" :key="t">
                                <input type="hidden" name="property_types[]" :value="t">
                            </template>
                            {{-- legacy single-string column kept in sync via first element (spec D2) --}}
                            <input type="hidden" name="property_type" :value="selected[0] || ''">
                        </div>

                        {{-- Suburbs: chip selector (free-entry) --}}
                        <div x-data="{ selected: @js(array_values($selectedSuburbs)), draft: '' }">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Suburbs <span class="font-normal" style="color:var(--text-muted);">(comma or enter)</span></label>
                            <div class="flex flex-wrap gap-1.5 mb-1.5">
                                <template x-for="(s, i) in selected" :key="i + '-' + s">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold"
                                          style="background:var(--brand-button, #0ea5e9); color:white;">
                                        <span x-text="s"></span>
                                        <button type="button" @click="selected.splice(i, 1)" class="hover:opacity-70" aria-label="Remove">×</button>
                                    </span>
                                </template>
                            </div>
                            <input type="text" x-model="draft" placeholder="e.g. Uvongo, Margate"
                                   @keydown.enter.prevent="if (draft.trim()) { selected.push(draft.trim()); draft = ''; }"
                                   @keydown.,.prevent="if (draft.trim()) { selected.push(draft.trim()); draft = ''; }"
                                   @blur="if (draft.trim()) { selected.push(draft.trim()); draft = ''; }"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <template x-for="(s, i) in selected" :key="'h-' + i + '-' + s">
                                <input type="hidden" name="suburbs[]" :value="s">
                            </template>
                        </div>
                    </div>

                    {{-- Row 2: Price range --}}
                    <div>
                        <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Price Range (R)</label>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <input type="number" name="price_min" value="{{ $val('price_min') }}" placeholder="Min price" min="0" step="50000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <input type="number" name="price_max" value="{{ $val('price_max') }}" placeholder="Max price" min="0" step="50000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Row 3a: Min/max bedrooms + bathrooms + garages + parking --}}
                    <div>
                        <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Bedrooms &amp; Rooms</label>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                            <div>
                                <label class="block text-[10px] mb-1" style="color:var(--text-muted);">Min Bedrooms</label>
                                <input type="number" name="beds_min" value="{{ $val('beds_min') }}" placeholder="Any" min="0" max="20"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-[10px] mb-1" style="color:var(--text-muted);">Max Bedrooms <span class="font-normal" style="color:var(--text-muted);">(leave blank for no limit)</span></label>
                                <input type="number" name="bedrooms_max" value="{{ $val('bedrooms_max') }}" placeholder="Any" min="0" max="20"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            @foreach([['baths_min','Bathrooms'],['garages_min','Garages'],['parking_min','Parking']] as [$field,$label])
                            <div>
                                <label class="block text-[10px] mb-1" style="color:var(--text-muted);">{{ $label }}</label>
                                <input type="number" name="{{ $field }}" value="{{ $val($field) }}" placeholder="Any" min="0" max="20"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Row 4: Floor size / Erf size --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Floor Size (m²)</label>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" name="floor_size_min" value="{{ $val('floor_size_min') }}" placeholder="Min" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <input type="number" name="floor_size_max" value="{{ $val('floor_size_max') }}" placeholder="Max" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Erf Size (m²)</label>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" name="erf_size_min" value="{{ $val('erf_size_min') }}" placeholder="Min" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <input type="number" name="erf_size_max" value="{{ $val('erf_size_max') }}" placeholder="Max" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Feature buckets: must-have / nice-to-have / deal-breakers (spec D5) --}}
                    @php
                        $chipBuckets = [
                            ['must_have_features',    'Must-Have Features',    'Property MUST have these. Missing any of these excludes the property from matches.', $selectedMustHaves,    'var(--ds-green, #10b981)'],
                            ['nice_to_have_features', 'Nice-to-Have Features', 'Presence boosts the match score (not a hard filter).',                              $selectedNiceToHaves,  'var(--brand-button, #0ea5e9)'],
                            ['deal_breakers',         'Deal-Breakers',         'Property MUST NOT have these. Presence of any excludes the property.',              $selectedDealBreakers, 'var(--ds-crimson, #e11d48)'],
                        ];
                    @endphp
                    @foreach($chipBuckets as [$fieldName, $bucketLabel, $bucketHelp, $bucketSelected, $bucketColor])
                    <div x-data="{ selected: @js(array_values($bucketSelected)) }">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $bucketLabel }}</label>
                        <p class="text-[10px] mb-1.5" style="color:var(--text-muted);">{{ $bucketHelp }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($featureOptions as $token)
                            <button type="button"
                                    @click="selected.includes('{{ $token }}') ? selected = selected.filter(v => v !== '{{ $token }}') : selected.push('{{ $token }}')"
                                    :class="selected.includes('{{ $token }}') ? 'text-white' : ''"
                                    :style="selected.includes('{{ $token }}') ? 'background:{{ $bucketColor }}; border-color:{{ $bucketColor }};' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);'"
                                    class="px-2.5 py-1 rounded-md text-[11px] font-semibold transition-all duration-150">
                                {{ $featureLabel($token) }}
                            </button>
                            @endforeach
                        </div>
                        <template x-for="t in selected" :key="'{{ $fieldName }}-' + t">
                            <input type="hidden" name="{{ $fieldName }}[]" :value="t">
                        </template>
                    </div>
                    @endforeach

                    {{-- Notes --}}
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notes (optional)</label>
                        <textarea name="notes" rows="2" placeholder="Any additional requirements..."
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ $val('notes') }}</textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="corex-btn-primary text-sm">
                            {{ $isEdit ? 'Update Wishlist' : 'Save Match' }}
                        </button>
                    </div>
                </form>
