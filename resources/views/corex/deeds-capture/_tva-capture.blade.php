{{--
    One TVA (The Virtual Agent) captured person — identity, match status, and a
    tick-to-ingest form for their captured phone/email rows. Rendered under a
    matched TrackedProperty's card, or standalone when no suspense record
    matched. Expects: $capture (TvaContactCapture, with items + matchedContact
    eager-loaded).
--}}
<div class="rounded-md p-3 mt-2" style="background: var(--surface-2); border: 1px dashed var(--border);"
     x-data="{
        target: {{ $capture->matchedContact ? Js::from('matched') : Js::from('new') }},
        pickerOpen: false, q: '', results: [], picked: null,
        async search() {
            if (this.q.length < 2) { this.results = []; return; }
            const res = await fetch({{ Js::from(route('deals-dr2.search.contacts')) }} + '?q=' + encodeURIComponent(this.q), { headers: { 'Accept': 'application/json' } });
            this.results = res.ok ? await res.json() : [];
        },
        pick(c) { this.picked = c; this.target = 'existing'; this.pickerOpen = false; this.q = c.label || c.name || ''; },
     }">
    <div class="text-xs font-semibold mb-2" style="color: var(--text-primary);">
        TVA capture — {{ trim(($capture->first_name ?? '') . ' ' . ($capture->surname ?? '')) ?: 'Unknown name' }}
        <span style="color: var(--text-muted); font-weight:normal;">&middot; ID {{ $capture->id_number }}</span>
    </div>

    <form method="POST" action="{{ route('corex.deeds-capture.tva.ingest', $capture->id) }}">
        @csrf
        <input type="hidden" name="target" :value="target">
        <input type="hidden" name="contact_id" :value="picked ? picked.id : ''">

        @if($capture->matchedContact)
            <div class="text-xs mb-2" style="color: var(--ds-green, #16a34a);">
                Matches existing contact: <strong>{{ trim($capture->matchedContact->first_name . ' ' . $capture->matchedContact->last_name) }}</strong>
                (ID match) &mdash; will enrich this contact, not create a new one.
            </div>
        @else
            <div class="flex flex-wrap items-center gap-2 mb-2 text-xs" style="color: var(--text-muted);">
                <span>No ID match found —</span>
                <label class="inline-flex items-center gap-1">
                    <input type="radio" x-model="target" value="new"> Create new contact
                </label>
                <label class="inline-flex items-center gap-1">
                    <input type="radio" x-model="target" value="existing"> Link to existing:
                </label>
                <span class="relative inline-block" style="min-width: 12rem;">
                    <input type="text" x-model="q" @input.debounce.300ms="search(); pickerOpen = true"
                           @focus="pickerOpen = true" @click.outside="pickerOpen = false"
                           placeholder="Search contacts&hellip;"
                           class="text-xs px-2 py-1 rounded w-full" style="border:1px solid var(--border); background: var(--surface); color: var(--text-primary);">
                    <div x-show="pickerOpen && results.length" x-cloak
                         class="absolute z-10 mt-1 w-64 rounded-md shadow-lg" style="background: var(--surface); border:1px solid var(--border);">
                        <template x-for="c in results" :key="c.id">
                            <button type="button" @click="pick(c)" class="block w-full text-left px-3 py-1.5 text-xs"
                                    style="border-bottom:1px solid var(--border); color: var(--text-primary);" x-text="c.label || c.name"></button>
                        </template>
                    </div>
                </span>
            </div>
        @endif

        <table class="w-full text-xs mb-2">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="text-left w-6 pb-1"></th>
                    <th class="text-left pb-1">Type</th>
                    <th class="text-left pb-1">Value</th>
                    <th class="text-left pb-1">Date</th>
                    <th class="text-left pb-1">Link Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach($capture->items as $item)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="py-1"><input type="checkbox" name="item_ids[]" value="{{ $item->id }}"></td>
                        <td class="py-1" style="text-transform:capitalize; color: var(--text-primary);">{{ $item->type }}</td>
                        <td class="py-1" style="color: var(--text-primary);">{{ $item->value }}</td>
                        <td class="py-1" style="color: var(--text-muted);">{{ $item->date?->format('Y-m-d') ?? '&mdash;' }}</td>
                        <td class="py-1" style="color: var(--text-muted);">{{ $item->link_date?->format('Y-m-d') ?? '&mdash;' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background: var(--brand-button, #0ea5e9);">
            Ingest ticked
        </button>
    </form>
</div>
