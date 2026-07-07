{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
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
          }"
          action="{{ !empty($trackedProperty)
              ? route('seller-outreach.entry.store-from-tracked-property', $trackedProperty->id)
              : route('seller-outreach.entry.store-from-prospecting', $listing->id) }}">
        @csrf

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
                    <input type="text" name="first_name" value="{{ old('first_name') }}" :required="mode === 'create'" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Last name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Phone</label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" placeholder="082 123 4567"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" maxlength="255"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            {{-- A.2.5 — optional SA ID number capture at create time. --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">ID number (optional)</label>
                <input type="text" name="id_number" value="{{ old('id_number') }}"
                       inputmode="numeric" maxlength="13" pattern="\d{13}"
                       placeholder="e.g. 7610025020081" title="13 digits — empty is fine"
                       class="w-full px-3 py-2 text-sm rounded-md"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">SA ID — 13 digits. Leave blank if not known.</p>
            </div>

            <div class="text-xs" style="color: var(--text-muted);">
                Provide at least a phone or email. We'll check if this person already exists in your contacts.
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
    </form>
</div>
@endsection
