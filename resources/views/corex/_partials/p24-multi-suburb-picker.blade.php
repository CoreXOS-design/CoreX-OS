{{--
    Reusable Property24 cascading Province → City → Suburb (MULTI-SELECT) picker.

    Used by Core Matches / Buyer Wishlists where a contact can be looking in
    several suburbs at once. Province + City are single-select cascading
    parents; Suburb is a multi-select chip control. Submitting writes one
    hidden input per chip: `p24_suburb_ids[]`.

    Usage:
      @include('corex._partials.p24-multi-suburb-picker', [
          'fieldName'       => 'p24_suburb_ids',
          'initialSuburbs'  => $initialSuburbs, // array of ['id'=>..,'name'=>..,'city_id'=>..,'province_id'=>..]
      ])
--}}
@php
    $fieldName      = $fieldName      ?? 'p24_suburb_ids';
    $initialSuburbs = $initialSuburbs ?? [];
@endphp

<div x-data="p24MultiSuburbPicker({ initialSuburbs: @js($initialSuburbs) })" x-init="init()" class="space-y-3">

    {{-- One hidden input per selected suburb id (so the server gets an array). --}}
    <template x-for="s in selected" :key="'sub-'+s.id">
        <input type="hidden" :name="'{{ $fieldName }}[]'" :value="s.id">
    </template>

    {{-- Chips of already-picked suburbs --}}
    <div class="flex flex-wrap gap-1.5 min-h-[28px]">
        <template x-for="(s, i) in selected" :key="'chip-'+s.id">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold"
                  style="background:var(--brand-button, #0ea5e9); color:white;">
                <span x-text="s.name + (s.city_name ? ' · ' + s.city_name : '')"></span>
                <button type="button" @click="remove(i)" class="hover:opacity-70" aria-label="Remove">×</button>
            </span>
        </template>
        <span x-show="selected.length === 0" class="text-[11px]" style="color:var(--text-muted);">
            No suburbs picked yet — choose Province → City → Suburb below.
        </span>
    </div>

    {{-- Cascading P24 picker --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        @foreach(['province', 'city', 'suburb'] as $level)
            @php
                $label = ['province' => 'Province', 'city' => 'City / Town', 'suburb' => 'Add Suburb'][$level];
                $deps  = ['province' => null, 'city' => 'province', 'suburb' => 'city'][$level];
            @endphp
            <div class="relative" @click.outside="closeDropdown('{{ $level }}')">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $label }}</label>
                <input type="text"
                       x-model="queries.{{ $level }}"
                       @focus="openDropdown('{{ $level }}')"
                       @input="onType('{{ $level }}')"
                       :placeholder="placeholders.{{ $level }}"
                       :disabled="@if($deps) !{{ $deps }}Id @else false @endif"
                       autocomplete="off"
                       class="w-full px-3 py-2 text-sm rounded-md outline-none transition-all duration-300"
                       style="border:1px solid var(--border); background:var(--surface); color:var(--text-primary);">

                <div x-show="dropdown.{{ $level }} && filtered.{{ $level }}.length > 0" x-cloak
                     class="absolute z-30 left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-md"
                     style="background:var(--surface); border:1px solid var(--border); box-shadow:0 8px 30px rgba(0,0,0,0.12);">
                    <template x-for="item in filtered.{{ $level }}" :key="item.id">
                        <button type="button"
                                @mousedown.prevent="select('{{ $level }}', item)"
                                class="w-full text-left px-3 py-2 text-sm transition-all duration-150"
                                style="color:var(--text-primary);"
                                onmouseover="this.style.background='var(--surface-2, #f0f2f8)'"
                                onmouseout="this.style.background=''"
                                x-text="item.name"></button>
                    </template>
                </div>

                <div x-show="loading.{{ $level }}" x-cloak class="text-[11px] mt-1" style="color:var(--text-muted);">Loading…</div>
            </div>
        @endforeach
    </div>
</div>

@once
@push('scripts')
<script>
function p24MultiSuburbPicker(init) {
    return {
        provinceId: 0, provinceName: '',
        cityId: 0,     cityName: '',
        selected: (init.initialSuburbs || []).map(s => ({
            id: parseInt(s.id, 10),
            name: s.name || '',
            city_id: s.city_id || 0,
            city_name: s.city_name || '',
            province_id: s.province_id || 0,
        })),
        queries:  { province: '', city: '', suburb: '' },
        options:  { province: [], city: [], suburb: [] },
        dropdown: { province: false, city: false, suburb: false },
        loading:  { province: false, city: false, suburb: false },
        placeholders: {
            province: 'Type to search…',
            city:     'Pick a province first',
            suburb:   'Pick a city first',
        },

        async init() { await this._load('province'); },

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
                if (!q) return opts.slice(0, 50);
                const pre = opts.filter(o => norm(o.name).startsWith(q));
                const sub = opts.filter(o => !norm(o.name).startsWith(q) && norm(o.name).includes(q));
                return [...pre, ...sub].slice(0, 50);
            };
            return { province: make('province'), city: make('city'), suburb: make('suburb') };
        },

        openDropdown(level)  { this.dropdown[level] = true; },
        closeDropdown(level) { setTimeout(() => { this.dropdown[level] = false; }, 150); },

        onType(level) {
            // Editing a parent invalidates downstream selections (NOT chips already locked-in).
            if (level === 'province') {
                this.provinceId = 0; this.provinceName = '';
                this.cityId = 0; this.cityName = ''; this.queries.city = ''; this.options.city = [];
                this.options.suburb = []; this.queries.suburb = '';
            }
            if (level === 'city') {
                this.cityId = 0; this.cityName = '';
                this.options.suburb = []; this.queries.suburb = '';
            }
            this.dropdown[level] = true;
        },

        async select(level, item) {
            this.queries[level] = item.name;
            this.dropdown[level] = false;
            if (level === 'province') {
                this.provinceId = item.id; this.provinceName = item.name;
                this.placeholders.city = 'Type a city / town…';
                this.placeholders.suburb = 'Pick a city first';
                await this._load('city');
            }
            if (level === 'city') {
                this.cityId = item.id; this.cityName = item.name;
                this.placeholders.suburb = 'Type a suburb…';
                await this._load('suburb');
            }
            if (level === 'suburb') {
                if (!this.selected.find(s => s.id === item.id)) {
                    this.selected.push({
                        id: item.id,
                        name: item.name,
                        city_id: this.cityId,
                        city_name: this.cityName,
                        province_id: this.provinceId,
                    });
                }
                // Clear the suburb query so the user can keep adding more.
                this.queries.suburb = '';
            }
        },

        remove(i) { this.selected.splice(i, 1); },
    };
}
</script>
@endpush
@endonce
