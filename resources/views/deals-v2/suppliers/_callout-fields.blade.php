{{-- .ai/specs/rentals-faults-work-orders.md §20 - the call-out fee question, ONE partial: the Add provider form, the fault / work order popups and the
     directory's inline editor all use it, so the wording is identical everywhere.
     Expects: $idp (id prefix); optional $mode ('unknown'), $amount, $note. --}}
@php
    $mode = old('callout_fee_mode', $mode ?? 'unknown');
    $amount = old('callout_fee_amount', $amount ?? null);
    $note = old('callout_fee_note', $note ?? null);
@endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:col-span-3" data-callout-fields
     x-data="{ mode: @js($mode) }" @reset.window="setTimeout(() => mode = $refs.mode.value, 0)">
    <div>
        <label for="{{ $idp }}-callout-mode" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Charges a call-out fee?</label>
        <select id="{{ $idp }}-callout-mode" name="callout_fee_mode" x-ref="mode" x-model="mode" class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" data-callout-mode>
            <option value="unknown" @selected($mode === 'unknown')>Not known</option>
            <option value="yes" @selected($mode === 'yes')>Yes</option>
            <option value="no" @selected($mode === 'no')>No</option>
        </select>
    </div>
    <div x-show="mode === 'yes'" x-cloak>
        <label for="{{ $idp }}-callout-amount" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Call-out fee (R, incl. VAT) <span class="text-red-500">*</span></label>
        <input id="{{ $idp }}-callout-amount" name="callout_fee_amount" type="number" step="0.01" min="0.01" max="999999.99" inputmode="decimal"
               :required="mode === 'yes'" :disabled="mode !== 'yes'" value="{{ $amount }}" placeholder="e.g. 750"
               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" data-callout-amount>
    </div>
    <div x-show="mode === 'yes'" x-cloak>
        <label for="{{ $idp }}-callout-note" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Call-out note <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
        <input id="{{ $idp }}-callout-note" name="callout_fee_note" maxlength="255" :disabled="mode !== 'yes'" value="{{ $note }}"
               placeholder="e.g. waived if the job goes ahead; after hours R1 200"
               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" data-callout-note>
    </div>
</div>
