{{--
    .ai/specs/rentals-faults-work-orders.md section 31 - a dialog that opens over the page, right where the agent is, when a Next-bar button (or the panel's own button) fires
    window event "corex-open" with this dialog's name. It holds the fields an action needs; nothing scrolls and nothing is pressed for anyone.
    <x-next-dialog name="quote-capture" title="Capture a quote" :open="$errors->has('amount')"> ...form... </x-next-dialog>
--}}
@props(['name', 'title', 'open' => false])
@once
    <style>
        .nd-overlay { position: fixed; inset: 0; z-index: 90000; display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; background: rgba(15, 23, 42, .55); overflow-y: auto; }
        .nd-overlay[x-cloak] { display: none; }
        .nd-box { width: 100%; max-width: 640px; background: var(--surface); color: var(--text); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; display: flex; flex-direction: column; gap: 12px; }
        .nd-title { font-size: 16px; font-weight: 700; margin: 0; }
        .nd-foot { display: flex; justify-content: flex-end; }
    </style>
@endonce
<div x-data="{ open: {{ $open ? 'true' : 'false' }} }" x-on:corex-open.window="if ($event.detail === @js($name)) { open = true }" x-on:keydown.escape.window="open = false" class="contents">
    {{-- teleported to <body>: the form it holds may live inside a folded panel (<details>), where a fixed overlay would never show --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="nd-overlay" role="dialog" aria-modal="true" data-next-dialog="{{ $name }}">
            <div class="nd-box" x-on:click.outside="open = false">
                <h3 class="nd-title">{{ $title }}</h3>
                {{ $slot }}
                <div class="nd-foot"><button type="button" class="corex-btn-outline text-xs" x-on:click="open = false" data-next-dialog-cancel>Cancel</button></div>
            </div>
        </div>
    </template>
</div>
