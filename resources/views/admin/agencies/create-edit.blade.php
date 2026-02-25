@extends('layouts.nexus')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:var(--brand-primary, #0b2a4a);">
        <h2 class="text-xl font-bold text-white">{{ $agency ? 'Edit Agency' : 'Create Agency' }}</h2>
        <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
            {{ $agency ? "Editing: {$agency->name}" : 'Add a new agency to the platform.' }}
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $agency ? route('agencies.update', $agency) : route('agencies.store') }}"
          class="space-y-5">
        @csrf
        @if($agency)
            @method('PUT')
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-6">

            {{-- Name --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Agency Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $agency?->name) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                       style="focus-border-color:var(--brand-secondary, #00b4d8);"
                       placeholder="e.g. HFC Coastal" required>
            </div>

            {{-- Slug (create only) --}}
            @if(!$agency)
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Slug</label>
                <input type="text" name="slug" value="{{ old('slug') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none"
                       placeholder="auto-generated if blank">
                <p class="text-xs text-slate-400 mt-1">Used in URLs. Must be unique. Leave blank to auto-generate from name.</p>
            </div>
            @endif

            {{-- Brand Colours — 3 tiers --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Brand Colours</label>
                <p class="text-xs text-slate-400 mb-3">These three colours are applied throughout the platform for this agency.</p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    {{-- Primary --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Primary</div>
                        <div class="text-xs text-slate-400">Sidebar, headers, buttons</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="primary_color" id="primary_color_picker"
                                   value="{{ old('primary_color', $agency?->primary_color ?? '#0b2a4a') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="primary_color_text"
                                   value="{{ old('primary_color', $agency?->primary_color ?? '#0b2a4a') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#0b2a4a">
                        </div>
                    </div>

                    {{-- Secondary --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-secondary, #00b4d8);">Secondary</div>
                        <div class="text-xs text-slate-400">Active states, links, accents</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="secondary_color" id="secondary_color_picker"
                                   value="{{ old('secondary_color', $agency?->secondary_color ?? '#00b4d8') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="secondary_color_text"
                                   value="{{ old('secondary_color', $agency?->secondary_color ?? '#00b4d8') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#00b4d8">
                        </div>
                    </div>

                    {{-- Tertiary --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-tertiary, #1a4a73);">Tertiary</div>
                        <div class="text-xs text-slate-400">Hover states, subtle fills</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="tertiary_color" id="tertiary_color_picker"
                                   value="{{ old('tertiary_color', $agency?->tertiary_color ?? '#1a4a73') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="tertiary_color_text"
                                   value="{{ old('tertiary_color', $agency?->tertiary_color ?? '#1a4a73') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#1a4a73">
                        </div>
                    </div>
                </div>

                {{-- Live preview --}}
                <div class="mt-4 rounded-xl border border-slate-200 p-4" id="color-preview">
                    <div class="text-xs text-slate-400 font-semibold uppercase tracking-wider mb-3">Preview</div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <div id="preview-primary" class="rounded-lg px-4 py-2 text-white text-sm font-semibold" style="background:#0b2a4a;">Primary button</div>
                        <div id="preview-secondary" class="rounded-lg px-4 py-2 text-white text-sm font-semibold" style="background:#00b4d8;">Secondary button</div>
                        <div id="preview-tertiary" class="rounded-lg px-4 py-2 text-white text-sm font-semibold" style="background:#1a4a73;">Tertiary hover</div>
                        <div class="flex items-center gap-2">
                            <span id="preview-swatch-p" class="inline-block w-6 h-6 rounded-full border-2 border-white shadow" style="background:#0b2a4a;"></span>
                            <span id="preview-swatch-s" class="inline-block w-6 h-6 rounded-full border-2 border-white shadow" style="background:#00b4d8;"></span>
                            <span id="preview-swatch-t" class="inline-block w-6 h-6 rounded-full border-2 border-white shadow" style="background:#1a4a73;"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Active status --}}
            <div class="flex items-center gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" id="is_active"
                       {{ old('is_active', $agency?->is_active ?? true) ? 'checked' : '' }}
                       class="w-4 h-4 rounded border-slate-300 cursor-pointer"
                       style="accent-color:var(--brand-secondary, #00b4d8);">
                <label for="is_active" class="text-sm font-medium cursor-pointer" style="color:var(--brand-primary, #0b2a4a);">Agency is active</label>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:var(--brand-primary, #0b2a4a);"
                    onmouseover="this.style.background='var(--brand-tertiary, #1a4a73)'" onmouseout="this.style.background='var(--brand-primary, #0b2a4a)'">
                {{ $agency ? 'Update Agency' : 'Create Agency' }}
            </button>
            <a href="{{ route('agencies.index') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 border border-slate-300 hover:bg-slate-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function syncPair(pickerId, textId, previewId, swatchId) {
        const picker  = document.getElementById(pickerId);
        const text    = document.getElementById(textId);
        const preview = document.getElementById(previewId);
        const swatch  = document.getElementById(swatchId);
        if (!picker || !text) return;

        function apply(val) {
            if (preview) preview.style.background = val;
            if (swatch)  swatch.style.background  = val;
        }

        picker.addEventListener('input', () => {
            text.value = picker.value;
            apply(picker.value);
        });
        text.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                picker.value = text.value;
                apply(text.value);
            }
        });
    }

    syncPair('primary_color_picker',   'primary_color_text',   'preview-primary',   'preview-swatch-p');
    syncPair('secondary_color_picker', 'secondary_color_text', 'preview-secondary', 'preview-swatch-s');
    syncPair('tertiary_color_picker',  'tertiary_color_text',  'preview-tertiary',  'preview-swatch-t');
});
</script>
@endsection
