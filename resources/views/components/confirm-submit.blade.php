{{--
    The app's own confirm step for a form button (replaces the browser's window.confirm() box - STANDARDS "Confirmations Before Destructive
    Actions"). Put it INSIDE the <form>: the trigger button opens an in-page dialog; its "confirm" button is the form's real submit button
    (so `name` / `value` reach the server exactly as a plain submit button's would). Escape, a click outside, or Cancel closes it.

    <x-confirm-submit title="Send to the owner" message="Send this to the owner now?" confirm-label="Send to owner" name="send_now" value="1">Save and send</x-confirm-submit>

    Extra attributes (class, x-bind:disabled, data-*) go to the trigger button.
--}}
@props(['title' => 'Please confirm', 'message', 'confirmLabel' => 'Yes, continue', 'name' => null, 'value' => null, 'danger' => false])
<span x-data="{ open: false, sending: false }" x-on:keydown.escape.window="open = false" class="inline-block">
    <button type="button" {{ $attributes->merge(['class' => 'corex-btn-primary text-xs']) }} x-on:click="open = true" data-confirm-trigger>{{ $slot }}</button>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(15, 23, 42, .5);"
         x-on:click.self="open = false" role="dialog" aria-modal="true" aria-label="{{ $title }}" data-confirm-modal>
        <div class="rounded-md p-5 w-full max-w-md space-y-3" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <h3 class="text-sm font-semibold">{{ $title }}</h3>
            <p class="text-sm">{{ $message }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="corex-btn-outline text-xs" x-on:click="open = false" data-confirm-cancel>Cancel</button>
                <button type="submit" @if($name) name="{{ $name }}" value="{{ $value }}" @endif
                        class="{{ $danger ? 'corex-btn-outline' : 'corex-btn-primary' }} text-xs" @if($danger) style="color: var(--ds-crimson, #b3261e);" @endif
                        x-on:click="sending = true" data-confirm-go>{{ $confirmLabel }}</button>
            </div>
        </div>
    </div>
</span>
