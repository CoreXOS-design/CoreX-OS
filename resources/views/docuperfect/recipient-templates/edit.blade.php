{{-- Recipient Templates — edit screen (cc3, 2026-08-26). Same fields as the
     inline "New Recipient Template" form on the index page, modelled on how
     Recipient Presets' edit route works (GET /{id}/edit -> dedicated view,
     posts PUT to the existing, already-working update() route). --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    .rt-input:focus { border-color: var(--brand-button) !important; box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent); outline: none; }
</style>
<div class="w-full max-w-3xl space-y-5"
     x-data="recipientTemplateEditForm({{ \Illuminate\Support\Js::from(old('party_slots', $recipientTemplate->party_slots ?? [])) }})">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Recipient Template</h1>
                <p class="text-xs" style="color: var(--text-muted);">Reusable wording for "Replace this party" — e.g. a deceased estate represented by its executor, or a company represented by its directors.</p>
            </div>
            <a href="{{ route('docuperfect.recipient-templates.index') }}" class="corex-btn-outline text-xs no-underline">← Back</a>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc pl-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('docuperfect.recipient-templates.update', $recipientTemplate) }}" class="rounded-md p-6 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                <input name="name" value="{{ old('name', $recipientTemplate->name) }}" required
                       class="w-full rounded-md px-3 py-2 text-sm rt-input"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. Deceased Estate — Executor">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Applies to party</label>
                <select name="role_token" required
                        class="w-full rounded-md px-3 py-2 text-sm rt-input"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach(['seller' => 'Seller', 'buyer' => 'Buyer', 'lessor' => 'Lessor', 'lessee' => 'Lessee', 'any' => 'Any party'] as $val => $label)
                        <option value="{{ $val }}" @selected(old('role_token', $recipientTemplate->role_token) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Internal key</label>
            <input name="key" value="{{ old('key', $recipientTemplate->key) }}" required pattern="[a-z0-9_]+"
                   class="w-full md:w-1/2 rounded-md px-3 py-2 text-sm rt-input"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                   placeholder="e.g. deceased_estate_executor">
            <p class="text-[11px] mt-1" style="color: var(--text-muted);">Lowercase, digits and underscores only — never shown to a recipient, just how this template is identified.</p>
        </div>

        {{-- Slots — the fields this template's sentence needs filled in. --}}
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Fields (click to insert into the text below)</label>
            <div class="space-y-2">
                <template x-for="(slot, i) in party_slots" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="slot.key" placeholder="field_key" pattern="[a-z0-9_]+"
                               class="rounded-md px-2 py-1.5 text-xs rt-input" style="width: 140px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                               :name="'party_slots[' + i + '][key]'">
                        <input type="text" x-model="slot.label" placeholder="Label shown to the agent"
                               class="flex-1 rounded-md px-2 py-1.5 text-xs rt-input" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                               :name="'party_slots[' + i + '][label]'">
                        <button type="button" @click="insertToken(slot.key)" class="text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--brand-icon,#2563eb);">Insert</button>
                        <button type="button" @click="party_slots.splice(i, 1)" class="text-xs" style="color: var(--ds-red,#dc2626);">×</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="party_slots.push({key: '', label: ''})" class="text-xs font-medium mt-2" style="color: var(--brand-icon,#2563eb);">+ Add field</button>
        </div>

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Text — click "Insert" above to add a field at the cursor</label>
            <textarea name="text_template" x-ref="textArea" required rows="3"
                      class="w-full rounded-md px-3 py-2 text-sm rt-input"
                      style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                      placeholder="e.g. The late estate of {deceased}, herein represented by {executor} in the capacity of Executor">{{ old('text_template', $recipientTemplate->text_template) }}</textarea>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Save Recipient Template</button>
            <a href="{{ route('docuperfect.recipient-templates.index') }}" class="text-xs px-4 py-2 no-underline" style="color: var(--text-secondary);">Cancel</a>
        </div>
    </form>
</div>

<script>
function recipientTemplateEditForm(initialSlots) {
    return {
        party_slots: initialSlots && initialSlots.length ? initialSlots : [{ key: '', label: '' }],
        insertToken(key) {
            if (!key) return;
            const el = this.$refs.textArea;
            const token = '{' + key + '}';
            const start = el.selectionStart ?? el.value.length;
            const end = el.selectionEnd ?? el.value.length;
            el.value = el.value.slice(0, start) + token + el.value.slice(end);
            this.$nextTick(() => {
                el.focus();
                el.selectionStart = el.selectionEnd = start + token.length;
            });
        },
    };
}
</script>
@endsection
