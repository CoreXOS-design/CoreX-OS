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
                <span class="text-xs text-slate-400" x-text="selectedIds.length + ' selected'"></span>
            </div>

            {{-- Available templates --}}
            <div class="border border-slate-200 rounded-lg max-h-60 overflow-y-auto divide-y divide-slate-100">
                @forelse($templates as $template)
                <label class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 cursor-pointer text-sm">
                    <input type="checkbox"
                           value="{{ $template->id }}"
                           class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                           :checked="selectedIds.includes({{ $template->id }})"
                           @change="toggleTemplate({{ $template->id }}, '{{ addslashes($template->name) }}')">
                    <span class="text-slate-700">{{ $template->name }}</span>
                </label>
                @empty
                <div class="px-3 py-4 text-sm text-slate-400 text-center">No web templates available.</div>
                @endforelse
            </div>
        </div>

        {{-- Selected order --}}
        <div class="ds-status-card p-4 space-y-3" x-show="selectedIds.length > 0" x-cloak>
            <label class="ds-label">Template Order</label>
            <div class="text-xs text-slate-400 mb-2">Use Move Up / Move Down to reorder.</div>

            <template x-for="(item, index) in selectedItems" :key="item.id">
                <div class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-3 py-2">
                    <span class="text-xs text-slate-400 w-6 text-center" x-text="index + 1"></span>
                    <span class="flex-1 text-sm text-slate-700" x-text="item.name"></span>
                    <button type="button" @click="moveUp(index)"
                            class="text-xs text-blue-500 hover:text-blue-700 disabled:text-slate-300"
                            :disabled="index === 0">Move Up</button>
                    <button type="button" @click="moveDown(index)"
                            class="text-xs text-blue-500 hover:text-blue-700 disabled:text-slate-300"
                            :disabled="index === selectedItems.length - 1">Move Down</button>
                    <button type="button" @click="removeTemplate(item.id)"
                            class="text-xs text-red-400 hover:text-red-600">Remove</button>
                </div>
            </template>

            {{-- Hidden inputs for submission --}}
            <template x-for="(item, index) in selectedItems" :key="'input-' + item.id">
                <input type="hidden" name="template_ids[]" :value="item.id">
            </template>
        </div>

        {{-- Submit --}}
        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2"
                    :disabled="selectedIds.length === 0">
                {{ isset($webPack) ? 'Update Web Pack' : 'Create Web Pack' }}
            </button>
            <a href="{{ route('docuperfect.web-packs.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
        </div>
    </form>
</div>

<script>
function webPackForm() {
    // Build initial state from existing pack items (edit mode)
    const existing = @json(isset($webPack) ? $webPack->items->map(fn($item) => ['id' => $item->template_id, 'name' => $item->template->name ?? 'Unknown']) : []);

    return {
        selectedItems: existing,
        get selectedIds() {
            return this.selectedItems.map(i => i.id);
        },
        toggleTemplate(id, name) {
            const idx = this.selectedItems.findIndex(i => i.id === id);
            if (idx >= 0) {
                this.selectedItems.splice(idx, 1);
            } else {
                this.selectedItems.push({ id, name });
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
        onSubmit(e) {
            if (this.selectedItems.length === 0) {
                e.preventDefault();
                alert('Please select at least one template.');
            }
        }
    };
}
</script>
@endsection
