{{-- Road to Sold v3 (spec 22.6) - give a property back to the pool. One reason pop-up for the prospect cards and the parked list; the Release
     buttons dispatch "rts-release" with the prospect and its address. Drawn only for someone who holds road_to_sold.release_prospects. --}}
<div x-data="{ open: false, id: null, address: '', reason: '' }" @rts-release.window="id = $event.detail.id; address = $event.detail.address; reason = ''; open = true" data-qa="release-modal">
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="open = false">
        <form method="POST" :action="'{{ url('road-to-sold/prospects') }}/' + id + '/release'" class="w-full max-w-md rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf
            @if(($track ?? 'sales') !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
            <div>
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">Give back to the pool</h2>
                <div class="text-xs mt-1" style="color: var(--text-muted);" x-text="address"></div>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rel-reason">Why</label>
                <textarea id="rel-reason" name="reason" x-model="reason" rows="3" maxlength="255" required class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle ?? 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);' }}"></textarea>
            </div>
            <div class="flex items-center justify-end gap-2">
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="open = false">Cancel</button>
                <button type="submit" class="corex-btn-primary corex-btn-xs" :disabled="reason.trim().length < 3">Give back</button>
            </div>
        </form>
    </div>
</div>
