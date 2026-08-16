{{--
    Shared "Property Address" capture — the box + modal + Alpine component that the
    CONTACT screen ("Start a Property from an Address") uses, extracted so the
    no-address Pitch Now flow reuses the EXACT same capture (Johan 2026-08-11).

    Renders: a read-only composed-summary button that opens the Property Address
    modal (Complex/Estate + Street + the shared P24 Province/City/Suburb picker),
    the live "already on our books" warning, and hidden inputs that submit the
    structured fields with the SURROUNDING form.

    This partial does NOT render its own <form> or submit button — the including
    page wraps it in the form it wants (contact save, or the pitch capture form)
    and supplies the submit control. One component, many mount points.

    Params (all optional except heldCheckUrl):
      $fieldPrefix   string  namespace for the P24 picker events + function scope
                             (e.g. 'contact_addr', 'pitch_addr') so two pickers on
                             different pages never cross-fire. Default 'addr'.
      $initial       array   initial field values: unitNumber, floorNumber,
                             unitSectionBlock, complexName, streetNumber,
                             streetName, suburb, city, province. Default all ''.
      $initialP24    array   provinceId, cityId, suburbId, provinceName, cityName,
                             suburbName for the picker. Default 0/''.
      $heldCheckUrl  string  POST route for the "already on our books" check.
--}}
@php
    $fieldPrefix  = $fieldPrefix  ?? 'addr';
    $initial      = array_merge([
        'unitNumber' => '', 'floorNumber' => '', 'unitSectionBlock' => '', 'complexName' => '',
        'streetNumber' => '', 'streetName' => '', 'suburb' => '', 'city' => '', 'province' => '',
    ], $initial ?? []);
    $initialP24   = array_merge([
        'provinceId' => 0, 'cityId' => 0, 'suburbId' => 0,
        'provinceName' => '', 'cityName' => '', 'suburbName' => '',
    ], $initialP24 ?? []);
    $fnName = 'propertyAddressCapture_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldPrefix);
@endphp

<div x-data="{{ $fnName }}({{ \Illuminate\Support\Js::from([
        'unitNumber'       => $initial['unitNumber'],
        'floorNumber'      => $initial['floorNumber'],
        'unitSectionBlock' => $initial['unitSectionBlock'],
        'complexName'      => $initial['complexName'],
        'streetNumber'     => $initial['streetNumber'],
        'streetName'       => $initial['streetName'],
        'suburb'           => $initial['suburb'],
        'city'             => $initial['city'],
        'province'         => $initial['province'],
     ]) }})">

    {{-- Read-only composed summary — a real, clearly-editable control (No Invisible Edits). --}}
    <button type="button" @click="openAddrModal = true"
            class="w-full flex items-center justify-between gap-3 rounded-md px-3 py-2 text-left transition-all duration-300"
            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        <span class="text-sm truncate" x-text="hasAddress ? summary : 'Click to set a property address'"
              :style="hasAddress ? '' : 'color:var(--text-muted);'"></span>
        <span class="inline-flex items-center gap-1 flex-shrink-0 text-[11px] font-semibold" style="color:var(--brand-icon, #2563eb);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <span x-text="hasAddress ? 'Edit' : 'Set'"></span>
        </span>
    </button>

    {{-- Live "already on our books" warning — read-only check as the agent types. --}}
    <div x-show="held" x-cloak class="mt-3 rounded-md p-3"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, transparent);">
        <div class="flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div class="text-xs leading-relaxed" style="color:var(--text-primary);">
                <strong>HFC already has this property on its books</strong> — <span x-text="held && held.label"></span>.
                <template x-if="held && held.address"><span> (<span x-text="held.address"></span>)</span></template>
                <div class="mt-1" style="color:var(--text-secondary);">
                    Check the existing record before canvassing the owner —
                    <template x-if="held && held.property_url"><a :href="held.property_url" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open the property record</a></template>
                    <template x-if="held && !held.property_url && held.tracked_url"><a :href="held.tracked_url" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open property intel</a></template>.
                </div>
            </div>
        </div>
    </div>

    {{-- Hidden inputs — submit the parent-managed components with the SURROUNDING form. --}}
    <input type="hidden" name="unit_number"        :value="unitNumber">
    <input type="hidden" name="floor_number"       :value="floorNumber">
    <input type="hidden" name="unit_section_block" :value="unitSectionBlock">
    <input type="hidden" name="complex_name"       :value="complexName">
    <input type="hidden" name="street_number"      :value="streetNumber">
    <input type="hidden" name="street_name"        :value="streetName">

    {{-- ===== PROPERTY-ADDRESS MODAL ===== --}}
    <div x-show="openAddrModal" x-cloak
         class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
         @keydown.escape.window="openAddrModal = false">
        <div class="absolute inset-0 bg-black/60" @click="openAddrModal = false"></div>
        <div class="relative w-full max-w-[46rem] max-h-[85vh] overflow-y-auto rounded-lg shadow-2xl"
             style="background:var(--surface); border:1px solid var(--border);" @click.stop>

            <div class="sticky top-0 z-10 flex items-center justify-between px-5 py-3 rounded-t-lg"
                 style="background:var(--brand-default, #0b2a4a); color:#fff;">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
                    <span class="text-sm font-bold">Property Address</span>
                </div>
                <button type="button" @click="openAddrModal = false" class="p-1 rounded hover:bg-white/10">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="p-5 space-y-5">
                {{-- Complex or Estate --}}
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default, #0b2a4a); color:#fff;">Complex or Estate</div>
                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Unit Number</label>
                                <input type="text" x-model="unitNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Floor Number</label>
                                <input type="text" x-model="floorNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Unit, Section or Block</label>
                            <input type="text" x-model="unitSectionBlock" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Complex or Estate</label>
                            <input type="text" x-model="complexName" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Street --}}
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default, #0b2a4a); color:#fff;">Street</div>
                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Number</label>
                            <input type="text" x-model="streetNumber" placeholder="e.g. 21" autocomplete="off" class="w-40 rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Name</label>
                            <input type="text" x-model="streetName" placeholder="e.g. Dee Road" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Province / City / Suburb — Property24-backed typeahead (shared partial). --}}
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default, #0b2a4a); color:#fff;">Province / City / Suburb</div>
                    <div class="p-4 rounded-b-md" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                        @include('corex._partials.p24-location-picker', [
                            'fieldPrefix'         => $fieldPrefix,
                            'initialProvinceId'   => $initialP24['provinceId'],
                            'initialCityId'       => $initialP24['cityId'],
                            'initialSuburbId'     => $initialP24['suburbId'],
                            'initialProvinceName' => $initialP24['provinceName'],
                            'initialCityName'     => $initialP24['cityName'],
                            'initialSuburbName'   => $initialP24['suburbName'],
                            'denormaliseNames'    => true,
                        ])
                        <p class="text-[11px] mt-2" style="color:var(--text-muted);">Suburb is optional, but if you type one it must be picked from the Property24 list so it links cleanly to a property later.</p>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 px-5 py-3 rounded-b-lg flex items-center justify-between" style="background:var(--surface); border-top:1px solid var(--border);">
                <button type="button" @click="clearAddress()" x-show="hasAddress"
                        class="px-3 py-2 rounded-md text-xs font-semibold transition-all duration-300"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--ds-crimson, #dc2626);">Clear address</button>
                <span x-show="!hasAddress"></span>
                <button type="button" @click="openAddrModal = false" class="px-4 py-2 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green, #16a34a);">Done</button>
            </div>
        </div>
    </div>
