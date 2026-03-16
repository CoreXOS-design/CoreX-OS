@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6 flex flex-col h-[calc(100vh-3.5rem)] lg:h-[calc(100vh-1rem)]"
     x-data="webTemplateEditor()">

    {{-- FIXED TOP BAR --}}
    <div style="background:#0b2a4a;" class="sticky top-0 z-50 px-6 py-3 flex items-center justify-between flex-shrink-0">

        {{-- LEFT SECTION --}}
        <div class="flex-shrink-0">
            <h2 class="text-sm font-semibold text-white leading-tight">Web Template Editor</h2>
            <p class="text-xs text-white/50 mt-0.5" x-text="name"></p>
        </div>

        {{-- CENTER SECTION --}}
        <div class="text-xs text-white/60">
            <span style="color:#00d4aa" x-text="fields.length"></span> fields total
            <span class="mx-1">&middot;</span>
            <span style="color:#00d4aa" x-text="countByParty('lessor')"></span> lessor
            <span class="mx-1">&middot;</span>
            <span style="color:#00d4aa" x-text="countByParty('lessee')"></span> lessee
            <span class="mx-1">&middot;</span>
            <span style="color:#00d4aa" x-text="countByParty('property')"></span> property
            <span class="mx-1">&middot;</span>
            <span style="color:#00d4aa" x-text="countByParty('agent')"></span> agent
        </div>

        {{-- RIGHT SECTION --}}
        <div class="flex items-center gap-2 flex-shrink-0">
            <a href="{{ route('docuperfect.templates.index') }}"
               class="text-xs px-3 py-1.5 border border-white/30 text-white/70 hover:bg-white/10 hover:text-white transition-colors">
                Back
            </a>
            <div x-data="{ submitting: false }">
                <form method="POST" action="{{ route('docuperfect.import.template.edit', $template->id) }}" class="inline" @submit="submitting = true">
                    @csrf
                    <button type="submit"
                            :disabled="submitting"
                            :class="submitting ? 'opacity-50 cursor-not-allowed' : 'hover:bg-amber-500/20 hover:text-amber-100'"
                            class="text-xs px-3 py-1.5 border border-amber-400/60 text-amber-300 transition-colors">
                        <span x-show="!submitting">Edit Document</span>
                        <span x-show="submitting">Opening...</span>
                    </button>
                </form>
            </div>
            <button type="button" @click="save()" :disabled="saving"
                    class="text-xs px-4 py-1.5 font-medium transition-colors"
                    :class="saving ? 'opacity-60 cursor-wait' : 'hover:opacity-90'"
                    style="background:#00d4aa; color:#000;">
                <span x-text="saving ? 'Saving...' : 'Save'"></span>
            </button>
        </div>
    </div>

    {{-- TWO-PANE AREA --}}
    <div class="flex flex-1 min-h-0 relative two-pane-container">

        {{-- LEFT PANE: Document Preview --}}
        <div class="overflow-y-auto bg-gray-100" :style="'width:' + leftWidth + '%'">
            <div class="py-4 px-4">
                <iframe src="{{ route('docuperfect.templates.webPreview', $template->id) }}"
                        class="w-full bg-white border border-gray-200"
                        style="min-height: calc(100vh - 8rem);"
                        id="webPreviewFrame"></iframe>
            </div>
        </div>

        {{-- DRAGGABLE DIVIDER --}}
        <div class="w-1 flex-shrink-0 cursor-col-resize transition-colors"
             :class="dragging ? 'bg-[#00d4aa]' : 'bg-slate-200 hover:bg-[#00d4aa]'"
             @mousedown.prevent="startDrag($event)"></div>

        {{-- RIGHT PANE: Field Assignments --}}
        <div class="flex-1 overflow-y-auto bg-white border-l border-gray-200 flex flex-col">

            {{-- Field Assignments Header --}}
            <div style="background:#0b2a4a;" class="px-5 py-3 flex-shrink-0">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-white">Field Assignments</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-white/20 text-white/70"
                          x-text="fields.length + ' fields'"></span>
                </div>
            </div>

            {{-- Grouped field list --}}
            <div class="px-5 py-4 space-y-4 overflow-y-auto flex-1">
                <template x-for="group in groupedFields" :key="group.party">
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider"
                                  :class="partyColor(group.party)">
                                <span x-text="partyLabel(group.party)"></span>
                            </span>
                            <span class="text-[10px] text-slate-400" x-text="'(' + group.items.length + ')'"></span>
                        </div>
                        <div class="space-y-1.5">
                            <template x-for="(field, fi) in group.items" :key="field.id">
                                <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-100">
                                    <span class="text-xs px-2 py-0.5 rounded bg-teal-50 text-teal-700 border border-teal-200 truncate"
                                          x-text="field.label"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-medium flex-shrink-0"
                                          :class="partyBadge(field.assignedTo)"
                                          x-text="partyLabel(field.assignedTo)"></span>
                                    <select class="text-[10px] rounded border border-gray-200 bg-white text-slate-600 px-1.5 py-0.5 ml-auto flex-shrink-0"
                                            :value="field.assignedTo"
                                            @change="reassignField(field.id, $event.target.value)">
                                        <option value="auto">Auto-fill</option>
                                        <option value="lessor">Lessor</option>
                                        <option value="lessee">Lessee</option>
                                        <option value="seller">Seller</option>
                                        <option value="buyer">Buyer</option>
                                        <option value="property">Property</option>
                                        <option value="agent">Agent</option>
                                        <option value="skip">Skip</option>
                                    </select>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Separator --}}
            <div class="border-t border-gray-200 mx-5"></div>

            {{-- Template Settings --}}
            <div class="px-5 py-4 space-y-3 flex-shrink-0">
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Template Settings</h4>

                <div>
                    <label class="text-[10px] font-medium text-slate-500 block mb-1">Template Name</label>
                    <input type="text" x-model="name"
                           class="w-full rounded border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="text-[10px] font-medium text-slate-500 block mb-1">Type</label>
                    <select x-model="templateType"
                            class="w-full rounded border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                        <option value="sales">Sales</option>
                        <option value="rental">Rental</option>
                        <option value="compliance">Compliance</option>
                        <option value="imported">Imported</option>
                    </select>
                </div>

                <div>
                    <label class="text-[10px] font-medium text-slate-500 block mb-1">Document Type</label>
                    <select x-model="documentTypeId"
                            class="w-full rounded border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                        <option value="">-- None --</option>
                        @foreach($documentTypes as $dt)
                        <option value="{{ $dt->id }}">{{ $dt->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" x-model="isGlobal" class="rounded border-slate-300">
                    <span class="text-sm text-slate-700">Global (all branches)</span>
                </div>

                <div x-show="!isGlobal" x-transition>
                    <label class="text-[10px] font-medium text-slate-500 block mb-1">Branch Access</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach($branches as $branch)
                        <label class="flex items-center gap-1 text-sm text-slate-700">
                            <input type="checkbox" value="{{ $branch->id }}"
                                   :checked="allowedBranches.includes({{ $branch->id }})"
                                   @change="toggleBranch({{ $branch->id }}, $event.target.checked)"
                                   class="rounded border-slate-300">
                            {{ $branch->name }}
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function webTemplateEditor() {
    const serverFields = @json($template->fields_json ?? []);

    return {
        fields: serverFields.map(f => ({...f})),
        name: @json($template->name),
        templateType: @json($template->template_type),
        documentTypeId: @json($template->document_type_id ? (string) $template->document_type_id : ''),
        isGlobal: @json((bool) $template->is_global),
        allowedBranches: @json($template->branches->pluck('id')->map(fn($id) => (int) $id)),
        saving: false,

        leftWidth: 60,
        dragging: false,
        dragStartX: 0,
        dragStartWidth: 0,

        init() {
            const onDrag = (e) => {
                if (!this.dragging) return;
                const container = this.$el.querySelector('.two-pane-container');
                const rect = container.getBoundingClientRect();
                const newWidth = ((e.clientX - rect.left) / rect.width) * 100;
                this.leftWidth = Math.min(75, Math.max(30, newWidth));
            };
            const onUp = () => {
                this.dragging = false;
            };
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', onUp);
        },

        startDrag(e) {
            this.dragging = true;
            this.dragStartX = e.clientX;
            this.dragStartWidth = this.leftWidth;
        },

        countByParty(party) {
            return this.fields.filter(f => (f.assignedTo || 'agent') === party).length;
        },

        get groupedFields() {
            const order = ['auto', 'lessor', 'lessee', 'seller', 'buyer', 'property', 'agent', 'skip'];
            const groups = {};
            this.fields.forEach(f => {
                const party = f.assignedTo || 'agent';
                if (!groups[party]) groups[party] = [];
                groups[party].push(f);
            });
            return order
                .filter(p => groups[p] && groups[p].length > 0)
                .map(p => ({ party: p, items: groups[p] }));
        },

        partyLabel(party) {
            const labels = { auto: 'Auto-fill', lessor: 'Lessor / Landlord', lessee: 'Lessee / Tenant', seller: 'Seller', buyer: 'Buyer', property: 'Property / Deal', agent: 'Agent', skip: 'Skipped' };
            return labels[party] || party;
        },

        partyColor(party) {
            const colors = {
                auto: 'text-teal-600',
                lessor: 'text-blue-900',
                lessee: 'text-teal-700',
                seller: 'text-purple-600',
                buyer: 'text-indigo-600',
                property: 'text-slate-600',
                agent: 'text-orange-600',
                skip: 'text-slate-400'
            };
            return colors[party] || 'text-slate-500';
        },

        partyBadge(party) {
            const badges = {
                auto: 'bg-teal-50 text-teal-700',
                lessor: 'bg-blue-100 text-blue-800',
                lessee: 'bg-teal-100 text-teal-800',
                seller: 'bg-purple-50 text-purple-700',
                buyer: 'bg-indigo-50 text-indigo-700',
                property: 'bg-slate-100 text-slate-700',
                agent: 'bg-orange-100 text-orange-800',
                skip: 'bg-slate-100 text-slate-400'
            };
            return badges[party] || 'bg-slate-100 text-slate-500';
        },

        reassignField(fieldId, newParty) {
            const field = this.fields.find(f => f.id === fieldId);
            if (field) field.assignedTo = newParty;
        },

        toggleBranch(branchId, checked) {
            if (checked) {
                if (!this.allowedBranches.includes(branchId)) this.allowedBranches.push(branchId);
            } else {
                this.allowedBranches = this.allowedBranches.filter(id => id !== branchId);
            }
        },

        async save() {
            this.saving = true;
            try {
                const body = {
                    fields: this.fields,
                    name: this.name,
                    template_type: this.templateType,
                    document_type_id: this.documentTypeId || null,
                    is_global: this.isGlobal,
                    allowed_branches: this.isGlobal ? [] : this.allowedBranches,
                };

                const resp = await fetch(@json(route('docuperfect.templates.saveFields', $template->id)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });

                if (!resp.ok) throw new Error('HTTP ' + resp.status);

                this.showToast('Template saved successfully', 'success');
            } catch (err) {
                this.showToast('Save failed: ' + err.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        showToast(message, type) {
            if (window.showToast) {
                window.showToast(message, type);
                return;
            }
            const toast = document.createElement('div');
            toast.className = 'fixed bottom-4 right-4 z-[9999] px-4 py-2 rounded-lg text-sm font-medium shadow-lg transition-all ' +
                (type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white');
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        },
    };
}
</script>
@endsection
