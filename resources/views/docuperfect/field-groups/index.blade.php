@extends('layouts.corex')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="fieldGroupManager()"
     x-cloak>

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Field Groups</h1>
                <p class="text-sm text-white/60">Create reusable groups of named fields for quick placement in templates.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('docuperfect.settings.namedFields') }}" class="corex-btn-outline">Named Fields</a>
                <a href="{{ route('docuperfect.templates.index') }}" class="corex-btn-outline">Templates</a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        {{-- LEFT: Group list --}}
        <div class="lg:col-span-5 space-y-3">
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">Existing Groups</div>
                    <div class="text-xs" style="color: var(--text-muted);" x-text="groups.length + ' total'"></div>
                </div>

                <div>
                    <template x-for="group in groups" :key="group.id">
                        <div class="px-4 py-3 flex items-center justify-between cursor-pointer transition-colors"
                             style="border-top: 1px solid var(--border);"
                             :style="editingId === group.id
                                ? 'border-top: 1px solid var(--border); background: color-mix(in srgb, var(--brand-icon) 10%, transparent); border-left: 4px solid var(--brand-icon);'
                                : 'border-top: 1px solid var(--border);'"
                             onmouseover="if(!this.dataset.active)this.style.background='var(--surface-2)'"
                             onmouseout="if(!this.dataset.active)this.style.background=''"
                             :data-active="editingId === group.id ? '1' : null"
                             @click="editGroup(group)">
                            <div>
                                <div class="text-sm font-medium" style="color: var(--text-primary);" x-text="group.name"></div>
                                <div class="text-xs flex items-center gap-2 mt-0.5" style="color: var(--text-muted);">
                                    <span x-text="group.fields.length + ' field' + (group.fields.length !== 1 ? 's' : '')"></span>
                                    <span class="ds-badge"
                                          :class="group.layout === 'horizontal' ? 'ds-badge-info' : 'ds-badge-default'"
                                          x-text="group.layout"></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click.stop="editGroup(group)"
                                        class="text-xs font-semibold transition-colors"
                                        style="color: var(--brand-icon);">Edit</button>
                                <button @click.stop="deleteGroup(group)"
                                        class="text-xs font-semibold transition-colors"
                                        style="color: var(--ds-crimson);">Delete</button>
                            </div>
                        </div>
                    </template>

                    <div x-show="groups.length === 0" class="py-12 px-6 text-center" style="border-top: 1px solid var(--border);">
                        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
                            </svg>
                        </div>
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No field groups yet</h3>
                        <p class="text-sm" style="color: var(--text-muted);">Create your first group on the right.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Create/Edit form --}}
        <div class="lg:col-span-7">
            <div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);" x-text="editingId ? 'Edit Group' : 'Create Group'"></h2>

                {{-- Group Name --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Group Name</label>
                    <input x-model="form.name" type="text" required
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Lessor — Full">
                </div>

                {{-- Layout toggle --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Layout</label>
                    <div class="flex gap-2">
                        <button type="button" @click="form.layout = 'vertical'"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                                :style="form.layout === 'vertical'
                                    ? 'background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);'
                                    : 'background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);'">
                            Vertical
                        </button>
                        <button type="button" @click="form.layout = 'horizontal'"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                                :style="form.layout === 'horizontal'
                                    ? 'background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);'
                                    : 'background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);'">
                            Horizontal
                        </button>
                    </div>
                </div>

                {{-- Field picker --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Add Fields (click to add)</label>
                    <div class="rounded-md p-3 max-h-52 overflow-y-auto space-y-3"
                         style="background: var(--surface-2); border: 1px solid var(--border);">
                        @foreach($pillarLabels as $key => $label)
                            @if(isset($namedFields[$key]) && $namedFields[$key]->count())
                                <div>
                                    <div class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">{{ $label }}</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($namedFields[$key] as $nf)
                                            <button type="button"
                                                    @click="addField({{ $nf->id }}, {{ json_encode($nf->name) }})"
                                                    class="px-2 py-1 rounded-md text-xs font-medium transition-colors"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                    onmouseover="this.style.borderColor='var(--brand-icon)';this.style.color='var(--brand-icon)'"
                                                    onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-primary)'">
                                                {{ $nf->name }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>

                {{-- Selected fields (sortable list) --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                        Group Fields <span style="color: var(--text-muted);" x-text="'(' + form.fields.length + ')'"></span>
                    </label>
                    <div class="space-y-1.5 min-h-[40px]" x-ref="sortableList">
                        <template x-for="(field, index) in form.fields" :key="index">
                            <div class="flex items-center gap-2 px-3 py-2 rounded-md"
                                 style="background: var(--surface-2); border: 1px solid var(--border);"
                                 draggable="true"
                                 @dragstart="dragStart(index, $event)"
                                 @dragover.prevent="dragOver(index, $event)"
                                 @drop.prevent="dragDrop(index)"
                                 @dragend="dragEnd()">
                                <span class="cursor-grab text-xs" style="color: var(--text-muted);">&#x2630;</span>
                                <span class="text-sm flex-1" style="color: var(--text-primary);" x-text="field.name"></span>
                                <input x-model="field.label_override" type="text"
                                       class="w-32 rounded-md px-2 py-1 text-xs"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       placeholder="Label override">
                                <button type="button" @click="removeField(index)"
                                        class="text-sm font-bold transition-colors"
                                        style="color: var(--ds-crimson);"
                                        aria-label="Remove field">&times;</button>
                            </div>
                        </template>
                        <div x-show="form.fields.length === 0" class="text-center text-xs py-3" style="color: var(--text-muted);">
                            Click fields above to add them to this group.
                        </div>
                    </div>
                </div>

                {{-- Action buttons --}}
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" @click="saveGroup()"
                            :disabled="saving || !form.name || form.fields.length === 0"
                            class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                        <span x-text="saving ? 'Saving...' : (editingId ? 'Update Group' : 'Create Group')"></span>
                    </button>
                    <button type="button" x-show="editingId" @click="resetForm()"
                            class="corex-btn-outline">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function fieldGroupManager() {
    return {
        groups: @json($groupsData),
        editingId: null,
        saving: false,
        form: { name: '', layout: 'vertical', fields: [] },

        // Drag reorder state
        dragIdx: null,

        addField(id, name) {
            this.form.fields.push({ named_field_id: id, label_override: null, name: name });
        },

        removeField(index) {
            this.form.fields.splice(index, 1);
        },

        dragStart(index, e) {
            this.dragIdx = index;
            e.dataTransfer.effectAllowed = 'move';
        },
        dragOver(index, e) {
            e.dataTransfer.dropEffect = 'move';
        },
        dragDrop(index) {
            if (this.dragIdx === null || this.dragIdx === index) return;
            const item = this.form.fields.splice(this.dragIdx, 1)[0];
            this.form.fields.splice(index, 0, item);
            this.dragIdx = null;
        },
        dragEnd() { this.dragIdx = null; },

        editGroup(group) {
            this.editingId = group.id;
            this.form = {
                name: group.name,
                layout: group.layout,
                fields: JSON.parse(JSON.stringify(group.fields)),
            };
        },

        resetForm() {
            this.editingId = null;
            this.form = { name: '', layout: 'vertical', fields: [] };
        },

        async saveGroup() {
            this.saving = true;
            const payload = {
                name: this.form.name,
                layout: this.form.layout,
                fields: this.form.fields.map(f => ({
                    named_field_id: f.named_field_id,
                    label_override: f.label_override || null,
                })),
            };

            const url = this.editingId
                ? '{{ url("docuperfect/field-groups") }}/' + this.editingId
                : '{{ route("docuperfect.field-groups.store") }}';
            const method = this.editingId ? 'PUT' : 'POST';

            try {
                const res = await fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                if (!res.ok) throw new Error('Save failed');
                // Reload page to refresh data
                window.location.reload();
            } catch (e) {
                window.showToast && window.showToast('Failed to save field group. Please try again.', 'error');
            } finally {
                this.saving = false;
            }
        },

        async deleteGroup(group) {
            if (!confirm('Archive field group "' + group.name + '"?')) return;

            try {
                const res = await fetch('{{ url("docuperfect/field-groups") }}/' + group.id, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                });
                if (!res.ok) throw new Error('Delete failed');
                window.location.reload();
            } catch (e) {
                window.showToast && window.showToast('Failed to archive field group.', 'error');
            }
        },
    };
}
</script>
@endsection