</div>

<script>
    function {{ $fnName }}(config) {
        return {
            openAddrModal: false,
            unitNumber:       config.unitNumber       || '',
            floorNumber:      config.floorNumber      || '',
            unitSectionBlock: config.unitSectionBlock || '',
            complexName:      config.complexName      || '',
            streetNumber:     config.streetNumber     || '',
            streetName:       config.streetName       || '',
            suburb:   config.suburb   || '',
            city:     config.city     || '',
            province: config.province || '',

            heldChecking: false,
            held: null,

            init() {
                window.addEventListener('p24-location-changed:{{ $fieldPrefix }}', (e) => {
                    if (!e.detail) return;
                    this.suburb   = e.detail.suburbName   || '';
                    this.city     = e.detail.cityName     || '';
                    this.province = e.detail.provinceName || '';
                    this.queueHeldCheck();
                });

                let t;
                this._queueHeldCheck = () => { clearTimeout(t); t = setTimeout(() => this.checkHeld(), 450); };
                this.$watch('streetName',  () => this.queueHeldCheck());
                this.$watch('streetNumber', () => this.queueHeldCheck());
                this.$watch('complexName', () => this.queueHeldCheck());
                if (this.streetName || this.streetNumber) this.queueHeldCheck();
            },

            queueHeldCheck() { if (this._queueHeldCheck) this._queueHeldCheck(); },

            async checkHeld() {
                if (!this.streetName && !this.streetNumber) { this.held = null; return; }
                this.heldChecking = true;
                try {
                    const res = await fetch('{{ $heldCheckUrl }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            street_number: this.streetNumber, street_name: this.streetName,
                            unit_number: this.unitNumber, complex_name: this.complexName,
                            suburb: this.suburb, city: this.city, province: this.province,
                        }),
                    });
                    if (!res.ok) { this.held = null; return; }
                    const data = await res.json();
                    this.held = data.held ? data : null;
                } catch (e) {
                    this.held = null;
                } finally {
                    this.heldChecking = false;
                }
            },

            get summary() {
                const parts = [];
                if (this.unitNumber)       parts.push('Unit ' + this.unitNumber.trim());
                if (this.unitSectionBlock) parts.push(this.unitSectionBlock.trim());
                if (this.complexName)      parts.push(this.complexName.trim());
                if (this.streetNumber && this.streetName) parts.push((this.streetNumber + ' ' + this.streetName).trim());
                else if (this.streetName)  parts.push(this.streetName.trim());
                if (this.suburb)           parts.push(this.suburb.trim());
                if (this.city && this.city.toLowerCase() !== (this.suburb || '').toLowerCase()) parts.push(this.city.trim());
                if (this.province)         parts.push(this.province.trim());
                return parts.filter(Boolean).join(', ');
            },

            get hasAddress() { return this.summary.length > 0; },

            clearAddress() {
                this.unitNumber = ''; this.floorNumber = ''; this.unitSectionBlock = '';
                this.complexName = ''; this.streetNumber = ''; this.streetName = '';
                this.suburb = ''; this.city = ''; this.province = '';
                window.dispatchEvent(new CustomEvent('p24-location-reset:{{ $fieldPrefix }}'));
            },
        };
    }
</script>
