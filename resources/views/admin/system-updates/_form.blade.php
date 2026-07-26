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
         style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); color:var(--text-primary, #111827); border:1px solid var(--ds-crimson, #c41e3a);">
        <div class="font-semibold mb-1">Please fix the following:</div>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="rounded-md p-5 space-y-5"
     style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">

    {{-- Type --}}
    <div>
        <label class="block text-sm font-semibold mb-2" style="color:var(--text-primary, #111827);">What kind of update is this?</label>
        <div class="flex flex-wrap gap-2">
            @foreach($types as $key => $meta)
                @php $checked = old('type', $update->type ?? 'feature') === $key; @endphp
                <label class="cursor-pointer px-3 py-2 rounded-md text-sm font-medium"
                       style="border:1px solid {{ $checked ? "var({$meta['token']}, {$meta['fallback']})" : 'var(--border, rgba(0,0,0,0.07))' }};
                              background:{{ $checked ? "color-mix(in srgb, var({$meta['token']}, {$meta['fallback']}) 12%, transparent)" : 'transparent' }};
                              color:var(--text-primary, #111827);">
                    <input type="radio" name="type" value="{{ $key }}" class="mr-2" @checked($checked)>
                    {{ $meta['label'] }}
                </label>
            @endforeach
        </div>
    </div>

    {{-- Title --}}
    <div>
        <label for="title" class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #111827);">Title</label>
        <input type="text" name="title" id="title" maxlength="160" required
               value="{{ old('title', $update->title) }}"
               placeholder="e.g. Bulk-send viewing packs from the property page"
               class="w-full px-3 py-2 rounded-md text-sm"
               style="background:var(--surface, #fff); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
    </div>

    {{-- Body --}}
    <div>
        <label for="body" class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #111827);">What changed?</label>
        <textarea name="body" id="body" rows="6" maxlength="5000" required
                  placeholder="Explain it the way you would to an agent on their first day."
                  class="w-full px-3 py-2 rounded-md text-sm"
                  style="background:var(--surface, #fff); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">{{ old('body', $update->body) }}</textarea>
        <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">
            Plain text. Line breaks are kept; formatting and HTML are not.
        </p>
    </div>

    {{-- Link --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="link_url" class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #111827);">Where does it live? <span class="font-normal" style="color:var(--text-secondary, #6b7280);">(optional)</span></label>
            <input type="text" name="link_url" id="link_url" maxlength="255"
                   value="{{ old('link_url', $update->link_url) }}"
                   placeholder="/corex/properties"
                   class="w-full px-3 py-2 rounded-md text-sm"
                   style="background:var(--surface, #fff); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">
                A CoreX page starting with “/”, or a full https:// address. Leave blank for no button.
            </p>
        </div>
        <div>
            <label for="link_label" class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #111827);">Button text <span class="font-normal" style="color:var(--text-secondary, #6b7280);">(optional)</span></label>
            <input type="text" name="link_label" id="link_label" maxlength="60"
                   value="{{ old('link_label', $update->link_label) }}"
                   placeholder="Take me there"
                   class="w-full px-3 py-2 rounded-md text-sm"
                   style="background:var(--surface, #fff); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
        </div>
    </div>

    {{-- Image --}}
    <div>
        <label for="image" class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #111827);">Screenshot <span class="font-normal" style="color:var(--text-secondary, #6b7280);">(optional)</span></label>
        @if($currentImg)
            <div class="mb-2">
                <img src="{{ $currentImg }}" alt="Current screenshot" class="rounded-md" style="max-height:160px; border:1px solid var(--border, rgba(0,0,0,0.07));">
                <label class="inline-flex items-center gap-2 text-xs mt-2" style="color:var(--text-secondary, #6b7280);">
                    <input type="checkbox" name="remove_image" value="1"> Remove this image
                </label>
            </div>
        @endif
        <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp,image/gif"
               class="w-full text-sm" style="color:var(--text-primary, #111827);">
        <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">JPG, PNG, WEBP or GIF, up to 4 MB.</p>
    </div>
</div>
