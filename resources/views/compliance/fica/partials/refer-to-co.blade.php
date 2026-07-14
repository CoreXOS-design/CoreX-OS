{{-- AT-236 — "Escalate to CO" third review action. Renders on every review surface
     for any NON-primary-CO authorized reviewer (a Reporting Officer / RO, or an agent).
     The primary CO is the escalation TARGET, so it is hidden for them. Mandatory reason.
     Guarded by $referralEnabled (agency setting; server re-enforces). --}}
@if(($referralEnabled ?? true) && ! ($viewerIsPrimaryCo ?? false))
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);"
     x-data="{ referOpen: false }">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Escalate to Compliance Officer</h3>
            <p class="text-xs mt-1" style="color:var(--text-secondary);">
                Send this FICA to the Compliance Officer for a decision. A reason is required and recorded.
            </p>
        </div>
        <button type="button" @click="referOpen = !referOpen"
                class="corex-btn-outline text-sm whitespace-nowrap"
                style="border-color:var(--ds-amber,#f59e0b); color:var(--ds-amber,#f59e0b);">
            <span x-show="!referOpen">Escalate to CO</span>
            <span x-show="referOpen" x-cloak>Cancel</span>
        </button>
    </div>

    <form method="POST" action="{{ route('compliance.fica.refer-to-co', $submission) }}"
          x-show="referOpen" x-cloak class="mt-4" @submit="$el.querySelector('button[type=submit]').disabled = true">
        @csrf
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">
            Reason for escalation <span style="color:var(--ds-crimson,#c41e3a);">*</span>
        </label>
        <textarea name="referral_note" rows="3" required minlength="3" maxlength="2000"
                  class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-3"
                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                  placeholder="Why does this need the Compliance Officer's decision?"></textarea>
        <button type="submit" class="corex-btn-primary w-full justify-center text-sm"
                style="background:var(--ds-amber,#f59e0b); box-shadow:none;">
            Escalate to Compliance Officer
        </button>
    </form>
</div>
@endif
