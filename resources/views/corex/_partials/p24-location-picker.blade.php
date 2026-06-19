{{--
    Reusable Property24 cascading Province → City → Suburb combobox.

    Usage:
      @include('corex._partials.p24-location-picker', [
          'fieldPrefix'        => 'p24',   // input name prefix (e.g. 'p24_province_id')
          'initialProvinceId'  => $p->p24_province_id ?? 0,
          'initialCityId'      => $p->p24_city_id ?? 0,
          'initialSuburbId'    => $p->p24_suburb_id ?? 0,
          'initialProvinceName'=> $p->province ?? '',
          'initialCityName'    => $p->city ?? '',
          'initialSuburbName'  => $p->suburb ?? '',
          'denormaliseNames'   => true,    // also write province/city/suburb text inputs
      ])

    Rendered hidden inputs:
      - p24_province_id / p24_city_id / p24_suburb_id (the FKs the server validates)
      - province / city / suburb (denormalised text, optional)

    Behaviour:
      - User types in each field; matching P24 records filter as they type.
      - If the typed text doesn't exactly match a P24 record on blur the
        field is flagged "not on the list" and the underlying ID is cleared.
      - Form cannot be submitted unless all three IDs are > 0.
--}}
@php
    $fieldPrefix         = $fieldPrefix         ?? 'p24';
    $initialProvinceId   = $initialProvinceId   ?? 0;
    $initialCityId       = $initialCityId       ?? 0;
    $initialSuburbId     = $initialSuburbId     ?? 0;
    $initialProvinceName = $initialProvinceName ?? '';
    $initialCityName     = $initialCityName     ?? '';
    $initialSuburbName   = $initialSuburbName   ?? '';
    $denormaliseNames    = $denormaliseNames    ?? true;
@endphp

<div x-data="p24LocationCombobox({
        initialProvinceId:   {{ (int) $initialProvinceId }},
        initialCityId:       {{ (int) $initialCityId }},
        initialSuburbId:     {{ (int) $initialSuburbId }},
        initialProvinceName: @js($initialProvinceName),
        initialCityName:     @js($initialCityName),
        initialSuburbName:   @js($initialSuburbName),
        eventPrefix:         @js($fieldPrefix),
     })" x-init="init()" class="grid grid-cols-1 sm:grid-cols-3 gap-3">

    {{-- Hidden inputs the server reads --}}
    <input type="hidden" name="{{ $fieldPrefix }}_province_id" :value="provinceId">
    <input type="hidden" name="{{ $fieldPrefix }}_city_id"     :value="cityId">
    <input type="hidden" name="{{ $fieldPrefix }}_suburb_id"   :value="suburbId">
    @if($denormaliseNames)
        <input type="hidden" name="province" :value="provinceName">
        <input type="hidden" name="city"     :value="cityName">
        <input type="hidden" name="suburb"   :value="suburbName">
    @endif

    @foreach(['province', 'city', 'suburb'] as $level)
        @php
            $label = ['province' => 'Province', 'city' => 'City / Town', 'suburb' => 'Suburb'][$level];
            $deps  = ['province' => null, 'city' => 'province', 'suburb' => 'city'][$level];
        @endphp
        <div class="relative" @click.outside="closeDropdown('{{ $level }}')">
            <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary, #64748b);">
                {{ $label }} <span class="text-red-500">*</span>
            </label>
            <input type="text"
                   data-loc-field="{{ $level }}"
                   x-model="queries.{{ $level }}"
                   @focus="openDropdown('{{ $level }}')"
                   @input="onType('{{ $level }}')"
                   @blur="onBlur('{{ $level }}')"
                   :placeholder="placeholders.{{ $level }}"
                   :disabled="@if($deps) !{{ $deps }}Id @else false @endif"
                   autocomplete="off"
                   class="w-full px-3 py-2.5 text-sm rounded-md outline-none transition-all duration-300"
                   :style="invalid.{{ $level }}
                       ? 'border:1px solid #fca5a5;background:var(--surface-2, #f0f2f8);color:var(--text-primary, #111827);'
                       : 'border:1px solid var(--border, rgba(0,0,0,0.07));background:var(--surface-2, #f0f2f8);color:var(--text-primary, #111827);'">

            {{-- Dropdown --}}
            <div x-show="dropdown.{{ $level }} && filtered.{{ $level }}.length > 0" x-cloak
                 class="absolute z-30 left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-md transition-all duration-300"
                 style="background:var(--surface, #ffffff);border:1px solid var(--border, rgba(0,0,0,0.07));box-shadow:0 8px 30px rgba(0,0,0,0.12);">
                <template x-for="item in filtered.{{ $level }}" :key="item.id">
                    <button type="button"
                            @mousedown.prevent="select('{{ $level }}', item)"
                            class="w-full text-left px-3 py-2 text-sm transition-all duration-150"
                            style="color:var(--text-primary, #111827);"
                            onmouseover="this.style.background='var(--surface-2, #f0f2f8)'"
                            onmouseout="this.style.background=''"
                            x-text="item.name"></button>
                </template>
            </div>

            {{-- Loading + "not on the list" messages --}}
            <div x-show="loading.{{ $level }}" x-cloak class="text-[11px] mt-1" style="color:var(--text-muted, #9ca3af);">Loading…</div>
            <div x-show="invalid.{{ $level }}" x-cloak class="text-[11px] mt-1" style="color:#b91c1c;">
                <span x-text="'“' + queries.{{ $level }} + '”'"></span>
                is not on Property24 — pick one of the suggestions.
            </div>
            <div x-show="!loading.{{ $level }} && !invalid.{{ $level }} && queries.{{ $level }} && filtered.{{ $level }}.length === 0 && dropdown.{{ $level }}" x-cloak
                 class="text-[11px] mt-1" style="color:var(--text-muted, #9ca3af);">No matches.</div>
        </div>
    @endforeach
