{{-- E-Sign V3 (ES-9) — Add Condition + Strikethrough affordance partial.
     Embed in any signing-experience view that should let a recipient add a
     new condition to an Other Conditions block OR strike through a clause.

     Required Blade locals:
       $signatureTemplate  — the SignatureTemplate model
       $blockId            — string, the insertable_blocks[].id (e.g. 'other_conditions')
       $blockPurpose       — one of: other_conditions | included_items | excluded_items | custom_named
       $partyId            — optional int (signing_request.id) for added_by_party_id
       $clauseRef          — optional string (for strikethrough flow), e.g. '5.2'
       $clauseOriginalText — optional string (the printed clause text being struck)

     Spec: .ai/specs/esign-v3-complete-spec.md §7.5.4, §7.5.5 --}}

@php
    $blockId            = $blockId ?? 'other_conditions';
    $blockPurpose       = $blockPurpose ?? 'other_conditions';
    $partyId            = $partyId ?? null;
    $clauseRef          = $clauseRef ?? null;
    $clauseOriginalText = $clauseOriginalText ?? null;
    $isStrikethrough    = !empty($clauseRef);
@endphp

<div x-data="addConditionAffordance({{ json_encode([
    'templateId'         => $signatureTemplate->id,
    'blockId'            => $blockId,
    'blockPurpose'       => $blockPurpose,
    'partyId'            => $partyId,
    'clauseRef'          => $clauseRef,
    'clauseOriginalText' => $clauseOriginalText,
    'storeConditionUrl'  => route('docuperfect.conditions.store',     $signatureTemplate),
    'storeStrikeUrl'     => route('docuperfect.strikethroughs.store', $signatureTemplate),
    'clausesUrl'         => route('docuperfect.clauses.json'),
    'csrfToken'          => csrf_token(),
]) }})"
     class="add-condition-affordance">

    @if($isStrikethrough)
        <button type="button" @click="open = true; mode = 'strikethrough'"
                class="text-xs px-2 py-1 rounded font-medium"
                style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent);
                       color: var(--ds-crimson, #dc2626);
                       border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);">
            Strike through &amp; replace
        </button>
    @else
        <button type="button" @click="open = true; mode = 'condition'"
                class="text-sm px-3 py-2 rounded font-semibold"
                style="background: var(--brand-button, #0ea5e9); color: #fff;">
            + Add condition
        </button>
    @endif

    {{-- Modal --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center"
         style="background: rgba(0,0,0,0.5);">
        <div class="rounded-md w-full max-w-2xl mx-4 p-6"
             style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.1));">

            <h3 class="text-sm font-bold mb-3" style="color: var(--text-primary, #111827);"
                x-text="mode === 'strikethrough' ? 'Strike through and replace clause' : 'Add a condition'"></h3>

            {{-- For strikethrough: show the original clause being struck --}}
            <div x-show="mode === 'strikethrough'" class="mb-3 rounded p-3"
                 style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 8%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);">
                <p class="text-[10px] font-semibold uppercase tracking-wider"
                   style="color: var(--ds-crimson, #dc2626);">Original clause (will be struck through)</p>
                <p class="text-sm line-through mt-1" x-text="clauseOriginalText"
                   style="color: var(--text-secondary, #4b5563);"></p>
            </div>

            {{-- Tabs --}}
            <div class="flex border-b mb-3" style="border-color: var(--border, rgba(0,0,0,0.1));">
                <button type="button" @click="tab = 'library'"
                        class="px-3 py-2 text-xs font-semibold"
                        :class="tab === 'library' ? 'border-b-2 -mb-px' : ''"
                        :style="tab === 'library'
                            ? 'border-color: var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9);'
                            : 'color: var(--text-secondary, #4b5563);'">
                    From clause library
                </button>
                <button type="button" @click="tab = 'custom'"
                        class="px-3 py-2 text-xs font-semibold"
                        :class="tab === 'custom' ? 'border-b-2 -mb-px' : ''"
                        :style="tab === 'custom'
                            ? 'border-color: var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9);'
                            : 'color: var(--text-secondary, #4b5563);'">
                    Write custom
                </button>
            </div>

            {{-- Library tab --}}
            <div x-show="tab === 'library'">
                <input type="text" x-model="search" @input.debounce.300ms="fetchClauses()"
                       placeholder="Search clauses…"
                       class="w-full text-sm rounded px-2 py-2 mb-2"
                       style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.2));">
                <div class="max-h-60 overflow-y-auto rounded"
                     style="border: 1px solid var(--border, rgba(0,0,0,0.1));">
                    <template x-if="!clauses.length">
                        <div class="text-xs px-2 py-3"
                             style="color: var(--text-muted, #6b7280);"
                             x-text="loadingClauses ? 'Loading…' : 'No clauses match.'"></div>
                    </template>
                    <template x-for="c in clauses" :key="c.id">
                        <button type="button" @click="selectClause(c)"
                                class="w-full text-left text-xs px-2 py-2"
                                style="border-bottom: 1px solid var(--border, rgba(0,0,0,0.05));">
                            <div class="font-semibold" style="color: var(--text-primary, #111827);" x-text="c.name"></div>
                            <div class="text-[11px] mt-1"
                                 style="color: var(--text-secondary, #4b5563);"
                                 x-text="(c.text || '').substring(0, 120)"></div>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Custom tab --}}
            <div x-show="tab === 'custom'">
                <textarea x-model="customText" rows="5"
                          placeholder="Type the condition wording…"
                          class="w-full text-sm rounded px-3 py-2"
                          style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.2));"></textarea>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <button type="button" @click="open = false"
                        class="text-sm px-3 py-2 rounded font-medium"
                        style="background: var(--surface-2, #f4f6fb); color: var(--text-primary, #111827);">
                    Cancel
                </button>
                <button type="button" @click="submit()"
                        :disabled="submitting || (!customText && !selectedClauseId)"
                        class="text-sm px-4 py-2 rounded font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
                        style="background: var(--brand-button, #0ea5e9); color: #fff;">
                    <span x-text="submitting ? 'Saving…' : 'Save'"></span>
                </button>
            </div>

            <div x-show="error" x-cloak class="mt-3 text-xs rounded px-3 py-2"
                 style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 8%, transparent);
                        color: var(--ds-crimson, #dc2626);"
                 x-text="error"></div>
        </div>
    </div>
</div>

<script>
function addConditionAffordance(cfg) {
    return {
        ...cfg,
        open: false,
        mode: 'condition', // 'condition' | 'strikethrough'
        tab: 'library',
        search: '',
        clauses: [],
        loadingClauses: false,
        customText: '',
        selectedClauseId: null,
        submitting: false,
        error: '',

        async fetchClauses() {
            this.loadingClauses = true;
            try {
                const url = this.clausesUrl + (this.search ? '?q=' + encodeURIComponent(this.search) : '');
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (r.ok) {
                    const data = await r.json();
                    this.clauses = Array.isArray(data) ? data : (data.data || data.clauses || []);
                }
            } catch (e) {
                console.warn(e);
            }
            this.loadingClauses = false;
        },

        selectClause(c) {
            this.selectedClauseId = c.id;
            this.customText = c.text || c.content || '';
            this.tab = 'custom';
        },

        async submit() {
            this.error = '';
            const content = (this.customText || '').trim();
            if (!content) {
                this.error = 'Please enter or select a condition.';
                return;
            }
            this.submitting = true;
            try {
                let url, body;
                if (this.mode === 'strikethrough') {
                    url = this.storeStrikeUrl;
                    body = {
                        clause_ref:           this.clauseRef,
                        clause_original_text: this.clauseOriginalText,
                        replacement_content:  content,
                        proposed_by_party_id: this.partyId,
                        library_clause_id:    this.selectedClauseId,
                    };
                } else {
                    url = this.storeConditionUrl;
                    body = {
                        block_id:          this.blockId,
                        block_purpose:     this.blockPurpose,
                        content:           content,
                        source:            this.selectedClauseId ? 'library' : 'custom',
                        library_clause_id: this.selectedClauseId,
                        added_via:         'recipient_signing',
                        added_by_party_id: this.partyId,
                    };
                }
                const r = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify(body),
                });
                if (r.ok) {
                    location.reload();
                } else {
                    const data = await r.json().catch(() => ({}));
                    this.error = data.message || data.error || 'Save failed (' + r.status + ')';
                }
            } catch (e) {
                this.error = 'Network error: ' + e.message;
            }
            this.submitting = false;
        },
    };
}
</script>
