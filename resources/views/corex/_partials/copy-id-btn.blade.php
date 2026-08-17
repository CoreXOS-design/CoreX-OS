{{-- Copy-to-clipboard button for an ID number / entity reg number on the MIC
     contact side. Mirrors the Deeds Capture "Copy ID" convenience
     (deeds-capture/index.blade.php) so an agent can grab the FULL value (e.g. to
     paste into a TVA lookup) without re-typing.

     Params:
       $value — an Alpine JS expression yielding the FULL value to copy
                (e.g. 'owner.id_number', 's.entity_reg_no'). Rendered raw.
       $label — button text (default 'Copy ID'); flips to 'Copied!' for 1.5s.

     The button carries its own x-data="{ copied: false }"; the $value expression
     resolves against the surrounding x-for scope (owner / s). --}}
@php($value ??= '')
@php($label ??= 'Copy ID')
<button type="button" x-data="{ copied: false }"
        @click.stop="navigator.clipboard.writeText({!! $value !!}); copied = true; setTimeout(() => copied = false, 1500)"
        class="text-[10px] font-semibold px-1.5 py-0.5 rounded align-middle"
        style="border:1px solid var(--border); color: var(--brand-icon, #0ea5e9); background: transparent; cursor: pointer;"
        x-text="copied ? 'Copied!' : @js($label)"></button>
