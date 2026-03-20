@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="webPackForm()"
>
    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">
                {{ isset($webPack) ? 'Edit Web Pack — ' . $webPack->name : 'Create Web Pack' }}
            </h2>
        </div>
        <a href="{{ route('docuperfect.web-packs.index') }}" class="text-sm text-white/70 hover:text-white">Back</a>
    </div>

    {{-- Errors --}}
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Form --}}
    <form method="POST"
          action="{{ isset($webPack) ? route('docuperfect.web-packs.update', $webPack->id) : route('docuperfect.web-packs.store') }}"
          class="space-y-6"
          @submit="onSubmit"
    >
        @csrf
        @if(isset($webPack))
            @method('PUT')
        @endif

        {{-- Name & Description --}}
        <div class="ds-status-card p-4 space-y-4">
            <div>
                <label class="ds-label">Pack Name <span class="text-red-400">*</span></label>
                <input type="text" name="name" value="{{ old('name', $webPack->name ?? '') }}"
                       class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       required>
            </div>
            <div>
                <label class="ds-label">Description</label>
                <textarea name="description" rows="2"
                          class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('description', $webPack->description ?? '') }}</textarea>
            </div>
        </div>

        {{-- Template Selection --}}
        <div class="ds-status-card p-4 space-y-4">
            <div class="flex items-center justify-between">
                <label class="ds-label mb-0">Select Web Templates <span class="text-red-400">*</span></label>
                <span class="text-xs text-slate-400" x-text="selectedItems.length + ' selected'"></span>
            </div>

            {{-- Available templates --}}
            <div class="border border-slate-200 rounded-lg max-h-60 overflow-y-auto divide-y divide-slate-100">
                @forelse($templates as $template)
                <label class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 cursor-pointer text-sm">
                    <input type="checkbox"
                           value="{{ $template->id }}"
                           class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                           :checked="selectedItems.some(i => i.id === {{ $template->id }})"
                           @change="toggleTemplate({{ $template->id }}, '{{ addslashes($template->name) }}')">
                    <span class="text-slate-700">{{ $template->name }}</span>
                    @if($template->template_type === 'cds')
                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-100 text-teal-700 font-semibold">CDS</span>
                    @else
                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 font-semibold">Web</span>
                    @endif
                </label>
                @empty
                <div class="px-3 py-4 text-sm text-slate-400 text-center">No web templates available.</div>
                @endforelse
            </div>
        </div>

        {{-- Selected order with slot configuration --}}
        <div class="ds-status-card p-4 space-y-3" x-show="selectedItems.length > 0" x-cloak>
            <label class="ds-label">Template Order & Slot Configuration</label>
            <div class="text-xs text-slate-400 mb-2">Configure each template's slot type. Selectable items in the same group are alternatives — the agent picks one.</div>

            <template x-for="(item, index) in selectedItems" :key="item.id">
                <div class="bg-white border rounded-lg px-3 py-2.5 transition-all"
                     :class="{
                         'border-blue-300 border-l-4 border-l-blue-500': item.slot_type === 'selectable' && item.slot_group === 1,
                         'border-amber-300 border-l-4 border-l-amber-500': item.slot_type === 'selectable' && item.slot_group === 2,
                         'border-green-300 border-l-4 border-l-green-500': item.slot_type === 'selectable' && item.slot_group === 3,
                         'border-slate-200': item.slot_type !== 'selectable',
                     }">
                    <div class="flex items-center gap-3">
                        {{-- Sort number --}}
                        <span class="text-xs text-slate-400 w-6 text-center" x-text="index + 1"></span>

                        {{-- Template name --}}
                        <span class="flex-1 text-sm font-medium text-slate-700" x-text="item.name"></span>

                        {{-- Slot type --}}
                        <select class="text-xs border border-gray-300 rounded px-2 py-1 bg-white"
                                x-model="item.slot_type"
                                @change="onSlotTypeChange(item)">
                            <option value="required">Required (always included)</option>
                            <option value="selectable">Selectable (agent picks one)</option>
                            <option value="optional">Optional (agent includes/excludes)</option>
                        </select>

                        {{-- Slot group (for selectable) --}}
                        <template x-if="item.slot_type === 'selectable'">
                            <div class="flex items-center gap-1">
                                <label class="text-[10px] text-gray-500">Group:</label>
                                <select class="text-xs border border-gray-300 rounded px-2 py-1 bg-white"
                                        x-model.number="item.slot_group">
                                    <option value="1">A</option>
                                    <option value="2">B</option>
                                    <option value="3">C</option>
                                </select>
                            </div>
                        </template>

                        {{-- Move Up/Down --}}
                        <button type="button" @click="moveUp(index)"
                                class="text-xs text-blue-500 hover:text-blue-700 disabled:text-slate-300"
                                :disabled="index === 0">&#9650;</button>
                        <button type="button" @click="moveDown(index)"
                                class="text-xs text-blue-500 hover:text-blue-700 disabled:text-slate-300"
                                :disabled="index === selectedItems.length - 1">&#9660;</button>

                        {{-- Remove --}}
                        <button type="button" @click="removeTemplate(item.id)"
                                class="text-xs text-red-400 hover:text-red-600">&times;</button>
                    </div>

                    {{-- Slot label (for selectable) --}}
                    <template x-if="item.slot_type === 'selectable'">
                        <div class="mt-2 pl-9">
                            <input type="text" placeholder="Slot label (e.g. 'Authority Type')..."
                                   class="text-xs border border-gray-300 rounded px-2 py-1 bg-white w-64"
                                   x-model="item.slot_label">
                        </div>
                    </template>
                </div>
            </template>

            {{-- Group summary --}}
            <template x-if="hasSelectableGroups">
                <div class="mt-3 p-3 bg-slate-50 rounded-lg">
                    <span class="text-[10px] font-semibold text-slate-500 uppercase">Selectable Groups</span>
                    <div class="mt-1 space-y-1">
                        <template x-for="g in selectableGroupSummary" :key="g.group">
                            <div class="text-xs text-slate-600 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full"
                                      :class="{
                                          'bg-blue-500': g.group === 1,
                                          'bg-amber-500': g.group === 2,
                                          'bg-green-500': g.group === 3,
                                      }"></span>
                                <span>Group <span x-text="['','A','B','C'][g.group]"></span>:</span>
                                <span x-text="g.names.join(' OR ')"></span>
                                <span class="text-slate-400" x-text="'(' + g.label + ')'"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Hidden inputs for submission --}}
            <template x-for="(item, index) in selectedItems" :key="'input-' + item.id">
                <div>
                    <input type="hidden" :name="'items[' + index + '][template_id]'" :value="item.id">
                    <input type="hidden" :name="'items[' + index + '][slot_type]'" :value="item.slot_type">
                    <input type="hidden" :name="'items[' + index + '][slot_group]'" :value="item.slot_type === 'selectable' ? item.slot_group : ''">
                    <input type="hidden" :name="'items[' + index + '][slot_label]'" :value="item.slot_type === 'selectable' ? (item.slot_label || '') : ''">
                </div>
            </template>
        </div>

        {{-- Submit --}}
        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2"
                    :disabled="selectedItems.length === 0">
                {{ isset($webPack) ? 'Update Web Pack' : 'Create Web Pack' }}
            </button>
            <a href="{{ route('docuperfect.web-packs.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
        </div>
    </form>
