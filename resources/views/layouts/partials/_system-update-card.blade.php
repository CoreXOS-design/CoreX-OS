{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     A single System Update card. Shared by the pop-up modal, the admin preview
     and the What's New archive, so the three can never drift apart visually.

     Spec: .ai/specs/system-updates.md §12

     Expects: $update (App\Models\SystemUpdate)
     Optional: $showLink   (bool, default true) — the preview suppresses navigation.
     Optional: $showHeader (bool, default true) — the What's New archive suppresses the
               chip + title, because there they are the collapsed row the user clicked to
               get here; repeating them inside the expansion reads as a duplicate.
--}}
@php
    $chip       = $update->typeChip();
    $showLink   = $showLink ?? true;
    $showHeader = $showHeader ?? true;
    $imageUrl   = null;

    // Absorb a missing file: the row can point at an image that is no longer on
    // disk (spec §9.4). A broken <img> frame is not acceptable, so we only render
    // the image when the file is actually there.
    if (filled($update->image_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($update->image_path)) {
        $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($update->image_path);
    }
@endphp

<div class="space-y-4">

    @if($showHeader)
        {{-- Type chip --}}
        <div>
            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[0.6875rem] font-bold uppercase tracking-wide"
                  style="background:color-mix(in srgb, var({{ $chip['token'] }}, {{ $chip['fallback'] }}) 15%, transparent);
                         color:var({{ $chip['token'] }}, {{ $chip['fallback'] }});">
                {{ $chip['label'] }}
            </span>
        </div>

        {{-- Title --}}
        <h2 class="text-lg font-bold leading-snug" style="color:var(--text-primary);">
            {{ $update->title }}
        </h2>
    @endif

    {{-- Screenshot --}}
    @if($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $update->title }}"
             class="w-full rounded-md"
             style="max-height:260px; object-fit:cover; border:1px solid var(--border);">
    @endif

    {{-- Body. Escaped, ALWAYS — never raw HTML.

         The author is a System Owner and therefore trusted, but this renders
         inside every authenticated session in CoreX, which makes it the
         highest-value XSS target in the product. e() escapes first, then nl2br
         adds the line breaks, so a typed <script> appears as visible text.
         Spec §9.3. --}}
    <div class="text-sm leading-relaxed" style="color:var(--text-secondary);">
        {!! nl2br(e($update->body)) !!}
    </div>

    {{-- "Take me there" — absorbed when no URL was set (spec §9.2). --}}
    @if($showLink && $update->hasLink())
        <div>
            <a href="{{ $update->link_url }}"
               @if(!\Illuminate\Support\Str::startsWith($update->link_url, '/')) target="_blank" rel="noopener" @endif
               data-system-update-link
               class="corex-btn-primary inline-flex items-center gap-2">
                {{ $update->linkLabelOrDefault() }}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    @endif
</div>
