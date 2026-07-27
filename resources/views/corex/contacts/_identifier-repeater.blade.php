{{--
    AT-125 — reusable repeatable identifier list (phones or emails) with
    add/remove + mark-primary. Posts kind[i][value], kind[i][is_primary]; the
    controller (ContactIdentifierService) writes the child rows + keeps the
    contacts.phone/email mirror correct.

    Contact-details Phase 1 — phone rows also post kind[i][country_iso] via a
    SEARCHABLE country combobox (type-to-search on name/ISO/dial-code, shows
    "South Africa (+27)" not a bare code — Johan's QA1 feedback: the original
    plain <select> with only "+27 ZA" style options was unusable, nobody could
    tell which country was which), default ZA. Defends against the "agent
    couldn't load a USA number" bug: every number now carries an explicit
    country, so WhatsApp deep-links and the dedup key stop assuming +27.

    Contact-details Phase 2 — BOTH kinds post kind[i][label_id], a managed
    label picked from the agency's Settings -> Contacts list (was a free-text
    box; now a dropdown so every row's label is one of a consistent, admin-
    controlled set). $labels — the ContactIdentifierLabel collection.

    Params:
      $kind        'phones' | 'emails'
      $type        'text'   | 'email'
      $title       label text
      $addLabel    e.g. 'phone' / 'email' (button reads "Add {addLabel}")
      $placeholder input placeholder
      $existing    (optional) Eloquent collection of the contact's child rows (edit)
      $labels      (optional) Eloquent collection of ContactIdentifierLabel
--}}
@php
    $valueKey = $kind === 'phones' ? 'phone' : 'email';
    $isPhones = $kind === 'phones';
    $countries = $isPhones ? config('country-dial-codes.countries', []) : [];
    $identifierLabels = $labels ?? collect();
    $old = old($kind);
    if (is_array($old)) {
        $items = collect($old)
            ->map(fn ($r) => ['value' => $r['value'] ?? '', 'label_id' => (string) ($r['label_id'] ?? ''), 'is_primary' => ! empty($r['is_primary']), 'country_iso' => $r['country_iso'] ?? 'ZA', 'is_whatsapp' => ! empty($r['is_whatsapp']), 'is_primary_whatsapp' => ! empty($r['is_primary_whatsapp'])])
            ->filter(fn ($r) => trim((string) $r['value']) !== '')
            ->values()->all();
    } elseif (! empty($existing) && $existing->count()) {
        $items = $existing
            ->map(fn ($r) => ['value' => $r->{$valueKey}, 'label_id' => $r->contact_identifier_label_id ? (string) $r->contact_identifier_label_id : '', 'is_primary' => (bool) $r->is_primary, 'country_iso' => $isPhones ? ($r->country_iso ?? 'ZA') : 'ZA', 'is_whatsapp' => $isPhones ? (bool) $r->is_whatsapp : false, 'is_primary_whatsapp' => $isPhones ? (bool) $r->is_primary_whatsapp : false])
            ->values()->all();
    } else {
        $items = [];
    }
    if (empty($items)) {
        $items = [['value' => '', 'label_id' => '', 'is_primary' => true, 'country_iso' => 'ZA', 'is_whatsapp' => false, 'is_primary_whatsapp' => false]];
    }
@endphp

