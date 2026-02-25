@extends('layouts.nexus')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:#0b2a4a;">
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

        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">

            {{-- Name --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:#0b2a4a;">Agency Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $agency?->name) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#00b4d8]"
                       placeholder="e.g. HFC Coastal" required>
            </div>

            {{-- Slug (create only) --}}
            @if(!$agency)
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:#0b2a4a;">Slug</label>
                <input type="text" name="slug" value="{{ old('slug') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none focus:border-[#00b4d8]"
                       placeholder="auto-generated if blank">
                <p class="text-xs text-slate-400 mt-1">Used in URLs. Must be unique. Leave blank to auto-generate from name.</p>
            </div>
            @endif

            {{-- Brand Colours --}}
            <div>
                <label class="block text-sm font-semibold mb-2" style="color:#0b2a4a;">Brand Colours</label>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Primary (e.g. navy)</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="primary_color"
                                   value="{{ old('primary_color', $agency?->primary_color ?? '#0b2a4a') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5">
                            <input type="text" id="primary_color_text"
                                   value="{{ old('primary_color', $agency?->primary_color ?? '#0b2a4a') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none focus:border-[#00b4d8]"
                                   maxlength="7" placeholder="#0b2a4a">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Secondary (e.g. cyan)</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="secondary_color"
                                   value="{{ old('secondary_color', $agency?->secondary_color ?? '#00b4d8') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5">
                            <input type="text" id="secondary_color_text"
                                   value="{{ old('secondary_color', $agency?->secondary_color ?? '#00b4d8') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none focus:border-[#00b4d8]"
                                   maxlength="7" placeholder="#00b4d8">
                        </div>
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-2">These colours will be used for this agency's branding across the platform.</p>
            </div>

            {{-- Active status --}}
            <div class="flex items-center gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" id="is_active"
                       {{ old('is_active', $agency?->is_active ?? true) ? 'checked' : '' }}
                       class="w-4 h-4 rounded border-slate-300 cursor-pointer"
                       style="accent-color:#00b4d8;">
                <label for="is_active" class="text-sm font-medium cursor-pointer" style="color:#0b2a4a;">Agency is active</label>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:#0b2a4a;"
                    onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
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
// Sync colour pickers with text inputs
document.addEventListener('DOMContentLoaded', function () {
    function syncPair(pickerId, textId) {
        const picker = document.querySelector('[name="' + pickerId + '"]');
        const text   = document.getElementById(textId);
        if (!picker || !text) return;
        picker.addEventListener('input', () => { text.value = picker.value; });
        text.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
        });
    }
    syncPair('primary_color',   'primary_color_text');
    syncPair('secondary_color', 'secondary_color_text');
});
</script>
@endsection
