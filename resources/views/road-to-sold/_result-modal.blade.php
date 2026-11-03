{{--
    The Result pop-up (road-to-sold.md 23.9): ONE answer sets the record, the claim and the property together. Used by the Prospects cards and the Held station list;
    both supply the Alpine state it reads (id, address, resultOpen, resultKey, resultDate, resultOptions, resultNeedsDate, pickResult). Inputs: $track, $inputStyle.
--}}
{{-- The result of the evaluation / presentation (road-to-sold.md 23.9): ONE answer sets the record, the claim and the property together --}}
<div x-show="resultOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="resultOpen = false" data-qa="result-modal">
    <form method="POST" :action="'{{ dirname(dirname(route('road-to-sold.prospects.result', 0))) }}/' + id + '/result'" class="w-full max-w-md rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <h2 class="text-sm font-bold" style="color: var(--text-primary);">Result</h2>
        <div class="text-xs" style="color: var(--text-muted);" x-text="address"></div>
        <div class="space-y-1.5">
            <template x-for="o in resultOptions" :key="o.key">
                <label class="flex items-start gap-2 text-xs cursor-pointer" style="color: var(--text-primary);">
                    <input type="radio" name="result_key" :value="o.key" x-model="resultKey" required class="mt-0.5" @change="pickResult()">
                    <span><span class="font-semibold" x-text="o.label"></span><span style="color: var(--text-muted);" x-text="' - ' + o.hint"></span></span>
                </label>
            </template>
        </div>
        <div x-show="resultKey && resultNeedsDate"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rs-on">Bring it back on</label>
            <input id="rs-on" type="date" name="revisit_on" x-model="resultDate" :disabled="!(resultKey && resultNeedsDate)" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rs-note">Note</label>
            <input id="rs-note" name="note" maxlength="255" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div class="flex items-center justify-end gap-2"><button type="button" class="corex-btn-outline corex-btn-xs" @click="resultOpen = false">Cancel</button><button type="submit" class="corex-btn-primary corex-btn-xs" :disabled="!resultKey">Save result</button></div>
    </form>
</div>

