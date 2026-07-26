{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Shared authoring form. Spec: .ai/specs/system-updates.md §7.2

     Expects: $update (SystemUpdate — may be unsaved), $isEdit (bool) --}}
@php
    $types      = collect(config('system-updates.types', []))->sortBy('sort');
    $currentImg = filled($update->image_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($update->image_path)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($update->image_path)
        : null;
@endphp

@if($errors->any())
    <div class="rounded-md px-4 py-3 text-sm"
         style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); color:var(--text-primary); border:1px solid var(--ds-crimson, #c41e3a);">
        <div class="font-semibold mb-1">Please fix the following:</div>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="rounded-md p-5 space-y-5"
     style="background:var(--surface); border:1px solid var(--border);">

    {{-- Type.

         The selected card is styled reactively (Alpine), NOT only from the server-rendered
         $checked. Server-only styling meant clicking a card moved the native radio dot while
         the border + tint stayed on whatever was selected at render time — the control looked
         like it had ignored the click. The server-rendered `style` remains as the initial
         paint so the correct card is highlighted before Alpine boots (and if it never does);
         `:style` merges over it on every change. --}}
    @php $selectedType = old('type', $update->type ?? 'feature'); @endphp
    <div>
        <label class="block text-sm font-semibold mb-2" style="color:var(--text-primary);">What kind of update is this?</label>
        <div class="flex flex-wrap gap-2" x-data="{ type: @js($selectedType) }">
            @foreach($types as $key => $meta)
                @php
                    $checked  = $selectedType === $key;
                    $onColour = "var({$meta['token']}, {$meta['fallback']})";
                    $onTint   = "color-mix(in srgb, {$onColour} 12%, transparent)";
                    $offBd    = 'var(--border)';
                @endphp
                <label class="cursor-pointer px-3 py-2 rounded-md text-sm font-medium transition"
                       style="border:1px solid {{ $checked ? $onColour : $offBd }};
                              background:{{ $checked ? $onTint : 'transparent' }};
                              color:var(--text-primary);"
                       :style="type === '{{ $key }}'
                           ? { borderColor: '{{ $onColour }}', background: '{{ $onTint }}' }
                           : { borderColor: '{{ $offBd }}', background: 'transparent' }">
                    <input type="radio" name="type" value="{{ $key }}" class="mr-2" x-model="type" @checked($checked)>
                    {{ $meta['label'] }}
                </label>
            @endforeach
        </div>
    </div>

    {{-- Title --}}
    <div>
        <label for="title" class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Title</label>
        <input type="text" name="title" id="title" maxlength="160" required
               value="{{ old('title', $update->title) }}"
               placeholder="e.g. Bulk-send viewing packs from the property page"
               class="w-full px-3 py-2 rounded-md text-sm"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
    </div>

    {{-- Body --}}
    <div>
        <label for="body" class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">What changed?</label>
        <textarea name="body" id="body" rows="6" maxlength="5000" required
                  placeholder="Explain it the way you would to an agent on their first day."
                  class="w-full px-3 py-2 rounded-md text-sm"
                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">{{ old('body', $update->body) }}</textarea>
        <p class="text-xs mt-1" style="color:var(--text-secondary);">
            Plain text. Line breaks are kept; formatting and HTML are not.
        </p>
    </div>

    {{-- Link --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="link_url" class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Where does it live? <span class="font-normal" style="color:var(--text-secondary);">(optional)</span></label>
            <input type="text" name="link_url" id="link_url" maxlength="255"
                   value="{{ old('link_url', $update->link_url) }}"
                   placeholder="/corex/properties"
                   class="w-full px-3 py-2 rounded-md text-sm"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-secondary);">
                A CoreX page starting with “/”, or a full https:// address. Leave blank for no button.
            </p>
        </div>
        <div>
            <label for="link_label" class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Button text <span class="font-normal" style="color:var(--text-secondary);">(optional)</span></label>
            <input type="text" name="link_label" id="link_label" maxlength="60"
                   value="{{ old('link_label', $update->link_label) }}"
                   placeholder="Take me there"
                   class="w-full px-3 py-2 rounded-md text-sm"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
    </div>

    {{-- Image --}}
    <div>
        <label for="image" class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Screenshot <span class="font-normal" style="color:var(--text-secondary);">(optional)</span></label>
        @if($currentImg)
            <div class="mb-2">
                <img src="{{ $currentImg }}" alt="Current screenshot" class="rounded-md" style="max-height:160px; border:1px solid var(--border);">
                <label class="inline-flex items-center gap-2 text-xs mt-2" style="color:var(--text-secondary);">
                    <input type="checkbox" name="remove_image" value="1"> Remove this image
                </label>
            </div>
        @endif
        <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp,image/gif"
               class="w-full text-sm" style="color:var(--text-primary);">
        <p class="text-xs mt-1" style="color:var(--text-secondary);">JPG, PNG, WEBP or GIF, up to 4 MB.</p>
    </div>
</div>