</div>

<script>
function webPackForm() {
    const existing = @json(
        isset($webPack)
            ? $webPack->items->map(fn($item) => [
                'id' => $item->template_id,
                'name' => $item->template->name ?? 'Unknown',
                'slot_type' => $item->slot_type ?? 'required',
                'slot_group' => $item->slot_group ?? 1,
                'slot_label' => $item->slot_label ?? '',
            ])
            : []
    );

    return {
        selectedItems: existing,

        toggleTemplate(id, name) {
            const idx = this.selectedItems.findIndex(i => i.id === id);
            if (idx >= 0) {
                this.selectedItems.splice(idx, 1);
            } else {
                this.selectedItems.push({
                    id,
                    name,
                    slot_type: 'required',
                    slot_group: 1,
                    slot_label: '',
                });
            }
        },
        removeTemplate(id) {
            this.selectedItems = this.selectedItems.filter(i => i.id !== id);
        },
        moveUp(index) {
            if (index <= 0) return;
            const items = this.selectedItems;
            [items[index - 1], items[index]] = [items[index], items[index - 1]];
        },
        moveDown(index) {
            if (index >= this.selectedItems.length - 1) return;
            const items = this.selectedItems;
            [items[index], items[index + 1]] = [items[index + 1], items[index]];
        },
        onSlotTypeChange(item) {
            if (item.slot_type === 'selectable') {
                item.slot_group = item.slot_group || 1;
            }
        },

        get hasSelectableGroups() {
            return this.selectedItems.some(i => i.slot_type === 'selectable');
        },

        get selectableGroupSummary() {
            const groups = {};
            this.selectedItems.filter(i => i.slot_type === 'selectable').forEach(i => {
                const g = i.slot_group || 1;
                if (!groups[g]) groups[g] = { group: g, names: [], label: '' };
                groups[g].names.push(i.name);
                if (i.slot_label) groups[g].label = i.slot_label;
            });
            return Object.values(groups).map(g => {
                if (!g.label && g.names.length > 0) g.label = 'agent picks one';
                return g;
            });
        },

        onSubmit(e) {
            if (this.selectedItems.length === 0) {
                e.preventDefault();
                alert('Please select at least one template.');
                return;
            }
            // Validate: selectable groups must have at least 2 items
            const groups = {};
            this.selectedItems.filter(i => i.slot_type === 'selectable').forEach(i => {
                const g = i.slot_group || 1;
                groups[g] = (groups[g] || 0) + 1;
            });
            for (const [g, count] of Object.entries(groups)) {
                if (count < 2) {
                    e.preventDefault();
                    alert('Selectable group ' + ['','A','B','C'][g] + ' needs at least 2 templates (agent picks one).');
                    return;
                }
            }
        }
    };
}
</script>
@endsection
