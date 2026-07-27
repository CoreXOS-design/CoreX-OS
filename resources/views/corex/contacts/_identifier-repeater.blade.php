{{--
    AT-125 — reusable repeatable identifier list (phones or emails) with
    add/remove + mark-primary. Posts kind[i][value], kind[i][is_primary]; the
    controller (ContactIdentifierService) writes the child rows + keeps the
    contacts.phone/email mirror correct.

    Contact-details Phase 1 — phone rows also post kind[i][country_iso] (a
    dial-code select, default ZA). Defends against the "agent couldn't load a
    USA number" bug: every number now carries an explicit country, so WhatsApp
    deep-links and the dedup key stop assuming +27.

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
            ->map(fn ($r) => ['value' => $r['value'] ?? '', 'label_id' => (string) ($r['label_id'] ?? ''), 'is_primary' => ! empty($r['is_primary']), 'country_iso' => $r['country_iso'] ?? 'ZA'])
            ->filter(fn ($r) => trim((string) $r['value']) !== '')
            ->values()->all();
    } elseif (! empty($existing) && $existing->count()) {
        $items = $existing
            ->map(fn ($r) => ['value' => $r->{$valueKey}, 'label_id' => $r->contact_identifier_label_id ? (string) $r->contact_identifier_label_id : '', 'is_primary' => (bool) $r->is_primary, 'country_iso' => $isPhones ? ($r->country_iso ?? 'ZA') : 'ZA'])
            ->values()->all();
    } else {
        $items = [];
    }
    if (empty($items)) {
        $items = [['value' => '', 'label_id' => '', 'is_primary' => true, 'country_iso' => 'ZA']];
    }
@endphp

<div x-data="corexIdentifierRepeater(@js($items))" data-identifier-group="{{ $kind }}" class="space-y-2">
    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $title }}</label>

    <template x-for="(item, idx) in items" :key="idx">
        <div class="flex items-center gap-2">
            @if($isPhones)
            <select :name="`{{ $kind }}[${idx}][country_iso]`" x-model="item.country_iso"
                    title="Country dialing code"
                    class="rounded-md px-2 py-2 text-sm transition-all duration-300"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none; max-width:6.5rem;">
                @foreach($countries as $c)
                <option value="{{ $c['iso'] }}">{{ $c['dial_code'] }} {{ $c['iso'] }}</option>
                @endforeach
            </select>
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
                <input type="radio" :checked="idx === primary" @change="setPrimary(idx)" style="accent-color:#00d4aa;">
                Primary
            </label>

            <button type="button" @click="remove(idx)" x-show="items.length > 1"
                    class="text-xs font-semibold px-2 py-1 rounded-md transition-all duration-300"
                    style="color:var(--ds-crimson, #c41e3a);" title="Remove">Remove</button>
        </div>
    </template>

    <button type="button" @click="add()"
            class="text-xs font-semibold transition-all duration-300"
            style="color:#00d4aa;">+ Add {{ $addLabel }}</button>
</div>

@once
    @push('scripts')
    <script>
        function corexIdentifierRepeater(initial) {
            // country_iso is phone-only (contact-details Phase 1); harmlessly
            // carried-but-unused on email rows, which render no country select.
            // label_id (Phase 2) applies to BOTH kinds.
            const seed = (Array.isArray(initial) && initial.length)
                ? initial.map(i => ({ value: i.value || '', label_id: i.label_id || '', country_iso: i.country_iso || 'ZA' }))
                : [{ value: '', label_id: '', country_iso: 'ZA' }];
            let primary = 0;
            if (Array.isArray(initial) && initial.length) {
                const p = initial.findIndex(i => i.is_primary);
                primary = p >= 0 ? p : 0;
            }
            return {
                items: seed,
                primary: primary,
                add() { this.items.push({ value: '', label_id: '', country_iso: 'ZA' }); },
                remove(i) {
                    this.items.splice(i, 1);
                    if (this.items.length === 0) { this.items.push({ value: '', label_id: '', country_iso: 'ZA' }); }
                    if (this.primary >= this.items.length) { this.primary = this.items.length - 1; }
                    else if (this.primary === i && i !== 0) { this.primary = 0; }
                },
                setPrimary(i) { this.primary = i; },
            };
        }
    </script>
    @endpush
@endonce
