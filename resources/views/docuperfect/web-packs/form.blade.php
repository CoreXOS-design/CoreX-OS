{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5"
     x-data="webPackForm()"
>
    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">
                    {{ isset($webPack) ? 'Edit Web Pack' : 'Create Web Pack' }}
                </h1>
                <p class="text-sm text-white/60">
                    {{ isset($webPack) ? $webPack->name : 'Group web templates into a reusable pack.' }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('docuperfect.web-packs.index') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">Back</a>
            </div>
        </div>
    </div>

    {{-- Errors --}}
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--ds-crimson);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">{{ $errors->first() }}</div>
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
        <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div>
                <label for="wp-name" class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Pack Name <span class="text-red-500">*</span></label>
                <input id="wp-name" type="text" name="name" value="{{ old('name', $webPack->name ?? '') }}"
                       class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); outline: none;"
                       required>
            </div>
            <div>
                <label for="wp-description" class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Description</label>
                <textarea id="wp-description" name="description" rows="2"
                          class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); outline: none;">{{ old('description', $webPack->description ?? '') }}</textarea>
            </div>
        </div>

        {{-- Template Selection --}}
        <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between">
                <label class="block text-xs font-semibold" style="color: var(--text-muted);">Select Web Templates <span class="text-red-500">*</span></label>
                <span class="text-xs" style="color: var(--text-muted);" x-text="selectedItems.length + ' selected'"></span>
            </div>

            {{-- Available templates --}}
            <div class="rounded-md max-h-60 overflow-y-auto" style="border: 1px solid var(--border);">
                @forelse($templates as $template)
                <label class="flex items-center gap-3 px-3 py-2 cursor-pointer text-sm transition-colors"
                       style="{{ !$loop->first ? 'border-top: 1px solid var(--border);' : '' }}"
                       onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                    <input type="checkbox"
                           value="{{ $template->id }}"
                           class="rounded"
                           style="accent-color: var(--brand-button, #0ea5e9);"
                           :checked="selectedItems.some(i => i.id === {{ $template->id }})"
                           @change="toggleTemplate({{ $template->id }}, '{{ addslashes($template->name) }}')">
                    <span class="flex-1" style="color: var(--text-primary);">{{ $template->name }}</span>
                    <span class="ds-badge ds-badge-info">Web</span>
                </label>
                @empty
                <div class="px-3 py-6 text-sm text-center" style="color: var(--text-muted);">No web templates available.</div>
                @endforelse
            </div>
        </div>

        {{-- Selected order with slot configuration --}}
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-show="selectedItems.length > 0" x-cloak>
            <label class="block text-xs font-semibold" style="color: var(--text-muted);">Template Order &amp; Slot Configuration</label>
            <div class="text-xs mb-2" style="color: var(--text-muted);">Configure each template's slot type. Selectable items in the same group are alternatives — the agent picks one.</div>

            <template x-for="(item, index) in selectedItems" :key="item.id">
                <div class="rounded-md border px-3 py-2.5 transition-all"
                     style="background: var(--surface); border-color: var(--border); border-left-width: 4px;"
                     :style="item.slot_type === 'selectable'
                         ? { borderLeftColor: item.slot_group === 1 ? 'var(--brand-button, #0ea5e9)' : (item.slot_group === 2 ? 'var(--ds-amber, #f59e0b)' : 'var(--ds-green, #059669)') }
                         : { borderLeftColor: 'var(--border)' }">
                    <div class="flex items-center gap-3 flex-wrap">
                        {{-- Sort number --}}
                        <span class="text-xs w-6 text-center flex-shrink-0" style="color: var(--text-muted);" x-text="index + 1"></span>

                        {{-- Template name --}}
                        <span class="flex-1 min-w-0 text-sm font-medium truncate" style="color: var(--text-primary);" x-text="item.name"></span>

                        {{-- Slot type --}}
                        <select class="text-xs rounded-md px-2 py-1 flex-shrink-0"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                x-model="item.slot_type"
                                @change="onSlotTypeChange(item)">
                            <option value="required">Required (always included)</option>
                            <option value="selectable">Selectable (agent picks one)</option>
                            <option value="optional">Optional (agent includes/excludes)</option>
                        </select>

                        {{-- Slot group (for selectable) --}}
                        <template x-if="item.slot_type === 'selectable'">
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <label class="text-[0.6875rem]" style="color: var(--text-muted);">Group:</label>
                                <select class="text-xs rounded-md px-2 py-1"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                        x-model.number="item.slot_group">
                                    <option value="1">A</option>
                                    <option value="2">B</option>
                                    <option value="3">C</option>
                                </select>
                            </div>
                        </template>

                        {{-- Move Up/Down --}}
                        <button type="button" @click="moveUp(index)"
                                class="text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex-shrink-0"
                                style="color: var(--brand-icon, #0ea5e9);"
                                :disabled="index === 0">&#9650;</button>
                        <button type="button" @click="moveDown(index)"
                                class="text-xs transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex-shrink-0"
                                style="color: var(--brand-icon, #0ea5e9);"
                                :disabled="index === selectedItems.length - 1">&#9660;</button>

                        {{-- Remove --}}
                        <button type="button" @click="removeTemplate(item.id)"
                                class="text-sm leading-none transition-colors flex-shrink-0"
                                style="color: var(--ds-crimson, #c41e3a);"
                                title="Remove template">&times;</button>
                    </div>

                    {{-- Slot label (for selectable) --}}
                    <template x-if="item.slot_type === 'selectable'">
                        <div class="mt-2 pl-9">
                            <input type="text" placeholder="Slot label (e.g. 'Authority Type')..."
                                   class="text-xs rounded-md px-2 py-1 w-64 max-w-full transition-all duration-300"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); outline: none;"
                                   x-model="item.slot_label">
                        </div>
                    </template>
                </div>
            </template>

            {{-- Group summary --}}
            <template x-if="hasSelectableGroups">
                <div class="mt-3 p-3 rounded-md" style="background: var(--surface-2);">
                    <span class="text-[0.6875rem] font-semibold uppercase" style="color: var(--text-muted);">Selectable Groups</span>
                    <div class="mt-1 space-y-1">
                        <template x-for="g in selectableGroupSummary" :key="g.group">
                            <div class="text-xs flex items-center gap-2" style="color: var(--text-secondary);">
                                <span class="w-2 h-2 rounded-full flex-shrink-0"
                                      :style="{ background: g.group === 1 ? 'var(--brand-button, #0ea5e9)' : (g.group === 2 ? 'var(--ds-amber, #f59e0b)' : 'var(--ds-green, #059669)') }"></span>
                                <span>Group <span x-text="['','A','B','C'][g.group]"></span>:</span>
                                <span x-text="g.names.join(' OR ')"></span>
                                <span style="color: var(--text-muted);" x-text="'(' + g.label + ')'"></span>
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
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2 disabled:opacity-40 disabled:cursor-not-allowed"
                    :disabled="selectedItems.length === 0">
                {{ isset($webPack) ? 'Update Web Pack' : 'Create Web Pack' }}
            </button>
            <a href="{{ route('docuperfect.web-packs.index') }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>
</div>

@php
$existingItems = isset($webPack)
    ? $webPack->items->map(function($item) {
        return [
            'id' => $item->template_id,
            'name' => $item->template->name ?? 'Unknown',
            'slot_type' => $item->slot_type ?? 'required',
            'slot_group' => $item->slot_group ?? 1,
            'slot_label' => $item->slot_label ?? '',
        ];
    })->toArray()
    : [];
@endphp

<script>
function webPackForm() {
    const existing = @json($existingItems);

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
