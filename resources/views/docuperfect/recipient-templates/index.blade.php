{{-- Recipient Templates (Johan, 2026-08-24, stage 3) — authored like the clause library,
     freeform text with insertable fields ("similar to whatsapp template builder"). --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    .rt-input:focus { border-color: var(--brand-button) !important; box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent); outline: none; }
</style>
<div class="w-full space-y-5" x-data="recipientTemplateBuilder({{ $errors->any() ? 'true' : 'false' }})">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Recipient Templates</h1>
                <p class="text-xs" style="color: var(--text-muted);">Reusable wording for "Replace this party" — e.g. a deceased estate represented by its executor, or a company represented by its directors.</p>
            </div>
            <button type="button" @click="showAdd = !showAdd" class="corex-btn-primary inline-flex items-center gap-2 text-xs">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                New Recipient Template
            </button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--ds-green);">
            {{ session('status') }}
        </div>
    @endif

    {{-- Add form --}}
    <div x-show="showAdd" x-cloak x-transition class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">New Recipient Template</h3>
        <form method="POST" action="{{ route('docuperfect.recipient-templates.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                    <input name="name" x-model="form.name" required
                           class="w-full rounded-md px-3 py-2 text-sm rt-input"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Deceased Estate — Executor">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Applies to party</label>
                    <select name="role_token" x-model="form.role_token" required
                            class="w-full rounded-md px-3 py-2 text-sm rt-input"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="seller">Seller</option>
                        <option value="buyer">Buyer</option>
                        <option value="lessor">Lessor</option>
                        <option value="lessee">Lessee</option>
                        <option value="any">Any party</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Internal key</label>
                <input name="key" x-model="form.key" required pattern="[a-z0-9_]+"
                       class="w-full md:w-1/2 rounded-md px-3 py-2 text-sm rt-input"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. deceased_estate_executor">
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Lowercase, digits and underscores only — never shown to a recipient, just how this template is identified.</p>
            </div>

            {{-- Slots — the fields this template's sentence needs filled in. --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Fields (click to insert into the text below)</label>
                <div class="space-y-2">
                    <template x-for="(slot, i) in form.party_slots" :key="i">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="slot.key" placeholder="field_key" pattern="[a-z0-9_]+"
                                       class="rounded-md px-2 py-1.5 text-xs rt-input" style="width: 140px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                       :name="'party_slots[' + i + '][key]'">
                                <input type="text" x-model="slot.label" placeholder="Label shown to the agent"
                                       class="flex-1 rounded-md px-2 py-1.5 text-xs rt-input" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                       :name="'party_slots[' + i + '][label]'">
                                <button type="button" @click="insertToken(slot.key)" class="text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--brand-icon,#2563eb);">Insert</button>
                                <button type="button" @click="form.party_slots.splice(i, 1)" class="text-xs" style="color: var(--ds-red,#dc2626);">×</button>
                            </div>
                            {{-- Same component tokens as the edit screen — see its comment for why these
                                 exist separately from the welded {field} token. --}}
                            <div class="flex items-center gap-2 flex-wrap pl-1" x-show="slot.key" x-cloak>
                                <span class="text-[10px] uppercase tracking-wide" style="color: var(--text-muted);">If standing in for someone else:</span>
                                <template x-for="part in ['company', 'company_reg', 'representative', 'representative_id']" :key="part">
                                    <button type="button" @click="insertToken(slot.key + '_' + part)"
                                            class="text-[11px] px-1.5 py-1 rounded"
                                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);"
                                            x-text="'{' + slot.key + '_' + part + '}'"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <button type="button" @click="form.party_slots.push({key: '', label: ''})" class="text-xs font-medium mt-2" style="color: var(--brand-icon,#2563eb);">+ Add field</button>
            </div>

            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Text — click "Insert" above to add a field at the cursor</label>
                <textarea name="text_template" x-model="form.text_template" x-ref="textArea" required rows="3"
                          class="w-full rounded-md px-3 py-2 text-sm rt-input"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                          placeholder="e.g. The late estate of {deceased}, herein represented by {executor} in the capacity of Executor"></textarea>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Save Recipient Template</button>
                <button type="button" @click="showAdd = false" class="text-xs px-4 py-2" style="color: var(--text-secondary);">Cancel</button>
            </div>
        </form>
    </div>

    {{-- This agency's own templates --}}
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Your Templates</h3>
        </div>
        @if($agencyTemplates->isEmpty())
            <div class="px-5 py-6 text-sm text-center" style="color: var(--text-muted);">No recipient templates yet — add one above, or use one of CoreX's standard templates below.</div>
        @else
            <div class="divide-y" style="border-color: var(--border);">
                @foreach($agencyTemplates as $t)
                    <div class="px-5 py-3 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="text-sm font-medium flex items-center gap-2" style="color: var(--text-primary);">
                                {{ $t->name }}
                                <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); color: var(--brand-icon,#0ea5e9);">{{ ucfirst($t->role_token) }}</span>
                            </div>
                            <div class="text-xs italic mt-0.5" style="color: var(--text-muted);">{{ $t->text_template }}</div>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <a href="{{ route('docuperfect.recipient-templates.edit', $t) }}" class="text-xs no-underline" style="color: var(--brand-icon,#2563eb);">Edit</a>
                            <form method="POST" action="{{ route('docuperfect.recipient-templates.destroy', $t) }}" onsubmit="return confirm('Remove this recipient template?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs" style="color: var(--ds-red,#dc2626);">Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- CoreX standard templates (read-only here — maintained centrally) --}}
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">CoreX Standard Templates</h3>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">Available to every agency. Add your own above to override one of these for your agency.</p>
        </div>
        <div class="divide-y" style="border-color: var(--border);">
            @foreach($coreXDefaults as $t)
                <div class="px-5 py-3">
                    <div class="text-sm font-medium flex items-center gap-2" style="color: var(--text-primary);">
                        {{ $t->name }}
                        <span class="ds-badge" style="background: var(--surface-2); color: var(--text-muted);">{{ ucfirst($t->role_token) }}</span>
                    </div>
                    <div class="text-xs italic mt-0.5" style="color: var(--text-muted);">{{ $t->text_template }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>

<script>
function recipientTemplateBuilder(hasErrors) {
    return {
        showAdd: hasErrors,
        form: {
            name: '',
            role_token: 'seller',
            key: '',
            text_template: '',
            party_slots: [{ key: 'deceased', label: 'Deceased' }, { key: 'executor', label: 'Executor' }],
        },
        insertToken(key) {
            if (!key) return;
            const el = this.$refs.textArea;
            const token = '{' + key + '}';
            const start = el.selectionStart ?? this.form.text_template.length;
            const end = el.selectionEnd ?? this.form.text_template.length;
            this.form.text_template = this.form.text_template.slice(0, start) + token + this.form.text_template.slice(end);
            this.$nextTick(() => {
                el.focus();
                el.selectionStart = el.selectionEnd = start + token.length;
            });
        },
    };
}
</script>
@endsection
