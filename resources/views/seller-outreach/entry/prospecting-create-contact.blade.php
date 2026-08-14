{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php($deedLink = ($deedLink ?? ['owners' => [], 'candidates' => [], 'tracked_property_id' => null]))
@php($deedLink = $deedLink + ['owners' => [], 'candidates' => [], 'tracked_property_id' => null])
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Compose pitch about this property</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Capture the seller's contact info first. We'll dedupe against existing contacts before creating a new one.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ url()->previous() }}" class="corex-btn-outline text-xs no-underline">
                    ← Back
                </a>
            </div>
        </div>
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
              // FIX 2 — live deed state (reactive), initialised from the server render and refreshed
              // by polling so a scrape auto-surfaces without a full reload (form state preserved).
              deed: { owners: @js($deedLink['owners'] ?? []), candidates: @js($deedLink['candidates'] ?? []) },
              deedPollUrl: @js($deedPollUrl ?? null),
              _deedTimer: null,
              startDeedPoll() {
                  if (!this.deedPollUrl) return;
                  this._deedTimer = setInterval(() => this.pollDeed(), 5000);
                  // The agent LEAVES this tab to run the CMA / TVA / deeds scrape, so the capture
                  // lands while this tab is backgrounded — where the browser freezes or throttles the
                  // interval to about once a minute. Without an immediate catch-up on return, the panel
                  // sits stale until the next slow tick and the agent gives up and links the deed by
                  // hand (Johan 2026-08-14 live blocker). Fire a poll the instant the tab is shown /
                  // regains focus so a scrape done while away surfaces the moment they are back.
                  this._onDeedVisible = () => { if (!document.hidden) this.pollDeed(); };
                  document.addEventListener('visibilitychange', this._onDeedVisible);
                  window.addEventListener('focus', this._onDeedVisible);
                  // And poll once now, closing the gap between the server render and the first tick.
                  this.pollDeed();
              },
              async pollDeed() {
                  if (document.hidden) return;   // don't poll a backgrounded tab
                  try {
                      const res = await fetch(this.deedPollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                      if (!res.ok) return;
                      const d = await res.json();
                      this.deed.owners = d.owners || [];
                      this.deed.candidates = d.candidates || [];
                      if (Array.isArray(d.deeds)) this.deeds = d.deeds;
                      // Multi-seller (Part A) + TVA (Part B) + reversibility (R1/R2) live state.
                      if (d.sellers) this.sellers = d.sellers;
                      if (d.tva) this.tva = d.tva;
                      if (d.property_id) this.propertyId = d.property_id;
                      if ('linked_deed' in d) this.linkedDeed = d.linked_deed;
                      if ('removed' in d) this.removed = d.removed || [];
                  } catch (e) { /* transient — keep polling */ }
              },
              // ── Multi-seller link (Part A) + TVA number picker (Part B) ──
              sellers: @js($sellerState['sellers'] ?? []),
              tva: @js($sellerState['tva'] ?? (object)[]),
              propertyId: @js($sellerState['property_id'] ?? null),
              linkSellerUrl: @js($linkSellerUrl ?? null),
              unlinkSellerUrl: @js($unlinkSellerUrl ?? null),
              tvaIngestUrl: @js($tvaIngestUrl ?? null),
              primarySellerUrl: @js($primarySellerUrl ?? null),
              deadEndSellerUrl: @js($deadEndSellerUrl ?? null),
              unlinkDeedUrl: @js($unlinkDeedUrl ?? null),
              removeNumberUrl: @js($removeNumberUrl ?? null),
              primaryNumberUrl: @js($primaryNumberUrl ?? null),
              linkedDeed: @js($sellerState['linked_deed'] ?? null),
              removed: @js($sellerState['removed'] ?? []),
              tvaPicks: {},
              sellerBusy: false,
              // When sellers are already linked, the manual seller-contact form is collapsed and
              // NOT a required gate — continue runs off the linked sellers.
              showManualForm: false,
              async setPrimary(contactId) {
                  if (!this.primarySellerUrl || this.sellerBusy) return;
                  // Optimistic single-primary — one click flips it instantly (others unset locally),
                  // then the server confirms. Fixes the click-repeatedly flakiness.
                  this.sellers = this.sellers.map(s => ({ ...s, is_primary: s.contact_id === contactId }));
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.primarySellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId }) });
                      if (res.ok) this.applySellerState(await res.json());
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              async markSellerDeadEnd(contactId, reason) {
                  if (!this.deadEndSellerUrl || this.sellerBusy) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.deadEndSellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, reason: reason || 'not_in_tva' }) });
                      if (res.ok) this.applySellerState(await res.json());
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              async clearSellerDeadEnd(contactId) {
                  if (!this.deadEndSellerUrl || this.sellerBusy) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.deadEndSellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, clear: true }) });
                      if (res.ok) this.applySellerState(await res.json());
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              _postHeaders() {
                  return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                           'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' };
              },
              applySellerState(d) {
                  this.sellers = d.sellers || [];
                  this.tva = d.tva || {};
                  if (d.property_id) this.propertyId = d.property_id;
                  if ('linked_deed' in d) this.linkedDeed = d.linked_deed;
                  if ('removed' in d) this.removed = d.removed || [];
              },
              isSellerLinked(owner) {
                  if (!owner) return false;
                  // Match on the resolved contact FIRST (an entity has no SA ID), then on SA ID.
                  return this.sellers.some(s =>
                      (owner.contact_id && s.contact_id === owner.contact_id)
                      || (owner.id_number && s.id_number === owner.id_number));
              },
              isRemoved(idNumber) { return !!idNumber && (this.removed || []).includes(idNumber); },
              async unlinkDeed() {
                  if (!this.unlinkDeedUrl || this.sellerBusy) return;
                  if (!window.confirm('Unlink this deed? Its auto-linked sellers are removed and the address reverts. Manual sellers stay.')) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.unlinkDeedUrl, { method: 'POST', headers: this._postHeaders(), body: '{}' });
                      if (res.ok) { this.applySellerState(await res.json()); this.pollDeed(); }
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              async removeNumber(contactId, type, value) {
                  if (!this.removeNumberUrl || this.sellerBusy) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.removeNumberUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, type, value }) });
                      if (res.ok) this.applySellerState(await res.json());
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              async setPrimaryNumber(contactId, type, value) {
                  if (!this.primaryNumberUrl || this.sellerBusy) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.primaryNumberUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, type, value }) });
                      if (res.ok) this.applySellerState(await res.json());
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              waUrl(v) { return 'https://wa.me/' + (v || '').replace(/[^0-9]/g, '').replace(/^0/, '27'); },
              async linkSeller(owner) {
                  if (!this.linkSellerUrl || this.sellerBusy) return;
                  // Prefer the contact already resolved off the deed (a director OR the company entity):
                  // links the exact contact, no SA ID required. An entity with no contact yet keys on its
                  // registration number, never a 13-digit SA ID; a natural person keys on their SA ID.
                  let body;
                  if (owner.contact_id) {
                      body = { contact_id: owner.contact_id };
                  } else if (owner.is_entity) {
                      body = { entity: true, entity_name: owner.display_name || owner.name, entity_reg_no: owner.entity_reg_no || owner.id_number || '' };
                  } else if (owner.id_number) {
                      body = { first_name: owner.first_name, last_name: owner.last_name, id_number: owner.id_number };
                  } else {
                      window.alert('This owner has no SA ID or registration number on the deed — cannot link as a distinct seller.');
                      return;
                  }
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.linkSellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify(body) });
                      if (res.ok) { this.applySellerState(await res.json()); }
                      else {
                          // NEVER a silent no-op — surface the server's reason (Johan 2026-08-14).
                          const err = await res.json().catch(() => ({}));
                          const msg = err.message || (err.errors && Object.values(err.errors).flat()[0]) || 'Could not link this owner as a seller.';
                          window.alert(msg);
                      }
                  } catch (e) {
                      window.alert('Could not link this owner — a network error occurred. Please try again.');
                  } finally { this.sellerBusy = false; }
              },
              async unlinkSeller(contactId) {
                  if (!this.unlinkSellerUrl || this.sellerBusy) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.unlinkSellerUrl, { method: 'POST', headers: this._postHeaders(),
                          body: JSON.stringify({ contact_id: contactId }) });
                      if (res.ok) this.applySellerState(await res.json());
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              tvaFor(idNumber) { return (idNumber && this.tva[idNumber]) ? this.tva[idNumber] : null; },
              toggleTvaPick(contactId, itemId) {
                  if (!this.tvaPicks[contactId]) this.tvaPicks[contactId] = [];
                  const arr = this.tvaPicks[contactId];
                  const i = arr.indexOf(itemId);
                  if (i >= 0) arr.splice(i, 1); else arr.push(itemId);
              },
              isTvaPicked(contactId, itemId) { return (this.tvaPicks[contactId] || []).includes(itemId); },
              async saveTvaNumbers(contactId) {
                  const ids = this.tvaPicks[contactId] || [];
                  if (!ids.length || !this.tvaIngestUrl || this.sellerBusy) return;
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.tvaIngestUrl, { method: 'POST', headers: this._postHeaders(),
                          body: JSON.stringify({ contact_id: contactId, item_ids: ids }) });
                      if (res.ok) { this.applySellerState(await res.json()); this.tvaPicks[contactId] = []; }
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
              },
              filteredDeeds() {
                  const q = this.deedSearch.trim().toLowerCase();
                  if (!q) return this.deeds;
                  return this.deeds.filter(d => (d.search || '').includes(q));
              },
              async pickDeed(deed) {
                  this.showDeedModal = false;
                  if (!this.linkDeedUrl || !deed.tracked_property_id || this.sellerBusy) return;
                  // R1 — selecting a deed REPLACES the deed: the Sellers panel + address follow it
                  // (applySellerState) and the deed panels refresh (pollDeed).
                  this.sellerBusy = true;
                  try {
                      const res = await fetch(this.linkDeedUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ tracked_property_id: deed.tracked_property_id }) });
                      if (res.ok) { this.applySellerState(await res.json()); this.pollDeed(); }
                  } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
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
          x-init="startDeedPoll()"
          action="{{ !empty($trackedProperty)
              ? route('seller-outreach.entry.store-from-tracked-property', $trackedProperty->id)
              : (!empty($property)
                  ? route('seller-outreach.entry.store-from-property', $property->id)
                  : route('seller-outreach.entry.store-from-prospecting', $listing->id)) }}">
        @csrf

        {{-- R1 — explicit LINKED-DEED state + unlink. When a deed is selected the sellers panel +
             property address follow it; ✕ Unlink reverts to auto-match (confirm-on-click). --}}
        <div x-show="linkedDeed" x-cloak class="rounded-md p-3 mb-4 flex items-center justify-between gap-3 flex-wrap"
             style="background: color-mix(in srgb, #10b981 10%, var(--surface)); border:1px solid color-mix(in srgb, #10b981 45%, var(--border));">
            <div class="min-w-0">
                <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded" style="background:#10b981; color:#fff;">Deed linked ✓</span>
                <span class="text-xs ml-1" style="color: var(--text-secondary);" x-text="linkedDeed && linkedDeed.address"></span>
                <span class="text-[11px] block mt-0.5" style="color: var(--text-muted);">Sellers + property address follow this deed.</span>
            </div>
            <button type="button" @click="unlinkDeed()" :disabled="sellerBusy"
                    class="shrink-0 text-xs font-semibold" style="color: var(--ds-crimson); background:none; border:0; cursor:pointer;">✕ Unlink deed</button>
        </div>

        {{-- ── MIC ↔ Deeds ↔ Contact loop (Part A + FIX 2) ──
             The deed the agent scraped already landed the registered owner(s) in CoreX (name +
             SA-ID). These panels are Alpine-REACTIVE (driven by `deed`, initialised from the server
             render and refreshed by polling) so a scrape that lands while the screen is open
             surfaces on its own — no full reload, form state + dead-end tick preserved.
             "Use from deeds" prefills the Create-new fields; "View full deed" opens Deeds Capture. --}}
        <div x-show="deed.owners.length" x-cloak class="rounded-md p-4 mb-4"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, var(--border));">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded"
                          style="background: var(--brand-icon, #0ea5e9); color:#fff;">From the deed you scraped</span>
                    <span class="text-xs" style="color: var(--text-muted);">Owner(s) already captured from the deeds record</span>
                </div>
                @if(auth()->user()->hasPermission('deeds_capture.access'))
                    <a href="{{ route('corex.deeds-capture.index') }}" target="_blank" rel="noopener"
                       class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">View full deed →</a>
                @endif
            </div>
            <div class="space-y-2">
                <template x-for="owner in deed.owners" :key="(owner.contact_id || '') + '-' + (owner.id_number || owner.name)">
                    <div class="flex items-center justify-between gap-3 rounded-md p-3"
                         style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                <template x-if="owner.is_entity"><span class="text-[10px] uppercase tracking-wider font-bold mr-1 px-1.5 py-0.5 rounded align-middle" style="background: color-mix(in srgb, #6366f1 20%, transparent); color: var(--text-primary);">Company</span></template>
                                <span x-text="owner.display_name || (((owner.first_name || '') + ' ' + (owner.last_name || '')).trim()) || owner.name || '(unnamed owner)'"></span>
                                <template x-if="owner.dead_end"><span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · <span x-text="owner.dead_end && owner.dead_end.label"></span></span></template>
                            </div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                <template x-if="owner.is_entity"><span><span x-show="owner.entity_reg_no">Reg: <span class="font-mono" x-text="owner.entity_reg_no"></span></span><span x-show="!owner.entity_reg_no" class="italic">Company / entity owner</span></span></template>
                                <template x-if="!owner.is_entity && owner.id_number"><span>ID: <span class="font-mono" x-text="owner.id_number"></span></span></template>
                                <template x-if="!owner.is_entity && !owner.id_number"><span class="italic">No ID on the deed record</span></template>
                            </div>
                        </div>
                        <div class="shrink-0 flex flex-col gap-1">
                            <template x-if="!isSellerLinked(owner)">
                                <button type="button" @click="linkSeller(owner)" :disabled="sellerBusy"
                                        class="px-3 py-1.5 text-xs font-semibold rounded-md border-0"
                                        style="background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;"
                                        x-text="owner.is_entity ? '+ Link company as seller' : '+ Link as seller'"></button>
                            </template>
                            <template x-if="isSellerLinked(owner)">
                                <span class="px-3 py-1.5 text-xs font-semibold rounded-md text-center"
                                      style="background: color-mix(in srgb, #10b981 18%, transparent); color: var(--text-primary);">✓ Seller linked</span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Possible deed match (agent-verified) — reactive; shows when there's no confirmed owner
             but candidate deeds exist (P24 marketing address vs deeds-office scheme address). --}}
        <div x-show="!deed.owners.length && deed.candidates.length" x-cloak class="rounded-md p-4 mb-4"
             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded"
                          style="background: var(--ds-amber, #f59e0b); color:#111;">Possible deed match</span>
                    <span class="text-xs" style="color: var(--text-muted);">Same street &amp; suburb — verify this is the same property before using</span>
                </div>
                @if(auth()->user()->hasPermission('deeds_capture.access'))
                    <a href="{{ route('corex.deeds-capture.index') }}" target="_blank" rel="noopener"
                       class="text-xs font-semibold no-underline" style="color: var(--ds-amber, #f59e0b);">Open Deeds Capture →</a>
                @endif
            </div>
            <div class="space-y-2">
                <template x-for="cand in deed.candidates" :key="cand.tracked_property_id">
                    <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <template x-if="cand.address"><div class="text-xs font-semibold mb-1" style="color: var(--text-secondary);">Deed: <span x-text="cand.address"></span></div></template>
                        <template x-for="owner in cand.owners" :key="(owner.contact_id || '') + '-' + (owner.id_number || owner.name)">
                            <div class="flex items-center justify-between gap-3 py-1">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                        <template x-if="owner.is_entity"><span class="text-[10px] uppercase tracking-wider font-bold mr-1 px-1.5 py-0.5 rounded align-middle" style="background: color-mix(in srgb, #6366f1 20%, transparent); color: var(--text-primary);">Company</span></template>
                                        <span x-text="owner.display_name || (((owner.first_name || '') + ' ' + (owner.last_name || '')).trim()) || owner.name || '(unnamed owner)'"></span>
                                                <template x-if="owner.dead_end"><span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · <span x-text="owner.dead_end && owner.dead_end.label"></span></span></template>
                                    </div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                        <template x-if="owner.is_entity"><span><span x-show="owner.entity_reg_no">Reg: <span class="font-mono" x-text="owner.entity_reg_no"></span></span><span x-show="!owner.entity_reg_no" class="italic">Company / entity owner</span></span></template>
                                        <template x-if="!owner.is_entity && owner.id_number"><span>ID: <span class="font-mono" x-text="owner.id_number"></span></span></template>
                                        <template x-if="!owner.is_entity && !owner.id_number"><span class="italic">No ID on the deed record</span></template>
                                    </div>
                                </div>
                                <div class="shrink-0 flex flex-col gap-1">
                                    <template x-if="!isSellerLinked(owner)">
                                        <button type="button" @click="linkSeller(owner)" :disabled="sellerBusy"
                                                class="px-3 py-1.5 text-xs font-semibold rounded-md"
                                                style="background: transparent; color: var(--text-primary); border:1px solid var(--ds-amber, #f59e0b); cursor:pointer;"
                                                x-text="owner.is_entity ? '+ Link company as seller' : '+ Link as seller'"></button>
                                    </template>
                                    <template x-if="isSellerLinked(owner)">
                                        <span class="px-3 py-1.5 text-xs font-semibold rounded-md text-center"
                                              style="background: color-mix(in srgb, #10b981 18%, transparent); color: var(--text-primary);">✓ Seller linked</span>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Sellers on this property (Part A multi-seller + Part B TVA picker) ──
             Property → many seller-links → many standalone Contacts (each ID-keyed, never merged).
             Reactive: updated by link/unlink + the poll. Each seller shows its own numbers and, when
             TVA scraped numbers matched its SA ID, a per-seller picker to add chosen numbers. --}}
        <div x-show="sellers.length" x-cloak class="rounded-md p-4 mb-4"
             style="background: color-mix(in srgb, #10b981 6%, var(--surface)); border:1px solid color-mix(in srgb, #10b981 40%, var(--border));">
            <div class="flex items-center gap-2 mb-2 flex-wrap">
                <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded" style="background:#10b981; color:#fff;">Sellers on this property</span>
                <span class="text-xs" style="color: var(--text-muted);">Each seller is its own contact — link as many as the property has</span>
            </div>
            <div class="space-y-3">
                <template x-for="s in sellers" :key="s.contact_id">
                    <div class="rounded-md p-3" style="background: var(--surface); border:1px solid var(--border);">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold flex items-center gap-2 flex-wrap" style="color: var(--text-primary);">
                                    <button type="button" @click="setPrimary(s.contact_id)" :disabled="sellerBusy"
                                            :title="s.is_primary ? 'This is the primary seller' : 'Make this the primary seller'"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold leading-none"
                                            :style="s.is_primary ? 'color:#b45309; background: color-mix(in srgb, #f59e0b 18%, transparent); border:0; cursor:pointer;' : 'color: var(--text-muted); background: var(--surface-2); border:0; cursor:pointer;'">
                                        <span x-text="s.is_primary ? '★' : '☆'"></span>
                                        <span x-text="s.is_primary ? 'Primary' : 'Make primary'"></span>
                                    </button>
                                    <template x-if="s.is_entity"><span class="text-[10px] uppercase tracking-wider font-bold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, #6366f1 20%, transparent); color: var(--text-primary);">Company</span></template>
                                    <span x-text="s.display_name || (((s.first_name || '') + ' ' + (s.last_name || '')).trim()) || 'Seller'"></span>
                                    <template x-if="s.dead_end"><span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · <span x-text="s.dead_end && s.dead_end.label"></span></span></template>
                                </div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    <template x-if="!s.is_entity && s.id_number"><span>ID: <span class="font-mono" x-text="s.id_number"></span></span></template>
                                    {{-- Entity seller: reg number + the representative directors (cc6's link) who are the contactable people. --}}
                                    <template x-if="s.is_entity">
                                        <span>
                                            <span x-show="s.entity_reg_no">Reg: <span class="font-mono" x-text="s.entity_reg_no"></span><span x-show="s.representatives && s.representatives.length"> · </span></span>
                                            <span x-show="s.representatives && s.representatives.length">Represented by <span x-text="(s.representatives || []).map(r => r.name).join(', ')"></span></span>
                                            <span x-show="!s.representatives || !s.representatives.length" class="italic">Directors link separately from the deed rows above</span>
                                        </span>
                                    </template>
                                </div>
                                {{-- Numbers/dead-end apply to natural-person sellers only — a company is reached through its directors. --}}
                                <template x-if="!s.is_entity">
                                <div class="flex flex-wrap gap-1 mt-1 items-center">
                                    <template x-for="p in s.phones" :key="'p' + p.value">
                                        <span class="inline-flex items-center gap-1 text-[11px] px-1.5 py-0.5 rounded font-mono"
                                              :style="p.is_primary ? 'background: color-mix(in srgb,#10b981 18%,transparent); color: var(--text-primary);' : 'background: var(--surface-2); color: var(--text-secondary);'">
                                            <template x-if="s.phones.length > 1"><button type="button" @click="setPrimaryNumber(s.contact_id,'phone',p.value)" :title="p.is_primary?'Primary phone':'Make primary phone'" style="background:none;border:0;cursor:pointer;" x-text="p.is_primary?'★':'☆'"></button></template>
                                            <span x-text="p.value"></span>
                                            <a :href="'tel:'+p.value" title="Call" class="no-underline" style="color: var(--brand-icon,#0ea5e9);">📞</a>
                                            <a :href="waUrl(p.value)" target="_blank" rel="noopener" title="WhatsApp" class="no-underline">🟢</a>
                                            <button type="button" @click="removeNumber(s.contact_id,'phone',p.value)" :disabled="sellerBusy" title="Remove number" style="background:none;border:0;cursor:pointer; color: var(--ds-crimson); font-weight:bold;">×</button>
                                        </span>
                                    </template>
                                    <template x-for="e in s.emails" :key="'e' + e.value">
                                        <span class="inline-flex items-center gap-1 text-[11px] px-1.5 py-0.5 rounded"
                                              :style="e.is_primary ? 'background: color-mix(in srgb,#10b981 18%,transparent); color: var(--text-primary);' : 'background: var(--surface-2); color: var(--text-secondary);'">
                                            <template x-if="s.emails.length > 1"><button type="button" @click="setPrimaryNumber(s.contact_id,'email',e.value)" :title="e.is_primary?'Primary email':'Make primary email'" style="background:none;border:0;cursor:pointer;" x-text="e.is_primary?'★':'☆'"></button></template>
                                            <span x-text="e.value"></span>
                                            <a :href="'mailto:'+e.value" title="Email" class="no-underline" style="color: var(--brand-icon,#0ea5e9);">✉</a>
                                            <button type="button" @click="removeNumber(s.contact_id,'email',e.value)" :disabled="sellerBusy" title="Remove email" style="background:none;border:0;cursor:pointer; color: var(--ds-crimson); font-weight:bold;">×</button>
                                        </span>
                                    </template>
                                    <span x-show="!s.contactable && !s.dead_end" class="text-[11px] italic" style="color: var(--text-muted);">No number yet —</span>
                                    {{-- Per-seller dead-end: a seller with nothing to reach can be acknowledged so continue isn't blocked. --}}
                                    <button type="button" x-show="!s.contactable && !s.dead_end" @click="markSellerDeadEnd(s.contact_id, 'not_in_tva')" :disabled="sellerBusy"
                                            class="text-[11px] font-semibold" style="color: var(--ds-amber, #f59e0b); background:none; border:0; cursor:pointer;">mark “No contact details”</button>
                                    <button type="button" x-show="s.dead_end" @click="clearSellerDeadEnd(s.contact_id)" :disabled="sellerBusy"
                                            class="text-[11px] font-semibold" style="color: var(--brand-icon, #0ea5e9); background:none; border:0; cursor:pointer;">clear dead-end</button>
                                </div>
                                </template>
                            </div>
                            <button type="button" @click="unlinkSeller(s.contact_id)" :disabled="sellerBusy"
                                    class="shrink-0 text-xs font-semibold" style="color: var(--ds-crimson); background:none; border:0; cursor:pointer;">Remove</button>
                        </div>

                        {{-- TVA numbers matched to THIS seller by ID — agent picks which to write onto this contact. --}}
                        <template x-if="tvaFor(s.id_number)">
                            <div class="mt-2 pt-2" style="border-top:1px dashed var(--border);">
                                <div class="text-[11px] font-semibold mb-1" style="color: var(--text-secondary);">TVA numbers scraped for this seller — tick which to add:</div>
                                <template x-for="item in tvaFor(s.id_number).items" :key="item.id">
                                    <label class="flex items-center gap-2 text-xs py-0.5" style="cursor:pointer;">
                                        <input type="checkbox" :checked="isTvaPicked(s.contact_id, item.id)" @change="toggleTvaPick(s.contact_id, item.id)">
                                        <span x-text="item.type === 'email' ? '✉' : '📞'"></span>
                                        <span class="font-mono" x-text="item.value"></span>
                                        <span class="text-[10px]" style="color: var(--text-muted);" x-show="item.link_date">· linked <span x-text="item.link_date"></span></span>
                                    </label>
                                </template>
                                <button type="button" @click="saveTvaNumbers(s.contact_id)"
                                        :disabled="sellerBusy || !((tvaPicks[s.contact_id] || []).length)"
                                        class="mt-1 px-3 py-1 text-xs font-semibold rounded-md border-0"
                                        style="background:#10b981; color:#fff; cursor:pointer;">Add picked numbers to this seller</button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Manual "Link a deed" fallback — ALWAYS available so the agent can pick the right deed
             when auto-match doesn't fire (P24 marketing address vs deeds-office scheme address). --}}
        <div x-show="deeds.length" x-cloak class="flex items-center justify-between gap-3 flex-wrap mb-4 rounded-md p-3"
                 style="background: var(--surface); border: 1px dashed var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">
                    <span x-show="!deed.owners.length && !deed.candidates.length">No deed auto-matched to this property — </span>
                    <span x-show="deed.owners.length || deed.candidates.length">Not the right owner? </span>
                    pick the scraped deed yourself.
                </div>
                <button type="button" @click="showDeedModal = true"
                        class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md border-0"
                        style="background: var(--brand-default, #0b2a4a); color:#fff; cursor:pointer;">
                    🔍 Link a deed
                </button>
        </div>

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

        {{-- When sellers are already linked, collapse the manual capture form — it is NOT a required
             gate anymore. "Create & continue" runs off the linked sellers. --}}
        <div x-show="sellers.length && !showManualForm" class="mb-4">
            <button type="button" @click="showManualForm = true"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md"
                    style="background: transparent; color: var(--text-secondary); border:1px dashed var(--border); cursor:pointer;">
                + Add another seller manually
            </button>
        </div>

        <div x-show="!sellers.length || showManualForm" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
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
                                ? 'background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;'
                                : 'background: var(--surface-2); color: var(--text-secondary); cursor:pointer;'">
                        Search existing
                    </button>
                    <button type="button" @click="mode = 'create'; selected = null"
                            class="px-3 py-1.5 text-xs font-semibold border-0"
                            style="background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;"
                            :style="mode === 'create'
                                ? 'background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;'
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
                    <input type="text" name="first_name" x-ref="firstName" value="{{ old('first_name') }}" :required="mode === 'create' && sellers.length === 0" maxlength="100"
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
                    class="corex-btn-primary px-6 py-2.5 text-sm"
                    :style="(mode === 'search' && !selected)
                        ? 'background: var(--surface-2); color: var(--text-muted); box-shadow:none; cursor:not-allowed;'
                        : 'background: var(--brand-button, #0ea5e9); color:#ffffff; cursor:pointer;'">
                <span x-text="(sellers.length && mode !== 'search') ? 'Create &amp; continue →' : (mode === 'search' ? 'Link &amp; continue →' : 'Create &amp; continue →')"></span>
            </button>
            <a href="{{ url()->previous() }}" class="corex-btn-outline text-sm no-underline">Cancel</a>
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