</div>

@once
@push('scripts')
<script>
function p24LocationCombobox(init) {
    // Only pre-fill the visible query text if there's a real P24 ID backing
    // it. Legacy free-text suburbs ("KwaZulu-Natal", "Margate", etc.) that
    // were never linked to P24 must start blank so the user is forced to
    // re-pick from the recognised list.
    const provinceId = init.initialProvinceId || 0;
    const cityId     = init.initialCityId     || 0;
    const suburbId   = init.initialSuburbId   || 0;
    return {
        // Per-instance event namespace. Two pickers on the same DOM (e.g. a
        // contact address modal sharing the page with a property form) must NOT
        // cross-fire: each dispatches "p24-location-changed:<prefix>" and each
        // listener subscribes only to its own prefix. Defaults to 'p24' so any
        // legacy include without an explicit prefix keeps the historic name.
        eventPrefix: init.eventPrefix || 'p24',
        provinceId: provinceId,
        cityId:     cityId,
        suburbId:   suburbId,
        provinceName: provinceId ? (init.initialProvinceName || '') : '',
        cityName:     cityId     ? (init.initialCityName     || '') : '',
        suburbName:   suburbId   ? (init.initialSuburbName   || '') : '',
        queries:    {
            province: provinceId ? (init.initialProvinceName || '') : '',
            city:     cityId     ? (init.initialCityName     || '') : '',
            suburb:   suburbId   ? (init.initialSuburbName   || '') : '',
        },
        options:    { province: [], city: [], suburb: [] },
        dropdown:   { province: false, city: false, suburb: false },
        loading:    { province: false, city: false, suburb: false },
        invalid:    { province: false, city: false, suburb: false },
        placeholders: {
            province: 'Type to search…',
            city:     'Pick a province first',
            suburb:   'Pick a city first',
        },

        async init() {
            await this._load('province');
            // Hydrate cities/suburbs if we have a saved property being edited.
            if (this.provinceId) await this._load('city');
            if (this.cityId)     await this._load('suburb');

            // Allow a parent form to clear this picker (e.g. a contact "Clear
            // address" button). Namespaced per-instance so it never resets a
            // sibling picker. Reusable across every include site.
            window.addEventListener('p24-location-reset:' + this.eventPrefix, () => this._reset());
        },

        _reset() {
            this.provinceId = 0; this.cityId = 0; this.suburbId = 0;
            this.provinceName = ''; this.cityName = ''; this.suburbName = '';
            this.queries.province = ''; this.queries.city = ''; this.queries.suburb = '';
            this.options.city = []; this.options.suburb = [];
            this.invalid.province = false; this.invalid.city = false; this.invalid.suburb = false;
            this.placeholders.city = 'Pick a province first';
            this.placeholders.suburb = 'Pick a city first';
        },

        async _load(level) {
            this.loading[level] = true;
            try {
                let url;
                if (level === 'province') url = '/api/v1/p24/provinces';
                if (level === 'city')     url = '/api/v1/p24/cities?province_id=' + this.provinceId;
                if (level === 'suburb')   url = '/api/v1/p24/suburbs?city_id=' + this.cityId;
                const r = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                this.options[level] = j.data || [];
            } finally { this.loading[level] = false; }
        },

        get filtered() {
            const norm = (s) => (s || '').toLowerCase().trim();
            const make = (level) => {
                const q = norm(this.queries[level]);
                const opts = this.options[level];
                if (!q) return opts.slice(0, 20);
                // Prefer prefix matches, then substring.
                const prefix = opts.filter(o => norm(o.name).startsWith(q));
                const subs   = opts.filter(o => !norm(o.name).startsWith(q) && norm(o.name).includes(q));
                return [...prefix, ...subs].slice(0, 20);
            };
            return { province: make('province'), city: make('city'), suburb: make('suburb') };
        },

        openDropdown(level) { this.dropdown[level] = true; },
        closeDropdown(level) { this.dropdown[level] = false; },

        onType(level) {
            // When the user edits the text, they're abandoning the prior pick.
            if (level === 'province') {
                this.provinceId = 0; this.provinceName = '';
                this.cityId = 0; this.cityName = ''; this.queries.city = ''; this.options.city = []; this.invalid.city = false;
                this.suburbId = 0; this.suburbName = ''; this.queries.suburb = ''; this.options.suburb = []; this.invalid.suburb = false;
            }
            if (level === 'city') {
                this.cityId = 0; this.cityName = '';
                this.suburbId = 0; this.suburbName = ''; this.queries.suburb = ''; this.options.suburb = []; this.invalid.suburb = false;
            }
            if (level === 'suburb') {
                this.suburbId = 0; this.suburbName = '';
            }
            this.invalid[level] = false;
            this.dropdown[level] = true;
        },

        onBlur(level) {
            // Slight delay so click-on-option fires first.
            setTimeout(() => {
                this.dropdown[level] = false;
                const q = (this.queries[level] || '').trim().toLowerCase();
                if (!q) { this.invalid[level] = false; return; }
                const match = this.options[level].find(o => o.name.toLowerCase() === q);
                if (match) {
                    this.select(level, match, false);
                    this.invalid[level] = false;
                } else {
                    this.invalid[level] = true;
                    // ID stays 0 → form-validation already blocks submit.
                }
            }, 150);
        },

        async select(level, item, reopenNext = true) {
            this.queries[level] = item.name;
            this.invalid[level] = false;
            this.dropdown[level] = false;

            if (level === 'province') {
                this.provinceId = item.id; this.provinceName = item.name;
                this.cityId = 0; this.cityName = ''; this.queries.city = ''; this.invalid.city = false;
                this.suburbId = 0; this.suburbName = ''; this.queries.suburb = ''; this.invalid.suburb = false;
                this.options.city = []; this.options.suburb = [];
                this.placeholders.city = 'Type a city / town…';
                this.placeholders.suburb = 'Pick a city first';
                await this._load('city');
            }
            if (level === 'city') {
                this.cityId = item.id; this.cityName = item.name;
                this.suburbId = 0; this.suburbName = ''; this.queries.suburb = ''; this.invalid.suburb = false;
                this.options.suburb = [];
                this.placeholders.suburb = 'Type a suburb…';
                await this._load('suburb');
            }
            if (level === 'suburb') {
                this.suburbId = item.id; this.suburbName = item.name;
            }
            // Tell the parent form (if it's listening) that location changed so
            // the map can re-geocode and the wizard can mirror IDs into s1. The
            // event is namespaced per-instance so sibling pickers never cross-fire.
            window.dispatchEvent(new CustomEvent('p24-location-changed:' + this.eventPrefix, { detail: {
                provinceId: this.provinceId, cityId: this.cityId, suburbId: this.suburbId,
                provinceName: this.provinceName, cityName: this.cityName, suburbName: this.suburbName,
            }}));
        },

        // Used by parent forms to gate submit.
        isComplete() {
            return this.provinceId > 0 && this.cityId > 0 && this.suburbId > 0;
        },
    };
}
</script>
@endpush
@endonce
