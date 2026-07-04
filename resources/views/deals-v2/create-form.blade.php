{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-158 WS-R2 — DR1-style single-page deal capture (the DEFAULT create path).
    One scrolling form, three DR1 sections (Property & Deal / Commission,
    Splits & Agents / Parties), NOT a step wizard. Writes through the SAME
    DealV2Controller@store shared write-path as the optional wizard (no logic
    fork). Multi-agent on BOTH sides (verbatim DR1 syncSelected). Parties are
    contact-linked with existing-contact selection as the fast default
    (property's seller auto-suggested; buyer picked from contacts) and an
    inline "add new contact" escape hatch.
--}}
<x-app-layout>
    <div>
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('deals-v2.index') }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0" style="color: var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <span class="flex-shrink-0" style="color: var(--border);">|</span>
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">New Deal</h1>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <a href="{{ route('deals-v2.create-wizard') }}" class="text-xs no-underline hidden sm:inline-flex items-center gap-1" style="color: var(--brand-icon, #0ea5e9);"
                       title="Prefer to be walked through it one step at a time? Use the guided wizard. Both create the same deal.">
                        Prefer a guided wizard? →
                    </a>
                    <button type="submit" form="dealForm" class="px-4 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                        Create Deal
                    </button>
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-5xl mx-auto" x-data="dealCapture()">
            @if($errors->any())
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: #f87171;">
                    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <form id="dealForm" method="POST" action="{{ route('deals-v2.store') }}" class="space-y-6" @submit="beforeSubmit($event)">
                @csrf

                {{-- SECTION 1: Property & Deal Type --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Property &amp; Deal Type</h2>

                    {{-- Property picker (search existing = the fast default) --}}
                    <div class="mb-4">
                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Property</label>
                        <input type="hidden" name="property_id" :value="selectedProperty ? selectedProperty.id : ''">

                        <template x-if="!selectedProperty">
                            <div class="relative">
                                <input type="text" x-model="propertySearch" @input.debounce.300ms="searchProperties()" placeholder="Search a property by address…"
                                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <div x-show="propertyResults.length" class="absolute z-20 mt-1 w-full rounded-md shadow-lg max-h-60 overflow-auto"
                                     style="background: var(--surface); border: 1px solid var(--border);">
                                    <template x-for="p in propertyResults" :key="p.id">
                                        <button type="button" @click="selectProperty(p)" class="block w-full text-left px-3 py-2 text-sm hover:bg-teal-500/10"
                                                style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                                            <span x-text="p.address || p.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedProperty">
                            <div class="flex items-center justify-between rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <span class="text-sm font-medium" style="color: var(--text-primary);" x-text="selectedProperty.address || selectedProperty.label"></span>
                                <button type="button" @click="clearProperty()" class="text-xs px-2 py-1 rounded" style="color: var(--text-muted);">Change</button>
                            </div>
                        </template>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Selecting a property fills the listing agent + commission and suggests the owner as seller.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Deal Type</label>
                            <select name="deal_type" x-model="dealType" @change="pickDefaultTemplate()" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="bond">Bond Sale</option>
                                <option value="cash">Cash Sale</option>
                                <option value="sale_of_2nd">Sale of 2nd Property</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Pipeline (tracking overlay)</label>
                            <select name="pipeline_template_id" x-model="selectedTemplateId" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <template x-for="t in availableTemplates" :key="t.id">
                                    <option :value="t.id" x-text="t.name + (t.is_default ? ' (default)' : '')"></option>
                                </template>
                            </select>
                            <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="!availableTemplates.length">
                                No template for this type yet — set one up in Pipeline Setup.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Offer Date</label>
                            <input type="date" name="offer_date" x-model="offerDate" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- SECTION 2: Commission --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Commission</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Purchase Price (R)</label>
                            <input type="number" name="purchase_price" x-model="purchasePrice" @input="calcFromPct()" step="0.01" min="1" required
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Commission %</label>
                            <input type="number" name="commission_percentage" x-model="commissionPercent" @input="calcFromPct()" step="0.01" min="0" max="100"
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Commission (Inc VAT)</label>
                            <input type="number" name="total_commission_inc_vat" x-model="commissionIncVat" @input="calcPctFromInc()" step="0.01" min="0" required
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Ex VAT / VAT</label>
                            <div class="rounded-md text-sm px-3 py-2 font-mono" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
                                R <span x-text="Number(commExVat).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span>
                                <span class="text-xs">+ R <span x-text="Number(commVat).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span> VAT</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- SECTION 3: Side Splits & Agents (DR1 — multi-agent BOTH sides) --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Side Splits &amp; Agents</h2>

                    <div class="grid grid-cols-2 gap-4 mb-5" x-data="{
                        lPct: 50, sPct: 50,
                        syncL(v) { this.lPct = Math.max(0, Math.min(100, parseFloat(v) || 0)); this.sPct = Math.round((100 - this.lPct) * 100) / 100; },
                        syncS(v) { this.sPct = Math.max(0, Math.min(100, parseFloat(v) || 0)); this.lPct = Math.round((100 - this.sPct) * 100) / 100; },
                    }">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Listing Split %</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="listing_split_percent" x-model="lPct" @input="syncL($event.target.value)" step="0.01" min="0" max="100"
                                       class="w-20 rounded-md text-sm px-2 py-1.5 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <input type="range" x-model="lPct" @input="syncL($event.target.value)" min="0" max="100" step="0.5" class="flex-1">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Selling Split %</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="selling_split_percent" x-model="sPct" @input="syncS($event.target.value)" step="0.01" min="0" max="100"
                                       class="w-20 rounded-md text-sm px-2 py-1.5 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <input type="range" x-model="sPct" @input="syncS($event.target.value)" min="0" max="100" step="0.5" class="flex-1">
                            </div>
                        </div>
                    </div>

                    @foreach(['listing', 'selling'] as $side)
                        <div class="rounded-lg p-4 mb-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">{{ ucfirst($side) }} Side</span>
                                <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="{{ $side }}_external" value="1" id="{{ $side }}_external_cb"
                                           {{ old($side . '_external') ? 'checked' : '' }} class="rounded" style="accent-color: #14b8a6;"
                                           onchange="document.getElementById('{{ $side }}_internal').style.display = this.checked ? 'none' : 'block'; document.getElementById('{{ $side }}_ext_fields').style.display = this.checked ? 'flex' : 'none';">
                                    External agency
                                </label>
                            </div>

                            <div id="{{ $side }}_ext_fields" class="gap-3 mb-3" style="display: {{ old($side . '_external') ? 'flex' : 'none' }};">
                                <div class="flex-1">
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Agency Name</label>
                                    <input type="text" name="{{ $side }}_external_agency" value="{{ old($side . '_external_agency') }}"
                                           class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Our Share %</label>
                                    <input type="number" name="{{ $side }}_our_share_percent" value="{{ old($side . '_our_share_percent', 100) }}" step="0.01" min="0" max="100"
                                           class="w-24 rounded-md text-sm px-3 py-1.5 focus:outline-none" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                            </div>

                            <div id="{{ $side }}_internal" style="display: {{ old($side . '_external') ? 'none' : 'block' }};">
                                <select id="{{ $side }}_select" class="w-full rounded-md text-sm px-3 py-1 focus:outline-none" multiple size="5"
                                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    @foreach($agents as $agent)
                                        <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                    @endforeach
                                </select>
                                <div class="text-xs mt-1" style="color: var(--text-muted);">Hold Ctrl / Cmd to select multiple — both sides accept multiple agents.</div>
                                <div id="{{ $side }}_selected" class="space-y-2 mt-3"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- SECTION 4: Parties (contacts — existing-first, inline add escape hatch) --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Parties</h2>

                    {{-- Hidden inputs serialise the Alpine contacts[] into the form (store() form-path) --}}
                    <template x-for="(c, i) in contacts" :key="c.contact_id + '-' + c.role">
                        <div>
                            <input type="hidden" :name="'contacts[' + i + '][contact_id]'" :value="c.contact_id">
                            <input type="hidden" :name="'contacts[' + i + '][role]'" :value="c.role">
                        </div>
                    </template>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        {{-- Sellers --}}
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Seller(s)</label>
                                <a href="{{ \Illuminate\Support\Facades\Route::has('contacts.create') ? route('contacts.create') : '#' }}" target="_blank"
                                   class="text-xs no-underline" style="color: var(--brand-icon, #0ea5e9);">+ New contact</a>
                            </div>
                            <div class="relative">
                                <input type="text" x-model="sellerSearch" @input.debounce.300ms="searchContacts('seller')" placeholder="Search contacts…"
                                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <div x-show="sellerResults.length" class="absolute z-20 mt-1 w-full rounded-md shadow-lg max-h-52 overflow-auto" style="background: var(--surface); border: 1px solid var(--border);">
                                    <template x-for="c in sellerResults" :key="c.id">
                                        <button type="button" @click="addContact(c, 'seller')" class="block w-full text-left px-3 py-2 text-sm hover:bg-teal-500/10" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                                            <span x-text="c.name"></span> <span class="text-xs" style="color: var(--text-muted);" x-text="c.phone || c.email || ''"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <div class="space-y-1.5 mt-2">
                                <template x-for="c in contacts.filter(x => x.role === 'seller' || x.role === 'co_seller')" :key="c.contact_id + c.role">
                                    <div class="flex items-center justify-between rounded px-2.5 py-1.5 text-sm" style="background: var(--surface-2);">
                                        <span style="color: var(--text-primary);"><span x-text="c.name"></span>
                                            <span class="text-xs" style="color: var(--text-muted);" x-text="c.role === 'co_seller' ? '(co-seller)' : '(seller)'"></span>
                                        </span>
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="c.role = (c.role === 'seller' ? 'co_seller' : 'seller')" class="text-xs" style="color: var(--text-muted);" x-text="c.role === 'seller' ? 'make co' : 'make main'"></button>
                                            <button type="button" @click="removeContact(c)" class="text-xs" style="color: #f87171;">✕</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Buyers --}}
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Buyer(s)</label>
                                <a href="{{ \Illuminate\Support\Facades\Route::has('contacts.create') ? route('contacts.create') : '#' }}" target="_blank"
                                   class="text-xs no-underline" style="color: var(--brand-icon, #0ea5e9);">+ New contact</a>
                            </div>
                            <div class="relative">
                                <input type="text" x-model="buyerSearch" @input.debounce.300ms="searchContacts('buyer')" placeholder="Search contacts…"
                                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <div x-show="buyerResults.length" class="absolute z-20 mt-1 w-full rounded-md shadow-lg max-h-52 overflow-auto" style="background: var(--surface); border: 1px solid var(--border);">
                                    <template x-for="c in buyerResults" :key="c.id">
                                        <button type="button" @click="addContact(c, 'buyer')" class="block w-full text-left px-3 py-2 text-sm hover:bg-teal-500/10" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                                            <span x-text="c.name"></span> <span class="text-xs" style="color: var(--text-muted);" x-text="c.phone || c.email || ''"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <div class="space-y-1.5 mt-2">
                                <template x-for="c in contacts.filter(x => x.role === 'buyer' || x.role === 'co_buyer')" :key="c.contact_id + c.role">
                                    <div class="flex items-center justify-between rounded px-2.5 py-1.5 text-sm" style="background: var(--surface-2);">
                                        <span style="color: var(--text-primary);"><span x-text="c.name"></span>
                                            <span class="text-xs" style="color: var(--text-muted);" x-text="c.role === 'co_buyer' ? '(co-buyer)' : '(buyer)'"></span>
                                        </span>
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="c.role = (c.role === 'buyer' ? 'co_buyer' : 'buyer')" class="text-xs" style="color: var(--text-muted);" x-text="c.role === 'buyer' ? 'make co' : 'make main'"></button>
                                            <button type="button" @click="removeContact(c)" class="text-xs" style="color: #f87171;">✕</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs mt-3" style="color: var(--text-muted);">The property's owner is suggested as seller automatically. Buyers are picked from your contacts — use “+ New contact” if they aren’t captured yet.</p>
                </div>

                {{-- SECTION 5: Notes --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Notes</h2>
                    <textarea name="notes" x-model="notes" rows="3" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                              style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                </div>
            </form>
        </div>
    </div>

    <script>
        const AGENCY_AGENTS = @json($agents->map(fn($a) => ['id' => (string) $a->id, 'name' => $a->name])->values());

        function dealCapture() {
            return {
                templates: @json($templatesJson),
                dealType: 'bond',
                selectedTemplateId: null,
                selectedProperty: null,
                propertySearch: '', propertyResults: [],
                contacts: [],
                sellerSearch: '', sellerResults: [],
                buyerSearch: '', buyerResults: [],
                purchasePrice: '', commissionPercent: 7.5, commissionIncVat: 0,
                vatRatePct: {{ $vatRate ?? 15 }},
                offerDate: new Date().toISOString().split('T')[0],
                notes: '',

                init() { this.pickDefaultTemplate(); },

                get availableTemplates() { return this.templates.filter(t => t.deal_type === this.dealType); },
                get commExVat() { return this.commissionIncVat > 0 ? this.commissionIncVat / (1 + this.vatRatePct / 100) : 0; },
                get commVat() { return this.commissionIncVat > 0 ? this.commissionIncVat - this.commExVat : 0; },

                pickDefaultTemplate() {
                    const avail = this.availableTemplates;
                    const def = avail.find(t => t.is_default) || avail[0];
                    this.selectedTemplateId = def ? def.id : null;
                },

                async searchProperties() {
                    if (this.propertySearch.length < 2) { this.propertyResults = []; return; }
                    try { const { data } = await axios.get('{{ route("deals-v2.search.properties") }}', { params: { q: this.propertySearch } }); this.propertyResults = data; }
                    catch (e) { this.propertyResults = []; }
                },
                async selectProperty(p) {
                    this.selectedProperty = p;
                    this.propertySearch = ''; this.propertyResults = [];
                    if (p.price) { this.purchasePrice = p.price; }
                    if (p.commission_percent) { this.commissionPercent = p.commission_percent; this.calcFromPct(); }
                    if (p.listing_agent_id) { this.preselectListingAgent(String(p.listing_agent_id)); }
                    try {
                        const { data } = await axios.get('/deals-v2/search/property-contacts/' + p.id);
                        const existing = this.contacts.map(c => c.contact_id);
                        data.forEach(c => { if (!existing.includes(c.id)) this.contacts.push({ contact_id: c.id, name: c.name, email: c.email, phone: c.phone, role: 'seller' }); });
                    } catch (e) { /* non-critical */ }
                },
                clearProperty() {
                    this.selectedProperty = null; this.propertySearch = '';
                    // keep manually-added buyers; drop auto-suggested sellers
                    this.contacts = this.contacts.filter(c => c.role === 'buyer' || c.role === 'co_buyer');
                },

                // Pre-select the property's listing agent in the multi-select on the listing side.
                preselectListingAgent(id) {
                    const sel = document.getElementById('listing_select');
                    if (!sel) return;
                    Array.from(sel.options).forEach(o => { if (o.value === id) o.selected = true; });
                    sel.dispatchEvent(new Event('change'));
                },

                async searchContacts(type) {
                    const q = type === 'seller' ? this.sellerSearch : this.buyerSearch;
                    if (q.length < 2) { if (type === 'seller') this.sellerResults = []; else this.buyerResults = []; return; }
                    try {
                        const { data } = await axios.get('{{ route("deals-v2.search.contacts") }}', { params: { q } });
                        if (type === 'seller') this.sellerResults = data; else this.buyerResults = data;
                    } catch (e) { if (type === 'seller') this.sellerResults = []; else this.buyerResults = []; }
                },
                addContact(c, role) {
                    if (this.contacts.find(x => x.contact_id === c.id && (x.role === role || x.role === 'co_' + role))) return;
                    this.contacts.push({ contact_id: c.id, name: c.name, email: c.email, phone: c.phone, role });
                    this.sellerSearch = ''; this.sellerResults = []; this.buyerSearch = ''; this.buyerResults = [];
                },
                removeContact(c) { this.contacts = this.contacts.filter(x => x !== c); },

                calcFromPct() {
                    if (this.purchasePrice > 0 && this.commissionPercent > 0) {
                        const ex = parseFloat(this.purchasePrice) * (parseFloat(this.commissionPercent) / 100);
                        this.commissionIncVat = (ex * (1 + this.vatRatePct / 100)).toFixed(2);
                    }
                },
                calcPctFromInc() {
                    if (this.purchasePrice > 0 && this.commissionIncVat > 0) {
                        const ex = parseFloat(this.commissionIncVat) / (1 + this.vatRatePct / 100);
                        this.commissionPercent = ((ex / parseFloat(this.purchasePrice)) * 100).toFixed(2);
                    }
                },

                beforeSubmit(e) {
                    if (!this.selectedProperty) { e.preventDefault(); alert('Select a property first.'); return; }
                    if (!this.selectedTemplateId) { e.preventDefault(); alert('Pick a pipeline for this deal type (set one up in Pipeline Setup if none exist).'); return; }
                },
            };
        }
    </script>

    {{-- DR1 multi-agent selection — verbatim from deals-v2/form.blade.php's syncSelected --}}
    <script>
        function syncSelected(selectEl, containerEl, sideName) {
            const selectedIds = Array.from(selectEl.selectedOptions).map(o => o.value);
            Array.from(containerEl.querySelectorAll('[data-user-id]')).forEach(row => {
                if (!selectedIds.includes(row.getAttribute('data-user-id'))) row.remove();
            });
            selectedIds.forEach(id => {
                if (containerEl.querySelector('[data-user-id="' + id + '"]')) return;
                const opt = selectEl.querySelector('option[value="' + id + '"]');
                const label = opt ? opt.textContent.trim() : ('User ' + id);
                const row = document.createElement('div');
                row.className = 'flex items-center gap-3';
                row.setAttribute('data-user-id', id);
                row.innerHTML = `
                    <input type="hidden" name="${sideName}_agents[]" value="${id}">
                    <div class="flex-1 text-sm font-medium" style="color:var(--text-primary)">${label}</div>
                    <input type="number" step="0.01" name="${sideName}_override[${id}]" placeholder="% split" class="w-24 rounded-md text-sm px-2 py-1 focus:outline-none" style="background:var(--surface);border:1px solid var(--border);color:var(--text-primary)">
                    <button type="button" class="text-xs px-2 py-1 rounded hover:bg-red-500/20" style="color:var(--text-muted)">✕</button>
                `;
                row.querySelector('button').addEventListener('click', () => {
                    Array.from(selectEl.options).forEach(o => { if (o.value === id) o.selected = false; });
                    row.remove();
                    autoFillSingle(containerEl);
                });
                containerEl.appendChild(row);
            });
            autoFillSingle(containerEl);
        }
        function autoFillSingle(containerEl) {
            const rows = containerEl.querySelectorAll('[data-user-id]');
            if (rows.length === 1) {
                const input = rows[0].querySelector('input[type=number]');
                if (input && !input.value) input.value = '100';
            } else if (rows.length > 1) {
                rows.forEach(r => { const i = r.querySelector('input[type=number]'); if (i && i.value === '100') i.value = ''; });
            }
        }
        ['listing', 'selling'].forEach(side => {
            const sel = document.getElementById(side + '_select');
            const container = document.getElementById(side + '_selected');
            if (sel && container) {
                syncSelected(sel, container, side);
                sel.addEventListener('change', () => syncSelected(sel, container, side));
            }
        });
    </script>
</x-app-layout>
