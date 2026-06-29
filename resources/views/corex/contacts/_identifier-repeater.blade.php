{{--
    AT-125 — reusable repeatable identifier list (phones or emails) with
    add/remove + mark-primary. Posts kind[i][value], kind[i][label],
    kind[i][is_primary]; the controller (ContactIdentifierService) writes the
    child rows + keeps the contacts.phone/email mirror correct.

    Params:
      $kind        'phones' | 'emails'
      $type        'text'   | 'email'
      $title       label text
      $addLabel    e.g. 'phone' / 'email' (button reads "Add {addLabel}")
      $placeholder input placeholder
      $existing    (optional) Eloquent collection of the contact's child rows (edit)
--}}
@php
    $valueKey = $kind === 'phones' ? 'phone' : 'email';
    $old = old($kind);
    if (is_array($old)) {
        $items = collect($old)
            ->map(fn ($r) => ['value' => $r['value'] ?? '', 'label' => $r['label'] ?? '', 'is_primary' => ! empty($r['is_primary'])])
            ->filter(fn ($r) => trim((string) $r['value']) !== '')
            ->values()->all();
    } elseif (! empty($existing) && $existing->count()) {
        $items = $existing
            ->map(fn ($r) => ['value' => $r->{$valueKey}, 'label' => $r->label, 'is_primary' => (bool) $r->is_primary])
            ->values()->all();
    } else {
        $items = [];
    }
    if (empty($items)) {
        $items = [['value' => '', 'label' => '', 'is_primary' => true]];
    }
@endphp

<div x-data="corexIdentifierRepeater(@js($items))" data-identifier-group="{{ $kind }}" class="space-y-2">
    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $title }}</label>

    <template x-for="(item, idx) in items" :key="idx">
        <div class="flex items-center gap-2">
            <input :type="'{{ $type }}'" :name="`{{ $kind }}[${idx}][value]`" x-model="item.value"
                   data-identifier-value
                   @blur="$dispatch('contact-check-dup')"
                   placeholder="{{ $placeholder }}"
                   class="flex-1 rounded-md px-3 py-2 text-sm transition-all duration-300"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">

            <input type="text" :name="`{{ $kind }}[${idx}][label]`" x-model="item.label"
                   placeholder="Label"
                   class="w-24 rounded-md px-3 py-2 text-sm transition-all duration-300"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted); outline:none;">

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
            const seed = (Array.isArray(initial) && initial.length)
                ? initial.map(i => ({ value: i.value || '', label: i.label || '' }))
                : [{ value: '', label: '' }];
            let primary = 0;
            if (Array.isArray(initial) && initial.length) {
                const p = initial.findIndex(i => i.is_primary);
                primary = p >= 0 ? p : 0;
            }
            return {
                items: seed,
                primary: primary,
                add() { this.items.push({ value: '', label: '' }); },
                remove(i) {
                    this.items.splice(i, 1);
                    if (this.items.length === 0) { this.items.push({ value: '', label: '' }); }
                    if (this.primary >= this.items.length) { this.primary = this.items.length - 1; }
                    else if (this.primary === i && i !== 0) { this.primary = 0; }
                },
                setPrimary(i) { this.primary = i; },
            };
        }
    </script>
    @endpush
@endonce
