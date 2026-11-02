{{-- The MIC card popover (mic-work-tab.md 13.12b). Same behaviour as <x-dropdown> (click opens, click outside / Esc closes, one 22rem wide panel) but it opens DOWNWARD only when the whole panel fits below the trigger in the window, otherwise UPWARD; when neither side fits it takes the larger side and the content scrolls.
     It is placed again on open and whenever the window is resized or anything scrolls, and it is kept inside the window sideways. Alpine component `micPop` is in market-intelligence/work.blade.php. --}}
@props(['align' => 'right', 'contentClasses' => 'p-1'])

<div class="relative" x-data="micPop('{{ $align === 'left' ? 'left' : 'right' }}')" @click.outside="open = false" @close.stop="open = false">
    <div x-ref="trigger" @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-ref="panel" x-show="open" data-qa="mic-pop-panel" :data-side="side"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 w-[22rem] max-w-[90vw] rounded-md shadow-lg"
         :style="pos"
         style="display: none;"
         @click="open = false">
        <div x-ref="inner" class="rounded-md ring-1 ring-black/5 {{ $contentClasses }}" style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">
            {{ $content }}
        </div>
    </div>
</div>
