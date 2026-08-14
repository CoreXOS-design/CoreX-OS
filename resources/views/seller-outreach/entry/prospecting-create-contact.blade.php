{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php($deedLink = ($deedLink ?? ['owners' => [], 'candidates' => [], 'tracked_property_id' => null]))
@php($deedLink = $deedLink + ['owners' => [], 'candidates' => [], 'tracked_property_id' => null])
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ url()->previous() }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">
            ← Back
        </a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Compose pitch about this property</h1>
        <p class="text-sm text-white/60">
            Capture the seller's contact info first. We'll dedupe against existing contacts before creating a new one.
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    {{-- Blocking duplicate panel — shown when duplicateGate() found an existing
         contact at add time (parity with the Contacts screen / DR2 party-picker).
         The action URL matches the source: tracked-property or prospecting listing. --}}
    @include('seller-outreach.entry._duplicate-modal', [
        'actionUrl' => !empty($trackedProperty)
            ? route('seller-outreach.entry.store-from-tracked-property', $trackedProperty->id)
            : (!empty($property)
                ? route('seller-outreach.entry.store-from-property', $property->id)
                : route('seller-outreach.entry.store-from-prospecting', $listing->id)),
    ])

    {{-- Source summary — listing OR tracked property. Map Workspace Phase B
         extends the view to render either context; the form below posts to
         the matching store route. --}}
    @if(!empty($trackedProperty))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Tracked Property
            </div>
            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                {{ $trackedProperty->displayAddress() }}
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                @if(!empty($trackedProperty->last_known_asking_price))R {{ number_format((float) $trackedProperty->last_known_asking_price, 0, '.', ',') }} · @endif
                {{ $trackedProperty->property_type ?? 'property' }}
                @if(!empty($trackedProperty->bedrooms)) · {{ $trackedProperty->bedrooms }} beds @endif
                @if(!empty($trackedProperty->bathrooms)) · {{ $trackedProperty->bathrooms }} baths @endif
                @if(!empty($trackedProperty->erf_number)) · Erf {{ $trackedProperty->erf_number }} @endif
            </div>
        </div>
    @elseif(!empty($property))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Property — in agency stock
            </div>
            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                {{ $property->address ?: $property->title ?: 'Property #' . $property->id }}{{ !empty($property->suburb) ? ', ' . $property->suburb : '' }}
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                @if(!empty($property->price))R {{ number_format((float) $property->price, 0, '.', ',') }} · @endif
                {{ $property->property_type ?? 'property' }}
                @if(!empty($property->beds)) · {{ $property->beds }} beds @endif
                @if(!empty($property->baths)) · {{ $property->baths }} baths @endif
            </div>
            <div class="text-xs mt-2" style="color: var(--text-muted);">
                No seller contact is linked yet — capture the seller below to pitch.
            </div>
        </div>
    @elseif(!empty($listing))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Listing from {{ strtoupper((string) ($listing->portal_source ?? 'portal')) }}
            </div>
            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                {{ $listing->address ?? '(no address)' }}{{ !empty($listing->suburb) ? ', ' . $listing->suburb : '' }}
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                @if(!empty($listing->price))R {{ number_format((float) $listing->price, 0, '.', ',') }} · @endif
                {{ $listing->property_type ?? 'property' }}
                @if(!empty($listing->bedrooms)) · {{ $listing->bedrooms }} beds @endif
                @if(!empty($listing->bathrooms)) · {{ $listing->bathrooms }} baths @endif
            </div>
        </div>
    @endif

    {{-- Contact form — SEARCH & link an existing contact, OR capture a new one.
         Both modes post to the store route matching the source; the controller
         branches on contact_id. --}}
    <form method="POST"
          x-data="{
              mode: 'create',
              q: '',
              results: [],
              loading: false,
              selected: null,
              searchUrl: '{{ route('corex.properties.contacts.search-global') }}',
              label(c) { return ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '(no name)'; },
              async search() {
                  const term = this.q.trim();
                  if (term.length < 2) { this.results = []; this.loading = false; return; }
                  this.loading = true;
                  try {
                      const res = await fetch(this.searchUrl + '?q=' + encodeURIComponent(term), {
                          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                      });
                      this.results = res.ok ? await res.json() : [];
                  } catch (e) { this.results = []; }
                  this.loading = false;
              },
              choose(c) { this.selected = c; this.results = []; this.q = ''; },
              // MIC ↔ Deeds ↔ Contact loop (Part A) — prefill the create-new fields from a
              // scraped deed owner (name + SA ID) so the agent uses what CoreX already ingested.
              useDeedOwner(o) {
                  this.mode = 'create';
                  this.selected = null;
                  this.$nextTick(() => {
                      if (this.$refs.firstName) this.$refs.firstName.value = o.first_name || '';
                      if (this.$refs.lastName)  this.$refs.lastName.value  = o.last_name || '';
                      if (this.$refs.idNumber)  this.$refs.idNumber.value  = o.id_number || '';
                      if (this.$refs.firstName) this.$refs.firstName.focus();
                  });
              },
              // ── Manual 'Link a deed' modal ──
              showDeedModal: false,
              deedSearch: '',
              deeds: @js($deeds ?? []),
              linkDeedUrl: @js($linkDeedUrl ?? null),
              filteredDeeds() {
                  const q = this.deedSearch.trim().toLowerCase();
                  if (!q) return this.deeds;
                  return this.deeds.filter(d => (d.search || '').includes(q));
              },
              pickDeed(deed) {
                  const owner = (deed.owners && deed.owners.length) ? deed.owners[0] : null;
                  if (owner) this.useDeedOwner(owner);
                  this.showDeedModal = false;
                  // Remember the link so it auto-surfaces next time (best-effort; never blocks the prefill).
                  if (this.linkDeedUrl && deed.tracked_property_id) {
                      fetch(this.linkDeedUrl, {
                          method: 'POST',
                          headers: {
                              'Content-Type': 'application/json',
                              'Accept': 'application/json',
                              'X-Requested-With': 'XMLHttpRequest',
                              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                          },
                          body: JSON.stringify({ tracked_property_id: deed.tracked_property_id }),
                      }).catch(() => {});
                  }
              },
              // ── Part B: 'No contact details available' dead-end override ──
              noContactDetails: false,
              deadEndReason: 'not_in_tva',
              contactTyped: {{ (trim((string) old('phone','')) !== '' || trim((string) old('email','')) !== '') ? 'true' : 'false' }},
              hasTypedContact() {
                  return !!((this.$refs.phone && this.$refs.phone.value.trim())
                      || (this.$refs.email && this.$refs.email.value.trim()));
              },
          }"
          action="{{ !empty($trackedProperty)
              ? route('seller-outreach.entry.store-from-tracked-property', $trackedProperty->id)
              : (!empty($property)
                  ? route('seller-outreach.entry.store-from-property', $property->id)
                  : route('seller-outreach.entry.store-from-prospecting', $listing->id)) }}">
        @csrf

        {{-- ── MIC ↔ Deeds ↔ Contact loop (Part A) ──
             The deed the agent scraped (CMA / deeds-office) already landed the registered
             owner(s) in CoreX (name + SA-ID). Surface them here so the agent USES what was
             ingested instead of re-typing. "Use from deeds" prefills the Create-new fields;
             "View full deed" opens the Deeds Capture screen. --}}
        @if(!empty($deedLink['owners']))
            <div class="rounded-md p-4 mb-4"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, var(--border));">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded"
                              style="background: var(--brand-icon, #0ea5e9); color:#fff;">From the deed you scraped</span>
                        <span class="text-xs" style="color: var(--text-muted);">Owner(s) already captured from the deeds record</span>
                    </div>
                    @if(auth()->user()->hasPermission('deeds_capture.access'))
                        <a href="{{ route('corex.deeds-capture.index') }}" target="_blank" rel="noopener"
                           class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">
                            View full deed →
                        </a>
                    @endif
                </div>

                <div class="space-y-2">
                    @foreach($deedLink['owners'] as $owner)
                        <div class="flex items-center justify-between gap-3 rounded-md p-3"
                             style="background: var(--surface); border: 1px solid var(--border);">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                    {{ trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? '')) ?: ($owner['name'] ?? '(unnamed owner)') }}
                                    @if(!empty($owner['is_primary']))
                                        <span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded"
                                              style="background: var(--surface-2); color: var(--text-muted);">Primary</span>
                                    @endif
                                    @if(!empty($owner['dead_end']))
                                        <span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded"
                                              style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · {{ $owner['dead_end']['label'] }}</span>
                                    @endif
                                </div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    @if(!empty($owner['id_number']))
                                        {{ strtoupper((string) ($owner['id_type'] ?? 'sa_id')) === 'COMPANY_REG' ? 'Reg' : 'ID' }}:
                                        <span class="font-mono">{{ $owner['id_number'] }}</span>
                                    @else
                                        <span class="italic">No ID on the deed record</span>
                                    @endif
                                </div>
                            </div>
                            <button type="button"
                                    @click='useDeedOwner(@json($owner))'
                                    class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md border-0"
                                    style="background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;">
                                Use from deeds
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Possible deed match (agent-verified). The deeds-office address routinely differs from
             the portal/marketing address (e.g. portal "516 Bream Crescent, Ramsgate" vs deed
             "516 Bidstone, The Nest, Ramsgate Beach"), so these share only street number + suburb
             and are NOT auto-linked — the agent confirms it's the same property, then "Use from
             deeds" prefills. --}}
        @if(empty($deedLink['owners']) && !empty($deedLink['candidates']))
            <div class="rounded-md p-4 mb-4"
                 style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded"
                              style="background: var(--ds-amber, #f59e0b); color:#111;">Possible deed match</span>
                        <span class="text-xs" style="color: var(--text-muted);">Same street &amp; suburb — verify this is the same property before using</span>
                    </div>
                    @if(auth()->user()->hasPermission('deeds_capture.access'))
                        <a href="{{ route('corex.deeds-capture.index') }}" target="_blank" rel="noopener"
                           class="text-xs font-semibold no-underline" style="color: var(--ds-amber, #f59e0b);">
                            Open Deeds Capture →
                        </a>
                    @endif
                </div>

                <div class="space-y-2">
                    @foreach($deedLink['candidates'] as $cand)
                        <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                            @if(!empty($cand['address']))
                                <div class="text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                                    Deed: {{ $cand['address'] }}
                                </div>
                            @endif
                            @foreach($cand['owners'] as $owner)
                                <div class="flex items-center justify-between gap-3 py-1">
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                            {{ trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? '')) ?: ($owner['name'] ?? '(unnamed owner)') }}
                                            @if(!empty($owner['is_primary']))
                                                <span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded"
                                                      style="background: var(--surface-2); color: var(--text-muted);">Primary</span>
                                            @endif
                                            @if(!empty($owner['dead_end']))
                                                <span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded"
                                                      style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · {{ $owner['dead_end']['label'] }}</span>
                                            @endif
                                        </div>
                                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                            @if(!empty($owner['id_number']))
                                                {{ strtoupper((string) ($owner['id_type'] ?? 'sa_id')) === 'COMPANY_REG' ? 'Reg' : 'ID' }}:
                                                <span class="font-mono">{{ $owner['id_number'] }}</span>
                                            @else
                                                <span class="italic">No ID on the deed record</span>
                                            @endif
                                        </div>
                                    </div>
                                    <button type="button"
                                            @click='useDeedOwner(@json($owner))'
                                            class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md"
                                            style="background: transparent; color: var(--text-primary); border:1px solid var(--ds-amber, #f59e0b); cursor:pointer;">
                                        Use from deeds
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Manual "Link a deed" fallback — ALWAYS available so the agent can pick the right deed
             when auto-match doesn't fire (P24 marketing address vs deeds-office scheme address). --}}
        @if(!empty($deeds))
            <div class="flex items-center justify-between gap-3 flex-wrap mb-4 rounded-md p-3"
                 style="background: var(--surface); border: 1px dashed var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">
                    @if(empty($deedLink['owners']) && empty($deedLink['candidates']))
                        No deed auto-matched to this property —
                    @else
                        Not the right owner?
                    @endif
                    pick the scraped deed yourself.
                </div>
                <button type="button" @click="showDeedModal = true"
                        class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md border-0"
                        style="background: var(--brand-default, #0b2a4a); color:#fff; cursor:pointer;">
                    🔍 Link a deed
                </button>
            </div>
        @endif

        {{-- #3 Address-first: when the source listing carries no street address, capture
             one BEFORE the seller. Reuses the SAME "Property Address" modal + component as
             the Contact screen's "Start a Property from an Address" (Johan 2026-08-11), so
             the agent works exactly as they do from contacts. The structured fields submit
             with THIS form; storeFromProspecting composes the address and lands it on the
             listing's OWN promoted property (external_id-tied). --}}
        @if(!empty($needsAddress) && $needsAddress)
            <div class="rounded-md p-4 mb-4" style="background: var(--surface); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
                <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                    Property address <span style="color: var(--ds-crimson);">*</span>
                </h2>
                <p class="text-xs mb-3" style="color: var(--text-muted);">
                    This listing was captured without a street address. Set it here to create the property, then continue.
                </p>
                @include('corex._partials.property-address-capture', [
                    'fieldPrefix'  => 'pitch_addr',
                    'heldCheckUrl' => route('corex.contacts.check-held-address'),
                    'initial'      => [
                        'unitNumber'       => old('unit_number', ''),
                        'floorNumber'      => old('floor_number', ''),
                        'unitSectionBlock' => old('unit_section_block', ''),
                        'complexName'      => old('complex_name', ''),
                        'streetNumber'     => old('street_number', ''),
                        'streetName'       => old('street_name', ''),
                        'suburb'           => old('suburb', ''),
                        'city'             => old('city', ''),
                        'province'         => old('province', ''),
                    ],
                    'initialP24'   => [
                        'provinceId'   => old('pitch_addr_province_id', 0),
                        'cityId'       => old('pitch_addr_city_id', 0),
                        'suburbId'     => old('pitch_addr_suburb_id', 0),
                        'provinceName' => old('province', ''),
                        'cityName'     => old('city', ''),
                        'suburbName'   => old('suburb', ''),
                    ],
                ])
            </div>
        @endif

        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Seller contact</h2>
                {{-- Mode toggle: pick a known owner, or capture a new one. --}}
                <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    {{-- Base `style` matches the initial mode ('create') so the toggle renders
                         correctly before Alpine hydrates; `:style` takes over reactively. --}}
                    <button type="button" @click="mode = 'search'"
                            class="px-3 py-1.5 text-xs font-semibold border-0"
                            style="background: var(--surface-2); color: var(--text-secondary); cursor:pointer;"
                            :style="mode === 'search'
                                ? 'background: var(--brand-default, #0b2a4a); color:#fff; cursor:pointer;'
                                : 'background: var(--surface-2); color: var(--text-secondary); cursor:pointer;'">
                        Search existing
                    </button>
                    <button type="button" @click="mode = 'create'; selected = null"
                            class="px-3 py-1.5 text-xs font-semibold border-0"
                            style="background: var(--brand-default, #0b2a4a); color:#fff; cursor:pointer;"
                            :style="mode === 'create'
                                ? 'background: var(--brand-default, #0b2a4a); color:#fff; cursor:pointer;'
                                : 'background: var(--surface-2); color: var(--text-secondary); cursor:pointer;'">
                        Create new
                    </button>
                </div>
            </div>

            {{-- ── Search existing contact ── --}}
            <div x-show="mode === 'search'" x-cloak class="space-y-2">
                {{-- Chosen contact — its id is what the controller links. --}}
                <template x-if="selected">
                    <div class="flex items-center justify-between gap-3 rounded-md p-3"
                         style="background: var(--surface-2); border:1px solid var(--border);">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);" x-text="label(selected)"></div>
                            <div class="text-xs truncate" style="color: var(--text-muted);">
                                <span x-text="selected.phone || ''"></span><span x-show="selected.phone && selected.email"> · </span><span x-text="selected.email || ''"></span>
                            </div>
                        </div>
                        <button type="button" @click="selected = null" class="text-xs font-semibold shrink-0" style="color: var(--brand-icon, #0ea5e9); background:none; border:0; cursor:pointer;">Change</button>
                        <input type="hidden" name="contact_id" :value="selected.id">
                    </div>
                </template>

                {{-- Search box + live results (hidden once a contact is chosen). --}}
                <div x-show="!selected">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Search your contacts</label>
                    <input type="text" x-model="q" @input.debounce.300ms="search()"
                           placeholder="Name, phone or email…" autocomplete="off"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <div class="mt-1 text-xs" style="color: var(--text-muted);" x-show="loading">Searching…</div>
                    <div class="mt-1 text-xs" style="color: var(--text-muted);" x-show="!loading && q.trim().length >= 2 && results.length === 0">
                        No matches — switch to “Create new”.
                    </div>
                    <div class="mt-2 rounded-md overflow-hidden" style="border:1px solid var(--border);" x-show="results.length > 0">
                        <template x-for="c in results" :key="c.id">
                            <button type="button" @click="choose(c)"
                                    class="w-full text-left px-3 py-2 text-sm block"
                                    style="background: var(--surface); color: var(--text-primary); border:0; border-bottom:1px solid var(--border); cursor:pointer;">
                                <span class="font-semibold" x-text="label(c)"></span>
                                <span class="text-xs" style="color: var(--text-muted);">— <span x-text="c.phone || c.email || ''"></span></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ── Create new contact ── --}}
            <div x-show="mode === 'create'" class="space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        First name <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <input type="text" name="first_name" x-ref="firstName" value="{{ old('first_name') }}" :required="mode === 'create'" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Last name</label>
                    <input type="text" name="last_name" x-ref="lastName" value="{{ old('last_name') }}" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Phone</label>
                    <input type="tel" name="phone" x-ref="phone" value="{{ old('phone') }}" maxlength="30" placeholder="082 123 4567"
                           @input="contactTyped = hasTypedContact(); if (contactTyped) noContactDetails = false"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Email</label>
                    <input type="email" name="email" x-ref="email" value="{{ old('email') }}" maxlength="255"
                           @input="contactTyped = hasTypedContact(); if (contactTyped) noContactDetails = false"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            {{-- A.2.5 — optional SA ID number capture at create time. --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">ID number (optional)</label>
                <input type="text" name="id_number" x-ref="idNumber" value="{{ old('id_number') }}"
                       inputmode="numeric" maxlength="13" pattern="\d{13}"
                       placeholder="e.g. 7610025020081" title="13 digits — empty is fine"
                       class="w-full px-3 py-2 text-sm rounded-md"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">SA ID — 13 digits. Leave blank if not known.</p>
            </div>

            {{-- Part B — deliberate dead-end override. Only meaningful when NO phone/email is
                 entered (real details win: typing either clears + disables the tick). When on, the
                 seller is still created from the deed (name + ID, deduped on ID) and flagged so no
                 future agent re-chases a genuinely uncontactable owner. --}}
            <div class="rounded-md p-3"
                 style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, var(--surface-2)); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, var(--border));">
                <label class="flex items-start gap-2" style="cursor:pointer;">
                    <input type="checkbox" name="no_contact_details" value="1" x-model="noContactDetails"
                           :disabled="contactTyped"
                           @change="if (contactTyped) noContactDetails = false"
                           class="mt-0.5">
                    <span class="text-xs" style="color: var(--text-primary);">
                        <span class="font-semibold">No contact details available</span>
                        — dead end (no TVA record / opted out / nothing to enter). The seller is still
                        saved from the deed and flagged so nobody chases it again.
                    </span>
                </label>
                <div x-show="noContactDetails" x-cloak class="mt-2" style="padding-left: 1.5rem;">
                    <label class="block text-[11px] font-semibold mb-1" style="color: var(--text-secondary);">Reason</label>
                    <select name="dead_end_reason" x-model="deadEndReason"
                            class="w-full px-3 py-2 text-sm rounded-md"
                            style="background: var(--surface); border:1px solid var(--border); color: var(--text-primary);">
                        <option value="not_in_tva">Not in TVA</option>
                        <option value="opted_out">Opted out</option>
                        <option value="no_record_found">No record found</option>
                    </select>
                </div>
            </div>

            <div class="text-xs" style="color: var(--text-muted);">
                <span x-show="!noContactDetails">Provide at least a phone or email — we'll check if this person already exists in your contacts.</span>
                <span x-show="noContactDetails" x-cloak>Dead-end mode: no phone/email needed. The owner's name + SA ID (from the deed) are required so the contact is ID-keyed and deduped.</span>
            </div>
            </div>{{-- /create-new --}}
        </div>

        <div class="flex items-center gap-2 flex-wrap mt-4">
            <button type="submit"
                    :disabled="mode === 'search' && !selected"
                    class="px-6 py-2.5 text-sm font-semibold rounded-md border-0"
                    style="background: var(--brand-button, #0ea5e9); color:#ffffff; cursor:pointer;"
                    :style="(mode === 'search' && !selected)
                        ? 'background: var(--surface-2); color: var(--text-muted); cursor:not-allowed;'
                        : 'background: var(--brand-button, #0ea5e9); color:#ffffff; cursor:pointer;'">
                <span x-text="mode === 'search' ? 'Link & continue →' : 'Create / link & continue →'"></span>
            </button>
            <a href="{{ url()->previous() }}" class="text-sm" style="color: var(--text-muted);">Cancel</a>
        </div>

        {{-- Manual deed-picker modal — clean, searchable, scrollable list of the agency's deeds.
             Inside the form so it shares the Alpine scope (prefills the fields via useDeedOwner). --}}
        <div x-show="showDeedModal" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-center p-4"
             style="background: rgba(0,0,0,0.5);"
             @keydown.escape.window="showDeedModal = false">
            <div class="w-full max-w-2xl rounded-lg mt-10 flex flex-col"
                 style="background: var(--surface); border:1px solid var(--border); max-height: 80vh;"
                 @click.outside="showDeedModal = false">
                <div class="flex items-center justify-between gap-3 px-4 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">Link a deed to this property</h3>
                    <button type="button" @click="showDeedModal = false" class="text-sm"
                            style="color: var(--text-muted); background:none;border:0;cursor:pointer;">✕</button>
                </div>
                <div class="px-4 py-3" style="border-bottom:1px solid var(--border);">
                    <input type="text" x-model="deedSearch" placeholder="Search address, owner, erf, suburb…" autocomplete="off"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                </div>
                <div class="overflow-y-auto px-2 py-2" style="min-height: 120px;">
                    <template x-for="deed in filteredDeeds()" :key="deed.tracked_property_id">
                        <button type="button" @click="pickDeed(deed)"
                                class="w-full text-left px-3 py-2 rounded-md mb-1 block"
                                style="background: var(--surface-2); border:1px solid var(--border); cursor:pointer;">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);" x-text="deed.address || '(no address)'"></div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                <template x-if="deed.erf"><span>Erf <span x-text="deed.erf"></span> · </span></template>
                                <span x-text="deed.suburb || ''"></span>
                                <template x-if="deed.sold_price"><span> · Sold R<span x-text="Number(deed.sold_price).toLocaleString()"></span></span></template>
                                <template x-if="deed.sold_date"><span> · <span x-text="deed.sold_date"></span></span></template>
                            </div>
                            <div class="text-xs mt-0.5 font-medium" style="color: var(--brand-icon, #0ea5e9);" x-text="deed.owner_names"></div>
                        </button>
                    </template>
                    <div x-show="filteredDeeds().length === 0" class="px-3 py-6 text-center text-sm" style="color: var(--text-muted);">
                        No deeds match “<span x-text="deedSearch"></span>”.
                    </div>
                </div>
                <div class="px-4 py-2 text-xs" style="border-top:1px solid var(--border); color: var(--text-muted);">
                    Picking a deed prefills the seller from its registered owner and remembers the link.
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
