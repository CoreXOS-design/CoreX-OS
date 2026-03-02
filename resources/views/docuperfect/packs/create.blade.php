@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="packBuilder()"
>

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">
                {{ isset($pack) ? 'Edit Pack — ' . $pack->name : 'Create Document Pack' }}
            </h2>
            <div class="text-sm text-white/60">Configure slots to define what this pack contains.</div>
        </div>
        <a href="{{ route('docuperfect.packs.index') }}" class="text-sm text-white/70 hover:text-white">Back</a>
    </div>

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST"
          action="{{ isset($pack) ? route('docuperfect.packs.update', $pack->id) : route('docuperfect.packs.store') }}"
          class="space-y-6"
          @submit="onSubmit"
    >
        @csrf
        @if(isset($pack))
            @method('PUT')
        @endif

        {{-- Name & Description --}}
        <div class="ds-status-card p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="ds-label block mb-1">Pack Name</label>
                    <input type="text" name="name" value="{{ old('name', $pack->name ?? '') }}" required
                           class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm"
                           placeholder="e.g. Full Mandate Pack">
                </div>
                <div>
                    <label class="ds-label block mb-1">Description (optional)</label>
                    <input type="text" name="description" value="{{ old('description', $pack->description ?? '') }}"
                           class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm"
                           placeholder="e.g. All documents needed for a new mandate">
                </div>
            </div>
        </div>

        {{-- Creation Mode --}}
        <div class="ds-status-card p-4 space-y-3">
            <h3 class="ds-section-header">Creation Mode</h3>
            <div class="space-y-2">
                <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                       :class="creationMode === 'linked' ? 'border-cyan-400 bg-cyan-50/50' : 'border-slate-200 hover:bg-slate-50'">
                    <input type="radio" name="creation_mode" value="linked" x-model="creationMode"
                           class="mt-0.5 rounded-full border-slate-300 text-cyan-600">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Linked Pack</div>
                        <div class="text-xs text-slate-500">Documents share named fields. Fill once, populate everywhere.</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                       :class="creationMode === 'individual' ? 'border-cyan-400 bg-cyan-50/50' : 'border-slate-200 hover:bg-slate-50'">
                    <input type="radio" name="creation_mode" value="individual" x-model="creationMode"
                           class="mt-0.5 rounded-full border-slate-300 text-cyan-600">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Individual Documents</div>
                        <div class="text-xs text-slate-500">Each document is standalone. Named fields don't sync.</div>
                    </div>
                </label>
            </div>
        </div>

        {{-- Visibility --}}
        <div class="ds-status-card p-4 space-y-3">
            <h3 class="ds-section-header">Visibility</h3>
            <div class="flex items-center gap-2">
                <input type="hidden" name="is_global" value="0">
                <input type="checkbox" name="is_global" value="1" id="packGlobal"
                       {{ old('is_global', $pack->is_global ?? false) ? 'checked' : '' }}
                       class="rounded border-slate-300">
                <label for="packGlobal" class="text-sm text-slate-700">Global (all branches)</label>
            </div>
            <div>
                <label class="ds-label block mb-1">Branch Access</label>
                <div class="flex flex-wrap gap-2">
                    @foreach($branches as $branch)
                    <label class="flex items-center gap-1 text-sm text-slate-700">
                        <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}"
                               {{ isset($pack) && $pack->branches->contains('id', $branch->id) ? 'checked' : '' }}
                               class="rounded border-slate-300">
                        {{ $branch->name }}
                    </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Slot Builder --}}
        <div class="ds-status-card p-4 space-y-3">
            <h3 class="ds-section-header">Pack Slots</h3>
            <div class="text-xs text-slate-500 mb-2">Define what the pack contains. Each slot can be a required template, a selectable type, or a Knowledge Base attachment.</div>

            <template x-if="slots.length === 0">
                <div class="text-center text-sm text-slate-400 py-6 border border-dashed border-slate-200 rounded-lg">
                    No slots yet. Click "Add Slot" to get started.
                </div>
            </template>

            <div class="space-y-3">
                <template x-for="(slot, idx) in slots" :key="idx">
                    <div class="border border-slate-200 rounded-lg p-4 bg-slate-50/50">
                        <div class="flex items-start gap-3">
                            {{-- Reorder arrows --}}
                            <div class="flex flex-col gap-0.5 pt-1">
                                <button type="button" @click="moveUp(idx)" :disabled="idx === 0"
                                        class="text-slate-400 hover:text-slate-700 disabled:text-slate-200 p-0.5">
                                    <i class="fas fa-arrow-up text-xs"></i>
                                </button>
                                <button type="button" @click="moveDown(idx)" :disabled="idx === slots.length - 1"
                                        class="text-slate-400 hover:text-slate-700 disabled:text-slate-200 p-0.5">
                                    <i class="fas fa-arrow-down text-xs"></i>
                                </button>
                            </div>

                            <div class="flex-1 space-y-3">
                                {{-- Row 1: Label + Type --}}
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-slate-500 font-medium">Slot Label</label>
                                        <input type="text" x-model="slot.label" required
                                               class="w-full rounded border-slate-300 text-sm px-3 py-1.5"
                                               placeholder="e.g. Mandate Document">
                                    </div>
                                    <div>
                                        <label class="text-xs text-slate-500 font-medium">Slot Type</label>
                                        <select x-model="slot.slot_type" @change="onTypeChange(idx)"
                                                class="w-full rounded border-slate-300 text-sm px-3 py-1.5">
                                            <option value="required">Required — always included</option>
                                            <option value="selectable">Selectable — agent picks</option>
                                            <option value="attachment">Attachment — Knowledge Base</option>
                                        </select>
                                    </div>
                                </div>

                                {{-- Row 2: Conditional config --}}
                                <div x-show="slot.slot_type === 'required'">
                                    <label class="text-xs text-slate-500 font-medium">Template</label>
                                    <select x-model="slot.template_id"
                                            class="w-full rounded border-slate-300 text-sm px-3 py-1.5">
                                        <option value="">Select template...</option>
                                        @foreach($templates as $tpl)
                                        <option value="{{ $tpl->id }}">{{ $tpl->name }}{{ $tpl->documentType ? ' (' . $tpl->documentType->name . ')' : '' }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div x-show="slot.slot_type === 'selectable'" class="space-y-2">
                                    <div>
                                        <label class="text-xs text-slate-500 font-medium">Document Type</label>
                                        <select x-model="slot.document_type_id"
                                                class="w-full rounded border-slate-300 text-sm px-3 py-1.5">
                                            <option value="">Select document type...</option>
                                            @foreach($documentTypes as $dt)
                                            <option value="{{ $dt->id }}">{{ $dt->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="text-xs text-slate-400">Agent will see all templates of this type and choose which to include.</div>
                                    <div class="flex items-center gap-4">
                                        <label class="flex items-center gap-1 text-xs text-slate-600">
                                            <input type="checkbox" x-model="slot.allow_multiple" class="rounded border-slate-300 text-sm">
                                            Allow multiple
                                        </label>
                                        <label class="flex items-center gap-1 text-xs text-slate-600">
                                            <input type="checkbox" x-model="slot.is_optional" class="rounded border-slate-300 text-sm">
                                            Optional (can skip)
                                        </label>
                                    </div>
                                </div>

                                <div x-show="slot.slot_type === 'attachment'" class="space-y-2">
                                    <div>
                                        <label class="text-xs text-slate-500 font-medium">Knowledge Base Category</label>
                                        <select x-model="slot.knowledge_category_id"
                                                class="w-full rounded border-slate-300 text-sm px-3 py-1.5">
                                            <option value="">Select category...</option>
                                            @foreach($knowledgeCategories as $kc)
                                            <option value="{{ $kc->id }}">{{ $kc->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="text-xs text-slate-400">Agent will see all documents in this category and choose which to attach.</div>
                                    <div class="flex items-center gap-4">
                                        <label class="flex items-center gap-1 text-xs text-slate-600">
                                            <input type="checkbox" x-model="slot.allow_multiple" class="rounded border-slate-300 text-sm">
                                            Allow multiple
                                        </label>
                                        <label class="flex items-center gap-1 text-xs text-slate-600">
                                            <input type="checkbox" x-model="slot.is_optional" class="rounded border-slate-300 text-sm">
                                            Optional (can skip)
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {{-- Delete slot --}}
                            <button type="button" @click="removeSlot(idx)" class="text-slate-300 hover:text-red-500 p-1 mt-1" title="Remove slot">
                                <i class="fas fa-times text-sm"></i>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" @click="addSlot()" class="inline-flex items-center gap-1 text-xs font-medium text-cyan-700 hover:text-cyan-900 mt-2">
                <i class="fas fa-plus text-[10px]"></i> Add Slot
            </button>
        </div>

        {{-- Preview Summary --}}
        <div class="ds-status-card p-4" x-show="slots.length > 0">
            <h3 class="ds-section-header">Preview</h3>
            <div class="text-xs text-slate-500 mb-2">When an agent launches this pack, they will:</div>
            <ul class="text-xs text-slate-700 space-y-1">
                <template x-for="(slot, idx) in slots" :key="'preview-' + idx">
                    <li class="flex items-center gap-2">
                        <template x-if="slot.slot_type === 'required'">
                            <span><span class="text-green-600 font-semibold">Automatically get:</span> <span x-text="slot.label || 'Untitled'"></span></span>
                        </template>
                        <template x-if="slot.slot_type === 'selectable'">
                            <span><span class="text-amber-600 font-semibold">Choose from:</span> <span x-text="slot.label || 'Untitled'"></span> <span class="text-slate-400" x-show="slot.is_optional">(optional)</span></span>
                        </template>
                        <template x-if="slot.slot_type === 'attachment'">
                            <span><span class="text-blue-600 font-semibold">Attach from:</span> <span x-text="slot.label || 'Untitled'"></span> <span class="text-slate-400" x-show="slot.is_optional">(optional)</span></span>
                        </template>
                    </li>
                </template>
            </ul>
        </div>

        {{-- Hidden JSON for form submission --}}
        <input type="hidden" name="slots_json" :value="JSON.stringify(slots)">

        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary text-sm">
                {{ isset($pack) ? 'Update Pack' : 'Create Pack' }}
            </button>
            <a href="{{ route('docuperfect.packs.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
        </div>
    </form>

</div>

<script>
function packBuilder() {
    return {
        creationMode: '{{ old('creation_mode', $pack->creation_mode ?? 'linked') }}',
        slots: @json(isset($existingSlots) ? $existingSlots : []),

        addSlot() {
            this.slots.push({
                label: '',
                slot_type: 'required',
                template_id: '',
                document_type_id: '',
                knowledge_category_id: '',
                allow_multiple: false,
                is_optional: false,
            });
        },

        removeSlot(index) {
            this.slots.splice(index, 1);
        },

        moveUp(index) {
            if (index > 0) {
                const temp = this.slots[index];
                this.slots.splice(index, 1);
                this.slots.splice(index - 1, 0, temp);
            }
        },

        moveDown(index) {
            if (index < this.slots.length - 1) {
                const temp = this.slots[index];
                this.slots.splice(index, 1);
                this.slots.splice(index + 1, 0, temp);
            }
        },

        onTypeChange(index) {
            const slot = this.slots[index];
            slot.template_id = '';
            slot.document_type_id = '';
            slot.knowledge_category_id = '';
            slot.allow_multiple = false;
            slot.is_optional = false;
        },

        onSubmit(e) {
            if (this.slots.length === 0) {
                e.preventDefault();
                alert('Please add at least one slot.');
            }
        }
    };
}
</script>
@endsection