<div x-data="corexIdentifierRepeater(@js($items), @js($countries))" data-identifier-group="{{ $kind }}" class="space-y-2">
    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $title }}</label>

    <template x-for="(item, idx) in items" :key="idx">
        <div class="flex items-center gap-2">
            @if($isPhones)
            <div class="relative" @click.outside="closeCountry(idx)">
                <input type="text"
                       title="Country dialing code — type to search"
                       :value="countryOpen[idx] ? (countryQuery[idx] ?? '') : countryLabel(item)"
                       @focus="openCountry(idx)"
                       @input="countryQuery[idx] = $event.target.value; countryOpen[idx] = true"
                       placeholder="Search country…"
                       autocomplete="off"
                       class="rounded-md px-2 py-2 text-sm transition-all duration-300"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none; width:11rem;">
                <input type="hidden" :name="`{{ $kind }}[${idx}][country_iso]`" :value="item.country_iso">

                <div x-show="countryOpen[idx] && filteredCountries(idx).length > 0" x-cloak
                     class="absolute z-30 left-0 mt-1 w-56 max-h-56 overflow-y-auto rounded-md"
                     style="background:var(--surface); border:1px solid var(--border); box-shadow:0 8px 30px rgba(0,0,0,0.15);">
                    <template x-for="c in filteredCountries(idx)" :key="c.iso">
                        <button type="button" @mousedown.prevent="pickCountry(idx, c.iso)"
                                class="w-full text-left px-3 py-2 text-sm"
                                style="color:var(--text-primary); border-bottom:1px solid var(--border);"
                                x-text="c.name + ' (' + c.dial_code + ')'"></button>
                    </template>
                </div>
                <div x-show="countryOpen[idx] && filteredCountries(idx).length === 0" x-cloak
                     class="absolute z-30 left-0 mt-1 w-56 rounded-md px-3 py-2 text-xs"
                     style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                    No matching country.
                </div>
            </div>
            @endif

            <input :type="'{{ $type }}'" :name="`{{ $kind }}[${idx}][value]`" x-model="item.value"
                   data-identifier-value
                   @blur="$dispatch('contact-check-dup')"
                   placeholder="{{ $placeholder }}"
                   class="flex-1 rounded-md px-3 py-2 text-sm transition-all duration-300"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">

            <select :name="`{{ $kind }}[${idx}][label_id]`" x-model="item.label_id"
                    title="Label"
                    class="w-28 rounded-md px-2 py-2 text-sm transition-all duration-300"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted); outline:none;">
                <option value="">No label</option>
                @foreach($identifierLabels as $l)
                <option value="{{ $l->id }}">{{ $l->name }}</option>
                @endforeach
            </select>

            <input type="hidden" :name="`{{ $kind }}[${idx}][is_primary]`" :value="idx === primary ? 1 : 0">

            <label class="flex items-center gap-1 text-[11px] whitespace-nowrap" style="color:var(--text-muted);" title="Mark as primary">
                <input type="radio" :checked="idx === primary" @change="setPrimary(idx)" style="accent-color:var(--brand-icon,#0ea5e9);">
                Primary
            </label>

            @if($isPhones)
            {{-- Contact-details Phase 3 — a number can be flagged reachable on
                 WhatsApp independently of being the primary CONTACT number
                 (e.g. an office line is primary-contact, a personal cell is
                 primary-WhatsApp). "Primary WA" only shown once 2+ rows are
                 WhatsApp-checked — with only one checked it's unambiguous. --}}
            <input type="hidden" :name="`{{ $kind }}[${idx}][is_whatsapp]`" :value="item.is_whatsapp ? 1 : 0">
            <label class="flex items-center gap-1 text-[11px] whitespace-nowrap" style="color:var(--text-muted);" title="Reachable on WhatsApp">
                <input type="checkbox" x-model="item.is_whatsapp" @change="onWhatsAppToggle(idx)" style="accent-color:#25D366;">
                WhatsApp
            </label>
            <input type="hidden" :name="`{{ $kind }}[${idx}][is_primary_whatsapp]`" :value="idx === primaryWhatsapp ? 1 : 0">
            <label x-show="item.is_whatsapp && whatsappCount() > 1" x-cloak
                   class="flex items-center gap-1 text-[11px] whitespace-nowrap" style="color:var(--text-muted);" title="Use this number for WhatsApp outreach">
                <input type="radio" :checked="idx === primaryWhatsapp" @change="setPrimaryWhatsapp(idx)" style="accent-color:#25D366;">
                Primary WA
            </label>
            @endif

            <button type="button" @click="remove(idx)" x-show="items.length > 1"
                    class="text-xs font-semibold px-2 py-1 rounded-md transition-all duration-300"
                    style="color:var(--ds-crimson, #c41e3a);" title="Remove">Remove</button>
        </div>
    </template>

    <button type="button" @click="add()"
            class="text-xs font-semibold transition-all duration-300"
            style="color:var(--brand-icon,#0ea5e9);">+ Add {{ $addLabel }}</button>
</div>

@once
    @push('scripts')
    <script>
        function corexIdentifierRepeater(initial, countries) {
            // country_iso is phone-only (contact-details Phase 1); harmlessly
            // carried-but-unused on email rows, which render no country picker.
            // label_id (Phase 2) applies to BOTH kinds.
            const seed = (Array.isArray(initial) && initial.length)
                ? initial.map(i => ({ value: i.value || '', label_id: i.label_id || '', country_iso: i.country_iso || 'ZA', is_whatsapp: !!i.is_whatsapp, is_primary_whatsapp: !!i.is_primary_whatsapp }))
                : [{ value: '', label_id: '', country_iso: 'ZA', is_whatsapp: false, is_primary_whatsapp: false }];
            let primary = 0;
            if (Array.isArray(initial) && initial.length) {
                const p = initial.findIndex(i => i.is_primary);
                primary = p >= 0 ? p : 0;
            }
            let primaryWhatsapp = -1;
            if (Array.isArray(initial) && initial.length) {
                const pw = initial.findIndex(i => i.is_primary_whatsapp);
                primaryWhatsapp = pw >= 0 ? pw : -1;
            }
            return {
                items: seed,
                primary: primary,
                // Contact-details Phase 3 — primary WhatsApp, phone rows only.
                // -1 = nothing designated (the normal state for most contacts).
                primaryWhatsapp: primaryWhatsapp,
                whatsappCount() { return this.items.filter(i => i.is_whatsapp).length; },
                onWhatsAppToggle(idx) {
                    if (this.items[idx].is_whatsapp) {
                        // Newly checked and nothing designated yet, or it's the
                        // only WhatsApp number now → make it primary outright.
                        if (this.primaryWhatsapp < 0 || this.whatsappCount() === 1) {
                            this.primaryWhatsapp = idx;
                        }
                    } else if (this.primaryWhatsapp === idx) {
                        // Unchecked the one that was primary — hand it to
                        // another checked row if one exists, else clear.
                        const next = this.items.findIndex(i => i.is_whatsapp);
                        this.primaryWhatsapp = next;
                    }
                },
                setPrimaryWhatsapp(idx) { this.primaryWhatsapp = idx; },
                // Searchable country combobox (Johan's QA1 feedback — the plain
                // <select> showed only a dial code + ISO, with no name and no
                // search, so nobody could tell which country was which).
                // countryOpen/countryQuery are transient per-row UI state, keyed
                // by row index; the actual selection lives on item.country_iso.
                countries: countries || [],
                countryOpen: {},
                countryQuery: {},
                countryLabel(item) {
                    const c = this.countries.find(c => c.iso === item.country_iso);
                    return c ? (c.name + ' (' + c.dial_code + ')') : 'Select country…';
                },
                filteredCountries(idx) {
                    const q = (this.countryQuery[idx] || '').toLowerCase().trim();
                    if (!q) return this.countries;
                    const starts = this.countries.filter(c => c.name.toLowerCase().startsWith(q));
                    const rest = this.countries.filter(c => !c.name.toLowerCase().startsWith(q)
                        && (c.name.toLowerCase().includes(q) || c.iso.toLowerCase().includes(q) || c.dial_code.includes(q)));
                    return [...starts, ...rest];
                },
                openCountry(idx) { this.countryOpen[idx] = true; this.countryQuery[idx] = ''; },
                closeCountry(idx) { this.countryOpen[idx] = false; },
                pickCountry(idx, iso) {
                    this.items[idx].country_iso = iso;
                    this.countryOpen[idx] = false;
                    this.countryQuery[idx] = '';
                },
                add() { this.items.push({ value: '', label_id: '', country_iso: 'ZA', is_whatsapp: false, is_primary_whatsapp: false }); },
                remove(i) {
                    this.items.splice(i, 1);
                    if (this.items.length === 0) { this.items.push({ value: '', label_id: '', country_iso: 'ZA', is_whatsapp: false, is_primary_whatsapp: false }); }
                    if (this.primary >= this.items.length) { this.primary = this.items.length - 1; }
                    else if (this.primary === i && i !== 0) { this.primary = 0; }
                    // primaryWhatsapp is a plain row INDEX (no per-item boolean
                    // mirrors it), so removing a row before it just needs a shift;
                    // removing the designated row itself falls back to any other
                    // WhatsApp-checked row, or -1 (none) if there isn't one.
                    if (this.primaryWhatsapp === i) {
                        this.primaryWhatsapp = this.items.findIndex(it => it.is_whatsapp);
                    } else if (this.primaryWhatsapp > i) {
                        this.primaryWhatsapp -= 1;
                    }
                    this.countryOpen = {}; // indices shifted — drop stale open/query UI state
                    this.countryQuery = {};
                },
                setPrimary(i) { this.primary = i; },
            };
        }
    </script>
    @endpush
@endonce
