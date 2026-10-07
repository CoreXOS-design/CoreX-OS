{{-- Mark-Lost dialog — the ONE copy. Included by the buyer detail page
     (command-center/buyers/detail.blade.php) and by Core Matches
     (corex/core-matches/index.blade.php), both posting to the same endpoint
     (command-center.buyers.mark-lost -> BuyerDetailController::markLost), so the
     fields, the validation and the reasons list cannot drift apart.

     Props:
       $ref      string       x-ref name the opener uses ($refs.<ref>.showModal())
       $agencyId int          whose agency_lost_deal_reasons list to offer
       $noun     string       'buyer' | 'tenant'
       $action   string|null  form action; null = the opener sets it on the form
                              (Core Matches: one dialog shared by every row)

     AT-401 — the reason list itself is NOT split by listing_type today
     (agency_lost_deal_reasons has applies_to_buyers/applies_to_sellers only, no
     tenant column), so it stays the shared sales+rental list, unchanged. --}}
@php
    $lostDialogRef = $ref ?? 'lostModal';
    $lostNoun      = $noun ?? 'buyer';
    $lostReasons   = DB::table('agency_lost_deal_reasons')->where('agency_id', (int) $agencyId)->where('applies_to_buyers', true)->where('active', true)->orderBy('display_order')->get();
@endphp
<dialog x-ref="{{ $lostDialogRef }}" class="rounded-md p-0 w-full max-w-md backdrop:bg-black/50" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
    <form method="POST" @if(!empty($action)) action="{{ $action }}" @endif data-mark-lost-form class="p-5 space-y-4">
        @csrf
        <h3 class="text-lg font-semibold">Why is this <span data-lost-noun>{{ $lostNoun }}</span> being marked as lost?</h3>
        <div class="space-y-1 max-h-48 overflow-y-auto">
            @foreach($lostReasons as $reason)
                <label class="flex items-center gap-2 px-2 py-1.5 rounded-md cursor-pointer text-xs" style="color: var(--text-primary);">
                    <input type="radio" name="reason_code" value="{{ $reason->code }}" required class="w-3 h-3">
                    <span>{{ $reason->label }}</span>
                    <span class="text-[10px] ml-auto" style="color: var(--text-muted);">{{ $reason->category }}</span>
                </label>
            @endforeach
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
            <textarea name="notes" rows="3" placeholder="Additional context…"
                      class="w-full rounded-md px-3 py-2 text-sm"
                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">What did the <span data-lost-noun>{{ $lostNoun }}</span> say? (optional)</label>
            <textarea name="outcome" rows="3" placeholder="{{ ucfirst($lostNoun) }}'s actual words…"
                      class="w-full rounded-md px-3 py-2 text-sm"
                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="this.closest('dialog').close()" class="corex-btn-outline">Cancel</button>
            <button type="submit" class="corex-btn-primary"
                    style="background: var(--ds-crimson, #c41e3a); border-color: var(--ds-crimson, #c41e3a);">
                Mark Lost
            </button>
        </div>
    </form>
</dialog>
