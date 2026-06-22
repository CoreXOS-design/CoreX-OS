{{--
    Contact type/tag pop-up picker (AT-79).

    Replaces the single contact-type dropdown. Lets the user assign MULTIPLE
    (parent signing role + optional sub-tag) pairs, and create a new sub-tag
    inline. Submits:
      parent_type_ids[]            distinct parents chosen
      tag_ids[]                    existing sub-tags chosen
      new_tags[i][parent_id|name]  sub-tags to create on save

    Params:
      $contactTypes  collection of the 4 parents, each with ->subTags
      $contact       (optional) contact being edited — seeds current assignments
--}}
@php
    $pickerParents = ($contactTypes ?? collect())->map(fn ($p) => [
        'id'      => (int) $p->id,
        'name'    => $p->name,
        'color'   => $p->color ?: '#6366f1',
        'subTags' => $p->subTags->map(fn ($t) => [
            'id'    => (int) $t->id,
            'name'  => $t->name,
            'color' => $t->color ?: '#6366f1',
        ])->values(),
    ])->values();

    $pickerInitial = [];
    $pc = $contact ?? null;
    if ($pc && $pc->exists) {
        $seenParents = [];
        foreach ($pc->tags as $t) {
            if (!$t->contact_type_id) { continue; }
            $pickerInitial[] = ['parentId' => (int) $t->contact_type_id, 'tagId' => (int) $t->id, 'newTag' => null];
            $seenParents[(int) $t->contact_type_id] = true;
        }
        foreach ($pc->parentTypes as $p) {
            if (empty($seenParents[(int) $p->id])) {
                $pickerInitial[] = ['parentId' => (int) $p->id, 'tagId' => null, 'newTag' => null];
                $seenParents[(int) $p->id] = true;
            }
        }
        // Fall back to the primary-type mirror for contacts created by writer
        // paths (import / P24 / PP webhook / mobile / e-sign reverse-mapping) that
        // set contacts.contact_type_id but never the pivot — so the type SHOWS and
        // isn't silently wiped when the agent edits and saves the contact. Only a
        // CANONICAL mirror is seeded (the picker only knows the 4 parents, and a
        // non-canonical id would fail the controller's canonical-parent rule).
        $canonicalIds = $pickerParents->pluck('id')->all();
        if ($pc->contact_type_id
            && empty($seenParents[(int) $pc->contact_type_id])
            && in_array((int) $pc->contact_type_id, $canonicalIds, true)) {
            $pickerInitial[] = ['parentId' => (int) $pc->contact_type_id, 'tagId' => null, 'newTag' => null];
        }
    }
@endphp

<div x-data="{
        open: false,
        parents: {{ Js::from($pickerParents) }},
        assignments: {{ Js::from($pickerInitial) }},
        draftParent: '',
        draftTagId: '',
        draftNewTag: '',
        parentOf(id) { return this.parents.find(p => p.id == id) || null; },
        parentName(id) { const p = this.parentOf(id); return p ? p.name : ''; },
        parentColor(id) { const p = this.parentOf(id); return p ? p.color : '#6366f1'; },
        subTagsFor(id) { const p = this.parentOf(id); return p ? p.subTags : []; },
        tagName(pid, tid) { const p = this.parentOf(pid); if (!p) return ''; const t = p.subTags.find(t => t.id == tid); return t ? t.name : ''; },
        add() {
            if (!this.draftParent) return;
            const pid = parseInt(this.draftParent);
            let a = { parentId: pid, tagId: null, newTag: null };
            if (this.draftNewTag.trim()) { a.newTag = this.draftNewTag.trim(); }
            else if (this.draftTagId) { a.tagId = parseInt(this.draftTagId); }
            const dup = this.assignments.some(x => x.parentId === a.parentId && (x.tagId || null) === (a.tagId || null) && (x.newTag || null) === (a.newTag || null));
            if (!dup) this.assignments.push(a);
            this.draftParent = ''; this.draftTagId = ''; this.draftNewTag = '';
            this.open = false;
        },
        remove(i) { this.assignments.splice(i, 1); },
     }">

    {{-- Hidden inputs that submit with the form --}}
    <template x-for="pid in [...new Set(assignments.map(a => a.parentId))]" :key="'pp' + pid">
        <input type="hidden" name="parent_type_ids[]" :value="pid">
    </template>
    <template x-for="(a, i) in assignments" :key="'a' + i">
        <span>
            <template x-if="a.tagId"><input type="hidden" name="tag_ids[]" :value="a.tagId"></template>
            <template x-if="a.newTag">
                <span>
                    <input type="hidden" :name="'new_tags[' + i + '][parent_id]'" :value="a.parentId">
                    <input type="hidden" :name="'new_tags[' + i + '][name]'" :value="a.newTag">
                </span>
            </template>
        </span>
    </template>

    {{-- Chips + trigger --}}
    <div class="flex flex-wrap gap-2 items-center">
        <template x-for="(a, i) in assignments" :key="'c' + i">
            <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-md"
                  :style="'background:color-mix(in srgb, ' + parentColor(a.parentId) + ' 12%, transparent); border:1px solid color-mix(in srgb, ' + parentColor(a.parentId) + ' 40%, transparent); color:' + parentColor(a.parentId) + ';'">
                <span x-text="parentName(a.parentId)"></span>
                <template x-if="a.tagId"><span><span style="opacity:0.6;">·</span> <span x-text="tagName(a.parentId, a.tagId)"></span></span></template>
                <template x-if="a.newTag"><span><span style="opacity:0.6;">·</span> <span x-text="a.newTag"></span> <span style="opacity:0.6;">(new)</span></span></template>
                <button type="button" @click="remove(i)" class="ml-0.5" style="opacity:0.7;" title="Remove">&times;</button>
            </span>
        </template>
        <button type="button" @click="open = true; draftParent=''; draftTagId=''; draftNewTag='';"
                class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-md"
                style="border:1px dashed var(--border); color:var(--brand-icon, #0ea5e9);">
            + Add type
        </button>
        <span x-show="assignments.length === 0" class="text-xs" style="color:var(--ds-crimson, #c41e3a);">Required — add at least one type</span>
    </div>

    {{-- Pop-up --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
         style="background:rgba(0,0,0,0.55);" @click.self="open = false" @keydown.escape.window="open = false">
        <div class="rounded-md w-full max-w-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border); box-shadow:0 10px 40px rgba(0,0,0,0.4);">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Add contact type</div>

            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Signing role</label>
                <select x-model="draftParent"
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">— choose role —</option>
                    <template x-for="p in parents" :key="p.id">
                        <option :value="p.id" x-text="p.name"></option>
                    </template>
                </select>
            </div>

            <div x-show="draftParent" x-cloak class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sub-tag <span style="font-weight:400;">(optional)</span></label>
                    <select x-model="draftTagId" :disabled="draftNewTag.trim() !== ''"
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">— none —</option>
                        <template x-for="t in subTagsFor(draftParent)" :key="t.id">
                            <option :value="t.id" x-text="t.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">…or create a new sub-tag</label>
                    <input type="text" x-model="draftNewTag" placeholder="e.g. Cash seller, First-time buyer"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <p class="text-[11px] mt-1" style="color:var(--text-muted);">New sub-tags are saved under the chosen role when you save the contact.</p>
                </div>
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button type="button" @click="add()" :disabled="!draftParent"
                        class="corex-btn-primary text-sm" :style="!draftParent ? 'opacity:0.4; cursor:not-allowed;' : ''">Add</button>
                <button type="button" @click="open = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
            </div>
        </div>
    </div>
</div>
